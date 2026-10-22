<?php

namespace App\Console\Commands;

use App\Support\BackupArchiveVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckBackup extends Command
{
    protected $signature = 'metrik:backup-check {--max-age= : Maximum age of the latest backup in hours}';

    protected $description = 'Verify the latest Metrik backup archive and fail if it is missing or stale';

    public function handle(BackupArchiveVerifier $verifier): int
    {
        $directory = trim((string) config('backup.directory', '')) ?: storage_path('app/private/backups');
        $maxAgeHours = max(1, (int) ($this->option('max-age') ?: config('backup.max_age_hours', 26)));
        $archives = glob($directory.DIRECTORY_SEPARATOR.'metrik-backup_*.zip') ?: [];

        if ($archives === []) {
            return $this->failure('No Metrik backup archive was found.');
        }

        usort($archives, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $archives[0];
        $ageSeconds = max(0, now()->timestamp - (int) filemtime($latest));
        $ageHours = $ageSeconds / 3600;

        try {
            $manifest = $verifier->verify($latest);
            if ($ageHours > $maxAgeHours) {
                return $this->failure(sprintf(
                    'Latest backup is stale: %s is %.1f hours old (maximum %.1f hours).',
                    basename($latest),
                    $ageHours,
                    (float) $maxAgeHours,
                ));
            }

            $this->info(sprintf(
                'PASS Latest backup verified: %s (%.1f hours old; format v%d).',
                basename($latest),
                $ageHours,
                (int) ($manifest['format_version'] ?? 1),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->failure('Latest backup could not be verified: '.$exception->getMessage());
        }
    }

    private function failure(string $message): int
    {
        $this->error('FAIL '.$message);
        Log::critical('Metrik backup check failed', ['message' => $message]);

        return self::FAILURE;
    }
}
