<?php

namespace App\Console\Commands;

use App\Support\BackupArchiveVerifier;
use App\Support\MysqlDumpBinaryLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class RunBackup extends Command
{
    protected $signature = 'metrik:backup {--keep= : Override retention count}';

    protected $description = 'Create and verify a compressed database and application-file backup, then prune old backups';

    public function handle(BackupArchiveVerifier $verifier, MysqlDumpBinaryLocator $dumpBinaryLocator): int
    {
        $startedAt = now();
        // An empty environment variable must not override the secure default path.
        $directory = trim((string) config('backup.directory', '')) ?: storage_path('app/private/backups');
        if (! str_starts_with($directory, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $directory) !== 1) {
            $directory = base_path($directory);
        }
        $retention = max(1, (int) ($this->option('keep') ?: config('backup.keep', 14)));
        $working = null;
        $temporaryArchive = null;

        try {
            if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
                throw new \RuntimeException('The PHP zip extension is required to create backups.');
            }

            $this->assertBackupDestinationCandidateSafe($directory);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Cannot create backup directory: {$directory}");
            }
            if (! is_writable($directory)) {
                throw new \RuntimeException("Backup directory is not writable: {$directory}");
            }
            $this->assertBackupDestinationSafe($directory);

            // A short random suffix prevents same-second manual/scheduled runs overwriting one another.
            $stamp = $startedAt->format('Ymd_His').'_'.bin2hex(random_bytes(4));
            $working = $directory.DIRECTORY_SEPARATOR.'.working_'.$stamp.'_'.bin2hex(random_bytes(4));
            if (! mkdir($working, 0700, true)) {
                throw new \RuntimeException('Cannot create temporary backup directory.');
            }

            $connection = (string) config('database.default');
            $activeConfig = DB::connection($connection)->getConfig();
            $databaseExtension = ($activeConfig['driver'] ?? null) === 'sqlite' ? 'sqlite' : 'sql';
            $databasePath = $working.DIRECTORY_SEPARATOR.'database.'.$databaseExtension;
            $this->dumpDatabase($databasePath, $dumpBinaryLocator);
            if (! is_file($databasePath) || filesize($databasePath) === 0) {
                throw new \RuntimeException('Database backup output is missing or empty.');
            }

            $this->copyConfiguredFiles($working);
            $manifest = [
                'application' => (string) config('app.name'),
                'created_at' => $startedAt->toIso8601String(),
                'database_connection' => $connection,
                'database_artifact' => 'database.'.$databaseExtension,
                'included_paths' => config('backup.paths', []),
                'format_version' => 2,
                'checksums' => $this->checksumFiles($working),
            ];
            $manifestPath = $working.DIRECTORY_SEPARATOR.'manifest.json';
            if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new \RuntimeException('Could not write backup manifest.');
            }
            @chmod($manifestPath, 0600);

            $filename = 'metrik-backup_'.$stamp.'.zip';
            $publishedArchive = $directory.DIRECTORY_SEPARATOR.$filename;
            $temporaryArchive = $directory.DIRECTORY_SEPARATOR.'.'.$filename.'.tmp';
            $archive = new ZipArchive();
            if ($archive->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create backup archive.');
            }
            @chmod($temporaryArchive, 0600);
            try {
                $this->addDirectoryToZip($archive, $working);
                if (! $archive->close()) {
                    throw new \RuntimeException('Could not finalize backup archive.');
                }
            } catch (Throwable $exception) {
                $archive->close();
                throw $exception;
            }

            // Check the completed archive before making it visible as the latest usable backup.
            $verifier->verify($temporaryArchive);
            @chmod($temporaryArchive, 0600);
            if (! rename($temporaryArchive, $publishedArchive)) {
                throw new \RuntimeException('Could not atomically publish the verified backup archive.');
            }
            $temporaryArchive = null;

            $this->pruneOldBackups($directory, $retention);
            Log::info('Metrik backup completed', [
                'file' => $filename,
                'format_version' => 2,
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);
            $this->info("Backup completed and verified: {$filename}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::critical('Metrik backup failed', [
                'message' => $exception->getMessage(),
                'started_at' => $startedAt->toIso8601String(),
            ]);
            $this->error('Backup failed: '.$exception->getMessage());
            $this->line('Detailed context was written to the Laravel application log.');
            if ($this->getOutput()->isVerbose()) {
                $this->line($exception->getTraceAsString());
            }
            report($exception);

            return self::FAILURE;
        } finally {
            if (is_string($working)) {
                $this->removeDirectory($working);
            }
            if (is_string($temporaryArchive) && is_file($temporaryArchive)) {
                @unlink($temporaryArchive);
            }
        }
    }

    private function dumpDatabase(string $destination, MysqlDumpBinaryLocator $dumpBinaryLocator): void
    {
        $connection = (string) config('database.default');
        $activeConnection = DB::connection($connection);
        // Use Laravel's resolved connection config so DB_URL/DATABASE_URL are respected.
        $config = $activeConnection->getConfig();
        $driver = $config['driver'] ?? null;

        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? '');
            if ($database === '' || $database === ':memory:') {
                throw new \RuntimeException('SQLite backup requires a file-backed database; in-memory databases cannot be backed up.');
            }
            if (! str_starts_with($database, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $database) !== 1) {
                $database = base_path($database);
            }
            if (! is_file($database) || ! is_readable($database)) {
                throw new \RuntimeException('Configured SQLite database file does not exist or is not readable.');
            }

            // VACUUM INTO creates a consistent standalone snapshot and includes committed WAL data.
            // Do not use this while the current connection is inside a transaction.
            if ($activeConnection->transactionLevel() > 0) {
                throw new \RuntimeException('Cannot back up SQLite while a database transaction is active.');
            }
            $quotedDestination = str_replace("'", "''", $destination);
            DB::connection($connection)->getPdo()->exec("VACUUM INTO '{$quotedDestination}'");
            @chmod($destination, 0600);

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Unsupported backup database driver: '.(string) ($driver ?? 'unknown'));
        }

        $binary = $dumpBinaryLocator->resolve((string) config('backup.mysqldump_binary', 'mysqldump'));
        $args = [$binary, '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--events', '--triggers'];
        if (! empty($config['host'])) $args[] = '--host='.$config['host'];
        if (! empty($config['port'])) $args[] = '--port='.$config['port'];
        if (! empty($config['unix_socket'])) $args[] = '--socket='.$config['unix_socket'];
        if (! empty($config['username'])) $args[] = '--user='.$config['username'];
        $databaseName = trim((string) ($config['database'] ?? ''));
        if ($databaseName === '') {
            throw new \RuntimeException('MySQL backup requires a configured database name.');
        }
        $args[] = $databaseName;

        $stream = fopen($destination, 'xb');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Could not create database dump output.');
        }
        @chmod($destination, 0600);
        $stderr = '';
        $writeFailure = false;
        try {
            // Stream stdout directly to disk to avoid holding a large dump in PHP memory.
            $process = new Process($args, base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout(max(30, (int) config('backup.dump_timeout', 300)));
            $exitCode = $process->run(function (string $type, string $buffer) use ($stream, &$stderr, &$writeFailure): void {
                if ($type === Process::ERR) {
                    $stderr = substr($stderr.$buffer, -8000);
                    return;
                }
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $written = fwrite($stream, substr($buffer, $offset));
                    if ($written === false || $written === 0) {
                        $writeFailure = true;
                        return;
                    }
                    $offset += $written;
                }
            });
            if (! fflush($stream)) {
                $writeFailure = true;
            }
        } finally {
            fclose($stream);
        }

        if ($writeFailure) {
            throw new \RuntimeException('Could not write the complete database dump.');
        }
        if ($exitCode !== 0 || ! is_file($destination) || filesize($destination) === 0) {
            throw new \RuntimeException('mysqldump failed: '.trim($stderr));
        }
    }

    private function copyConfiguredFiles(string $working): void
    {
        $filesRoot = $working.DIRECTORY_SEPARATOR.'files';
        foreach ((array) config('backup.paths', []) as $relative) {
            $relative = trim((string) $relative);
            if ($relative === '') {
                continue;
            }
            $relativeNormalized = str_replace('\\', '/', $relative);
            if (str_starts_with($relativeNormalized, '/') || preg_match('/^[A-Za-z]:/', $relativeNormalized) === 1
                || str_contains($relativeNormalized, "\0")
                || in_array('..', explode('/', $relativeNormalized), true)) {
                throw new \RuntimeException('METRIK_BACKUP_PATHS must contain safe project-relative paths only.');
            }
            $source = base_path($relativeNormalized);
            if (! file_exists($source) || is_link($source)) {
                continue;
            }
            $destination = $filesRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            if (is_file($source)) {
                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
                    throw new \RuntimeException("Could not create backup directory for {$relative}");
                }
                if (! copy($source, $destination)) {
                    throw new \RuntimeException("Could not back up {$relative}");
                }
                @chmod($destination, 0600);
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $item) {
                // Do not follow or copy symlinks: they may point outside the declared backup scope.
                if ($item->isLink()) {
                    continue;
                }
                $path = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
                if ($item->isDir()) {
                    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
                        throw new \RuntimeException("Could not create backup directory for {$relative}");
                    }
                } elseif ($item->isFile()) {
                    if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
                        throw new \RuntimeException("Could not create backup directory for {$relative}");
                    }
                    if (! copy($item->getPathname(), $path)) {
                        throw new \RuntimeException("Could not back up {$relative}");
                    }
                    @chmod($path, 0600);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function checksumFiles(string $directory): array
    {
        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            $hash = hash_file('sha256', $file->getPathname());
            if (! is_string($hash)) {
                throw new \RuntimeException("Could not hash backup file {$relative}");
            }
            $checksums[$relative] = $hash;
        }
        ksort($checksums);

        return $checksums;
    }

    private function addDirectoryToZip(ZipArchive $archive, string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            if (! $archive->addFile($file->getPathname(), $path)) {
                throw new \RuntimeException("Could not add {$path} to backup archive.");
            }
        }
    }

    private function assertBackupDestinationCandidateSafe(string $directory): void
    {
        $realPublic = realpath(public_path());
        if ($realPublic !== false && $this->isSameOrChildPath($directory, $realPublic)) {
            throw new \RuntimeException('Backup destination must be outside the public web root.');
        }

        foreach ((array) config('backup.paths', []) as $relative) {
            $relative = trim((string) $relative);
            if ($relative === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $relative);
            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)) {
                throw new \RuntimeException('METRIK_BACKUP_PATHS must contain safe project-relative paths only.');
            }
            $source = realpath(base_path($normalized));
            if ($source !== false && $this->isSameOrChildPath($directory, $source)) {
                throw new \RuntimeException('Backup destination must not be inside a path included in the backup.');
            }
        }
    }

    private function assertBackupDestinationSafe(string $directory): void
    {
        $realBackup = realpath($directory);
        $realPublic = realpath(public_path());
        if ($realBackup === false) {
            throw new \RuntimeException('Could not resolve backup destination.');
        }
        if ($realPublic !== false && $this->isSameOrChildPath($realBackup, $realPublic)) {
            throw new \RuntimeException('Backup destination must be outside the public web root.');
        }

        foreach ((array) config('backup.paths', []) as $relative) {
            $relative = trim((string) $relative);
            if ($relative === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $relative);
            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)) {
                throw new \RuntimeException('METRIK_BACKUP_PATHS must contain safe project-relative paths only.');
            }
            $source = realpath(base_path($normalized));
            if ($source !== false && $this->isSameOrChildPath($realBackup, $source)) {
                throw new \RuntimeException('Backup destination must not be inside a path included in the backup.');
            }
        }
    }

    private function isSameOrChildPath(string $candidate, string $parent): bool
    {
        $normalize = static fn (string $path): string => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $candidate = $normalize($candidate);
        $parent = $normalize($parent);
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $parent = strtolower($parent);
        }

        return $candidate === $parent || str_starts_with($candidate, $parent.DIRECTORY_SEPARATOR);
    }

    private function pruneOldBackups(string $directory, int $keep): void
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'metrik-backup_*.zip') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $keep) as $file) {
            if (! @unlink($file)) {
                Log::warning('Could not remove expired Metrik backup', ['file' => basename($file)]);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
