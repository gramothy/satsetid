<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappLog;
use App\Notifications\WhatsAppAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Health checks for the WhatsApp channel. `whatsapp:monitor` runs them every minute and
 * emails active owners when a problem starts, again every reminder interval while it lasts,
 * and once when it is gone. The owner dashboard shows the current problems.
 * Messages never contain message text, phone numbers or credentials.
 */
class WhatsAppMonitor
{
    /** Unix time of the last `whatsapp:monitor` run. */
    public const HEARTBEAT_KEY = 'whatsapp:monitor:last_run';

    /** The dashboard warns when the monitor has not run for this long (scheduler stopped). */
    public const HEARTBEAT_STALE_SECONDS = 300;

    /** Short reconnects (gateway closed, connecting, waiting for a scan, unreachable) are not alerted. */
    public const GATEWAY_GRACE_SECONDS = 180;

    public const PENDING_MAX_AGE_MINUTES = 10;

    public const FAILED_WINDOW_MINUTES = 60;

    public const FAILED_THRESHOLD = 5;

    public const WEBHOOK_BACKLOG_THRESHOLD = 10;

    public const REMIND_AFTER_MINUTES = 60;

    /** key => unix time the problem was first seen */
    private const FIRST_SEEN_KEY = 'whatsapp:monitor:first_seen';

    /** key => ['message' => ..., 'notified_at' => unix time] for problems owners were told about */
    private const ALERTED_KEY = 'whatsapp:monitor:alerted';

    public function __construct(private WhatsAppService $whatsapp) {}

    /**
     * Current problems as key => ['message' => string, 'grace' => seconds, 'remind' => minutes].
     * Empty when WhatsApp is disabled. $freshGateway asks the gateway instead of using the cached state.
     */
    public function problems(bool $freshGateway = false): array
    {
        if (! config('whatsapp.enabled')) {
            return [];
        }

        $problems = [];
        if ($this->whatsapp->provider() === 'baileys') {
            $problems += $this->gatewayProblems($freshGateway);
        }

        $stale = WhatsappLog::query()->where('direction', 'outbound')->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(self::PENDING_MAX_AGE_MINUTES));
        $staleCount = (clone $stale)->count();
        if ($staleCount > 0) {
            $oldest = (clone $stale)->min('created_at');
            $problems['pending'] = $this->problem(
                "{$staleCount} pesan WhatsApp menunggu kirim lebih dari ".self::PENDING_MAX_AGE_MINUTES.' menit (paling lama '
                .(int) Carbon::parse($oldest)->diffInMinutes(now()).' menit). Pastikan php artisan queue:work dan wa-gateway berjalan.'
            );
        }

        $failed = WhatsappLog::query()->where('direction', 'outbound')->where('status', 'failed')
            ->where(fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', 'not_opted_in'))
            ->where('updated_at', '>=', now()->subMinutes(self::FAILED_WINDOW_MINUTES))->count();
        if ($failed >= self::FAILED_THRESHOLD) {
            $problems['failed'] = $this->problem(
                "{$failed} pesan WhatsApp gagal dalam ".self::FAILED_WINDOW_MINUTES.' menit terakhir. Periksa kode error di Admin → Log WhatsApp.'
            );
        }

        return $problems;
    }

    /**
     * Problems for the owner dashboard: current problems plus a warning when the scheduler
     * (and therefore this monitor and the retry of pending messages) is not running.
     *
     * @return array<string, string> key => message
     */
    public function dashboardWarnings(): array
    {
        if (! config('whatsapp.enabled')) {
            return [];
        }

        $warnings = array_map(fn (array $problem): string => $problem['message'], $this->problems());
        $lastRun = Cache::get(self::HEARTBEAT_KEY);
        if (! is_int($lastRun) || now()->timestamp - $lastRun > self::HEARTBEAT_STALE_SECONDS) {
            $warnings['monitor'] = 'Pemantauan WhatsApp tidak berjalan, jadi tidak ada email peringatan dan pesan tertunda tidak dicoba ulang. Jalankan php artisan schedule:work.';
        }

        return $warnings;
    }

    /**
     * One monitor pass (scheduled every minute). Returns what was found and who was told.
     *
     * @return array{problems: array<string, array>, alerted: list<string>, recovered: list<string>}
     */
    public function run(): array
    {
        $now = now()->timestamp;
        Cache::forever(self::HEARTBEAT_KEY, $now);

        if (! config('whatsapp.enabled')) {
            // Switched off on purpose: forget earlier problems without a "recovered" email.
            Cache::forget(self::FIRST_SEEN_KEY);
            Cache::forget(self::ALERTED_KEY);

            return ['problems' => [], 'alerted' => [], 'recovered' => []];
        }

        $problems = $this->problems(freshGateway: true);
        $firstSeen = array_intersect_key(Cache::get(self::FIRST_SEEN_KEY, []), $problems);
        $alerted = Cache::get(self::ALERTED_KEY, []);
        $toAlert = [];

        foreach ($problems as $key => $problem) {
            $firstSeen[$key] ??= $now;
            if ($now - $firstSeen[$key] < $problem['grace']) {
                continue;
            }
            $notifiedAt = $alerted[$key]['notified_at'] ?? null;
            if ($notifiedAt === null || $now - $notifiedAt >= $problem['remind'] * 60) {
                $toAlert[$key] = $problem['message'];
                $alerted[$key] = ['message' => $problem['message'], 'notified_at' => $now];
            }
        }

        $recovered = array_diff_key($alerted, $problems);
        $alerted = array_diff_key($alerted, $recovered);
        Cache::forever(self::FIRST_SEEN_KEY, $firstSeen);
        Cache::forever(self::ALERTED_KEY, $alerted);

        if ($toAlert !== []) {
            Log::error('WhatsApp monitor alert', ['problems' => $toAlert]);
            $this->notifyOwners(new WhatsAppAlert($toAlert));
        }
        if ($recovered !== []) {
            $resolved = array_map(fn (array $entry): string => $entry['message'], $recovered);
            Log::info('WhatsApp monitor recovered', ['problems' => array_keys($resolved)]);
            $this->notifyOwners(new WhatsAppAlert($resolved, recovered: true));
        }

        return ['problems' => $problems, 'alerted' => array_keys($toAlert), 'recovered' => array_keys($recovered)];
    }

    private function gatewayProblems(bool $fresh): array
    {
        if (! $this->whatsapp->baileysConfigured()) {
            return ['gateway' => $this->problem('WhatsApp aktif, tetapi WHATSAPP_GATEWAY_TOKEN atau WHATSAPP_WEBHOOK_SECRET belum diisi.')];
        }

        $session = $this->whatsapp->gatewaySession($fresh);
        $problems = [];
        $message = match ($session['state']) {
            'open' => null,
            'qr' => 'wa-gateway menunggu scan QR, jadi chatbot tidak menerima pesan. Sambungkan nomor di Admin → Hubungkan WhatsApp.',
            'logged_out' => 'Sesi WhatsApp diputus (perangkat dikeluarkan dari HP, nomor diblokir, atau tombol Putuskan). Jalankan ulang wa-gateway lalu scan QR baru.',
            'unauthorized' => 'wa-gateway menolak token Laravel. Samakan WHATSAPP_GATEWAY_TOKEN dengan WA_GATEWAY_TOKEN, lalu php artisan config:clear.',
            'unreachable' => 'wa-gateway tidak berjalan atau tidak bisa dihubungi. Jalankan npm start di folder wa-gateway.',
            default => 'wa-gateway belum tersambung ke WhatsApp (sedang menyambung ulang).',
        };
        if ($message !== null) {
            // A session that was logged out or a wrong token needs a person right away.
            $immediate = in_array($session['state'], ['logged_out', 'unauthorized'], true);
            $problems['gateway'] = $this->problem($message, $immediate ? 0 : self::GATEWAY_GRACE_SECONDS);
        }

        $backlog = $session['webhook_backlog'] ?? null;
        if (is_int($backlog) && $backlog >= self::WEBHOOK_BACKLOG_THRESHOLD) {
            $problems['webhook_backlog'] = $this->problem(
                "wa-gateway menahan {$backlog} event yang belum diterima Laravel. Periksa WHATSAPP_WEBHOOK_SECRET sama dengan WA_WEBHOOK_SECRET, dan log Laravel."
            );
        }

        $setAside = $session['webhook_failed'] ?? null;
        if (is_int($setAside) && $setAside > 0) {
            $problems['webhook_failed'] = $this->problem(
                "{$setAside} event dari wa-gateway disisihkan karena ditolak Laravel atau tertahan lebih dari 24 jam. Periksa folder wa-gateway/data/webhook-queue/failed, lalu npm run webhooks:replay atau hapus file yang sudah ditangani.",
                remind: 24 * 60,
            );
        }

        return $problems;
    }

    private function problem(string $message, int $grace = 0, int $remind = self::REMIND_AFTER_MINUTES): array
    {
        return ['message' => $message, 'grace' => $grace, 'remind' => $remind];
    }

    private function notifyOwners(WhatsAppAlert $alert): void
    {
        $owners = User::query()->where('role', User::ROLE_OWNER)->where('active', true)->get();
        if ($owners->isEmpty()) {
            return;
        }

        try {
            Notification::send($owners, $alert);
        } catch (Throwable $exception) {
            // A broken mail setup must not stop the monitor; the alert is in the log already.
            Log::error('WhatsApp monitor email failed', ['error' => class_basename($exception)]);
        }
    }
}
