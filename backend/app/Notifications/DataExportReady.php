<?php

namespace App\Notifications;

use App\Models\DataExportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataExportReady extends Notification
{
    use Queueable;

    public function __construct(public readonly DataExportRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your data export is ready')
            ->line('Your personal data export is ready. Sign in and open Profile → Privacy to download it.')
            ->action('Open privacy settings', rtrim(config('app.frontend_url'), '/').'/profile')
            ->line('The download link expires in '.config('learning.data_export_ttl_hours').' hours.');
    }
}
