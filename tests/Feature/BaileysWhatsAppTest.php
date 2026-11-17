<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use App\Services\QueueService;
use App\Services\WhatsAppService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BaileysWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'http://127.0.0.1:3100';

    private const TOKEN = 'fake-gateway-token-0123456789';

    private const SECRET = 'fake-webhook-secret-0123456789';

    private const CUSTOMER = '6281234567890';

    private const BOT = '628111111111';

    private int $sendCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'baileys',
            // Ignored by Baileys: the linked number comes from the gateway.
            'whatsapp.phone_number' => '14155238886',
            'whatsapp.baileys.gateway_url' => self::GATEWAY,
            'whatsapp.baileys.gateway_token' => self::TOKEN,
            'whatsapp.baileys.webhook_secret' => self::SECRET,
            'whatsapp.baileys.timeout' => 30,
            'whatsapp.baileys.max_attempts' => 5,
        ]);
    }

    // --- Webhook signature -------------------------------------------------------------

    public function test_webhook_accepts_valid_signature_and_rejects_wrong_or_expired_ones(): void
    {
        $this->fakeGateway();
        $this->restaurant();
        $event = $this->messageEvent('sig-1', 'ANTRE ayam-bakar');

        $this->webhook($event, signature: str_repeat('0', 64))->assertForbidden();
        $this->webhook($event, secret: 'secret-yang-salah-0123456789')->assertForbidden();
        $this->webhook($event, timestamp: now()->timestamp - 301)->assertForbidden();
        $this->webhook($event, timestamp: now()->timestamp + 301)->assertForbidden();
        $this->call('POST', '/webhooks/baileys', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertForbidden();
        $this->assertDatabaseCount('whatsapp_incoming_messages', 0);
        $this->assertDatabaseCount('whatsapp_logs', 0);
        Http::assertNothingSent();

        $this->webhook($event, timestamp: now()->timestamp - 299)->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('whatsapp_incoming_messages', 1);
    }

    public function test_webhook_is_closed_unless_baileys_is_enabled_and_configured(): void
    {
        $event = ['type' => 'session', 'state' => 'open', 'phone' => self::BOT, 'timestamp' => now()->timestamp];

        config(['whatsapp.enabled' => false]);
        $this->webhook($event)->assertStatus(503);

        config(['whatsapp.enabled' => true, 'whatsapp.provider' => 'meta']);
        $this->webhook($event)->assertStatus(503);

        config(['whatsapp.provider' => 'baileys', 'whatsapp.baileys.webhook_secret' => '']);
        $this->webhook($event, secret: '')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_malformed_events_after_verification(): void
    {
        $this->webhook(['type' => 'unknown'])->assertStatus(422);
        $this->webhook(['type' => 'message', 'provider_message_id' => 'x', 'from' => '0812', 'text' => 'YA', 'timestamp' => 1])->assertStatus(422);
        $this->webhook(['type' => 'status', 'provider_message_id' => 'x', 'status' => 'sent'])->assertStatus(422);
        $this->webhook(['type' => 'session', 'state' => 'banned'])->assertStatus(422);
        $this->assertDatabaseCount('whatsapp_incoming_messages', 0);
    }

    // --- Incoming messages -------------------------------------------------------------

    public function test_incoming_message_triggers_reply_through_the_gateway(): void
    {
        $this->fakeGateway();
        $this->restaurant();
        $this->connected();

        $this->incoming('in-1', 'ANTRE ayam-bakar')->assertOk();

        $reply = WhatsappLog::query()->where('event_key', 'reply:in-1')->firstOrFail();
        $this->assertSame('sent', $reply->status);
        $this->assertSame('WAID1', $reply->provider_message_id);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::GATEWAY.'/send'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request['to'] === self::CUSTOMER
            && str_contains($request['text'], 'Ayam Bakar Nusantara')
            && $request['client_ref'] === 'log:'.$reply->id);
    }

    public function test_duplicate_incoming_message_is_processed_once(): void
    {
        $this->fakeGateway();
        $this->restaurant();
        $this->connected();

        $this->incoming('dup-1', 'ANTRE ayam-bakar')->assertOk();
        $this->incoming('dup-1', 'ANTRE ayam-bakar')->assertOk();

        $this->assertSame(1, WhatsappLog::query()->where('direction', 'inbound')->count());
        $this->assertSame(1, WhatsappLog::query()->where('direction', 'outbound')->count());
        Http::assertSentCount(1);
    }

    public function test_full_chatbot_flow_creates_ticket_only_after_ya(): void
    {
        $this->fakeGateway();
        $restaurant = $this->restaurant();
        $this->connected();

        $this->incoming('flow-1', 'ANTRE ayam-bakar')->assertOk();
        $this->assertDatabaseHas('whatsapp_conversations', ['phone_number' => self::CUSTOMER, 'restaurant_id' => $restaurant->id, 'state' => 'waiting_party_size']);
        $this->incoming('flow-2', '2')->assertOk();
        $this->assertDatabaseHas('whatsapp_conversations', ['state' => 'waiting_confirmation', 'party_size' => 2]);
        $this->assertDatabaseCount('queue_tickets', 0);

        $this->incoming('flow-3', 'YA')->assertOk();
        $this->assertDatabaseHas('queue_tickets', ['queue_number' => 'A-001', 'party_size' => 2, 'phone_number' => self::CUSTOMER]);
        $this->incoming('flow-4', 'YA')->assertOk();
        $this->assertDatabaseCount('queue_tickets', 1);

        $this->assertSame(4, WhatsappLog::query()->where('direction', 'outbound')->where('status', 'sent')->count());
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::GATEWAY.'/send'
            && str_contains($request['text'], 'Berhasil!') && str_contains($request['text'], 'A-001'));
    }

    // --- Delivery status ---------------------------------------------------------------

    public function test_status_updates_move_messages_forward_only(): void
    {
        $this->fakeGateway();
        $this->restaurant();
        $this->connected();
        $this->incoming('st-1', 'ANTRE ayam-bakar');
        $outbound = WhatsappLog::query()->where('direction', 'outbound')->firstOrFail();

        $this->webhook(['type' => 'status', 'provider_message_id' => $outbound->provider_message_id, 'status' => 'read'])->assertOk();
        $this->assertSame('read', $outbound->fresh()->status);

        $this->webhook(['type' => 'status', 'provider_message_id' => $outbound->provider_message_id, 'status' => 'delivered'])->assertOk();
        $this->assertSame('read', $outbound->fresh()->status);

        $this->webhook(['type' => 'status', 'provider_message_id' => 'unknown-id', 'status' => 'failed'])->assertOk();
        $this->assertSame('read', $outbound->fresh()->status);
    }

    public function test_failed_receipt_marks_sent_message_failed(): void
    {
        $this->fakeGateway();
        $this->restaurant();
        $this->connected();
        $this->incoming('st-2', 'ANTRE ayam-bakar');
        $outbound = WhatsappLog::query()->where('direction', 'outbound')->firstOrFail();

        $this->webhook(['type' => 'status', 'provider_message_id' => $outbound->provider_message_id, 'status' => 'failed'])->assertOk();
        $this->assertDatabaseHas('whatsapp_logs', ['id' => $outbound->id, 'status' => 'failed', 'error_code' => 'provider_delivery_failed']);
    }

    // --- Sending -----------------------------------------------------------------------

    public function test_successful_send_stores_provider_id_and_sent_at(): void
    {
        $this->fakeGateway();
        $message = $this->pendingMessage();
        WhatsappLog::query()->whereKey($message->id)->update(['error_code' => 'gateway_unavailable']);

        $this->assertTrue(app(WhatsAppService::class)->deliver($message->fresh()));

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('WAID1', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->error_code);
        $this->assertSame(1, $message->attempts);
    }

    public function test_rate_limit_and_unavailable_gateway_keep_message_pending(): void
    {
        $this->fakeGateway(send: Http::sequence()
            ->push(['error' => 'daily_cap'], 429)
            ->push(['error' => 'busy'], 503)
            ->push(['error' => 'internal'], 500));
        $message = $this->pendingMessage();
        $whatsapp = app(WhatsAppService::class);

        foreach ([1, 2, 3] as $attempt) {
            $this->assertFalse($whatsapp->deliver($message->fresh()));
            $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'pending', 'error_code' => 'gateway_unavailable', 'attempts' => $attempt]);
        }
        Http::assertSentCount(3);
    }

    public function test_message_fails_after_max_attempts_against_an_unavailable_gateway(): void
    {
        config(['whatsapp.baileys.max_attempts' => 2]);
        $this->fakeGateway(send: Http::response(['error' => 'daily_cap'], 429));
        $message = $this->pendingMessage();
        $whatsapp = app(WhatsAppService::class);

        $whatsapp->deliver($message->fresh());
        $whatsapp->deliver($message->fresh());

        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'failed', 'error_code' => 'gateway_unavailable', 'attempts' => 2]);
    }

    public function test_not_connected_gateway_pauses_sending_without_spending_attempts(): void
    {
        $this->fakeGateway(send: Http::response(['error' => 'not_connected'], 503));
        $message = $this->pendingMessage();
        $whatsapp = app(WhatsAppService::class);

        $whatsapp->deliver($message->fresh());
        $whatsapp->deliver($message->fresh());

        // The 503 marks the gateway closed, so the second call does not even reach /send.
        Http::assertSentCount(1);
        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'pending', 'attempts' => 1, 'error_code' => 'gateway_unavailable']);
        $this->assertSame('closed', $whatsapp->gatewaySession()['state']);
    }

    public function test_rejected_gateway_token_pauses_sending_instead_of_failing(): void
    {
        $this->fakeGateway(send: Http::response(['error' => 'unauthorized'], 401));
        $message = $this->pendingMessage();
        $whatsapp = app(WhatsAppService::class);

        $whatsapp->deliver($message->fresh());
        $whatsapp->deliver($message->fresh());

        Http::assertSentCount(1);
        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'pending', 'attempts' => 1, 'error_code' => 'gateway_unavailable']);
        $this->assertSame('unauthorized', $whatsapp->gatewaySession()['state']);
    }

    public function test_refused_connection_keeps_message_pending(): void
    {
        $this->fakeGateway(send: Http::failedConnection('cURL error 7: Failed to connect to 127.0.0.1 port 3100: Connection refused'));
        $message = $this->pendingMessage();

        $this->assertFalse(app(WhatsAppService::class)->deliver($message->fresh()));

        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'pending', 'error_code' => 'gateway_unavailable', 'attempts' => 1]);
        $this->assertSame('unreachable', app(WhatsAppService::class)->gatewaySession()['state']);
    }

    public function test_timeout_after_the_request_was_sent_is_failed_and_never_retried(): void
    {
        $this->fakeGateway(send: function (ClientRequest $request) {
            return Create::rejectionFor(new ConnectException(
                'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
                $request->toPsrRequest(),
                null,
                ['errno' => 28, 'connect_time' => 0.0004, 'pretransfer_time' => 0.0006, 'size_upload' => 96.0],
            ));
        });
        $message = $this->pendingMessage();

        $this->assertFalse(app(WhatsAppService::class)->deliver($message->fresh()));
        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'failed', 'error_code' => 'ambiguous_timeout']);

        $this->artisan('whatsapp:send-pending')->assertSuccessful();
        app(WhatsAppService::class)->deliver($message->fresh());
        Http::assertSentCount(1);
    }

    public function test_gateway_rejection_is_final_with_sanitized_code(): void
    {
        $this->fakeGateway(send: Http::response(['error' => 'Not_On_WhatsApp <script>'], 422));
        $message = $this->pendingMessage();

        app(WhatsAppService::class)->deliver($message->fresh());

        $this->assertDatabaseHas('whatsapp_logs', ['id' => $message->id, 'status' => 'failed', 'error_code' => 'http_422_not_on_whatsappscript']);
    }

    public function test_number_that_never_wrote_to_the_bot_is_not_messaged(): void
    {
        $this->fakeGateway();
        $restaurant = $this->restaurant();
        $this->connected();
        $ticket = app(QueueService::class)->createTicket($restaurant, phone: '6289999999999');

        app(QueueService::class)->callNext($restaurant);

        $this->assertDatabaseHas('whatsapp_logs', ['queue_ticket_id' => $ticket->id, 'status' => 'failed', 'error_code' => 'not_opted_in']);
        $this->assertNull($ticket->fresh()->called_notified_at);
        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/send'));
    }

    public function test_notifications_are_plain_text_for_baileys(): void
    {
        config(['whatsapp.templates.called' => 'queue_called', 'whatsapp.templates.three_ahead' => 'queue_three']);
        $this->fakeGateway();
        $restaurant = $this->restaurant();
        $this->connected();
        // Opted in long ago: outside Meta's 24-hour window, yet Baileys still sends plain text.
        WhatsAppConversation::query()->create(['phone_number' => self::CUSTOMER, 'last_message_at' => now()->subDays(3)]);
        $ticket = app(QueueService::class)->createTicket($restaurant, phone: self::CUSTOMER);

        app(QueueService::class)->callNext($restaurant);

        $log = WhatsappLog::query()->where('event_key', "ticket:{$ticket->id}:called")->firstOrFail();
        $this->assertSame('text', $log->payload['type']);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($ticket->fresh()->called_notified_at);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/send')
            && str_contains($request['text'], 'Giliran A-001 sudah tiba!'));
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->body(), 'queue_called'));
    }

    // --- Gateway offline and back ------------------------------------------------------

    public function test_pending_message_is_sent_when_the_gateway_comes_back(): void
    {
        $this->fakeGateway(state: 'closed');
        $restaurant = $this->restaurant();
        WhatsAppConversation::query()->create(['phone_number' => self::CUSTOMER, 'last_message_at' => now()]);
        $ticket = app(QueueService::class)->createTicket($restaurant, phone: self::CUSTOMER);

        app(QueueService::class)->callNext($restaurant);
        $this->artisan('whatsapp:send-pending')->assertSuccessful();

        $log = WhatsappLog::query()->where('event_key', "ticket:{$ticket->id}:called")->firstOrFail();
        $this->assertSame('pending', $log->status);
        $this->assertSame(0, $log->attempts);
        Http::assertNotSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/send'));

        // The gateway reconnects and reports "open": the backlog goes out right away.
        $this->connected();

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNotNull($ticket->fresh()->called_notified_at);
        Http::assertSentCount(2); // one /health check while offline, then /send
    }

    // --- Outlet page and entry link ----------------------------------------------------

    public function test_entry_link_uses_the_connected_number(): void
    {
        $this->fakeGateway();
        $restaurant = $this->restaurant();
        $this->connected('6285550001111');

        $this->get(route('outlets.whatsapp', $restaurant->slug))
            ->assertRedirect('https://wa.me/6285550001111?text=ANTRE%20ayam-bakar');
        $this->assertDatabaseCount('queue_tickets', 0);
    }

    public function test_entry_link_is_hidden_while_the_gateway_waits_for_a_qr_scan(): void
    {
        $this->fakeGateway(state: 'qr', phone: null);
        $restaurant = $this->restaurant();

        $this->get(route('outlets.show', $restaurant->slug))->assertOk()
            ->assertDontSee('Lanjut via WhatsApp')->assertSee('Pendaftaran lewat WhatsApp belum tersedia');
        $this->get(route('outlets.whatsapp', $restaurant->slug))->assertStatus(503);
    }

    public function test_outlet_pages_ask_the_gateway_at_most_every_30_seconds(): void
    {
        $this->fakeGateway();
        $restaurant = $this->restaurant();

        $this->get(route('outlets.show', $restaurant->slug))->assertOk()->assertSee('Lanjut via WhatsApp');
        $this->get(route('outlets.show', $restaurant->slug))->assertOk();
        $this->get(route('outlets.whatsapp', $restaurant->slug))->assertRedirect();
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        $this->get(route('outlets.show', $restaurant->slug))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_outlet_page_never_calls_the_gateway_when_whatsapp_is_disabled(): void
    {
        config(['whatsapp.enabled' => false]);
        $restaurant = $this->restaurant();

        $this->get(route('outlets.show', $restaurant->slug))->assertOk()->assertDontSee('Lanjut via WhatsApp');
        Http::assertNothingSent();
    }

    // --- Admin connect page ------------------------------------------------------------

    public function test_connect_page_is_for_the_owner_only(): void
    {
        $restaurant = $this->restaurant();
        $cashier = User::factory()->outletAdmin()->create();
        $cashier->restaurants()->attach($restaurant);

        $this->get(route('admin.whatsapp.connect'))->assertRedirect(route('admin.login'));

        $this->actingAs($cashier);
        $this->get(route('admin.whatsapp.connect'))->assertForbidden();
        $this->get(route('admin.whatsapp.connect.status'))->assertForbidden();
        $this->post(route('admin.whatsapp.logout'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Hubungkan WhatsApp');
        Http::assertNothingSent();
    }

    public function test_owner_sees_qr_rendered_by_laravel_while_waiting_for_scan(): void
    {
        $this->fakeGateway(state: 'qr', phone: null);

        $this->actingAs(User::factory()->create())->get(route('admin.whatsapp.connect'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Menunggu scan QR')
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('data-status-url="'.route('admin.whatsapp.connect.status').'"', false)
            ->assertSee('bukan API resmi WhatsApp')
            ->assertDontSee('2@FAKE-QR-REF');

        $this->getJson(route('admin.whatsapp.connect.status'))->assertOk()
            ->assertJson(['state' => 'qr'])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'data:image/svg+xml;base64,'));
    }

    public function test_owner_sees_connected_number_and_can_disconnect_it(): void
    {
        $this->fakeGateway(phone: '6285550001111');
        $owner = User::factory()->create();

        $this->actingAs($owner)->get(route('admin.whatsapp.connect'))->assertOk()
            ->assertSee('Tersambung')->assertSee('+6285550001111')->assertSee('Putuskan nomor')
            ->assertDontSee('data:image/svg+xml', false);

        $this->post(route('admin.whatsapp.logout'))->assertRedirect(route('admin.whatsapp.connect'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'sudah diputus'));
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::GATEWAY.'/logout'
            && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_connect_page_explains_an_offline_gateway(): void
    {
        Http::fake([self::GATEWAY.'/*' => Http::failedConnection()]);

        $this->actingAs(User::factory()->create())->get(route('admin.whatsapp.connect'))->assertOk()
            ->assertSee('Gateway tidak berjalan')->assertSee('npm start');
    }

    // --- Logs --------------------------------------------------------------------------

    public function test_logs_contain_no_tokens_or_message_text(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message.' '.json_encode($event->context);
        });
        $this->fakeGateway(send: Http::sequence()->push(['error' => 'busy'], 503)->push(['error' => 'send_uncertain'], 409));
        $this->restaurant();
        $this->connected();

        $this->incoming('log-1', 'ANTRE ayam-bakar rahasia-customer');
        $this->artisan('whatsapp:send-pending')->assertSuccessful();

        $this->assertNotEmpty($lines);
        $all = implode("\n", $lines);
        foreach ([self::TOKEN, self::SECRET, 'rahasia-customer', 'Ayam Bakar Nusantara'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertDatabaseHas('whatsapp_logs', ['direction' => 'outbound', 'status' => 'failed', 'error_code' => 'http_409_send_uncertain']);
        $this->actingAs(User::factory()->create())->get(route('admin.whatsapp-logs.index'))->assertOk()->assertDontSee(self::TOKEN);
    }

    // --- Helpers -----------------------------------------------------------------------

    /**
     * Fake wa-gateway. $send replaces the default POST /send answer (message ids WAID1, WAID2, ...).
     */
    private function fakeGateway(mixed $send = null, string $state = 'open', ?string $phone = self::BOT): void
    {
        Http::fake([
            self::GATEWAY.'/health' => Http::response(['state' => $state, 'phone' => $phone, 'sent_today' => 3]),
            self::GATEWAY.'/qr' => Http::response(['qr' => $state === 'qr' ? '2@FAKE-QR-REF,abc,def,ghi' : null]),
            self::GATEWAY.'/logout' => Http::response(['ok' => true]),
            self::GATEWAY.'/send' => $send ?? fn () => Http::response(['message_id' => 'WAID'.++$this->sendCounter]),
        ]);
    }

    private function webhook(array $event, ?int $timestamp = null, ?string $secret = null, ?string $signature = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->timestamp;
        $signature ??= hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);

        return $this->call('POST', '/webhooks/baileys', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SATSET_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SATSET_SIGNATURE' => $signature,
        ], $body);
    }

    private function messageEvent(string $id, string $text, string $from = self::CUSTOMER): array
    {
        return ['type' => 'message', 'provider_message_id' => $id, 'from' => $from, 'text' => $text, 'timestamp' => now()->timestamp];
    }

    private function incoming(string $id, string $text): TestResponse
    {
        return $this->webhook($this->messageEvent($id, $text));
    }

    private function connected(string $phone = self::BOT): void
    {
        $this->webhook(['type' => 'session', 'state' => 'open', 'phone' => $phone, 'timestamp' => now()->timestamp])->assertOk();
    }

    /**
     * An outbound message to a customer who wrote to the bot, with the gateway connected.
     */
    private function pendingMessage(): WhatsappLog
    {
        WhatsAppConversation::query()->create(['phone_number' => self::CUSTOMER, 'last_message_at' => now()]);
        app(WhatsAppService::class)->rememberGatewaySession('open', self::BOT, now()->timestamp);

        return app(WhatsAppService::class)->sendTextMessage(self::CUSTOMER, 'Halo dari SatSet');
    }

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::query()->create(array_merge([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'prefix' => 'A', 'category' => 'Ayam',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ], $overrides));
    }
}
