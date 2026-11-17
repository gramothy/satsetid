<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use App\Services\QueueService;
use App\Services\QueueWhatsAppNotificationService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Tinggal 3" / "tinggal 1" notifications (backlog #1 and #8).
 */
class QueueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private QueueService $queue;

    private QueueWhatsAppNotificationService $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $counter = 0;
        Http::fake(['http://127.0.0.1:3100/send' => function () use (&$counter) {
            return Http::response(['message_id' => 'WAID'.++$counter]);
        }]);
        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'baileys',
            'whatsapp.baileys.gateway_url' => 'http://127.0.0.1:3100',
            'whatsapp.baileys.gateway_token' => 'fake-gateway-token-0123456789',
            'whatsapp.baileys.webhook_secret' => 'fake-webhook-secret-0123456789',
        ]);
        app(WhatsAppService::class)->rememberGatewaySession('open', '628111111111', now()->timestamp);

        $this->restaurant = Restaurant::query()->create([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'prefix' => 'A', 'category' => 'Ayam',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
        $this->queue = app(QueueService::class);
        $this->notifier = app(QueueWhatsAppNotificationService::class);
    }

    public function test_two_cancellations_at_once_still_trigger_tinggal_3(): void
    {
        [, $second, $third, $fourth, $fifth] = $this->tickets(5);
        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->assertFalse($this->notified($fifth, 'three_ahead'), 'A-005 masih 4 antrean di depan');

        // Two customers ahead of A-005 cancel at the same moment: both cancellations are committed
        // before either request runs the notification step, so A-005 goes from 4 ahead to 2 ahead.
        QueueTicket::query()->whereKey([$second->id, $third->id])
            ->update(['status' => QueueTicket::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->flush();

        $notice = WhatsappLog::query()->where('event_key', "ticket:{$fifth->id}:three_ahead")->firstOrFail();
        $this->assertSame('sent', $notice->status);
        $this->assertSame("⏳ *Tinggal 2 antrean lagi*\n\nNomor *A-005* di Ayam Bakar Nusantara sebentar lagi dipanggil.\nSilakan mulai menuju outlet.", $notice->message);
        $this->assertNotNull($fifth->fresh()->three_ahead_notified_at);
        $this->assertTrue($this->notified($fourth, 'one_ahead'), 'A-004 sekarang tinggal 1');

        // Later queue changes do not repeat it.
        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->flush();
        $this->assertSame(1, WhatsappLog::query()->where('event_key', "ticket:{$fifth->id}:three_ahead")->count());
        Http::assertSentCount(WhatsappLog::query()->where('status', 'sent')->count());
    }

    public function test_cancellations_through_the_queue_service_notify_once(): void
    {
        [$first, $second, $third, , $fifth] = $this->tickets(5);

        $this->queue->cancelTicket($second);
        $this->queue->cancelTicket($third);
        $this->queue->cancelTicket($first);
        $this->flush();

        // A-005: 4 ahead -> 3 ahead ("tinggal 3") -> 2 ahead -> 1 ahead ("tinggal 1").
        $this->assertSame(
            [
                "⏳ *Tinggal 3 antrean lagi*\n\nNomor *A-005* di Ayam Bakar Nusantara sebentar lagi dipanggil.\nSilakan mulai menuju outlet.",
                "🔔 *Tinggal 1 antrean lagi*\n\nNomor *A-005* dipanggil setelah satu antrean lagi.\nSilakan menuju *Ayam Bakar Nusantara* sekarang agar tidak terlewat.",
            ],
            WhatsappLog::query()->where('queue_ticket_id', $fifth->id)->orderBy('id')->pluck('message')->all(),
        );
    }

    public function test_jump_past_both_thresholds_sends_only_tinggal_1(): void
    {
        [, $second, $third, $fourth, $fifth] = $this->tickets(5);

        QueueTicket::query()->whereKey([$second->id, $third->id, $fourth->id])
            ->update(['status' => QueueTicket::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->flush();

        $this->assertTrue($this->notified($fifth, 'one_ahead'));
        $this->assertFalse($this->notified($fifth, 'three_ahead'), 'tidak perlu "tinggal 3" setelah "tinggal 1"');
        $this->assertNotNull($fifth->fresh()->one_ahead_notified_at);
    }

    public function test_customer_who_joined_inside_a_threshold_is_not_told_it_again(): void
    {
        [$first, $second, $third] = $this->tickets(3);
        $this->assertNotNull($third->three_ahead_notified_at, 'A-003 mendaftar dengan 2 antrean di depan');
        $this->assertNull($third->one_ahead_notified_at);
        $this->assertNotNull($first->one_ahead_notified_at, 'A-001 mendaftar tanpa antrean di depan');

        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);
        $this->assertFalse($this->notified($third, 'three_ahead'));
        $this->assertFalse($this->notified($first, 'one_ahead'));

        // Once A-003 reaches 1 ahead it is still told to get ready.
        $this->queue->cancelTicket($second);
        $this->flush();
        $this->assertTrue($this->notified($third, 'one_ahead'));
    }

    public function test_next_in_line_after_a_jump_is_told_to_get_ready(): void
    {
        [$first, $second] = $this->tickets(2);

        // A-002 joined with 1 ahead; A-001 cancels before any notification ran, so A-002 jumps to 0 ahead.
        $this->queue->cancelTicket($first);
        $this->flush();

        $notice = WhatsappLog::query()->where('event_key', "ticket:{$second->id}:one_ahead")->firstOrFail();
        $this->assertSame("🔔 *Anda berikutnya*\n\nNomor *A-002* akan dipanggil berikutnya.\nSilakan menuju *Ayam Bakar Nusantara* sekarang.", $notice->message);
        $this->assertSame('sent', $notice->status);
    }

    public function test_waiting_positions_use_one_ticket_query_regardless_of_queue_length(): void
    {
        $this->tickets(10);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->notifier->notifyWaitingPositions($this->restaurant, $this->queue);

        $ticketQueries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "queue_tickets"'));
        DB::disableQueryLog();
        $this->assertCount(1, $ticketQueries, $ticketQueries->implode("\n"));
        $this->assertCount(0, collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'from "restaurants"')));
    }

    /**
     * @return array<int, QueueTicket> tickets A-001 ... A-00n of customers who wrote to the bot
     */
    private function tickets(int $count): array
    {
        $tickets = [];
        for ($i = 1; $i <= $count; $i++) {
            $phone = sprintf('62812000000%02d', $i);
            WhatsAppConversation::query()->create(['phone_number' => $phone, 'last_message_at' => now()]);
            $tickets[] = $this->queue->createTicket($this->restaurant, phone: $phone);
        }

        return $tickets;
    }

    private function notified(QueueTicket $ticket, string $event): bool
    {
        return WhatsappLog::query()->where('event_key', "ticket:{$ticket->id}:{$event}")->exists();
    }

    private function flush(): void
    {
        $this->artisan('whatsapp:send-pending')->assertSuccessful();
    }
}
