<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsappLog;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GreenApiWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = '7107000000';
    private const API_TOKEN = 'fake-greenapi-token';
    private const WEBHOOK_TOKEN = 'fake-webhook-secret';
    private const SEND_URL = 'https://7107.api.greenapi.com/waInstance7107000000/sendMessage/fake-greenapi-token';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'app.url' => 'https://queue.example.test',
            'whatsapp.enabled' => true,
            'whatsapp.provider' => 'greenapi',
            'whatsapp.phone_number' => '0812 0000 1111',
            'whatsapp.greenapi.api_url' => 'https://7107.api.greenapi.com/',
            'whatsapp.greenapi.id_instance' => self::INSTANCE,
            'whatsapp.greenapi.api_token' => self::API_TOKEN,
            'whatsapp.greenapi.webhook_token' => self::WEBHOOK_TOKEN,
        ]);
    }

    public function test_outlet_page_links_to_linked_number(): void
    {
        $restaurant = $this->restaurant();
        $this->get(route('outlets.whatsapp', $restaurant->slug))
            ->assertRedirect('https://wa.me/6281200001111?text=ANTRE%20ayam-bakar');
    }

    public function test_full_chatbot_flow_over_greenapi(): void
    {
        $this->fakeGreenApi();
        $this->restaurant();

        $this->incoming('M1', 'ANTRE ayam-bakar')->assertOk();
        $this->incoming('M1-menu', '1');
        $this->incoming('M2', '2')->assertOk();
        $this->incoming('M3', 'YA', 'extendedTextMessage')->assertOk();

        $this->assertDatabaseHas('queue_tickets', ['queue_number' => 'A-001', 'party_size' => 2, 'phone_number' => '6281234567890']);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::SEND_URL
            && $request['chatId'] === '6281234567890@c.us'
            && str_contains($request['message'], 'A-001'));
        $this->assertSame(4, WhatsappLog::query()->where('direction', 'outbound')->where('status', 'sent')->count());
    }

    public function test_duplicate_delivery_is_processed_once(): void
    {
        $this->fakeGreenApi();
        $this->restaurant();
        $this->incoming('M1', 'ANTRE ayam-bakar');
        $this->incoming('M1', 'ANTRE ayam-bakar');

        $this->assertSame(1, WhatsappLog::query()->where('direction', 'inbound')->count());
        Http::assertSentCount(1);
    }

    public function test_wrong_or_missing_token_is_rejected(): void
    {
        Http::fake();
        $this->restaurant();
        $body = $this->incomingBody('M1', 'ANTRE ayam-bakar');

        $this->postJson('/webhooks/greenapi', $body)->assertForbidden();
        $this->postJson('/webhooks/greenapi', $body, ['Authorization' => 'Bearer forged'])->assertForbidden();
        $this->assertDatabaseCount('whatsapp_conversations', 0);
        Http::assertNothingSent();
    }

    public function test_other_instances_groups_and_own_messages_are_ignored(): void
    {
        Http::fake();
        $this->restaurant();

        $otherInstance = $this->incomingBody('M1', 'ANTRE ayam-bakar');
        $otherInstance['instanceData']['idInstance'] = 7107999999;
        $group = $this->incomingBody('M2', 'ANTRE ayam-bakar');
        $group['senderData']['chatId'] = '6281234567890-1581234048@g.us';
        $outgoing = $this->incomingBody('M3', 'ANTRE ayam-bakar');
        $outgoing['typeWebhook'] = 'outgoingMessageReceived';

        foreach ([$otherInstance, $group, $outgoing] as $body) {
            $this->webhook($body)->assertOk();
        }
        $this->assertDatabaseCount('whatsapp_conversations', 0);
        Http::assertNothingSent();
    }

    public function test_meta_and_twilio_webhooks_are_closed_when_greenapi_is_active(): void
    {
        $this->postJson('/webhooks/whatsapp', ['object' => 'whatsapp_business_account'])->assertStatus(503);
        $this->post('/webhooks/twilio', ['Body' => 'ANTRE ayam-bakar'])->assertStatus(503);
    }

    public function test_status_notification_moves_message_forward(): void
    {
        $this->fakeGreenApi();
        $this->restaurant();
        $this->incoming('M1', 'ANTRE ayam-bakar');
        $this->incoming('M1-menu', '1');
        $outbound = WhatsappLog::query()->where('direction', 'outbound')->firstOrFail();

        $this->webhook($this->statusBody($outbound->provider_message_id, 'read'))->assertOk();
        $this->assertSame('read', $outbound->fresh()->status);

        $this->webhook($this->statusBody($outbound->provider_message_id, 'delivered'));
        $this->assertSame('read', $outbound->fresh()->status);
    }

    public function test_notifications_are_plain_text_even_without_recent_chat(): void
    {
        $this->fakeGreenApi();
        config(['whatsapp.templates.called' => 'satset_called']);
        $restaurant = $this->restaurant();
        app(QueueService::class)->createTicket($restaurant, phone: '081234567890');

        $this->actingAs(User::factory()->create())->post(route('admin.restaurants.queue.call-next', $restaurant));

        Http::assertSent(fn (ClientRequest $request) => $request['chatId'] === '6281234567890@c.us'
            && str_contains($request['message'], 'Giliran A-001 sudah tiba'));
        $this->assertNotNull(QueueTicket::query()->firstOrFail()->called_notified_at);
    }

    public function test_send_failure_is_logged_without_secrets(): void
    {
        Http::fake(['https://7107.api.greenapi.com/*' => Http::response(['message' => 'quota exceeded'], 466)]);
        $this->restaurant();
        $this->incoming('M1', 'ANTRE ayam-bakar');
        $this->incoming('M1-menu', '1');

        $this->assertDatabaseHas('whatsapp_logs', ['direction' => 'outbound', 'status' => 'failed', 'error_code' => 'http_466']);
        $this->actingAs(User::factory()->create())->get(route('admin.whatsapp-logs.index'))
            ->assertOk()->assertDontSee(self::API_TOKEN);
    }

    private function incoming(string $id, string $text, string $type = 'textMessage'): TestResponse
    {
        return $this->webhook($this->incomingBody($id, $text, $type));
    }

    private function webhook(array $body): TestResponse
    {
        return $this->postJson('/webhooks/greenapi', $body, ['Authorization' => 'Bearer '.self::WEBHOOK_TOKEN]);
    }

    private function incomingBody(string $id, string $text, string $type = 'textMessage'): array
    {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => (int) self::INSTANCE, 'wid' => '6281200001111@c.us', 'typeInstance' => 'whatsapp'],
            'timestamp' => now()->timestamp,
            'idMessage' => $id,
            'senderData' => ['chatId' => '6281234567890@c.us', 'sender' => '6281234567890@c.us', 'senderName' => 'Budi'],
            'messageData' => $type === 'textMessage'
                ? ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => $text]]
                : ['typeMessage' => $type, 'extendedTextMessageData' => ['text' => $text]],
        ];
    }

    private function statusBody(string $id, string $status): array
    {
        return [
            'typeWebhook' => 'outgoingMessageStatus',
            'chatId' => '6281234567890@c.us',
            'instanceData' => ['idInstance' => (int) self::INSTANCE, 'wid' => '6281200001111@c.us', 'typeInstance' => 'whatsapp'],
            'timestamp' => now()->timestamp,
            'idMessage' => $id,
            'status' => $status,
            'sendByApi' => true,
        ];
    }

    private function fakeGreenApi(): void
    {
        $counter = 0;
        Http::fake(['https://7107.api.greenapi.com/*' => function () use (&$counter) {
            return Http::response(['idMessage' => 'OUT'.++$counter]);
        }]);
    }

    private function restaurant(): Restaurant
    {
        return Restaurant::query()->create([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'prefix' => 'A', 'category' => 'Ayam',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
    }
}
