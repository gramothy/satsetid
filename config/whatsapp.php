<?php

return [
    'enabled' => (bool) env('WHATSAPP_ENABLED', false),
    // "baileys" (wa-gateway, default), "meta" (WhatsApp Cloud API), "twilio" (Twilio WhatsApp API)
    // or "greenapi" (GREEN-API). Baileys and GREEN-API are unofficial linked devices; see README.
    'provider' => env('WHATSAPP_PROVIDER', 'baileys'),
    // Public WhatsApp number customers chat with (Meta/Twilio/GREEN-API). With Twilio/GREEN-API this is also
    // the sender number. Baileys ignores it and uses the number linked in the gateway.
    'phone_number' => env('WHATSAPP_PHONE_NUMBER'),

    // Baileys: the local wa-gateway (Node) holds the WhatsApp session.
    'baileys' => [
        // Base URL of wa-gateway. It only listens on 127.0.0.1.
        'gateway_url' => env('WHATSAPP_GATEWAY_URL', 'http://127.0.0.1:3100'),
        // Laravel -> gateway (Authorization: Bearer). Same value as WA_GATEWAY_TOKEN in wa-gateway/.env.
        'gateway_token' => env('WHATSAPP_GATEWAY_TOKEN'),
        // Gateway -> Laravel webhook HMAC key. Same value as WA_WEBHOOK_SECRET in wa-gateway/.env.
        'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),
        // Seconds to wait for POST /send. The gateway always answers within about 25 seconds.
        'timeout' => (int) env('WHATSAPP_GATEWAY_TIMEOUT', 30),
        // Delivery attempts while the gateway answers 429/5xx or cannot be reached; then "failed".
        'max_attempts' => 5,
    ],

    // Meta Cloud API
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'api_version' => env('WHATSAPP_API_VERSION'),

    // Twilio
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
    ],

    // GREEN-API: apiUrl, idInstance and apiTokenInstance from the console. The webhook token is
    // our own secret, entered as webhookUrlToken in the instance settings.
    'greenapi' => [
        'api_url' => env('GREENAPI_API_URL'),
        'id_instance' => env('GREENAPI_ID_INSTANCE'),
        'api_token' => env('GREENAPI_API_TOKEN'),
        'webhook_token' => env('GREENAPI_WEBHOOK_TOKEN'),
    ],

    // Meta: approved template names. Twilio: approved Content SIDs (HX...). Not used by Baileys or GREEN-API.
    'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),
    'templates' => [
        'three_ahead' => env('WHATSAPP_TEMPLATE_THREE_AHEAD'),
        'one_ahead' => env('WHATSAPP_TEMPLATE_ONE_AHEAD'),
        'called' => env('WHATSAPP_TEMPLATE_CALLED'),
        'cancelled' => env('WHATSAPP_TEMPLATE_CANCELLED'),
    ],
];
