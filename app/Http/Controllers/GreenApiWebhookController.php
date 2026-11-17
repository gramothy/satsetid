<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GreenApiWebhookController extends Controller
{
    /**
     * GREEN-API posts every notification type (incoming messages, delivery statuses, instance state) here.
     */
    public function receive(Request $request, WhatsAppChatbotService $chatbot, WhatsAppService $whatsapp): Response
    {
        $this->authorizeGreenApi($request, $whatsapp);

        if ((string) $request->input('instanceData.idInstance') !== (string) config('whatsapp.greenapi.id_instance')) {
            return response('', 200);
        }

        match ((string) $request->input('typeWebhook')) {
            'incomingMessageReceived' => $this->incomingMessage($request, $chatbot),
            'outgoingMessageStatus' => $whatsapp->applyDeliveryStatus(
                (string) $request->input('idMessage', ''),
                match ((string) $request->input('status')) {
                    'delivered' => 'delivered',
                    'read' => 'read',
                    'failed', 'noAccount', 'suspended', 'yellowCard' => 'failed',
                    default => '',
                },
            ),
            default => null,
        };
        $whatsapp->dispatchPending();

        return response('', 200);
    }

    private function incomingMessage(Request $request, WhatsAppChatbotService $chatbot): void
    {
        // Personal chats only ("628...@c.us"); groups (@g.us) and other IDs are ignored.
        if (! preg_match('/^(\d{8,15})@c\.us$/', (string) $request->input('senderData.chatId'), $match)) {
            return;
        }

        $text = match ((string) $request->input('messageData.typeMessage')) {
            'textMessage' => $request->input('messageData.textMessageData.textMessage'),
            'extendedTextMessage', 'quotedMessage' => $request->input('messageData.extendedTextMessageData.text'),
            default => '',
        };

        $chatbot->receive([
            'id' => (string) $request->input('idMessage', ''),
            'from' => $match[1],
            'timestamp' => (string) $request->input('timestamp', ''),
            'text' => ['body' => is_string($text) ? $text : ''],
        ]);
    }

    /**
     * GREEN-API sends our webhookUrlToken as "Authorization: Bearer <token>" (or "Basic <token>").
     */
    private function authorizeGreenApi(Request $request, WhatsAppService $whatsapp): void
    {
        $token = $this->stripScheme((string) config('whatsapp.greenapi.webhook_token'));
        abort_unless(config('whatsapp.enabled') && $whatsapp->provider() === 'greenapi' && $token !== '', 503);
        abort_if(strlen($request->getContent()) > 65536, 413);
        abort_unless(hash_equals($token, $this->stripScheme((string) $request->header('Authorization'))), 403);
    }

    private function stripScheme(string $value): string
    {
        return trim((string) preg_replace('/^(Bearer|Basic)\s+/i', '', trim($value)));
    }
}
