<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $expireMinutes = (int) config(
            'auth.passwords.users.expire',
            60,
        );

        return (new MailMessage())
            ->subject('Reset Password - SYOP')
            ->view(
                'emails.reset-password',
                [
                    'userName' => $notifiable->name ?? null,
                    'resetUrl' => $this->buildResetUrl($notifiable),
                    'expireMinutes' => $expireMinutes,
                ],
            );
    }

    /**
     * Link mengarah ke halaman SPA Vue yang benar-benar dilayani oleh
     * Laravel (app.url), BUKAN app.frontend_url -- di lokal, frontend_url
     * mengarah ke Vite dev server (mis. :5173) yang cuma melayani asset,
     * bukan route SPA seperti /reset-password.
     */
    private function buildResetUrl(mixed $notifiable): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/reset-password'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
    }
}
