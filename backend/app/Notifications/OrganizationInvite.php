<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $group, public readonly string $inviter, public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviter} invited you to LinguaForge")
            ->line("{$this->inviter} invited you to join “{$this->group}” and learn languages with their family plan.")
            ->action('Accept invitation', $this->url)
            ->line('The invitation expires in '.config('billing.invitation_ttl_days').' days. If you were not expecting it, ignore this email.');
    }
}
