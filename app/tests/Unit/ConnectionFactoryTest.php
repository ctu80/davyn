<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/davyn-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DB_PATH');
        foreach (['', '-wal', '-shm'] as $suffix) {
            $f = $this->dbPath . $suffix;
            if (is_file($f)) @unlink($f);
        }
    }

    public function testConnectionEnablesHardeningPragmas(): void
    {
        $pdo = ConnectionFactory::create(new Config());

        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'FK enforcement must be ON');
        // synchronous NORMAL == 1
        self::assertSame(1, (int) $pdo->query('PRAGMA synchronous')->fetchColumn());
    }
}
