<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\WhatsAppConversation;

class QueueWhatsAppNotificationService
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function notifyCalled(QueueTicket $ticket): void
    {
        if (! $ticket->called_notified_at) {
            $this->notify($ticket, 'called', "Giliran {$ticket->queue_number} sudah tiba!\nSilakan menuju {$ticket->restaurant->name}.", 'called_notified_at');
        }
    }

    public function notifyCancelled(QueueTicket $ticket): void
    {
        $this->notify($ticket, 'cancelled', "Antrean {$ticket->queue_number} di {$ticket->restaurant->name} telah dibatalkan.");
    }

    /**
     * Tell waiting customers that their turn is close: "tinggal 3" (3 or fewer groups ahead) and
     * "tinggal 1" (1 or none ahead). Thresholds use "<=" so a jump, such as two cancellations at once,
     * never skips a notice; the *_notified_at markers and unique event keys send each notice once.
     * When both thresholds are crossed together only the closer one ("tinggal 1") is sent.
     * Tickets that joined already inside a threshold have its marker set (QueueService::createTicket).
     *
     * $queue is kept for callers; positions come from one query, counted in memory.
     */
    public function notifyWaitingPositions(Restaurant $restaurant, QueueService $queue): void
    {
        $active = $restaurant->queueTickets()
            ->whereIn('status', [QueueTicket::STATUS_WAITING, QueueTicket::STATUS_CALLED])
            ->orderBy('sequence_number')
            ->get();

        foreach ($active->values() as $ahead => $ticket) {
            if ($ticket->status !== QueueTicket::STATUS_WAITING) {
                continue;
            }
            $ticket->setRelation('restaurant', $restaurant);

            if ($ahead <= 1) {
                if (! $ticket->one_ahead_notified_at) {
                    $text = $ahead === 1
                        ? "Tinggal 1 antrean lagi sebelum {$ticket->queue_number}.\nSilakan bersiap menuju {$restaurant->name}."
                        : "Giliran {$ticket->queue_number} berikutnya, tidak ada antrean lagi di depan Anda.\nSilakan bersiap menuju {$restaurant->name}.";
                    $this->notify($ticket, 'one_ahead', $text, 'one_ahead_notified_at');
                }
            } elseif ($ahead <= 3 && ! $ticket->three_ahead_notified_at) {
                $this->notify($ticket, 'three_ahead', "Tinggal {$ahead} antrean lagi sebelum giliran {$ticket->queue_number} di {$restaurant->name}.", 'three_ahead_notified_at');
            }
        }
    }

    private function notify(QueueTicket $ticket, string $event, string $text, ?string $field = null): void
    {
        if (! $ticket->phone_number) {
            return;
        }

        $key = "ticket:{$ticket->id}:{$event}";

        $template = config("whatsapp.templates.{$event}");
        $recent = WhatsAppConversation::query()->where('phone_number', $ticket->phone_number)
            ->where('last_message_at', '>', now()->subHours(24))->exists();

        // Baileys and GREEN-API have no 24-hour window: always plain text (Baileys checks opt-in at delivery).
        if (! $recent && filled($template) && $this->whatsapp->hasSessionWindow()) {
            $this->whatsapp->sendTemplateMessage($ticket->phone_number, $template,
                [$ticket->queue_number, $ticket->restaurant->name], $text, $ticket, eventKey: $key, notificationField: $field);
        } else {
            $this->whatsapp->sendTextMessage($ticket->phone_number, $text, $ticket, eventKey: $key, notificationField: $field);
        }
    }
}
