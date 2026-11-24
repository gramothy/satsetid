<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menu-driven chatbot. Every step offers numbered choices. "0" (or BATAL, TIDAK, and similar words) always returns
 * to the outlet menu, so a customer can never get stuck in a question.
 *
 * States: restaurant_selected -> menu -> waiting_party_size -> waiting_confirmation -> queue_created,
 * plus waiting_cancel_confirmation (menu option 4, answered with YA).
 * "queue_created" behaves like "menu" while its ticket is active, so the menu stays reachable after registration.
 */
class WhatsAppChatbotService
{
    public function __construct(private QueueService $queue, private WhatsAppService $whatsapp) {}

    public function receive(array $message): void
    {
        $id = $message['id'] ?? null;
        $phone = $this->queue->normalizePhoneNumber((string) ($message['from'] ?? ''));
        if (! is_string($id) || strlen($id) > 255 || $id === '' || ! preg_match('/^[1-9]\d{7,14}$/', $phone)) {
            // Logged without the message text or the sender number, so a silent drop is at least visible.
            \Illuminate\Support\Facades\Log::warning('WhatsApp inbound dropped: unusable sender or id', [
                'has_id' => is_string($id) && $id !== '',
                'sender_length' => strlen((string) ($message['from'] ?? '')),
            ]);

            return;
        }
        $text = (string) ($message['text']['body'] ?? $message['interactive']['button_reply']['id']
            ?? $message['interactive']['list_reply']['id'] ?? $message['button']['payload'] ?? '');
        $text = mb_substr(trim($text), 0, 1000);
        $receivedAt = isset($message['timestamp']) && ctype_digit((string) $message['timestamp'])
            ? Carbon::createFromTimestamp((int) $message['timestamp'])
            : now();
        if ($receivedAt->isFuture()) {
            $receivedAt = now();
        }

        DB::transaction(function () use ($id, $phone, $text, $receivedAt): void {
            if (! DB::table('whatsapp_incoming_messages')->insertOrIgnore([
                'provider_message_id' => $id, 'phone_number' => $phone,
                'created_at' => now(), 'updated_at' => now(),
            ])) {
                return;
            }
            DB::table('whatsapp_conversations')->insertOrIgnore([
                'phone_number' => $phone, 'state' => 'restaurant_selected',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $conversation = WhatsAppConversation::query()->where('phone_number', $phone)->lockForUpdate()->firstOrFail();
            WhatsappLog::query()->create([
                'direction' => 'inbound', 'status' => 'received', 'message' => $text,
                'provider_message_id' => $id, 'phone_number' => $phone,
                'restaurant_id' => $conversation->restaurant_id,
            ]);
            if ($conversation->last_message_at && $receivedAt->lt($conversation->last_message_at)) {
                return;
            }
            $conversation->last_message_at = $receivedAt;
            $reply = $this->advance($conversation, $text);
            $conversation->save();
            $ticket = $conversation->queue_ticket_id ? QueueTicket::query()->find($conversation->queue_ticket_id) : null;
            $restaurant = $conversation->restaurant_id ? Restaurant::query()->find($conversation->restaurant_id) : null;
            WhatsappLog::query()->where('direction', 'inbound')->where('provider_message_id', $id)
                ->update(['restaurant_id' => $restaurant?->id, 'queue_ticket_id' => $ticket?->id]);
            $this->whatsapp->sendTextMessage($phone, $reply, $ticket, $restaurant, 'reply:'.$id);
        }, 3);
    }

    /** Words that confirm a registration. "1" is the numbered equivalent. */
    private const YES = ['ya', 'iya', 'iyaa', 'y', 'yes', 'ok', 'oke', 'okay', 'okey', 'siap', 'setuju', 'lanjut', 'betul', 'benar'];

    /** Words that step back to the outlet menu from any step. "0" is handled separately. */
    private const BACK = ['batal', 'tidak', 'tdk', 'gak', 'ga', 'nggak', 'enggak', 'no', 'menu', 'keluar', 'selesai', 'stop'];

    /** Words that change the party size at the confirmation step. "2" is the numbered equivalent. */
    private const CHANGE = ['ubah', 'ganti'];

    private const SESSION_MINUTES = 30;

    private function advance(WhatsAppConversation $conversation, string $text): string
    {
        // Outlet QR link: "Saya mau antre di <Nama Outlet> (<slug>)". The slug in brackets identifies the outlet.
        // The older "ANTRE <slug>" form is still understood. Either always starts over.
        if (preg_match('/^\s*(?:saya\s+)?(?:mau\s+)?(?:antre|antri)\b.*\(([a-z0-9-]+)\)[\s.!?]*$/iu', $text, $matches)
            || preg_match('/^ANTR[EI]\s+([a-z0-9-]+)[\s.!?]*$/i', $text, $matches)) {
            return $this->start($conversation, mb_strtolower($matches[1]));
        }

        $restaurant = $conversation->restaurant_id ? Restaurant::query()->lockForUpdate()->find($conversation->restaurant_id) : null;

        if ($conversation->state === 'waiting_cancel_confirmation' && $restaurant) {
            return $this->cancelConfirmation($conversation, $restaurant, $text);
        }

        // A ticket stays reachable by chat for as long as it is active, not only for 30 minutes.
        if ($conversation->state === 'queue_created' && $restaurant) {
            $ticket = $conversation->queue_ticket_id ? QueueTicket::query()->find($conversation->queue_ticket_id) : null;
            if ($ticket?->isActive()) {
                return $this->menu($conversation, $restaurant, $ticket, $text);
            }
        }

        if (! $conversation->restaurant_id || ! $conversation->expires_at || $conversation->expires_at->isPast()) {
            $wasRegistering = in_array($conversation->state, ['menu', 'waiting_party_size', 'waiting_confirmation'], true);
            $conversation->state = 'restaurant_selected';
            if ($restaurant && $wasRegistering) {
                return "Sesi di *{$restaurant->name}* sudah berakhir karena tidak ada balasan selama 30 menit.\n\nKirim *Saya mau antre di {$restaurant->name} ({$restaurant->slug})* untuk mulai lagi.";
            }

            return 'Halo! Untuk mengambil nomor antrean, scan QR SatSet di outlet lalu ketuk *Lanjut via WhatsApp*.';
        }
        if (! $restaurant) {
            return 'Maaf, outlet ini sudah tidak tersedia. Silakan scan QR SatSet di outlet.';
        }
        if (! $restaurant->active || ! $restaurant->queue_open) {
            $conversation->state = 'restaurant_selected';

            return $this->closedMessage($restaurant);
        }

        // Every message in an open session extends it, so the menu does not expire while the customer is reading it.
        $conversation->expires_at = now()->addMinutes(self::SESSION_MINUTES);

        return match ($conversation->state) {
            'waiting_party_size' => $this->askPartySize($conversation, $restaurant, $text),
            'waiting_confirmation' => $this->confirm($conversation, $restaurant, $text),
            default => $this->menu($conversation, $restaurant, null, $text),
        };
    }

    /** Outlet menu. Numbers only: they cost the customer the least effort on a phone and cannot be misspelled. */
    private function menu(WhatsAppConversation $conversation, Restaurant $restaurant, ?QueueTicket $ticket, string $text): string
    {
        if ($this->isBack($text)) {
            return $this->menuText($restaurant, $ticket);
        }
        if (in_array($this->firstWord($text), ['status', 'cek'], true)) {
            return $this->myQueue($restaurant, $ticket);
        }

        return match ($this->choice($text)) {
            1 => $this->chooseTake($conversation, $restaurant, $ticket),
            2 => $this->snapshotText($restaurant)."\n\n".$this->menuList($ticket),
            3 => $this->myQueue($restaurant, $ticket),
            4 => $this->askCancel($conversation, $ticket),
            default => $this->isGreeting($text) || trim($text) === ''
                ? $this->menuText($restaurant, $ticket)
                : 'Balas dengan angka '.($ticket ? '*1*, *2*, *3*, atau *4*' : '*1*, *2*, atau *3*').".\n\n".$this->menuList($ticket),
        };
    }

    /** Menu option 4. Cancelling needs a second answer, so a stray "4" never removes a place in the queue. */
    private function askCancel(WhatsAppConversation $conversation, ?QueueTicket $ticket): string
    {
        if (! $ticket) {
            return "Anda belum punya nomor antrean aktif.\n\n".$this->menuList();
        }
        $conversation->state = 'waiting_cancel_confirmation';

        return "Batalkan nomor *{$ticket->queue_number}*?\nBalas *YA* untuk membatalkan, atau *0* untuk kembali.";
    }

    private function cancelConfirmation(WhatsAppConversation $conversation, Restaurant $restaurant, string $text): string
    {
        $ticket = $conversation->queue_ticket_id ? QueueTicket::query()->lockForUpdate()->find($conversation->queue_ticket_id) : null;
        if (! $ticket || ! $ticket->isActive()) {
            $conversation->fill(['state' => 'menu', 'queue_ticket_id' => null]);

            return "Nomor antrean Anda sudah tidak aktif.\n\n".$this->menuText($restaurant);
        }
        if ($this->isBack($text)) {
            $conversation->state = 'queue_created';

            return "Pembatalan tidak jadi. Nomor *{$ticket->queue_number}* tetap aktif.\n\n".$this->menuList($ticket);
        }
        if (! in_array($this->firstWord($text), self::YES, true)) {
            return "Balas *YA* untuk membatalkan nomor *{$ticket->queue_number}*, atau *0* untuk kembali.";
        }

        $this->queue->cancelTicket($ticket);
        $conversation->fill(['state' => 'menu', 'queue_ticket_id' => null, 'party_size' => null]);

        return "Nomor antrean *{$ticket->queue_number}* sudah dibatalkan.\n\n"
            .$this->snapshotText($restaurant)."\n\n".$this->menuList();
    }

    private function chooseTake(WhatsAppConversation $conversation, Restaurant $restaurant, ?QueueTicket $ticket): string
    {
        if ($ticket) {
            return "Anda sudah punya nomor antrean aktif di outlet ini.\n\n".$this->ticketSummary($ticket, '*Antrean Anda*');
        }
        $conversation->fill(['state' => 'waiting_party_size', 'party_size' => null]);

        return "Untuk berapa orang? Balas dengan angka {$this->sizeRange($restaurant)}.\n"
            .'Ketik *0* untuk kembali ke menu.'
            ."\n_Satu kelompok cukup satu nomor antrean._";
    }

    private function myQueue(Restaurant $restaurant, ?QueueTicket $ticket): string
    {
        if ($ticket) {
            return $this->ticketSummary($ticket, '*Antrean Anda*');
        }

        return "Anda belum punya nomor antrean aktif di *{$restaurant->name}*.\n\n".$this->menuList();
    }

    private function askPartySize(WhatsAppConversation $conversation, Restaurant $restaurant, string $text): string
    {
        if ($this->isBack($text) || $this->isGreeting($text)) {
            return $this->backToMenu($conversation, $restaurant);
        }
        $size = $this->partySize($text);
        if ($size === null || $size < 1 || $size > $restaurant->max_party_size) {
            return "Mohon balas jumlah orang dengan angka {$this->sizeRange($restaurant)}, misalnya *2*.\nKetik *0* untuk kembali ke menu.";
        }
        $conversation->fill(['party_size' => $size, 'state' => 'waiting_confirmation']);
        $snapshot = $this->queue->getRestaurantSnapshot($restaurant);

        return "Mohon periksa kembali:\n\n"
            ."Outlet: *{$restaurant->name}*\n"
            ."Jumlah orang: *{$size}*\n"
            .$this->queueLine($snapshot['active_count'], $snapshot['estimated_wait'])."\n\n"
            ."Balas:\n"
            ."*1* untuk ambil nomor antrean\n"
            ."*2* untuk ubah jumlah orang\n"
            .'*0* untuk batal dan kembali ke menu';
    }

    private function confirm(WhatsAppConversation $conversation, Restaurant $restaurant, string $text): string
    {
        if ($this->isBack($text) || $this->isGreeting($text)) {
            return $this->backToMenu($conversation, $restaurant);
        }
        $word = $this->firstWord($text);
        if ($this->choice($text) === 2 || in_array($word, self::CHANGE, true)) {
            $conversation->fill(['state' => 'waiting_party_size', 'party_size' => null]);

            return "Untuk berapa orang? Balas dengan angka {$this->sizeRange($restaurant)}.\nKetik *0* untuk kembali ke menu.";
        }
        if ($this->choice($text) !== 1 && ! in_array($word, self::YES, true)) {
            return "Balas *1* untuk ambil nomor antrean, *2* untuk ubah jumlah orang, atau *0* untuk kembali ke menu.";
        }

        try {
            $ticket = $this->queue->createTicket($restaurant, phone: $conversation->phone_number, partySize: $conversation->party_size);
        } catch (ValidationException $exception) {
            $conversation->fill(['state' => 'waiting_party_size', 'party_size' => null]);

            return 'Maaf, '.lcfirst(implode(' ', $exception->validator->errors()->all()))."\n\nUntuk berapa orang? Balas dengan angka {$this->sizeRange($restaurant)}.";
        }
        $conversation->fill(['state' => 'queue_created', 'queue_ticket_id' => $ticket->id]);

        return $this->ticketSummary($ticket, '✅ *Berhasil!* Anda sudah masuk antrean.')
            ."\n\nKami akan mengirim pesan saat giliran Anda sudah dekat.";
    }

    private function backToMenu(WhatsAppConversation $conversation, Restaurant $restaurant): string
    {
        $conversation->fill(['state' => 'menu', 'party_size' => null]);

        return $this->menuText($restaurant);
    }

    private function start(WhatsAppConversation $conversation, string $slug): string
    {
        $restaurant = Restaurant::query()->where('slug', $slug)->lockForUpdate()->first();
        if (! $restaurant) {
            $conversation->fill(['restaurant_id' => null, 'queue_ticket_id' => null, 'party_size' => null, 'state' => 'restaurant_selected', 'expires_at' => null]);

            return "Maaf, outlet tidak ditemukan.\nSilakan scan ulang QR SatSet yang ada di outlet.";
        }
        $conversation->fill(['restaurant_id' => $restaurant->id, 'party_size' => null, 'queue_ticket_id' => null,
            'state' => 'menu', 'expires_at' => now()->addMinutes(self::SESSION_MINUTES)]);
        if (! $restaurant->active || ! $restaurant->queue_open) {
            $conversation->state = 'restaurant_selected';

            return $this->closedMessage($restaurant);
        }
        if ($ticket = $this->queue->findActiveTicketForPhone($restaurant, $conversation->phone_number)) {
            $conversation->fill(['state' => 'queue_created', 'queue_ticket_id' => $ticket->id]);

            return "Anda sudah punya nomor antrean aktif di outlet ini.\n\n".$this->ticketSummary($ticket, '*Antrean Anda*');
        }

        return $this->menuText($restaurant);
    }

    /** Greeting, what the visitor is looking at (outlet facts), the live queue line, then the numbered menu. */
    private function menuText(Restaurant $restaurant, ?QueueTicket $ticket = null): string
    {
        return "Halo, selamat datang di *{$restaurant->name}* 👋\n\n"
            .$this->outletInfo($restaurant)."\n\n"
            .$this->snapshotText($restaurant)."\n\n"
            .$this->menuList($ticket);
    }

    /** Only facts the outlet actually stores: short description and the party size limit. */
    private function outletInfo(Restaurant $restaurant): string
    {
        $lines = [];
        if (filled($restaurant->description)) {
            $lines[] = mb_strimwidth(trim((string) $restaurant->description), 0, 160, '…');
        }
        $lines[] = "Maks. {$restaurant->max_party_size} orang per kelompok";

        return implode("\n", $lines);
    }

    private function menuList(?QueueTicket $ticket = null): string
    {
        $lines = ['Balas dengan angka:', '*1* Ambil nomor antrean', '*2* Lihat antrean sekarang', '*3* Antrean saya'];
        if ($ticket) {
            $lines[] = '*4* Batalkan antrean saya';
        }

        return implode("\n", $lines);
    }

    private function snapshotText(Restaurant $restaurant): string
    {
        $snapshot = $this->queue->getRestaurantSnapshot($restaurant);

        return $snapshot['active_count'] > 0
            ? "Saat ini ada *{$snapshot['active_count']} antrean*, perkiraan tunggu ±{$snapshot['estimated_wait']} menit."
            : 'Saat ini *belum ada antrean*.';
    }

    private function closedMessage(Restaurant $restaurant): string
    {
        return "Maaf, antrean di *{$restaurant->name}* sedang ditutup.\nSilakan tanyakan ke petugas outlet.";
    }

    /** "*1 sampai 6*" (or "*1*" when the outlet seats one person per number). */
    private function sizeRange(Restaurant $restaurant): string
    {
        return $restaurant->max_party_size > 1 ? "*1 sampai {$restaurant->max_party_size}*" : '*1*';
    }

    /** A single digit from 0 to 4 typed as a menu choice ("1", "2.", "4)"). Anything else is null. */
    private function choice(string $text): ?int
    {
        return preg_match('/^\s*([0-4])[.)]?\s*$/', $text, $matches) ? (int) $matches[1] : null;
    }

    private function isBack(string $text): bool
    {
        return $this->choice($text) === 0 || in_array($this->firstWord($text), self::BACK, true);
    }

    private function isGreeting(string $text): bool
    {
        return in_array($this->firstWord($text), ['halo', 'hai', 'hi', 'hello', 'selamat', 'permisi'], true);
    }

    /** "2", "02", "2 orang", "2org", "6 orang dewasa"; anything else (2.5, dua, -1) is null. */
    private function partySize(string $text): ?int
    {
        return preg_match('/^(\d{1,2})(?:\s*(?:orang|org|pax|tamu|org\.)\b.*)?$/iu', trim($text), $matches) ? (int) $matches[1] : null;
    }

    /** First word in lower case, without punctuation or emoji ("Ya!" -> "ya", "batal dong" -> "batal"). */
    private function firstWord(string $text): string
    {
        return preg_match('/\p{L}+/u', $text, $matches) ? mb_strtolower($matches[0]) : '';
    }

    private function queueLine(int $ahead, int $minutes): string
    {
        return $ahead > 0
            ? "Antrean di depan: *{$ahead}*\nPerkiraan tunggu: ±{$minutes} menit"
            : 'Antrean di depan: *belum ada*';
    }

    private function ticketSummary(QueueTicket $ticket, string $heading): string
    {
        $ticket->loadMissing('restaurant');
        $publicUrl = app(\App\Services\PublicUrl::class);

        $lines = [
            $heading,
            '',
            "Nomor antrean: *{$ticket->queue_number}*",
            "Outlet: {$ticket->restaurant->name}",
            "Jumlah orang: {$ticket->party_size}",
        ];
        if ($ticket->status === QueueTicket::STATUS_WAITING) {
            $ahead = max(0, $this->queue->calculateCustomerPosition($ticket) - 1);
            $lines[] = $ahead > 0
                ? "Di depan Anda: *{$ahead} antrean*\nPerkiraan tunggu: ±".$this->queue->estimatedWaitForAhead($ticket->restaurant, $ahead).' menit'
                : 'Di depan Anda: *tidak ada*, Anda berikutnya.';
        } elseif ($ticket->status === QueueTicket::STATUS_CALLED) {
            $lines[] = 'Status: *Dipanggil*. Silakan menuju outlet sekarang.';
        } else {
            $lines[] = "Status: {$ticket->statusLabel()}";
        }
        // A link only a computer on this network can open is never sent to a visitor's phone.
        $base = $publicUrl->base();
        if (! $publicUrl->isLocal($base)) {
            $lines[] = '';
            $lines[] = 'Pantau antrean: '.$base.route('queue.show', ['ticket' => $ticket->public_token], false);
        }

        return implode("\n", $lines);
    }
}
