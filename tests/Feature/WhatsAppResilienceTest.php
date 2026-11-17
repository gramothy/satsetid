<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use App\Services\QueueService;
use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppMonitor;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regressions found by chaos testing: chatbot input that customers really type, notices that
 * outlived a gateway outage, messages stuck in "sending", and the cost of the polling endpoints.
 */
class WhatsAppResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '628111111111';

    private int $messageCounter = 0;

    private int $sendCounter = 0;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3100/send' => fn () => Http::response(['message_id' => 'WAID'.++$this->sendCounter])]);
        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'baileys',
            'whatsapp.baileys.gateway_url' => 'http://127.0.0.1:3100',
            'whatsapp.baileys.gateway_token' => 'fake-gateway-token-0123456789',
            'whatsapp.baileys.webhook_secret' => 'fake-webhook-secret-0123456789',
        ]);
        $this->gatewayOpen();
        $this->restaurant = Restaurant::query()->create([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'prefix' => 'A', 'category' => 'Ayam',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
    }

    // --- Chatbot input -----------------------------------------------------------------

    public static function entryMessages(): array
    {
        return [
            'lowercase' => ['antre ayam-bakar'],
            'uppercase slug' => ['ANTRE Ayam-Bakar'],
            'ANTRI spelling' => ['ANTRI ayam-bakar'],
            'trailing punctuation' => ['ANTRE ayam-bakar.'],
            'extra spaces' => ['ANTRE   ayam-bakar'],
        ];
    }

    #[DataProvider('entryMessages')]
    public function test_entry_message_variants_open_the_outlet(string $text): void
    {
        $reply = $this->say('6281200000001', $text);

        $this->assertSame('waiting_party_size', $this->state('6281200000001'));
        $this->assertStringContainsString('selamat datang di *Ayam Bakar Nusantara*', $reply);
    }

    public static function partySizes(): array
    {
        return [
            'digit' => ['2', 2], 'with orang' => ['2 orang', 2], 'short org' => ['3org', 3],
            'padded' => [' 4 ', 4], 'leading zero' => ['02', 2], 'with words after' => ['6 orang dewasa', 6],
            'pax' => ['5 pax', 5],
            'word' => ['dua', null], 'decimal' => ['2.5', null], 'negative' => ['-1', null],
            'too many' => ['10', null], 'zero' => ['0', null],
        ];
    }

    #[DataProvider('partySizes')]
    public function test_party_size_accepts_natural_answers_and_rejects_the_rest(string $text, ?int $expected): void
    {
        $this->say('6281200000002', 'ANTRE ayam-bakar');
        $reply = $this->say('6281200000002', $text);

        if ($expected === null) {
            $this->assertSame('waiting_party_size', $this->state('6281200000002'));
            $this->assertStringContainsString('*1 sampai 6*', $reply);
        } else {
            $this->assertSame('waiting_confirmation', $this->state('6281200000002'));
            $this->assertStringContainsString("Jumlah orang: *{$expected}*", $reply);
        }
    }

    public static function confirmations(): array
    {
        return [
            'YA' => ['YA', true], 'Ya.' => ['Ya.', true], 'iya' => ['iya', true], 'ok' => ['ok', true],
            'oke' => ['Oke!', true], 'y' => ['y', true], 'emoji' => ['ya 👍', true], 'siap' => ['siap', true],
            'BATAL' => ['BATAL', false], 'batal dong' => ['batal dong', false], 'tidak' => ['tidak', false],
        ];
    }

    #[DataProvider('confirmations')]
    public function test_confirmation_understands_common_yes_and_no_answers(string $text, bool $creates): void
    {
        $this->say('6281200000003', 'ANTRE ayam-bakar');
        $this->say('6281200000003', '2');
        $reply = $this->say('6281200000003', $text);

        $this->assertSame($creates ? 1 : 0, QueueTicket::query()->where('phone_number', '6281200000003')->count());
        $this->assertStringContainsString($creates ? 'Berhasil!' : 'nomor antrean belum dibuat', $reply);
        $this->assertSame($creates ? 'queue_created' : 'waiting_party_size', $this->state('6281200000003'));
    }

    public function test_unclear_confirmation_repeats_the_choice_without_creating_a_ticket(): void
    {
        $this->say('6281200000004', 'ANTRE ayam-bakar');
        $this->say('6281200000004', '2');

        $reply = $this->say('6281200000004', 'hmm nanti dulu');

        $this->assertSame(0, QueueTicket::query()->count());
        $this->assertStringContainsString('Balas *YA*', $reply);
    }

    public function test_expired_registration_tells_the_customer_how_to_restart(): void
    {
        $this->say('6281200000005', 'ANTRE ayam-bakar');
        $this->travel(31)->minutes();

        $reply = $this->say('6281200000005', '2');

        $this->assertStringContainsString('sudah berakhir', $reply);
        $this->assertStringContainsString('*ANTRE ayam-bakar*', $reply);
        $this->assertSame(0, QueueTicket::query()->count());
    }

    public function test_customer_can_still_check_the_ticket_hours_after_registering(): void
    {
        foreach (['ANTRE ayam-bakar', '2', 'YA'] as $text) {
            $this->say('6281200000006', $text);
        }
        $this->travel(2)->hours();

        $reply = $this->say('6281200000006', 'status?');

        $this->assertStringContainsString('*Status antrean Anda*', $reply);
        $this->assertStringContainsString('Nomor antrean: *A-001*', $reply);
        $this->assertStringContainsString('http://localhost/queue/', $reply);
    }

    public function test_bot_messages_use_whatsapp_formatting_and_stay_short(): void
    {
        $replies = [];
        foreach (['ANTRE ayam-bakar', '2', 'YA'] as $text) {
            $replies[] = $this->say('6281200000007', $text);
        }

        foreach ($replies as $reply) {
            $this->assertMatchesRegularExpression('/\*[^*\n]+\*/', $reply, 'kata penting ditebalkan');
            $this->assertLessThanOrEqual(12, substr_count($reply, "\n") + 1, $reply);
            $this->assertStringNotContainsString('**', $reply, 'bukan Markdown');
        }
    }

    // --- Notices that outlived an outage -----------------------------------------------

    public function test_notice_for_a_ticket_that_moved_on_is_skipped_not_sent(): void
    {
        $tickets = $this->tickets(5);
        $queue = app(QueueService::class);
        $this->gatewayDown();
        $queue->callNext($this->restaurant);
        $queue->completeCurrent($this->restaurant);
        $queue->callNext($this->restaurant);
        $queue->completeCurrent($this->restaurant);

        $this->gatewayOpen();
        $this->artisan('whatsapp:send-pending')->assertSuccessful();

        // A-002 was already served: its "dipanggil" notice is no longer true.
        $called = WhatsappLog::query()->where('event_key', "ticket:{$tickets[1]->id}:called")->firstOrFail();
        $this->assertSame(['skipped', 'stale_notification'], [$called->status, $called->error_code]);
        $this->assertNull($tickets[1]->fresh()->called_notified_at);
        // A-005 is still waiting, so its notice is sent; the skipped messages did not stop the batch.
        $three = WhatsappLog::query()->where('event_key', "ticket:{$tickets[4]->id}:three_ahead")->firstOrFail();
        $this->assertSame('sent', $three->status);
        Http::assertSentCount(WhatsappLog::query()->where('status', 'sent')->count());
    }

    public function test_send_pending_keeps_going_after_a_message_that_was_not_sent(): void
    {
        WhatsAppConversation::query()->create(['phone_number' => '6281200000099', 'last_message_at' => now()]);
        $whatsapp = app(WhatsAppService::class);
        $this->gatewayDown();
        $first = $whatsapp->sendTextMessage('6281299999999', 'Belum pernah menulis ke bot');
        $second = $whatsapp->sendTextMessage('6281200000099', 'Sudah opt-in');

        $this->gatewayOpen();
        $this->artisan('whatsapp:send-pending')->assertSuccessful();

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('sent', $second->fresh()->status);
    }

    public function test_skipped_customer_is_told_what_happened(): void
    {
        [$first] = $this->tickets(1);
        $queue = app(QueueService::class);
        $queue->callNext($this->restaurant);
        $queue->skipCurrent($this->restaurant);
        $this->artisan('whatsapp:send-pending');

        $notice = WhatsappLog::query()->where('event_key', "ticket:{$first->id}:skipped")->firstOrFail();
        $this->assertSame('sent', $notice->status);
        $this->assertStringContainsString('dilewati karena belum hadir', $notice->message);
    }

    // --- Messages stuck in "sending" ---------------------------------------------------

    public function test_message_stuck_in_sending_is_alerted_and_can_be_retried_by_the_owner(): void
    {
        Notification::fake();
        Http::fake(['http://127.0.0.1:3100/health' => Http::response(['state' => 'open', 'phone' => self::BOT])]);
        $owner = User::factory()->create();
        $stuck = $this->outbound('sending');
        $this->travel(3)->minutes();

        $this->assertArrayHasKey('stuck', app(WhatsAppMonitor::class)->problems());

        Bus::fake([DeliverWhatsAppMessage::class]);
        $this->actingAs($owner)->post(route('admin.whatsapp-logs.retry', $stuck))->assertRedirect()
            ->assertSessionHas('status', 'Pesan dijadwalkan untuk dikirim ulang.');

        $this->assertSame(['pending', 0, null], [$stuck->fresh()->status, $stuck->fresh()->attempts, $stuck->fresh()->error_code]);
        Bus::assertDispatched(DeliverWhatsAppMessage::class);
        $this->assertArrayNotHasKey('stuck', app(WhatsAppMonitor::class)->problems());
    }

    public function test_retry_is_refused_for_messages_that_must_not_be_resent(): void
    {
        $owner = User::factory()->create();
        Bus::fake([DeliverWhatsAppMessage::class]);

        $recentSending = $this->outbound('sending');
        $sent = $this->outbound('sent');
        $notOptedIn = $this->outbound('failed', 'not_opted_in');
        foreach ([$recentSending, $sent, $notOptedIn] as $log) {
            $this->actingAs($owner)->post(route('admin.whatsapp-logs.retry', $log))->assertStatus(409);
        }

        $ambiguous = $this->outbound('failed', 'ambiguous_timeout');
        $this->actingAs($owner)->post(route('admin.whatsapp-logs.retry', $ambiguous))->assertRedirect();
        $this->assertSame('pending', $ambiguous->fresh()->status);
        Bus::assertDispatchedTimes(DeliverWhatsAppMessage::class, 1);
    }

    public function test_cashier_cannot_retry_messages(): void
    {
        $cashier = User::factory()->outletAdmin()->create();
        $failed = $this->outbound('failed', 'ambiguous_timeout');

        $this->actingAs($cashier)->post(route('admin.whatsapp-logs.retry', $failed))->assertForbidden();
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_log_page_explains_error_codes(): void
    {
        $owner = User::factory()->create();
        $this->outbound('failed', 'ambiguous_timeout');
        $this->outbound('skipped', 'stale_notification');

        $this->actingAs($owner)->get(route('admin.whatsapp-logs.index'))->assertOk()
            ->assertSee('Kirim ulang')
            ->assertSee('Tidak dikirim');
    }

    // --- Cost of the polling endpoints and time zone --------------------------------

    public function test_polling_endpoints_stay_cheap_with_a_long_queue(): void
    {
        $tickets = $this->tickets(30);

        $this->assertLessThanOrEqual(2, $this->queries(fn () => $this->getJson(route('outlets.status', $this->restaurant->slug))->assertOk()));
        $this->assertLessThanOrEqual(3, $this->queries(fn () => $this->getJson(route('queue.status', end($tickets)->public_token))->assertOk()));
    }

    public function test_ticket_page_estimate_matches_the_groups_ahead(): void
    {
        $tickets = $this->tickets(4);

        $this->getJson(route('queue.status', $tickets[3]->public_token))->assertOk()
            ->assertJsonFragment(['people_before' => 3, 'estimated_wait' => 15]);
    }

    public function test_app_uses_indonesian_western_time_by_default(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', now()->getTimezone()->getName());
    }

    // --- Helpers -------------------------------------------------------------------------

    private function say(string $phone, string $text): string
    {
        $id = 'wamid.test'.++$this->messageCounter;
        app(WhatsAppChatbotService::class)->receive([
            'id' => $id, 'from' => $phone, 'text' => ['body' => $text], 'timestamp' => (string) now()->timestamp,
        ]);

        return (string) WhatsappLog::query()->where('event_key', 'reply:'.$id)->value('message');
    }

    private function state(string $phone): ?string
    {
        return WhatsAppConversation::query()->where('phone_number', $phone)->value('state');
    }

    /**
     * @return array<int, QueueTicket> tickets A-001 ... of customers who wrote to the bot
     */
    private function tickets(int $count): array
    {
        $tickets = [];
        for ($i = 1; $i <= $count; $i++) {
            $phone = sprintf('6281300000%03d', $i);
            WhatsAppConversation::query()->create(['phone_number' => $phone, 'last_message_at' => now()]);
            $tickets[] = app(QueueService::class)->createTicket($this->restaurant, phone: $phone);
        }

        return $tickets;
    }

    private function outbound(string $status, ?string $errorCode = null): WhatsappLog
    {
        WhatsAppConversation::query()->firstOrCreate(['phone_number' => '6281400000001'], ['last_message_at' => now()]);

        return WhatsappLog::query()->create([
            'direction' => 'outbound', 'phone_number' => '6281400000001', 'message' => 'Halo',
            'payload' => ['type' => 'text', 'text' => ['body' => 'Halo']], 'status' => $status, 'error_code' => $errorCode,
        ]);
    }

    private function gatewayOpen(): void
    {
        app(WhatsAppService::class)->rememberGatewaySession('open', self::BOT, now()->timestamp);
    }

    private function gatewayDown(): void
    {
        app(WhatsAppService::class)->rememberGatewaySession('closed', null, now()->timestamp);
    }

    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
