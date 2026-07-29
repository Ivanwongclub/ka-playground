<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * S04C STEP 2 (OD-29) — sent when a self-registration is APPROVED. Carries the
 * single-use activation link (verify address + set password in one act). The
 * link opens the public activation page; only the plaintext token travels here,
 * to the applicant's own not-yet-verified address. Real delivery channels/ladders
 * are S09; this is the message they will carry.
 */
class AccountActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function activationUrl(): string
    {
        return rtrim((string) config('app.public_url'), '/')."/activate/{$this->token}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activate your KA Playground account')
            ->greeting('Welcome to Armour Academy')
            ->line('Your registration has been approved. To finish setting up your account, verify your email and choose a password.')
            ->action('Activate your account', $this->activationUrl())
            ->line('This link is single-use and expires in 7 days. If you did not register, you can ignore this email.');
    }
}
