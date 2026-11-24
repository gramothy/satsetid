<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicUrlTest extends TestCase
{
    use RefreshDatabase;

    private const METRICS = 'http://127.0.0.1:20241';

    private ?string $tunnelHost = 'first-tunnel.trycloudflare.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['services.cloudflared.metrics_url' => self::METRICS]);
    }

    /** Fake cloudflared's /quicktunnel. The closure reads $this->tunnelHost on every call; null means "no tunnel". */
    private function fakeTunnel(): void
    {
        Http::fake([self::METRICS.'/quicktunnel' => function () {
            return $this->tunnelHost === null
                ? Http::response('', 500)
                : Http::response(['hostname' => $this->tunnelHost]);
        }]);
    }

    public function test_an_explicit_public_app_url_wins_and_cloudflared_is_never_asked(): void
    {
        config(['app.url' => 'https://queue.example.com/']);
        $this->fakeTunnel();

        $this->assertSame('https://queue.example.com', app(PublicUrl::class)->base());
        Http::assertNothingSent();
    }

    public function test_the_running_tunnel_is_used_when_app_url_is_local(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->fakeTunnel();

        $this->assertSame('https://first-tunnel.trycloudflare.com', app(PublicUrl::class)->base());
    }

    public function test_falls_back_to_app_url_when_no_tunnel_is_running(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->tunnelHost = null;
        $this->fakeTunnel();

        $this->assertSame('http://localhost:8000', app(PublicUrl::class)->base());
    }

    public function test_a_malformed_hostname_from_cloudflared_is_ignored(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->tunnelHost = 'evil.example/path?x=1';
        $this->fakeTunnel();

        $this->assertSame('http://localhost:8000', app(PublicUrl::class)->base());
    }

    public function test_disabled_tunnel_detection_makes_no_request(): void
    {
        config(['app.url' => 'http://localhost:8000', 'services.cloudflared.metrics_url' => '']);
        $this->fakeTunnel();

        $this->assertSame('http://localhost:8000', app(PublicUrl::class)->base());
        Http::assertNothingSent();
    }

    public function test_links_follow_the_tunnel_as_it_starts_and_stops(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->fakeTunnel();
        $url = app(PublicUrl::class);

        $this->assertSame('https://first-tunnel.trycloudflare.com', $url->base());

        // Within the cache window the answer does not change, so requests do not all ask cloudflared.
        $this->tunnelHost = 'second-tunnel.trycloudflare.com';
        $this->assertSame('https://first-tunnel.trycloudflare.com', $url->base());

        // After the window the new tunnel is picked up (it was restarted and has a new hostname).
        $this->travel(16)->seconds();
        $this->assertSame('https://second-tunnel.trycloudflare.com', $url->base());

        // The tunnel stops: after the window the links fall back to APP_URL.
        $this->tunnelHost = null;
        $this->travel(16)->seconds();
        $this->assertSame('http://localhost:8000', $url->base());

        // And it comes back.
        $this->tunnelHost = 'third-tunnel.trycloudflare.com';
        $this->travel(16)->seconds();
        $this->assertSame('https://third-tunnel.trycloudflare.com', $url->base());
    }

    public function test_an_unreachable_cloudflared_falls_back_without_throwing(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Http::fake([self::METRICS.'/quicktunnel' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);

        $this->assertSame('http://localhost:8000', app(PublicUrl::class)->base());
    }

    public function test_the_qr_page_encodes_the_tunnel_address_when_the_admin_browses_locally(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->fakeTunnel();
        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Bakar Nusantara', 'slug' => 'ayam-bakar', 'category' => 'Ayam', 'prefix' => 'A',
            'average_service_minutes' => 5, 'max_wait_minutes' => 30, 'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
        $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

        $this->actingAs($owner)->get(route('admin.qr.show', $restaurant))
            ->assertOk()
            ->assertSee('https://first-tunnel.trycloudflare.com/r/ayam-bakar');
    }
}
