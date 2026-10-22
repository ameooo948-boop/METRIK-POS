<?php

namespace App\Console\Commands;

use App\Support\BackupArchiveVerifier;
use Illuminate\Console\Command;
use Throwable;
use ZipArchive;

class RestoreBackup extends Command
{
    protected $signature = 'metrik:restore {archive : Path to a Metrik backup ZIP} {--destination= : Empty isolated directory to extract the verified restore payload} {--verify-only : Verify the archive without extracting it}';

    protected $description = 'Verify a backup and optionally extract it to an isolated restore directory; never overwrites the configured database';

    public function handle(BackupArchiveVerifier $verifier): int
    {
        $destination = null;
        $createdDestination = false;
        $archivePath = (string) $this->argument('archive');
        if (! is_file($archivePath)) {
            $this->error('Backup archive does not exist.');

            return self::FAILURE;
        }

        try {
            $manifest = $verifier->verify($archivePath);
            $this->info('PASS Backup archive integrity and supported checksums verified.');
            $this->line('Created at: '.(string) ($manifest['created_at'] ?? 'unknown'));
            $this->line('Database artifact: '.(string) ($manifest['database_artifact'] ?? 'legacy format; detect from archive contents'));

            if ($this->option('verify-only')) {
                return self::SUCCESS;
            }

            $destinationOption = trim((string) $this->option('destination'));
            if ($destinationOption === '') {
                $this->error('Provide --destination with a new or empty isolated directory. Production database files are never replaced by this command.');

                return self::FAILURE;
            }

            $destination = $this->normalizeDestination($destinationOption);
            $this->assertDestinationIsSafe($destination);
            $destinationExisted = file_exists($destination);
            if ($destinationExisted && (! is_dir($destination) || count(scandir($destination) ?: []) > 2)) {
                $this->error('Restore destination already exists and is not empty. Choose an isolated empty destination.');

                return self::FAILURE;
            }
            if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
                throw new \RuntimeException('Could not create restore destination directory.');
            }
            $createdDestination = ! $destinationExisted;

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
                throw new \RuntimeException('Could not reopen the verified backup archive.');
            }
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->getNameIndex($index);
                    if (! is_string($entry) || str_ends_with($entry, '/') || $entry === 'manifest.json') {
                        continue;
                    }
                    $normalized = str_replace('\\', '/', $entry);
                    $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
                    $parent = dirname($target);
                    if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                        throw new \RuntimeException("Could not create restore directory for {$normalized}.");
                    }
                    $input = $zip->getStream($entry);
                    if (! is_resource($input)) {
                        throw new \RuntimeException("Could not read {$normalized} from archive.");
                    }
                    $output = fopen($target, 'xb');
                    if (! is_resource($output)) {
                        fclose($input);
                        throw new \RuntimeException("Could not create restored file {$normalized}.");
                    }
                    try {
                        while (! feof($input)) {
                            $chunk = fread($input, 1024 * 1024);
                            if ($chunk === false) {
                                throw new \RuntimeException("Could not read {$normalized} from archive.");
                            }
                            if ($chunk === '') {
                                continue;
                            }
                            $offset = 0;
                            $length = strlen($chunk);
                            while ($offset < $length) {
                                $written = fwrite($output, substr($chunk, $offset));
                                if ($written === false || $written === 0) {
                                    throw new \RuntimeException("Could not write restored file {$normalized}.");
                                }
                                $offset += $written;
                            }
                        }
                        if (! fflush($output)) {
                            throw new \RuntimeException("Could not flush restored file {$normalized}.");
                        }
                    } finally {
                        fclose($input);
                        fclose($output);
                    }
                    @chmod($target, 0600);
                }
            } finally {
                $zip->close();
            }

            foreach ((array) ($manifest['checksums'] ?? []) as $entry => $expectedHash) {
                $restoredFile = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $entry);
                $actualHash = is_file($restoredFile) ? hash_file('sha256', $restoredFile) : false;
                if (! is_string($actualHash) || ! hash_equals(strtolower((string) $expectedHash), $actualHash)) {
                    throw new \RuntimeException('Restored file checksum verification failed for '.(string) $entry.'.');
                }
            }

            $databaseArtifact = (string) ($manifest['database_artifact'] ?? (is_file($destination.DIRECTORY_SEPARATOR.'database.sqlite') ? 'database.sqlite' : 'database.sql'));
            if ($databaseArtifact === 'database.sqlite') {
                if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                    throw new \RuntimeException('The PDO SQLite driver is required to validate a restored SQLite database.');
                }
                $restoredDatabase = $destination.DIRECTORY_SEPARATOR.'database.sqlite';
                $pdo = new \PDO('sqlite:'.$restoredDatabase);
                $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
                $pdo = null;
                if ($integrity !== 'ok') {
                    throw new \RuntimeException('Restored SQLite database failed PRAGMA integrity_check.');
                }
                $this->info('PASS Restored SQLite database integrity_check.');
            }

            $this->newLine();
            $this->info('Restore payload extracted and validated successfully to: '.$destination);
            $this->warn('This command does not import database.sql or switch the live application database. Validate the extracted snapshot in an isolated staging environment first.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($createdDestination && is_string($destination)) {
                $this->removeDirectory($destination);
            }
            report($exception);
            $this->error('Restore verification/extraction failed. Check the application log for details.');

            return self::FAILURE;
        }
    }

    private function normalizeDestination(string $destination): string
    {
        if (! str_starts_with($destination, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $destination) !== 1) {
            $destination = base_path($destination);
        }

        return rtrim($destination, DIRECTORY_SEPARATOR);
    }

    private function assertDestinationIsSafe(string $destination): void
    {
        if ($destination === '' || $destination === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:$/', $destination) === 1 || str_contains($destination, "\0")) {
            throw new \RuntimeException('Restore destination path is invalid.');
        }

        $realPublic = realpath(public_path());
        if ($realPublic !== false && $this->isSameOrChildPath($destination, $realPublic)) {
            throw new \RuntimeException('Restore destination must be outside the public web root.');
        }

        $parent = dirname($destination);
        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new \RuntimeException('Could not create parent directory for restore destination.');
        }
        $realParent = realpath($parent);
        $realPublic = realpath(public_path());
        if ($realParent === false) {
            throw new \RuntimeException('Could not resolve restore destination parent directory.');
        }
        $candidate = rtrim($realParent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($destination);
        if ($realPublic !== false && $this->isSameOrChildPath($candidate, $realPublic)) {
            throw new \RuntimeException('Restore destination must be outside the public web root.');
        }
        if (is_link($destination)) {
            throw new \RuntimeException('Restore destination must not be a symbolic link.');
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
}
