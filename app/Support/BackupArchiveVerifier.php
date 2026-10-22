<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Validates a Metrik backup archive before it is trusted or restored.
 * Format v1 is accepted for compatibility with backups created by earlier builds;
 * format v2 requires a SHA-256 checksum for every payload file.
 */
class BackupArchiveVerifier
{
    /** @return array<string, mixed> */
    public function verify(string $archivePath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to verify backup archives.');
        }

        if (! is_file($archivePath) || ! is_readable($archivePath) || filesize($archivePath) === 0) {
            throw new RuntimeException('Backup archive does not exist, is unreadable, or is empty.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('Backup archive failed ZIP consistency validation.');
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson) || $manifestJson === '') {
                throw new RuntimeException('Backup manifest.json is missing or unreadable.');
            }

            try {
                $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Backup manifest is invalid JSON.', previous: $exception);
            }

            if (! is_array($manifest) || ! in_array((int) ($manifest['format_version'] ?? 0), [1, 2], true)) {
                throw new RuntimeException('Unsupported backup format version.');
            }

            $databaseEntries = array_values(array_filter(
                ['database.sqlite', 'database.sql'],
                fn (string $entry): bool => $zip->locateName($entry) !== false,
            ));
            if (count($databaseEntries) !== 1) {
                throw new RuntimeException('Backup must contain exactly one database snapshot or dump.');
            }
            if (isset($manifest['database_artifact']) && $manifest['database_artifact'] !== $databaseEntries[0]) {
                throw new RuntimeException('Backup manifest database_artifact does not match the actual database file.');
            }

            $this->assertSafeArchiveEntries($zip);

            $checksums = $manifest['checksums'] ?? null;
            if ((int) $manifest['format_version'] >= 2) {
                if (! is_array($checksums) || $checksums === []) {
                    throw new RuntimeException('Backup checksum manifest is missing.');
                }

                foreach ($checksums as $entry => $expectedHash) {
                    if (! is_string($entry) || ! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/i', $expectedHash) !== 1) {
                        throw new RuntimeException('Backup checksum manifest contains an invalid entry.');
                    }
                    $this->assertSafeEntryName($entry);
                    if ($entry === 'manifest.json' || $zip->locateName($entry) === false) {
                        throw new RuntimeException('Backup checksum references a missing or invalid archive entry.');
                    }
                    $actualHash = $this->hashZipEntry($zip, $entry);
                    if (! hash_equals(strtolower($expectedHash), $actualHash)) {
                        throw new RuntimeException("Backup checksum verification failed for {$entry}.");
                    }
                }

                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->getNameIndex($index);
                    if (! is_string($entry) || str_ends_with($entry, '/') || $entry === 'manifest.json') {
                        continue;
                    }
                    if (! array_key_exists($entry, $checksums)) {
                        throw new RuntimeException("Backup file {$entry} has no checksum in the manifest.");
                    }
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function assertSafeArchiveEntries(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            if (! is_string($entry)) {
                throw new RuntimeException('Backup contains an invalid archive entry.');
            }

            $this->assertSafeEntryName($entry);
            if (str_ends_with($entry, '/')) {
                continue;
            }
            if ($entry !== 'manifest.json'
                && $entry !== 'database.sqlite'
                && $entry !== 'database.sql'
                && ! str_starts_with($entry, 'files/')) {
                throw new RuntimeException("Unexpected file in backup archive: {$entry}");
            }
        }
    }

    private function assertSafeEntryName(string $entry): void
    {
        $normalized = str_replace('\\', '/', $entry);
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || str_contains($normalized, "\0")) {
            throw new RuntimeException('Backup contains an unsafe archive path.');
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new RuntimeException('Backup contains a path traversal entry.');
            }
        }
    }

    private function hashZipEntry(ZipArchive $zip, string $entry): string
    {
        $stream = $zip->getStream($entry);
        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read {$entry} from backup archive.");
        }

        $context = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Could not read {$entry} from backup archive.");
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }
}
