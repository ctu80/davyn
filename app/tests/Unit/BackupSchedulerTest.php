<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Backup\BackupScheduler;
use Davyn\Backup\BackupService;
use Davyn\Settings\SettingsRepository;
use Davyn\Tests\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;

final class BackupSchedulerTest extends TestCase
{
    private string $dir = '';
    private string $dbPath = '';
    private PDO $pdo;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->dir    = sys_get_temp_dir() . '/davyn-bkp-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0777, true);
        // dbPath only locates the backup dir; the connection below is what gets vacuumed.
        $this->dbPath = $this->dir . '/davyn.sqlite';
        $this->pdo      = Db::migratedMemory();
        $this->settings = new SettingsRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function scheduler(): BackupScheduler
    {
        return new BackupScheduler($this->pdo, $this->dbPath, new BackupService(), $this->settings);
    }

    private function backupDir(): string
    {
        $d = $this->dir . '/backups';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    /** Drop a placeholder backup file with a controlled age (seconds in the past). */
    private function makeBackup(string $stamp, int $ageSeconds): string
    {
        $f = $this->backupDir() . "/davyn-backup-$stamp.sqlite";
        file_put_contents($f, 'x');
        touch($f, time() - $ageSeconds);
        return $f;
    }

    private function backupCount(): int
    {
        return count(glob($this->backupDir() . '/davyn-backup-*.sqlite') ?: []);
    }

    public function testOffIsNeverDue(): void
    {
        $this->assertFalse($this->scheduler()->isDue('off'));
        $this->assertFalse($this->scheduler()->isDue('nonsense'));
    }

    public function testDueWhenNoBackupsExist(): void
    {
        $this->assertTrue($this->scheduler()->isDue('weekly'));
        $this->assertTrue($this->scheduler()->isDue('daily'));
    }

    public function testNotDueWhenNewestIsRecent(): void
    {
        $this->makeBackup('20260101-000000', 3600); // 1h old
        $s = $this->scheduler();
        $this->assertFalse($s->isDue('weekly'));
        $this->assertFalse($s->isDue('daily'));
    }

    public function testDueWhenNewestExceedsInterval(): void
    {
        $this->makeBackup('20260101-000000', 8 * 86400); // 8 days old
        $s = $this->scheduler();
        $this->assertTrue($s->isDue('weekly'));
        $this->assertTrue($s->isDue('daily'));
    }

    public function testNextDueAtIsNewestPlusInterval(): void
    {
        $age = 2 * 86400;
        $this->makeBackup('20260101-000000', $age);
        $newest = time() - $age;
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', $newest + 86400),
            $this->scheduler()->nextDueAt('daily'),
        );
        $this->assertNull($this->scheduler()->nextDueAt('off'));
    }

    public function testMaybeRunCreatesBackupWhenDueAndRecordsLastRun(): void
    {
        $this->settings->set('backup_auto_frequency', 'weekly');
        $this->assertSame(0, $this->backupCount());

        $this->scheduler()->maybeRun();

        $this->assertSame(1, $this->backupCount());
        $this->assertNotSame('', $this->settings->get('backup_last_run_at'));
    }

    public function testMaybeRunDoesNothingWhenOff(): void
    {
        $this->settings->set('backup_auto_frequency', 'off');
        $this->scheduler()->maybeRun();
        $this->assertFalse(is_dir($this->dir . '/backups'));
    }

    public function testMaybeRunPrunesOldButKeepsMinKeep(): void
    {
        // Not due (a recent backup exists), but prune still runs each tick.
        $this->settings->set('backup_auto_frequency', 'weekly');
        $this->settings->set('backup_retention_days', '1');
        $this->settings->set('backup_min_keep', '2');

        $this->makeBackup('20260110-000000', 3600);        // recent (newest, kept)
        $this->makeBackup('20260104-000000', 10 * 86400);  // old
        $this->makeBackup('20260103-000000', 11 * 86400);  // old
        $this->makeBackup('20260102-000000', 12 * 86400);  // old
        $this->assertSame(4, $this->backupCount());

        $this->scheduler()->maybeRun();

        // min_keep=2 keeps the 2 newest; the rest, older than 1 day, are pruned.
        $this->assertSame(2, $this->backupCount());
    }

    public function testRetentionZeroKeepsEverything(): void
    {
        $this->settings->set('backup_auto_frequency', 'weekly');
        $this->settings->set('backup_retention_days', '0'); // keep forever
        $this->settings->set('backup_min_keep', '1');

        $this->makeBackup('20260110-000000', 3600);        // recent → not due
        $this->makeBackup('20260104-000000', 30 * 86400);
        $this->makeBackup('20260103-000000', 40 * 86400);
        $this->assertSame(3, $this->backupCount());

        $this->scheduler()->maybeRun();

        $this->assertSame(3, $this->backupCount());
    }
}
