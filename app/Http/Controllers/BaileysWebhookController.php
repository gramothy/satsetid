<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Events from wa-gateway (Baileys): incoming messages, delivery receipts and session changes.
 *
 * Signed with X-Satset-Timestamp (unix seconds) and
 * X-Satset-Signature = hex HMAC-SHA256(WHATSAPP_WEBHOOK_SECRET, timestamp + "." + raw body).
 */
class BaileysWebhookController extends Controller
{
    /** Seconds a signed request stays valid; older (or future) timestamps are replays. */
    public const MAX_CLOCK_SKEW = 300;

    private const GATEWAY_STATES = ['connecting', 'qr', 'open', 'closed', 'logged_out'];

    private const STATUSES = ['delivered', 'read', 'failed'];

    public function __invoke(Request $request, WhatsAppChatbotService $chatbot, WhatsAppService $whatsapp): JsonResponse
    {
        $secret = (string) config('whatsapp.baileys.webhook_secret');
        abort_unless(config('whatsapp.enabled') && $whatsapp->provider() === 'baileys' && $secret !== '', 503);
        abort_if(strlen($request->getContent()) > 65536, 413);

        // Verify the raw body before reading any of it.
        $timestamp = (string) $request->header('X-Satset-Timestamp');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, strtolower((string) $request->header('X-Satset-Signature'))), 403);
        abort_unless(preg_match('/^\d{1,12}$/', $timestamp) && abs(now()->timestamp - (int) $timestamp) <= self::MAX_CLOCK_SKEW, 403);

        $event = json_decode($request->getContent(), true);
        abort_unless(is_array($event), 422);

        match ($event['type'] ?? null) {
            'message' => $this->message($event, $chatbot),
            'status' => $this->status($event, $whatsapp),
            'session' => $this->session($event, $whatsapp, (int) $timestamp),
            default => abort(422),
        };

        $whatsapp->dispatchPending();

        return response()->json(['ok' => true]);
    }

    private function message(array $event, WhatsAppChatbotService $chatbot): void
    {
        $id = $event['provider_message_id'] ?? null;
        $from = $event['from'] ?? null;
        $text = $event['text'] ?? null;
        $sentAt = $event['timestamp'] ?? null;
        abort_unless($this->validId($id) && is_string($from) && preg_match('/^[1-9]\d{7,14}$/', $from)
            && is_string($text) && (is_int($sentAt) || (is_string($sentAt) && ctype_digit($sentAt))), 422);

        // Same shape as a Meta message; duplicates are dropped through whatsapp_incoming_messages.
        $chatbot->receive([
            'id' => $id,
            'from' => $from,
            'text' => ['body' => $text],
            'timestamp' => (string) $sentAt,
        ]);
    }

    private function status(array $event, WhatsAppService $whatsapp): void
    {
        $id = $event['provider_message_id'] ?? null;
        $status = $event['status'] ?? null;
        abort_unless($this->validId($id) && in_array($status, self::STATUSES, true), 422);

        $whatsapp->applyDeliveryStatus($id, $status);
    }

    private function session(array $event, WhatsAppService $whatsapp, int $signedAt): void
    {
        $state = $event['state'] ?? null;
        $phone = $event['phone'] ?? null;
        abort_unless(in_array($state, self::GATEWAY_STATES, true) && ($phone === null || is_string($phone)), 422);

        $at = is_int($event['timestamp'] ?? null) && $event['timestamp'] > 0 ? $event['timestamp'] : $signedAt;
        $whatsapp->rememberGatewaySession($state, $phone, $at);
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && $id !== '' && strlen($id) <= 255;
    }
}
