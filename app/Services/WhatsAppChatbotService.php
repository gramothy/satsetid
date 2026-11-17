<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WhatsAppChatbotService
{
    public function __construct(private QueueService $queue, private WhatsAppService $whatsapp) {}

    public function receive(array $message): void
    {
        $id = $message['id'] ?? null;
        $phone = $this->queue->normalizePhoneNumber((string) ($message['from'] ?? ''));
        if (! is_string($id) || strlen($id) > 255 || $id === '' || ! preg_match('/^[1-9]\d{7,14}$/', $phone)) {
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

    /** Words that confirm a registration ("YA") or step back ("BATAL"), matched on the first word. */
    private const YES = ['ya', 'iya', 'iyaa', 'y', 'yes', 'ok', 'oke', 'okay', 'okey', 'siap', 'setuju', 'lanjut', 'betul', 'benar'];

    private const NO = ['batal', 'tidak', 'tdk', 'gak', 'ga', 'nggak', 'enggak', 'no', 'ubah', 'ganti'];

    private function advance(WhatsAppConversation $conversation, string $text): string
    {
        // "ANTRE <slug>" (also "antri", any case, trailing punctuation) always starts over.
        if (preg_match('/^ANTR[EI]\s+([a-z0-9-]+)[\s.!?]*$/i', $text, $matches)) {
            return $this->start($conversation, mb_strtolower($matches[1]));
        }

        $restaurant = $conversation->restaurant_id ? Restaurant::query()->lockForUpdate()->find($conversation->restaurant_id) : null;

        // A ticket stays reachable by chat for as long as it is active, not only for 30 minutes.
        if ($conversation->state === 'queue_created' && $conversation->queue_ticket_id) {
            $ticket = QueueTicket::query()->find($conversation->queue_ticket_id);
            if ($ticket?->isActive()) {
                return $this->ticketSummary($ticket, '*Status antrean Anda*');
            }
        }

        if (! $conversation->restaurant_id || ! $conversation->expires_at || $conversation->expires_at->isPast()) {
            $wasRegistering = in_array($conversation->state, ['waiting_party_size', 'waiting_confirmation'], true);
            $conversation->state = 'restaurant_selected';
            if ($restaurant && $wasRegistering) {
                return "Sesi pendaftaran di *{$restaurant->name}* sudah berakhir karena tidak ada balasan selama 30 menit.\n\nKirim *ANTRE {$restaurant->slug}* untuk mulai lagi.";
            }

            return 'Halo! Untuk mengambil nomor antrean, scan QR SatSet di outlet lalu ketuk *Lanjut via WhatsApp*.';
        }
        if (! $restaurant) {
            return 'Maaf, outlet ini sudah tidak tersedia. Silakan scan QR SatSet di outlet.';
        }
        if ($conversation->state === 'queue_created') {
            $ticket = QueueTicket::query()->find($conversation->queue_ticket_id);

            return $ticket
                ? $this->ticketSummary($ticket, '*Status antrean Anda*')
                : "Kirim *ANTRE {$restaurant->slug}* untuk mengambil nomor antrean baru.";
        }
        if (! $restaurant->active || ! $restaurant->queue_open) {
            $conversation->state = 'restaurant_selected';

            return $this->closedMessage($restaurant);
        }

        $word = $this->firstWord($text);
        if (in_array($word, self::NO, true)) {
            $conversation->fill(['state' => 'waiting_party_size', 'party_size' => null]);

            return "Baik, nomor antrean belum dibuat.\n\nUntuk berapa orang? Balas dengan {$this->sizeRange($restaurant)}.";
        }

        if ($conversation->state === 'waiting_party_size') {
            $size = $this->partySize($text);
            if ($size === null || $size < 1 || $size > $restaurant->max_party_size) {
                return "Mohon balas jumlah orang dengan {$this->sizeRange($restaurant)}, misalnya *2*.";
            }
            $conversation->fill(['party_size' => $size, 'state' => 'waiting_confirmation', 'expires_at' => now()->addMinutes(30)]);
            $snapshot = $this->queue->getRestaurantSnapshot($restaurant);

            return "Mohon periksa kembali:\n\n"
                ."Outlet: *{$restaurant->name}*\n"
                ."Jumlah orang: *{$size}*\n"
                .$this->queueLine($snapshot['active_count'], $snapshot['estimated_wait'])."\n\n"
                .'Balas *YA* untuk mengambil nomor antrean, atau *BATAL* untuk mengubah jumlah orang.';
        }

        if ($conversation->state === 'waiting_confirmation') {
            if (! in_array($word, self::YES, true)) {
                return 'Balas *YA* untuk mengambil nomor antrean, atau *BATAL* untuk mengubah jumlah orang.';
            }
            try {
                $ticket = $this->queue->createTicket($restaurant, phone: $conversation->phone_number, partySize: $conversation->party_size);
            } catch (ValidationException $exception) {
                $conversation->fill(['state' => 'waiting_party_size', 'party_size' => null]);

                return 'Maaf, '.lcfirst(implode(' ', $exception->validator->errors()->all()));
            }
            $conversation->fill(['state' => 'queue_created', 'queue_ticket_id' => $ticket->id]);

            return $this->ticketSummary($ticket, '✅ *Berhasil!* Anda sudah masuk antrean.')
                ."\n\nKami akan mengirim pesan saat giliran Anda sudah dekat.";
        }

        return "Kirim *ANTRE {$restaurant->slug}* untuk memulai pendaftaran.";
    }

    private function start(WhatsAppConversation $conversation, string $slug): string
    {
        $restaurant = Restaurant::query()->where('slug', $slug)->lockForUpdate()->first();
        if (! $restaurant) {
            $conversation->fill(['restaurant_id' => null, 'queue_ticket_id' => null, 'party_size' => null, 'state' => 'restaurant_selected', 'expires_at' => null]);

            return "Maaf, outlet tidak ditemukan.\nSilakan scan ulang QR SatSet yang ada di outlet.";
        }
        $conversation->fill(['restaurant_id' => $restaurant->id, 'party_size' => null, 'queue_ticket_id' => null,
            'state' => 'waiting_party_size', 'expires_at' => now()->addMinutes(30)]);
        if (! $restaurant->active || ! $restaurant->queue_open) {
            $conversation->state = 'restaurant_selected';

            return $this->closedMessage($restaurant);
        }
        if ($ticket = $this->queue->findActiveTicketForPhone($restaurant, $conversation->phone_number)) {
            $conversation->fill(['state' => 'queue_created', 'queue_ticket_id' => $ticket->id]);

            return $this->ticketSummary($ticket, 'Anda sudah punya nomor antrean aktif di outlet ini.');
        }
        $snapshot = $this->queue->getRestaurantSnapshot($restaurant);
        $queue = $snapshot['active_count'] > 0
            ? "Saat ini ada *{$snapshot['active_count']} antrean*, perkiraan tunggu ±{$snapshot['estimated_wait']} menit."
            : 'Saat ini *belum ada antrean*.';

        return "Halo, selamat datang di *{$restaurant->name}* 👋\n\n{$queue}\n\n"
            ."Untuk berapa orang? Balas dengan {$this->sizeRange($restaurant)}.\n"
            .'_Satu kelompok cukup satu nomor antrean._';
    }

    private function closedMessage(Restaurant $restaurant): string
    {
        return "Maaf, antrean di *{$restaurant->name}* sedang ditutup.\nSilakan tanyakan ke petugas outlet.";
    }

    /** "angka *1 sampai 6*" (or "angka *1*" when the outlet seats one person per number). */
    private function sizeRange(Restaurant $restaurant): string
    {
        return $restaurant->max_party_size > 1 ? "angka *1 sampai {$restaurant->max_party_size}*" : 'angka *1*';
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
        // Built from APP_URL, not the webhook request host: behind a tunnel or proxy that host is not reachable by customers.
        $url = rtrim(config('app.url'), '/').route('queue.show', ['ticket' => $ticket->public_token], false);

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
        $lines[] = '';
        $lines[] = "Pantau antrean: {$url}";

        return implode("\n", $lines);
    }
}
