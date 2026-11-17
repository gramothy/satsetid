<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappLog;
use App\Notifications\WhatsAppAlert;
use App\Services\WhatsAppMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WhatsAppMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fake-gateway-token-0123456789';

    private const SECRET = 'fake-webhook-secret-0123456789';

    private array $health = ['state' => 'open', 'phone' => '628111111111', 'sent_today' => 0, 'webhook_backlog' => 0, 'webhook_failed' => 0];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3100/health' => fn () => Http::response($this->health)]);
        Notification::fake();
        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'baileys',
            'whatsapp.baileys.gateway_url' => 'http://127.0.0.1:3100',
            'whatsapp.baileys.gateway_token' => self::TOKEN,
            'whatsapp.baileys.webhook_secret' => self::SECRET,
        ]);
        $this->owner = User::factory()->create();
    }

    public function test_healthy_channel_sends_nothing(): void
    {
        $this->monitor();

        Notification::assertNothingSent();
        $this->assertIsInt(cache()->get(WhatsAppMonitor::HEARTBEAT_KEY));
    }

    public function test_disabled_whatsapp_is_not_checked(): void
    {
        config(['whatsapp.enabled' => false]);
        $this->health['state'] = 'logged_out';

        $this->monitor();

        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_logged_out_session_alerts_active_owners_once_then_reminds_and_recovers(): void
    {
        $inactiveOwner = User::factory()->create(['active' => false]);
        $cashier = User::factory()->outletAdmin()->create();
        $this->health['state'] = 'logged_out';

        $this->monitor();
        Notification::assertSentTo($this->owner, WhatsAppAlert::class, fn (WhatsAppAlert $alert) => ! $alert->recovered
            && str_contains($alert->problems['gateway'], 'Sesi WhatsApp diputus'));
        Notification::assertNotSentTo([$inactiveOwner, $cashier], WhatsAppAlert::class);

        $this->travel(30)->minutes();
        $this->monitor();
        Notification::assertSentToTimes($this->owner, WhatsAppAlert::class, 1);

        $this->travel(31)->minutes();
        $this->monitor();
        Notification::assertSentToTimes($this->owner, WhatsAppAlert::class, 2);

        $this->health['state'] = 'open';
        $this->monitor();
        Notification::assertSentToTimes($this->owner, WhatsAppAlert::class, 3);
        Notification::assertSentTo($this->owner, WhatsAppAlert::class, fn (WhatsAppAlert $alert) => $alert->recovered
            && array_keys($alert->problems) === ['gateway']);
    }

    public function test_short_reconnect_is_not_alerted(): void
    {
        $this->health['state'] = 'closed';
        $this->monitor();
        $this->travel(2)->minutes();
        $this->monitor();
        Notification::assertNothingSent();

        // Back before the grace period ended: no alert and no "recovered" email either.
        $this->health['state'] = 'open';
        $this->monitor();
        Notification::assertNothingSent();

        $this->health['state'] = 'unreachable';
        $this->monitor();
        $this->travel(4)->minutes();
        $this->monitor();
        Notification::assertSentTo($this->owner, WhatsAppAlert::class, fn (WhatsAppAlert $alert) => str_contains($alert->problems['gateway'], 'tidak berjalan'));
    }

    public function test_stale_pending_messages_and_failure_spike_are_alerted(): void
    {
        $this->outbound('pending', minutesAgo: 11);
        $this->outbound('pending', minutesAgo: 2);
        foreach (range(1, 5) as $i) {
            $this->outbound('failed', minutesAgo: 5, errorCode: 'http_422_not_on_whatsapp');
        }
        $this->outbound('failed', minutesAgo: 5, errorCode: 'not_opted_in');

        $result = $this->monitor();

        $this->assertEqualsCanonicalizing(['pending', 'failed'], $result['alerted']);
        $this->assertStringContainsString('1 pesan WhatsApp menunggu kirim', $result['problems']['pending']['message']);
        $this->assertStringContainsString('5 pesan WhatsApp gagal', $result['problems']['failed']['message']);
    }

    public function test_few_failures_are_not_a_spike(): void
    {
        foreach (range(1, 4) as $i) {
            $this->outbound('failed', minutesAgo: 5, errorCode: 'http_500');
        }
        $this->outbound('failed', minutesAgo: 90, errorCode: 'http_500');

        $this->assertSame([], $this->monitor()['alerted']);
    }

    public function test_webhook_backlog_and_set_aside_events_are_alerted(): void
    {
        $this->health['webhook_backlog'] = 12;
        $this->health['webhook_failed'] = 2;

        $result = $this->monitor();

        $this->assertEqualsCanonicalizing(['webhook_backlog', 'webhook_failed'], $result['alerted']);
        $this->assertStringContainsString('menahan 12 event', $result['problems']['webhook_backlog']['message']);
        $this->assertStringContainsString('npm run webhooks:replay', $result['problems']['webhook_failed']['message']);
    }

    public function test_alert_email_names_the_problem_without_secrets(): void
    {
        $this->health['state'] = 'unauthorized';
        $this->monitor();

        Notification::assertSentTo($this->owner, WhatsAppAlert::class, function (WhatsAppAlert $alert): bool {
            $mail = $alert->toMail($this->owner);
            $html = (string) $mail->render();

            return $mail->subject === '[SatSet] Peringatan WhatsApp'
                && str_contains($html, 'menolak token Laravel')
                && str_contains($html, route('admin.whatsapp.connect'))
                && ! str_contains($html, self::TOKEN) && ! str_contains($html, self::SECRET);
        });
    }

    public function test_owner_dashboard_shows_problems_and_a_stopped_scheduler(): void
    {
        $this->health['state'] = 'logged_out';

        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Perlu perhatian: WhatsApp')
            ->assertSee('Sesi WhatsApp diputus')
            ->assertSee('Pemantauan WhatsApp tidak berjalan');

        $this->monitor();
        $this->health['state'] = 'open';
        cache()->forget('whatsapp:baileys:session');
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Perlu perhatian: WhatsApp');
    }

    public function test_cashier_dashboard_never_checks_whatsapp(): void
    {
        $this->health['state'] = 'logged_out';
        $cashier = User::factory()->outletAdmin()->create();

        $this->actingAs($cashier)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Perlu perhatian: WhatsApp');
        Http::assertNothingSent();
    }

    public function test_monitor_runs_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('whatsapp:monitor')->assertSuccessful();
    }

    private function monitor(): array
    {
        return app(WhatsAppMonitor::class)->run();
    }

    private function outbound(string $status, int $minutesAgo, ?string $errorCode = null): void
    {
        $log = WhatsappLog::query()->create([
            'direction' => 'outbound', 'phone_number' => '6281234567890', 'message' => 'isi rahasia',
            'status' => $status, 'error_code' => $errorCode,
        ]);
        $log->forceFill(['created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }
}
