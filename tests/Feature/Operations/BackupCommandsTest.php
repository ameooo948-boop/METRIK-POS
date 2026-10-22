<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;
use ZipArchive;

class BackupCommandsTest extends TestCase
{
    public function test_sqlite_backup_is_verified_and_can_be_extracted_without_overwriting_the_live_database(): void
    {
        if (! extension_loaded('pdo_sqlite') || ! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Backup integration test requires pdo_sqlite and zip extensions.');
        }

        $root = storage_path('framework/testing/metrik-backup-'.Str::uuid());
        $databasePath = $root.DIRECTORY_SEPARATOR.'source.sqlite';
        $backupDirectory = $root.DIRECTORY_SEPARATOR.'backups';
        $restoreDirectory = $root.DIRECTORY_SEPARATOR.'restore';
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        try {
            $pdo = new PDO('sqlite:'.$databasePath);
            $pdo->exec('CREATE TABLE backup_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $pdo->exec("INSERT INTO backup_probe (value) VALUES ('restore-ok')");
            $pdo = null;

            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $databasePath,
                'backup.directory' => $backupDirectory,
                'backup.paths' => [],
                'backup.keep' => 2,
                'backup.max_age_hours' => 24,
            ]);
            DB::purge('sqlite');

            $this->artisan('metrik:backup')->assertSuccessful();
            $archives = glob($backupDirectory.DIRECTORY_SEPARATOR.'metrik-backup_*.zip') ?: [];
            $this->assertCount(1, $archives);

            $this->artisan('metrik:backup-check', ['--max-age' => 24])->assertSuccessful();

            $corruptArchive = $root.DIRECTORY_SEPARATOR.'corrupt.zip';
            $this->assertTrue(copy($archives[0], $corruptArchive));
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($corruptArchive) === true);
            $this->assertTrue($zip->deleteName('database.sqlite'));
            $this->assertTrue($zip->addFromString('database.sqlite', 'not-a-database'));
            $this->assertTrue($zip->close());
            $this->artisan('metrik:restore', [
                'archive' => $corruptArchive,
                '--verify-only' => true,
            ])->assertExitCode(1);

            $publicRestoreDirectory = public_path('metrik-restore-test-'.Str::uuid());
            $this->artisan('metrik:restore', [
                'archive' => $archives[0],
                '--destination' => $publicRestoreDirectory,
            ])->assertExitCode(1);
            $this->assertDirectoryDoesNotExist($publicRestoreDirectory);

            $this->artisan('metrik:restore', [
                'archive' => $archives[0],
                '--destination' => $restoreDirectory,
            ])->assertSuccessful();

            $restoredPath = $restoreDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
            $this->assertFileExists($restoredPath);
            $restored = new PDO('sqlite:'.$restoredPath);
            $value = $restored->query('SELECT value FROM backup_probe WHERE id = 1')->fetchColumn();
            $this->assertSame('restore-ok', $value);
            $restored = null;

            // Verify the configured source path was not replaced by the restore command.
            $source = new PDO('sqlite:'.$databasePath);
            $this->assertSame('restore-ok', $source->query('SELECT value FROM backup_probe WHERE id = 1')->fetchColumn());
            $source = null;
        } finally {
            DB::purge('sqlite');
            $this->removeTestDirectory($root);
        }
    }

    public function test_backup_check_fails_when_no_archive_exists(): void
    {
        $directory = storage_path('framework/testing/metrik-backup-empty-'.Str::uuid());
        config(['backup.directory' => $directory]);

        try {
            $this->artisan('metrik:backup-check')
                ->expectsOutputToContain('No Metrik backup archive was found.')
                ->assertExitCode(1);
        } finally {
            $this->removeTestDirectory($directory);
        }
    }

    private function removeTestDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
