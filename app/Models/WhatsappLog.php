<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappLog extends Model
{
    /** A delivery job runs at most 45 seconds; "sending" for longer means the worker died mid-send. */
    public const STUCK_SENDING_MINUTES = 2;

    /** Admin-facing explanation of error codes (exact codes, then prefixes). */
    private const ERROR_DESCRIPTIONS = [
        'not_opted_in' => 'Nomor ini belum pernah chat ke bot, jadi tidak dikirimi pesan.',
        'stale_notification' => 'Tidak dikirim karena status antrean sudah berubah.',
        'ambiguous_timeout' => 'Tidak pasti terkirim (koneksi terputus saat mengirim). Cek di HP outlet sebelum kirim ulang.',
        'http_409_send_uncertain' => 'Tidak pasti terkirim. Cek di HP outlet sebelum kirim ulang.',
        'http_422_not_on_whatsapp' => 'Nomor tidak terdaftar di WhatsApp.',
        'gateway_unavailable' => 'wa-gateway tidak tersambung atau sedang sibuk.',
        'not_configured' => 'WhatsApp belum aktif atau konfigurasinya belum lengkap.',
        'template_required' => 'Di luar 24 jam sejak chat terakhir customer dan template belum diatur.',
        'transport_or_delivery_error' => 'Gagal menghubungi layanan pengiriman.',
        'provider_delivery_failed' => 'Ditolak WhatsApp setelah terkirim.',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class);
    }

    public function isStuckSending(): bool
    {
        return $this->status === 'sending' && $this->updated_at?->lte(now()->subMinutes(self::STUCK_SENDING_MINUTES));
    }

    /**
     * Failed or stuck outgoing messages can be queued again by an owner after checking the outlet phone.
     */
    public function canRetry(): bool
    {
        return $this->direction === 'outbound'
            && (($this->status === 'failed' && $this->error_code !== 'not_opted_in') || $this->isStuckSending());
    }

    public function errorDescription(): ?string
    {
        if ($this->isStuckSending()) {
            return 'Macet di status Mengirim (worker berhenti saat mengirim). Cek di HP outlet sebelum kirim ulang.';
        }
        if (! $this->error_code) {
            return null;
        }

        return self::ERROR_DESCRIPTIONS[$this->error_code] ?? match (true) {
            str_starts_with($this->error_code, 'http_4') => 'Ditolak oleh layanan pengiriman.',
            str_starts_with($this->error_code, 'http_5') => 'Layanan pengiriman sedang bermasalah.',
            default => null,
        };
    }
}
