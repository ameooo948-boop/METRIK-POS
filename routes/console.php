<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Daily backup plus an hourly freshness/integrity check. Cron/Task Scheduler must run
// `php artisan schedule:run` every minute on the application host.
$backupSchedule = Schedule::command('metrik:backup')
    ->dailyAt(config('backup.schedule', '02:15'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/metrik-backup.log'))
    ->onFailure(static function (): void {
        logger()->critical('Scheduled Metrik backup command failed.');
    });

$backupCheckSchedule = Schedule::command('metrik:backup-check')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/metrik-backup-check.log'))
    ->onFailure(static function (): void {
        logger()->critical('Scheduled Metrik backup freshness/integrity check failed.');
    });

$backupAlertEmail = config('backup.alert_email');
if (is_string($backupAlertEmail) && filter_var($backupAlertEmail, FILTER_VALIDATE_EMAIL)) {
    $backupSchedule->emailOutputOnFailure($backupAlertEmail);
    $backupCheckSchedule->emailOutputOnFailure($backupAlertEmail);
}
