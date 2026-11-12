<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing, family and achievement notices: emailed (billing only) and kept in-app (database channel).
 * `kind` drives the in-app copy, which the frontend localises; the mail copy is English.
 */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable;

    private const MAIL = [
        'subscription_activated' => ['Your subscription is active', 'Thank you! Your :plan plan is now active.'],
        'subscription_cancel_scheduled' => ['Your subscription will end', 'Your :plan plan will end on :date. You keep full access until then and can resume any time before.'],
        'subscription_resumed' => ['Your subscription continues', 'Your :plan plan will renew as usual.'],
        'subscription_ended' => ['Your subscription has ended', 'Your :plan plan has ended. Your progress is kept and you can continue on the Free plan.'],
        'payment_failed' => ['Payment failed', 'We could not collect the payment for your :plan plan. Please update your payment method to keep access.'],
        'payment_succeeded' => ['Payment received', 'We received your payment for the :plan plan. Thank you!'],
    ];

    /** @param array<string, string|int|null> $data */
    public function __construct(public readonly string $kind, public readonly array $data = []) {}

    public function via(object $notifiable): array
    {
        return isset(self::MAIL[$this->kind]) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = self::MAIL[$this->kind];
        $replace = collect($this->data)->mapWithKeys(fn ($v, $k) => [":{$k}" => (string) $v])->all();

        return (new MailMessage)
            ->subject($subject)
            ->line(strtr($line, $replace))
            ->action('Manage your plan', rtrim(config('app.frontend_url'), '/').'/billing');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, ...$this->data];
    }
}
