<?php

$directory = trim((string) env('METRIK_BACKUP_DIRECTORY', ''));
$schedule = trim((string) env('METRIK_BACKUP_SCHEDULE', '02:15'));
if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $schedule) !== 1) {
    $schedule = '02:15';
}

return [
    // Empty/unset env values fall back to a private directory outside public/.
    'directory' => $directory !== '' ? $directory : storage_path('app/private/backups'),
    'keep' => max(1, (int) env('METRIK_BACKUP_KEEP', 14)),
    // Alerting requires a working mail transport and a monitored recipient.
    'alert_email' => trim((string) env('METRIK_BACKUP_ALERT_EMAIL', '')) ?: null,
    'max_age_hours' => max(1, (int) env('METRIK_BACKUP_MAX_AGE_HOURS', 26)),
    // Include only user-generated/business files required to recover the system.
    'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'METRIK_BACKUP_PATHS',
        'storage/app/public,storage/app/private/report-exports',
    ))))),
    'mysqldump_binary' => trim((string) env('METRIK_MYSQLDUMP_BINARY', 'mysqldump')) ?: 'mysqldump',
    'dump_timeout' => max(30, (int) env('METRIK_BACKUP_DUMP_TIMEOUT', 300)),
    'schedule' => $schedule,
];
