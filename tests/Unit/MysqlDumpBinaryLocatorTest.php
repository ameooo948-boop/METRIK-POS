<?php

namespace Tests\Unit;

use App\Support\MysqlDumpBinaryLocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MysqlDumpBinaryLocatorTest extends TestCase
{
    public function test_it_returns_a_configured_existing_executable_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'metrik-mysqldump-');
        self::assertIsString($path);

        try {
            self::assertSame($path, (new MysqlDumpBinaryLocator())->resolve($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_configured_executable_has_actionable_guidance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('METRIK_MYSQLDUMP_BINARY');

        (new MysqlDumpBinaryLocator())->resolve('C:/missing-metrik-test/mysqldump.exe');
    }
}
