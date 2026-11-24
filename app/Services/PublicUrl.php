<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The public address customers and their phones use.
 *
 * An explicit public APP_URL always wins. While APP_URL still points at this computer, the address of the running
 * cloudflared quick tunnel is used instead, read from cloudflared's metrics endpoint. When the tunnel stops, the
 * address falls back to APP_URL again. The answer is cached for a few seconds, so links follow a tunnel that
 * restarts within that window without asking cloudflared on every request.
 */
class PublicUrl
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const CACHE_KEY = 'public-url.tunnel';

    private const CACHE_SECONDS = 15;

    /** Base URL for links that leave the app: WhatsApp messages, QR codes, provider callbacks. */
    public function base(): string
    {
        $configured = $this->configured();
        if (! $this->isLocal($configured)) {
            return $configured;
        }

        return $this->tunnelBase() ?? $configured;
    }

    /** APP_URL without a trailing slash. */
    public function configured(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** True for addresses that only this computer can reach. */
    public function isLocal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' || in_array($host, self::LOCAL_HOSTS, true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }

    /** https://<host> of the running quick tunnel, or null when there is none (or cloudflared is not configured). */
    public function tunnelBase(): ?string
    {
        $metrics = rtrim((string) config('services.cloudflared.metrics_url'), '/');
        if ($metrics === '') {
            return null;
        }

        // Negative answers are cached too, so a stopped tunnel does not cost a timeout on every request.
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => ['url' => $this->fetchTunnel($metrics)]);

        return $cached['url'];
    }

    private function fetchTunnel(string $metrics): ?string
    {
        try {
            $response = Http::acceptJson()->connectTimeout(1)->timeout(1)->get($metrics.'/quicktunnel');
        } catch (\Throwable) {
            return null;
        }

        $host = $response->successful() ? $response->json('hostname') : null;

        return is_string($host) && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host)
            ? 'https://'.$host
            : null;
    }
}
