<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves mysqldump without depending on the web server/PHP process inheriting
 * the interactive user's PATH (a common issue with XAMPP and Windows services).
 */
final class MysqlDumpBinaryLocator
{
    public function resolve(?string $configuredBinary = null): string
    {
        $configuredBinary = trim((string) $configuredBinary);
        if ($configuredBinary === '') {
            $configuredBinary = 'mysqldump';
        }

        $isExplicitPath = str_contains($configuredBinary, '/')
            || str_contains($configuredBinary, '\\')
            || preg_match('/^[A-Za-z]:/', $configuredBinary) === 1;

        if ($isExplicitPath) {
            if (is_file($configuredBinary)) {
                return $configuredBinary;
            }

            throw new RuntimeException(
                'Configured MySQL dump executable was not found at "'.$configuredBinary
                .'". Set METRIK_MYSQLDUMP_BINARY to the full path of mysqldump.exe or mariadb-dump.exe.'
            );
        }

        $found = (new ExecutableFinder())->find($configuredBinary);
        if (is_string($found) && $found !== '') {
            return $found;
        }

        foreach ($this->platformCandidates() as $candidate) {
            if (is_file($candidate) && ! is_dir($candidate)) {
                return $candidate;
            }
        }

        $platformHint = PHP_OS_FAMILY === 'Windows'
            ? ' For XAMPP, try METRIK_MYSQLDUMP_BINARY=C:/xampp/mysql/bin/mysqldump.exe.'
            : ' Install the MySQL/MariaDB client tools and ensure mysqldump is on PATH, or set METRIK_MYSQLDUMP_BINARY to its full path.';

        throw new RuntimeException(
            'Could not find the MySQL dump executable "'.$configuredBinary
            .'". Set METRIK_MYSQLDUMP_BINARY to the full path of mysqldump or mariadb-dump.'
            .$platformHint
        );
    }

    /** @return list<string> */
    private function platformCandidates(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $candidates = [];
        $systemDrive = rtrim((string) (getenv('SystemDrive') ?: 'C:'), '\\/');
        $xamppRoots = array_unique(array_filter([
            trim((string) getenv('XAMPP_ROOT')),
            $systemDrive.'/xampp',
            'C:/xampp',
        ]));

        foreach ($xamppRoots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $candidates[] = $root.'/mysql/bin/mysqldump.exe';
            $candidates[] = $root.'/mysql/bin/mariadb-dump.exe';
        }

        $programFiles = rtrim(str_replace('\\', '/', (string) (getenv('ProgramFiles') ?: 'C:/Program Files')), '/');
        $programFilesX86 = rtrim(str_replace('\\', '/', (string) (getenv('ProgramFiles(x86)') ?: 'C:/Program Files (x86)')), '/');
        $patterns = [
            $programFiles.'/MySQL/MySQL Server */bin/mysqldump.exe',
            $programFilesX86.'/MySQL/MySQL Server */bin/mysqldump.exe',
            $programFiles.'/MariaDB */bin/mysqldump.exe',
            $programFiles.'/MariaDB */bin/mariadb-dump.exe',
            'C:/laragon/bin/mysql/*/bin/mysqldump.exe',
            'C:/laragon/bin/mariadb/*/bin/mariadb-dump.exe',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern) ?: [];
            rsort($matches, SORT_NATURAL);
            array_push($candidates, ...$matches);
        }

        return array_values(array_unique($candidates));
    }
}
