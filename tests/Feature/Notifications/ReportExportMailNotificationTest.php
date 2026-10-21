<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\ReportExportReady;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class ReportExportMailNotificationTest extends TestCase
{
    public function test_report_export_email_is_disabled_by_default(): void
    {
        config(['metrik.notifications.report_export_email_enabled' => false]);
        $user = new User(['name' => 'Test User', 'email' => 'test@example.com']);
        $notification = new ReportExportReady(1, 'https://example.test/reports/1', '2026-10-01', '2026-10-21');

        $this->assertSame(['database'], $notification->via($user));
    }

    public function test_report_export_email_can_be_enabled_and_has_a_download_action(): void
    {
        config(['metrik.notifications.report_export_email_enabled' => true]);
        $user = new User(['name' => 'Test User', 'email' => 'test@example.com']);
        $notification = new ReportExportReady(7, 'https://example.test/reports/7', '2026-10-01', '2026-10-21');

        $this->assertSame(['database', 'mail'], $notification->via($user));
        $mail = $notification->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame('تقرير Metrik POS جاهز للتنزيل', $mail->subject);
        $this->assertSame('تنزيل التقرير', $mail->actionText);
        $this->assertSame('https://example.test/reports/7', $mail->actionUrl);
    }
}
