<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueueService
{
    public function getActiveQueueCount(Restaurant $restaurant): int
    {
        return $restaurant->queueTickets()
            ->whereIn('status', [QueueTicket::STATUS_WAITING, QueueTicket::STATUS_CALLED])
            ->count();
    }

    public function calculateEstimatedWait(Restaurant $restaurant, ?QueueTicket $ticket = null): int
    {
        $peopleAhead = $ticket
            ? max($this->calculateCustomerPosition($ticket) - 1, 0)
            : $this->getActiveQueueCount($restaurant);

        return $this->estimatedWaitForAhead($restaurant, $peopleAhead);
    }

    /**
     * Minutes to wait with this many groups ahead, when the position is already known (saves a query).
     */
    public function estimatedWaitForAhead(Restaurant $restaurant, int $groupsAhead): int
    {
        return max(0, $groupsAhead) * $restaurant->average_service_minutes;
    }

    public function calculateCustomerPosition(QueueTicket $ticket): int
    {
        if (! $ticket->isActive()) {
            return 0;
        }

        return QueueTicket::query()
            ->where('restaurant_id', $ticket->restaurant_id)
            ->whereIn('status', [QueueTicket::STATUS_WAITING, QueueTicket::STATUS_CALLED])
            ->where('sequence_number', '<=', $ticket->sequence_number)
            ->count();
    }

    public function generateQueueNumber(Restaurant $restaurant): array
    {
        $nextSequence = ((int) QueueTicket::query()
            ->where('restaurant_id', $restaurant->id)
            ->max('sequence_number')) + 1;

        return [
            'sequence_number' => $nextSequence,
            'queue_number' => sprintf('%s-%03d', strtoupper($restaurant->prefix), $nextSequence),
        ];
    }

    public function getRestaurantStatus(int $estimatedWait): string
    {
        return match (true) {
            $estimatedWait <= 15 => 'Normal',
            $estimatedWait <= 30 => 'Padat',
            default => 'Terlalu Padat',
        };
    }

    /**
     * Polled by every open outlet page, so it runs two queries: counts per status, and the number being served.
     */
    public function getRestaurantSnapshot(Restaurant $restaurant): array
    {
        $counts = $restaurant->queueTickets()
            ->whereIn('status', [QueueTicket::STATUS_WAITING, QueueTicket::STATUS_CALLED])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $waiting = (int) ($counts[QueueTicket::STATUS_WAITING] ?? 0);
        $active = $waiting + (int) ($counts[QueueTicket::STATUS_CALLED] ?? 0);
        $estimatedWait = $this->estimatedWaitForAhead($restaurant, $active);

        return [
            'active_count' => $active,
            'waiting_count' => $waiting,
            'current_number' => $active > $waiting
                ? $restaurant->queueTickets()->where('status', QueueTicket::STATUS_CALLED)->value('queue_number')
                : null,
            'estimated_wait' => $estimatedWait,
            'status' => $this->getRestaurantStatus($estimatedWait),
            'available' => $restaurant->active && $restaurant->queue_open,
        ];
    }

    public function findActiveTicketForPhone(Restaurant $restaurant, string $phone): ?QueueTicket
    {
        return QueueTicket::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('phone_number', $this->normalizePhoneNumber($phone))
            ->whereIn('status', [QueueTicket::STATUS_WAITING, QueueTicket::STATUS_CALLED])
            ->orderBy('sequence_number')
            ->first();
    }

    public function createTicket(Restaurant $restaurant, ?string $name = null, ?string $phone = null, int $partySize = 1): QueueTicket
    {
        return DB::transaction(function () use ($restaurant, $name, $phone, $partySize): QueueTicket {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            if (! $restaurant->active || ! $restaurant->queue_open) {
                throw ValidationException::withMessages(['restaurant' => 'Antrean outlet sedang ditutup.']);
            }
            if ($partySize < 1 || $partySize > $restaurant->max_party_size) {
                throw ValidationException::withMessages(['party_size' => "Jumlah tamu harus 1 sampai {$restaurant->max_party_size} orang."]);
            }
            if ($phone && ($existing = $this->findActiveTicketForPhone($restaurant, $phone))) {
                return $existing;
            }
            $number = $this->generateQueueNumber($restaurant);
            // Every active ticket is ahead of the new one. The sign-up confirmation already shows that
            // count, so a "tinggal 3" / "tinggal 1" threshold reached at sign-up is marked as done.
            $ahead = $this->getActiveQueueCount($restaurant);

            return QueueTicket::query()->create([
                'restaurant_id' => $restaurant->id,
                'customer_name' => $name ?: null,
                'phone_number' => $phone ? $this->normalizePhoneNumber($phone) : null,
                'party_size' => $partySize,
                'queue_number' => $number['queue_number'],
                'sequence_number' => $number['sequence_number'],
                'status' => QueueTicket::STATUS_WAITING,
                'joined_at' => now(),
                'three_ahead_notified_at' => $ahead < 3 ? now() : null,
                // Joined with 2 or fewer ahead: the sign-up confirmation already states that count.
                'two_ahead_notified_at' => $ahead <= 2 ? now() : null,
                'one_ahead_notified_at' => $ahead < 1 ? now() : null,
            ]);
        });
    }

    public function callNext(Restaurant $restaurant): ?QueueTicket
    {
        $ticket = DB::transaction(function () use ($restaurant): ?QueueTicket {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            if ($restaurant->queueTickets()->where('status', QueueTicket::STATUS_CALLED)->exists()) {
                return null;
            }

            $ticket = $restaurant->queueTickets()
                ->where('status', QueueTicket::STATUS_WAITING)
                ->orderBy('sequence_number')
                ->lockForUpdate()
                ->first();

            if (! $ticket) {
                return null;
            }

            $ticket->update([
                'status' => QueueTicket::STATUS_CALLED,
                'called_at' => now(),
            ]);

            return $ticket->fresh('restaurant');
        });

        if ($ticket) {
            $this->afterChange($restaurant, $ticket, 'called');
        }

        return $ticket;
    }

    public function completeCurrent(Restaurant $restaurant): ?QueueTicket
    {
        return $this->transitionCurrent($restaurant, QueueTicket::STATUS_COMPLETED, 'completed_at');
    }

    public function skipCurrent(Restaurant $restaurant): ?QueueTicket
    {
        return $this->transitionCurrent($restaurant, QueueTicket::STATUS_SKIPPED, 'completed_at', 'skipped');
    }

    public function cancelTicket(QueueTicket $ticket): QueueTicket
    {
        $changed = DB::transaction(function () use ($ticket): bool {
            Restaurant::query()->whereKey($ticket->restaurant_id)->lockForUpdate()->firstOrFail();
            $fresh = QueueTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $fresh->isActive()) {
                return false;
            }
            $fresh->update([
                'status' => QueueTicket::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            return true;
        });
        $ticket = $ticket->fresh('restaurant');
        if ($changed) {
            $this->afterChange($ticket->restaurant, $ticket, 'cancelled');
        }

        return $ticket;
    }

    public function normalizePhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }

    private function transitionCurrent(Restaurant $restaurant, string $status, string $timestampColumn, ?string $event = null): ?QueueTicket
    {
        $ticket = DB::transaction(function () use ($restaurant, $status, $timestampColumn): ?QueueTicket {
            Restaurant::query()->whereKey($restaurant->id)->lockForUpdate()->firstOrFail();
            $ticket = $restaurant->queueTickets()
                ->where('status', QueueTicket::STATUS_CALLED)
                ->orderBy('sequence_number')
                ->lockForUpdate()
                ->first();

            if (! $ticket) {
                return null;
            }

            $ticket->update([
                'status' => $status,
                $timestampColumn => now(),
            ]);

            return $ticket->fresh('restaurant');
        });

        if ($ticket) {
            $this->afterChange($restaurant, $ticket, $event);
        }

        return $ticket;
    }

    private function afterChange(Restaurant $restaurant, ?QueueTicket $ticket = null, ?string $event = null): void
    {
        try {
            $notifications = app(QueueWhatsAppNotificationService::class);
            if ($event === 'called') {
                $notifications->notifyCalled($ticket);
            } elseif ($event === 'cancelled') {
                $notifications->notifyCancelled($ticket);
            } elseif ($event === 'skipped') {
                $notifications->notifySkipped($ticket);
            }
            $notifications->notifyWaitingPositions($restaurant, $this);
            app(WhatsAppService::class)->dispatchPending();
        } catch (Throwable) {
            Log::error('Queue updated but notification scheduling failed', ['restaurant_id' => $restaurant->id]);
        }
    }
}
