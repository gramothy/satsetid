<?php

namespace App\Services;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppService
{
    /** Last known wa-gateway session: state, linked number, messages sent today. */
    public const GATEWAY_SESSION_CACHE_KEY = 'whatsapp:baileys:session';

    /** Seconds a known gateway state is trusted before GET /health is asked again. */
    public const GATEWAY_SESSION_TTL = 30;

    /** States reported by wa-gateway, plus "unreachable"/"unauthorized" when Laravel cannot ask it. */
    public const GATEWAY_STATES = ['connecting', 'qr', 'open', 'closed', 'logged_out', 'unreachable', 'unauthorized'];

    public function provider(): string
    {
        return match (config('whatsapp.provider')) {
            'meta' => 'meta',
            'twilio' => 'twilio',
            'greenapi' => 'greenapi',
            default => 'baileys',
        };
    }

    /**
     * Official APIs only allow free-form text within 24 hours of the customer's last message.
     * GREEN-API and Baileys link an ordinary WhatsApp number and have no such window.
     */
    public function hasSessionWindow(): bool
    {
        return ! in_array($this->provider(), ['greenapi', 'baileys'], true);
    }

    public function ready(): bool
    {
        if (! config('whatsapp.enabled')) {
            return false;
        }

        if ($this->provider() === 'baileys') {
            return $this->baileysConfigured() && $this->gatewaySession()['state'] === 'open';
        }

        if ($this->provider() === 'greenapi') {
            return (bool) preg_match('#^https://[a-z0-9.-]+/?$#i', (string) config('whatsapp.greenapi.api_url'))
                && preg_match('/^\d+$/', (string) config('whatsapp.greenapi.id_instance'))
                && filled(config('whatsapp.greenapi.api_token'))
                && filled(config('whatsapp.greenapi.webhook_token'))
                && preg_match('/^[1-9]\d{7,14}$/', $this->publicNumber());
        }

        if ($this->provider() === 'twilio') {
            return (bool) preg_match('/^AC[0-9a-f]{32}$/i', (string) config('whatsapp.twilio.account_sid'))
                && filled(config('whatsapp.twilio.auth_token'))
                && preg_match('/^[1-9]\d{7,14}$/', $this->publicNumber());
        }

        return filled(config('whatsapp.access_token'))
            && preg_match('/^\d+$/', (string) config('whatsapp.phone_number_id'))
            && preg_match('/^v\d+\.\d+$/', (string) config('whatsapp.api_version'));
    }

    /**
     * Gateway URL, token and webhook secret are set. The gateway itself may still be offline.
     */
    public function baileysConfigured(): bool
    {
        return filled(config('whatsapp.baileys.gateway_token'))
            && filled(config('whatsapp.baileys.webhook_secret'))
            && preg_match('#^https?://[^\s/]+#i', (string) config('whatsapp.baileys.gateway_url')) === 1;
    }

    /**
     * Last known wa-gateway session: ['state' => ..., 'phone' => ?string, 'sent_today' => ?int, 'at' => unix seconds].
     * Session webhooks keep it current; otherwise GET /health (1 second timeout) is asked at most once
     * per GATEWAY_SESSION_TTL, so outlet pages never call the gateway on every request.
     */
    public function gatewaySession(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::GATEWAY_SESSION_CACHE_KEY);
            if (is_array($cached) && in_array($cached['state'] ?? null, self::GATEWAY_STATES, true)) {
                return $cached;
            }
        }

        $session = $this->fetchGatewayHealth();
        Cache::put(self::GATEWAY_SESSION_CACHE_KEY, $session, self::GATEWAY_SESSION_TTL);

        return $session;
    }

    /**
     * Store a state the gateway reported (session webhook). An older report than the one cached is ignored.
     */
    public function rememberGatewaySession(string $state, mixed $phone, int $at): bool
    {
        $cached = Cache::get(self::GATEWAY_SESSION_CACHE_KEY);
        if (is_array($cached) && (int) ($cached['at'] ?? 0) > $at) {
            return false;
        }

        Cache::put(self::GATEWAY_SESSION_CACHE_KEY, $this->sessionRecord($state, $phone, null, $at), self::GATEWAY_SESSION_TTL);

        return true;
    }

    /**
     * Raw QR string while the gateway waits for a scan, otherwise null.
     */
    public function gatewayQr(): ?string
    {
        if (! $this->baileysConfigured()) {
            return null;
        }

        try {
            $qr = $this->gateway(2)->get('/qr')->json('qr');
        } catch (Throwable) {
            return null;
        }

        return is_string($qr) && $qr !== '' && strlen($qr) <= 2048 ? $qr : null;
    }

    /**
     * Unlink the WhatsApp number from the gateway and delete its session files.
     */
    public function gatewayLogout(): bool
    {
        if (! $this->baileysConfigured()) {
            return false;
        }

        try {
            $loggedOut = $this->gateway(15)->post('/logout')->successful();
        } catch (Throwable) {
            $loggedOut = false;
        }
        Cache::forget(self::GATEWAY_SESSION_CACHE_KEY);

        return $loggedOut;
    }

    /**
     * Apply a provider delivery receipt, only ever moving a message forward.
     */
    public function applyDeliveryStatus(string $providerMessageId, string $status): void
    {
        $allowedPrevious = match ($status) {
            'delivered' => ['sent'],
            'read' => ['sent', 'delivered'],
            'failed' => ['sent'],
            default => [],
        };
        if ($providerMessageId === '' || $allowedPrevious === []) {
            return;
        }

        WhatsappLog::query()->where('direction', 'outbound')
            ->where('provider_message_id', $providerMessageId)
            ->whereIn('status', $allowedPrevious)->update([
                'status' => $status,
                'error_code' => $status === 'failed' ? 'provider_delivery_failed' : null,
            ]);
    }

    private function publicNumber(): string
    {
        // Baileys: the number linked by scanning the QR, never a configured value.
        if ($this->provider() === 'baileys') {
            return (string) ($this->gatewaySession()['phone'] ?? '');
        }

        return app(QueueService::class)->normalizePhoneNumber((string) config('whatsapp.phone_number'));
    }

    public function entryUrl(Restaurant $restaurant): ?string
    {
        if (! $this->ready()) {
            return null;
        }

        $phone = $this->publicNumber();
        if (! preg_match('/^[1-9]\d{7,14}$/', $phone)) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?'.http_build_query(
            ['text' => 'ANTRE '.$restaurant->slug], '', '&', PHP_QUERY_RFC3986
        );
    }

    public function sendTextMessage(string $phone, string $text, ?QueueTicket $ticket = null, ?Restaurant $restaurant = null, ?string $eventKey = null, ?string $notificationField = null): WhatsappLog
    {
        return $this->enqueue($phone, $text, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ], $ticket, $restaurant, $eventKey, $notificationField);
    }

    public function sendTemplateMessage(string $phone, string $name, array $parameters, string $summary, ?QueueTicket $ticket = null, ?Restaurant $restaurant = null, ?string $eventKey = null, ?string $notificationField = null): WhatsappLog
    {
        return $this->enqueue($phone, $summary, [
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => config('whatsapp.template_language', 'id')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($value): array => ['type' => 'text', 'text' => (string) $value], $parameters),
                ]],
            ],
        ], $ticket, $restaurant, $eventKey, $notificationField);
    }

    private function enqueue(string $phone, string $summary, array $payload, ?QueueTicket $ticket, ?Restaurant $restaurant, ?string $eventKey, ?string $notificationField): WhatsappLog
    {
        $values = [
            'phone_number' => app(QueueService::class)->normalizePhoneNumber($phone),
            'restaurant_id' => $restaurant?->id ?? $ticket?->restaurant_id,
            'queue_ticket_id' => $ticket?->id,
            'direction' => 'outbound',
            'message' => $summary,
            'payload' => $payload,
            'status' => 'pending',
            'notification_field' => $notificationField,
        ];

        return $eventKey
            ? WhatsappLog::query()->firstOrCreate(['event_key' => $eventKey], $values)
            : WhatsappLog::query()->create($values);
    }

    public function deliver(WhatsappLog $message): bool
    {
        if ($this->provider() === 'baileys') {
            return $this->deliverViaBaileys($message);
        }

        // Claim once before HTTP. Ambiguous transport failures require manual review.
        if (! $this->claim($message)) {
            return false;
        }

        try {
            if (! $this->ready()) {
                return $this->failed($message, 'not_configured');
            }

            $payload = $message->payload;
            if (($payload['type'] ?? null) === 'text' && $this->hasSessionWindow()) {
                $recentContact = WhatsAppConversation::query()
                    ->where('phone_number', $message->phone_number)
                    ->where('last_message_at', '>', now()->subHours(24))->exists();
                if (! $recentContact) {
                    return $this->failed($message, 'template_required');
                }
            }

            if ($this->provider() === 'twilio') {
                $response = $this->postToTwilio($message->phone_number, $payload);
                $providerId = $response->json('sid');
            } elseif ($this->provider() === 'greenapi') {
                $response = $this->postToGreenApi($message->phone_number, $payload['text']['body'] ?? $message->message);
                $providerId = $response->json('idMessage');
            } else {
                $response = Http::withToken(config('whatsapp.access_token'))
                    ->acceptJson()->connectTimeout(3)->timeout(8)
                    ->post('https://graph.facebook.com/'.config('whatsapp.api_version').'/'.config('whatsapp.phone_number_id').'/messages', [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $message->phone_number,
                        ...$payload,
                    ]);
                $providerId = $response->json('messages.0.id');
            }

            if (! $response->successful() || ! is_string($providerId) || $providerId === '') {
                $providerCode = $this->provider() === 'twilio' ? $response->json('code') : null;

                return $this->failed($message, 'http_'.$response->status().(is_int($providerCode) ? '_'.$providerCode : ''));
            }

            $this->markSent($message, $providerId);

            return true;
        } catch (Throwable) {
            return $this->failed($message, 'transport_or_delivery_error');
        }
    }

    /**
     * Baileys (wa-gateway). No 24-hour window or templates, but only numbers that wrote to the bot
     * first may receive messages. Pacing and the daily cap are enforced by the gateway.
     */
    private function deliverViaBaileys(WhatsappLog $message): bool
    {
        if (! config('whatsapp.enabled') || ! $this->baileysConfigured()) {
            return $this->claim($message) ? $this->failed($message, 'not_configured') : false;
        }

        $optedIn = WhatsAppConversation::query()->where('phone_number', $message->phone_number)
            ->whereNotNull('last_message_at')->exists();
        if (! $optedIn) {
            return $this->claim($message) ? $this->failed($message, 'not_opted_in') : false;
        }

        // Gateway offline or waiting for a QR scan: keep the message queued without spending an attempt.
        // The gateway's "open" session webhook dispatches it again (as does whatsapp:send-pending).
        if ($this->gatewaySession()['state'] !== 'open') {
            WhatsappLog::query()->whereKey($message->id)->where('status', 'pending')
                ->update(['error_code' => 'gateway_unavailable']);

            return false;
        }

        if (! $this->claim($message)) {
            return false;
        }
        $message->refresh();

        try {
            $response = $this->gateway(max(1, (int) config('whatsapp.baileys.timeout', 30)))->post('/send', [
                'to' => $message->phone_number,
                'text' => (string) ($message->payload['text']['body'] ?? $message->message),
                'client_ref' => 'log:'.$message->id,
            ]);
        } catch (ConnectionException $exception) {
            if ($this->requestMayHaveReachedGateway($exception)) {
                // The gateway may still send it. Never resend automatically: manual review.
                return $this->failed($message, 'ambiguous_timeout');
            }
            $this->markGatewayDown('unreachable');

            return $this->retryLater($message);
        } catch (Throwable) {
            return $this->failed($message, 'transport_or_delivery_error');
        }

        if ($response->successful()) {
            $providerId = $response->json('message_id');
            $this->markSent($message, is_string($providerId) && $providerId !== '' && strlen($providerId) <= 255 ? $providerId : null);

            return true;
        }

        // Wrong WHATSAPP_GATEWAY_TOKEN is a configuration problem, not a message problem:
        // pause sending (the admin page shows "Token gateway ditolak") and keep the message.
        if ($response->status() === 401) {
            $this->markGatewayDown('unauthorized');

            return $this->retryLater($message);
        }

        // 429 daily cap, 503 not connected or busy, other 5xx: not sent, try again later.
        if ($response->status() === 429 || $response->serverError()) {
            if ($response->status() === 503 && $response->json('error') === 'not_connected') {
                $this->markGatewayDown('closed');
            }

            return $this->retryLater($message);
        }

        // 400 invalid, 409 send_uncertain, 422 not_on_whatsapp, ...: final.
        return $this->failed($message, $this->gatewayErrorCode($response));
    }

    private function gateway(int|float $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('whatsapp.baileys.gateway_url'), '/'))
            ->withToken((string) config('whatsapp.baileys.gateway_token'))
            ->acceptJson()->asJson()
            ->connectTimeout(min(3, $timeout))->timeout($timeout);
    }

    private function fetchGatewayHealth(): array
    {
        if (! $this->baileysConfigured()) {
            return $this->sessionRecord('unreachable', null, null, now()->timestamp);
        }

        try {
            $response = $this->gateway(1)->get('/health');
        } catch (Throwable) {
            return $this->sessionRecord('unreachable', null, null, now()->timestamp);
        }

        if ($response->status() === 401) {
            return $this->sessionRecord('unauthorized', null, null, now()->timestamp);
        }
        if (! $response->successful()) {
            return $this->sessionRecord('unreachable', null, null, now()->timestamp);
        }

        return $this->sessionRecord(
            (string) $response->json('state'), $response->json('phone'), $response->json('sent_today'), now()->timestamp,
            $response->json('webhook_backlog'), $response->json('webhook_failed'),
        );
    }

    /**
     * webhook_backlog: events the gateway holds because Laravel has not accepted them yet;
     * webhook_failed: events it set aside for manual review. Only known from GET /health.
     */
    private function sessionRecord(string $state, mixed $phone, mixed $sentToday, int $at, mixed $webhookBacklog = null, mixed $webhookFailed = null): array
    {
        $phone = is_string($phone) ? app(QueueService::class)->normalizePhoneNumber($phone) : '';
        $count = fn (mixed $value): ?int => is_int($value) && $value >= 0 ? $value : null;

        return [
            'state' => in_array($state, self::GATEWAY_STATES, true) ? $state : 'unreachable',
            'phone' => preg_match('/^[1-9]\d{7,14}$/', $phone) ? $phone : null,
            'sent_today' => $count($sentToday),
            'webhook_backlog' => $count($webhookBacklog),
            'webhook_failed' => $count($webhookFailed),
            'at' => $at,
        ];
    }

    /**
     * Stop using the gateway until it reports "open" again (webhook or the next /health check).
     */
    private function markGatewayDown(string $state): void
    {
        $cached = Cache::get(self::GATEWAY_SESSION_CACHE_KEY);
        Cache::put(
            self::GATEWAY_SESSION_CACHE_KEY,
            $this->sessionRecord($state, is_array($cached) ? ($cached['phone'] ?? null) : null, null, now()->timestamp),
            self::GATEWAY_SESSION_TTL,
        );
    }

    /**
     * Whether a request that failed in transport may still have reached the gateway (and been queued).
     * cURL reports how far the transfer got: refused or unresolvable connections never send a byte.
     */
    private function requestMayHaveReachedGateway(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();
        $context = $previous !== null && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];

        if (isset($context['pretransfer_time']) || isset($context['size_upload'])) {
            return (float) ($context['pretransfer_time'] ?? 0) > 0 || (float) ($context['size_upload'] ?? 0) > 0;
        }

        // Without transfer details only a timeout can hide a request that did arrive.
        return ($context['errno'] ?? null) === 28 || str_contains(strtolower($exception->getMessage()), 'timed out');
    }

    private function retryLater(WhatsappLog $message): bool
    {
        if ($message->attempts >= max(1, (int) config('whatsapp.baileys.max_attempts', 5))) {
            return $this->failed($message, 'gateway_unavailable');
        }

        $message->update(['status' => 'pending', 'error_code' => 'gateway_unavailable']);
        Log::warning('WhatsApp gateway unavailable; message stays pending', ['log_id' => $message->id, 'attempts' => $message->attempts]);

        return false;
    }

    /**
     * "http_<status>_<error>", with the gateway's error word reduced to [a-z0-9_].
     */
    private function gatewayErrorCode(Response $response): string
    {
        $error = $response->json('error');
        $error = is_string($error) ? substr(preg_replace('/[^a-z0-9_]+/', '', strtolower($error)) ?? '', 0, 40) : '';

        return 'http_'.$response->status().($error !== '' ? '_'.$error : '');
    }

    /**
     * Move a pending message to "sending" exactly once, so two workers never send it twice.
     */
    private function claim(WhatsappLog $message): bool
    {
        return WhatsappLog::query()->whereKey($message->id)->where('status', 'pending')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
    }

    private function markSent(WhatsappLog $message, ?string $providerId): void
    {
        DB::transaction(function () use ($message, $providerId): void {
            $message->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now(), 'error_code' => null]);
            if ($message->queue_ticket_id && in_array($message->notification_field, [
                'three_ahead_notified_at', 'one_ahead_notified_at', 'called_notified_at',
            ], true)) {
                QueueTicket::query()->whereKey($message->queue_ticket_id)
                    ->whereNull($message->notification_field)->update([$message->notification_field => now()]);
            }
        });
    }

    /**
     * Messages are stored in Meta's payload shape; translate them for Twilio at send time.
     */
    private function postToTwilio(string $to, array $payload): Response
    {
        $sid = (string) config('whatsapp.twilio.account_sid');
        $params = [
            'From' => 'whatsapp:+'.$this->publicNumber(),
            'To' => 'whatsapp:+'.$to,
        ];

        if (($payload['type'] ?? null) === 'template') {
            $params['ContentSid'] = $payload['template']['name'];
            $params['ContentVariables'] = json_encode(collect($payload['template']['components'][0]['parameters'] ?? [])
                ->values()->mapWithKeys(fn (array $parameter, int $index): array => [(string) ($index + 1) => $parameter['text']])
                ->all(), JSON_THROW_ON_ERROR);
        } else {
            $params['Body'] = $payload['text']['body'] ?? '';
        }

        // Twilio can only report delivery to a publicly reachable HTTPS address.
        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($appUrl, 'https://')) {
            $params['StatusCallback'] = $appUrl.route('whatsapp.twilio.status', [], false);
        }

        return Http::withBasicAuth($sid, (string) config('whatsapp.twilio.auth_token'))
            ->asForm()->acceptJson()->connectTimeout(3)->timeout(8)
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json', $params);
    }

    /**
     * GREEN-API has no templates; template payloads fall back to their stored summary text.
     */
    private function postToGreenApi(string $to, string $text): Response
    {
        // The token is part of the URL, so this URL must never be logged.
        $url = rtrim((string) config('whatsapp.greenapi.api_url'), '/')
            .'/waInstance'.config('whatsapp.greenapi.id_instance')
            .'/sendMessage/'.config('whatsapp.greenapi.api_token');

        return Http::acceptJson()->connectTimeout(3)->timeout(8)->post($url, [
            'chatId' => $to.'@c.us',
            'message' => $text,
            'linkPreview' => false,
        ]);
    }

    private function failed(WhatsappLog $message, string $code): bool
    {
        $message->update(['status' => 'failed', 'error_code' => $code]);
        Log::warning('WhatsApp delivery failed', ['log_id' => $message->id, 'code' => $code]);

        return false;
    }

    public function dispatchPending(): void
    {
        try {
            WhatsappLog::query()->where('direction', 'outbound')->where('status', 'pending')
                ->orderBy('id')->limit(100)->pluck('id')
                ->each(fn (int $id) => DeliverWhatsAppMessage::dispatch($id)->afterCommit());
        } catch (Throwable) {
            Log::warning('WhatsApp outbox dispatch deferred; run whatsapp:send-pending.');
        }
    }
}
