<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsappLog;
use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The booth flow: visitor scans the QR (opens the bot), reads the outlet info, tries the menu,
 * cancels and registers again, while the cashier watches the queue on a laptop.
 */
class BoothFlowTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '628111111111';

    private int $messageCounter = 0;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3100/send' => Http::response(['message_id' => 'WAID1'])]);
        config([
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'baileys',
            'whatsapp.baileys.gateway_url' => 'http://127.0.0.1:3100',
            'whatsapp.baileys.gateway_token' => 'fake-gateway-token-0123456789',
            'whatsapp.baileys.webhook_secret' => 'fake-webhook-secret-0123456789',
        ]);
        app(WhatsAppService::class)->rememberGatewaySession('open', self::BOT, now()->timestamp);
        $this->restaurant = Restaurant::query()->create([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'prefix' => 'A', 'category' => 'Ayam',
            'description' => 'Ayam bakar kecap khas Nusantara, dimasak di atas arang.',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
    }

    public function test_welcome_shows_outlet_facts_before_the_menu(): void
    {
        $reply = $this->say('6281200000301', 'Saya mau antre di Ayam Bakar Nusantara (ayam-bakar)');

        $this->assertStringContainsString('selamat datang di *Ayam Bakar Nusantara*', $reply);
        $this->assertStringNotContainsString('Kategori', $reply);
        $this->assertStringContainsString('Ayam bakar kecap khas Nusantara', $reply);
        $this->assertStringContainsString('Maks. 6 orang per kelompok', $reply);
        $this->assertStringContainsString('*1* Ambil nomor antrean', $reply);
        $this->assertStringNotContainsString('Batalkan', $reply, 'opsi batal hanya muncul saat ada tiket');
    }

    public function test_welcome_skips_the_description_line_when_the_outlet_has_none(): void
    {
        $this->restaurant->update(['description' => null]);

        $reply = $this->say('6281200000302', 'ANTRE ayam-bakar');

        $this->assertStringContainsString("selamat datang di *Ayam Bakar Nusantara* 👋\n\nMaks. 6 orang per kelompok\n\n", $reply);
    }

    public function test_status_word_shows_the_ticket_after_registering(): void
    {
        $this->registerTicket('6281200000303');

        $reply = $this->say('6281200000303', 'status');

        $this->assertStringContainsString('*Antrean Anda*', $reply);
        $this->assertStringContainsString('Nomor antrean: *A-001*', $reply);
    }

    public function test_menu_shows_cancel_option_only_while_a_ticket_is_active(): void
    {
        $this->registerTicket('6281200000304');

        $reply = $this->say('6281200000304', 'halo');

        $this->assertStringContainsString('*4* Batalkan antrean saya', $reply);
        $this->assertStringContainsString('*1*, *2*, *3*, atau *4*', $this->say('6281200000304', 'hmm'));
    }

    public function test_cancel_needs_a_yes_and_then_frees_the_customer_to_register_again(): void
    {
        $this->registerTicket('6281200000305');

        $ask = $this->say('6281200000305', '4');
        $this->assertSame('waiting_cancel_confirmation', $this->state('6281200000305'));
        $this->assertStringContainsString('Batalkan nomor *A-001*?', $ask);
        $this->assertSame(QueueTicket::STATUS_WAITING, QueueTicket::query()->firstOrFail()->status);

        $done = $this->say('6281200000305', 'YA');
        $this->assertSame(QueueTicket::STATUS_CANCELLED, QueueTicket::query()->firstOrFail()->status);
        $this->assertSame('menu', $this->state('6281200000305'));
        $this->assertStringContainsString('sudah dibatalkan', $done);

        $this->say('6281200000305', '1');
        $this->assertSame('waiting_party_size', $this->state('6281200000305'));
    }

    public function test_stray_text_or_zero_keeps_the_ticket_during_cancel_confirmation(): void
    {
        $this->registerTicket('6281200000306');

        $this->say('6281200000306', '4');
        $this->say('6281200000306', 'hmm nanti dulu');
        $this->assertSame(QueueTicket::STATUS_WAITING, QueueTicket::query()->firstOrFail()->status);
        $this->assertSame('waiting_cancel_confirmation', $this->state('6281200000306'));

        $back = $this->say('6281200000306', '0');
        $this->assertSame('queue_created', $this->state('6281200000306'));
        $this->assertStringContainsString('tetap aktif', $back);
        $this->assertSame(QueueTicket::STATUS_WAITING, QueueTicket::query()->firstOrFail()->status);
    }

    public function test_option_four_without_a_ticket_only_explains(): void
    {
        $reply = $this->say('6281200000307', 'ANTRE ayam-bakar');
        $this->assertStringNotContainsString('*4*', $reply);

        $reply = $this->say('6281200000307', '4');

        $this->assertStringContainsString('belum punya nomor antrean aktif', $reply);
        $this->assertSame('menu', $this->state('6281200000307'));
    }

    public function test_cashier_sees_the_phone_number_partly_masked(): void
    {
        $this->registerTicket('6281234567890');
        $cashier = User::factory()->create(['role' => 'owner', 'active' => true]);

        $this->actingAs($cashier)->get(route('admin.restaurants.queue', $this->restaurant))
            ->assertOk()
            ->assertSee('6281*****7890')
            ->assertDontSee('6281234567890');
    }

    public function test_masking_keeps_first_and_last_digits_and_hides_the_middle(): void
    {
        $long = new QueueTicket(['phone_number' => '6281234567890']);
        $short = new QueueTicket(['phone_number' => '12345678']);
        $none = new QueueTicket(['phone_number' => null]);

        $this->assertSame('6281*****7890', $long->maskedPhone());
        $this->assertSame('*****678', $short->maskedPhone());
        $this->assertNull($none->maskedPhone());
    }

    public function test_qr_opens_the_bot_when_whatsapp_is_ready(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

        $this->actingAs($owner)->get(route('admin.qr.show', $this->restaurant))
            ->assertOk()
            ->assertSee('https://wa.me/'.self::BOT.'?text=', false)
            ->assertSee('Saya%20mau%20antre%20di%20Ayam%20Bakar%20Nusantara', false);
    }

    public function test_qr_falls_back_to_the_outlet_page_when_the_gateway_is_down(): void
    {
        app(WhatsAppService::class)->rememberGatewaySession('closed', null, now()->timestamp);
        $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

        $this->actingAs($owner)->get(route('admin.qr.show', $this->restaurant))
            ->assertOk()
            ->assertDontSee('wa.me/', false)
            ->assertSee('/r/ayam-bakar', false);
    }

    /** Visitor scans the QR, confirms, and gets ticket A-001 (the first ticket of this outlet). */
    private function registerTicket(string $phone): void
    {
        $this->say($phone, 'ANTRE ayam-bakar');
        $this->say($phone, '1');
        $this->say($phone, '2');
        $this->say($phone, 'YA');
    }

    private function say(string $phone, string $text): string
    {
        $id = 'wamid.booth'.++$this->messageCounter;
        app(WhatsAppChatbotService::class)->receive([
            'id' => $id, 'from' => $phone, 'text' => ['body' => $text], 'timestamp' => (string) now()->timestamp,
        ]);

        return (string) WhatsappLog::query()->where('event_key', 'reply:'.$id)->value('message');
    }

    private function state(string $phone): ?string
    {
        return WhatsAppConversation::query()->where('phone_number', $phone)->value('state');
    }
}
