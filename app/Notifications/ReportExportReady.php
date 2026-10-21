<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportExportReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $reportExportId,
        public readonly string $downloadUrl,
        public readonly string $fromDate,
        public readonly string $toDate,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('metrik.notifications.report_export_email_enabled', false)
            && filled($notifiable->routeNotificationFor('mail', $this))) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = filled($notifiable->name ?? null) ? (string) $notifiable->name : 'بك';

        return (new MailMessage)
            ->subject('تقرير Metrik POS جاهز للتنزيل')
            ->greeting('مرحبًا '.$greetingName)
            ->line(sprintf('تم تجهيز تقرير المبيعات للفترة من %s إلى %s.', $this->fromDate, $this->toDate))
            ->action('تنزيل التقرير', $this->downloadUrl)
            ->line('احتفظ بهذه الرسالة للرجوع إلى رابط التقرير عند الحاجة.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'report_export_ready',
            'title' => 'التقرير جاهز للتنزيل',
            'message' => sprintf('تم تجهيز تقرير الفترة من %s إلى %s.', $this->fromDate, $this->toDate),
            'report_export_id' => $this->reportExportId,
            'url' => $this->downloadUrl,
        ];
    }
}
