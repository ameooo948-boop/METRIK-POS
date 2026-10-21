<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
        return ['database'];
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
