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
            $this->notify($ticket, 'called', "📣 *Giliran {$ticket->queue_number} sudah tiba!*\n\n"
                ."Silakan menuju *{$ticket->restaurant->name}* dan tunjukkan nomor ini ke petugas.", 'called_notified_at');
        }
    }

    public function notifyCancelled(QueueTicket $ticket): void
    {
        $this->notify($ticket, 'cancelled', "Nomor antrean *{$ticket->queue_number}* di {$ticket->restaurant->name} telah dibatalkan.\n\n"
            .'Untuk mengantre lagi, scan QR SatSet di outlet.');
    }

    /**
     * The counter skipped a called customer who was not there (no-show).
     */
    public function notifySkipped(QueueTicket $ticket): void
    {
        $this->notify($ticket, 'skipped', "Nomor antrean *{$ticket->queue_number}* di {$ticket->restaurant->name} dilewati karena belum hadir saat dipanggil.\n\n"
            .'Jika Anda sudah di lokasi, silakan temui petugas outlet.');
    }

    /**
     * Tell waiting customers that their turn is close: "tinggal 3", "tinggal 2", and "tinggal 1" (or none ahead).
     * Each number has its own marker, so every notice is sent once. When a jump crosses several numbers at once
     * (two cancellations), only the closest one is sent: 4 ahead -> 2 ahead sends "tinggal 2" only.
     * Tickets that joined already at or inside a threshold have its marker set (QueueService::createTicket).
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
                        ? "🔔 *Tinggal 1 antrean lagi*\n\nNomor *{$ticket->queue_number}* dipanggil setelah satu antrean lagi.\n"
                            ."Silakan menuju *{$restaurant->name}* sekarang agar tidak terlewat."
                        : "🔔 *Anda berikutnya*\n\nNomor *{$ticket->queue_number}* akan dipanggil berikutnya.\n"
                            ."Silakan menuju *{$restaurant->name}* sekarang.";
                    $this->notify($ticket, 'one_ahead', $text, 'one_ahead_notified_at');
                }
            } elseif ($ahead === 2) {
                if (! $ticket->two_ahead_notified_at) {
                    $this->notify($ticket, 'two_ahead', "⏳ *Tinggal 2 antrean lagi*\n\n"
                        ."Nomor *{$ticket->queue_number}* di {$restaurant->name} sebentar lagi dipanggil.\nSilakan mulai menuju outlet.", 'two_ahead_notified_at');
                }
            } elseif ($ahead === 3 && ! $ticket->three_ahead_notified_at) {
                $this->notify($ticket, 'three_ahead', "⏳ *Tinggal {$ahead} antrean lagi*\n\n"
                    ."Nomor *{$ticket->queue_number}* di {$restaurant->name} sebentar lagi dipanggil.\nSilakan mulai menuju outlet.", 'three_ahead_notified_at');
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
