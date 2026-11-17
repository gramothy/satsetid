<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to SatSet owners from `whatsapp:monitor`: problems found, or problems resolved.
 */
class WhatsAppAlert extends Notification
{
    /**
     * @param  array<string, string>  $problems  key => message (no message text, phone numbers or credentials)
     */
    public function __construct(public array $problems, public bool $recovered = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->recovered ? '[SatSet] WhatsApp kembali normal' : '[SatSet] Peringatan WhatsApp')
            ->greeting('Halo '.$notifiable->name.',')
            ->line($this->recovered
                ? 'Masalah WhatsApp berikut sudah tidak terdeteksi lagi:'
                : 'Pemantauan SatSet menemukan masalah pada WhatsApp:');

        foreach ($this->problems as $message) {
            $mail->line('• '.$message);
        }

        if (! $this->recovered) {
            $mail->action('Buka Hubungkan WhatsApp', route('admin.whatsapp.connect'))
                ->line('Selama masalah berlangsung, pengingat dikirim paling sering sekali per jam.');
        }

        return $mail->salutation('SatSet');
    }
}
