<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification
{
    public function __construct(private string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verification code - ' . config('app.name', 'Kalinga'))
            ->greeting('Your verification code')
            ->line("**{$this->code}**")
            ->line('This code expires in 10 minutes. Do not share it with anyone.')
            ->line('If you did not attempt to sign in, change your password immediately.');
    }
}
