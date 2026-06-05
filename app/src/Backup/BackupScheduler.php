<?php
declare(strict_types=1);

namespace Davyn\Backup;

use Davyn\Audit\ActivityLog;
use Davyn\Config\Config;
use Davyn\Settings\SettingsRepository;
use PDO;

/**
 * Request-triggered backup scheduler.
 *
 * Davyn has no cron/worker, so automatic backups piggyback on inbound traffic:
 * arm() registers a shutdown hook that — after the HTTP response is flushed — runs a
 * cheap, throttled, lock-guarded check and creates the due backup, then prunes old ones.
 * A fully idle server never ticks, but an idle server has no new data to capture anyway;
 * CalDAV/CardDAV clients syncing every few minutes provide a reliable heartbeat.
 *
 * The "last backup" clock is the newest backup file's mtime, so a recent manual backup
 * also satisfies the schedule (no redundant double-backup right after a manual one).
 */
class BackupScheduler
{
    /** Schedule frequencies accepted by the config endpoint. */
    public const FREQUENCIES = ['off', 'daily', 'weekly', 'monthly'];

    /** Interval in seconds per frequency (monthly ≈ 30 days). */
    private const INTERVALS = [
        'daily'   => 86400,
        'weekly'  => 604800,
        'monthly' => 2592000,
    ];

    /** Do real work at most once per this window, to keep per-request cost near zero. */
    private const THROTTLE_SECONDS = 900; // 15 min

    private BackupService $backups;
    private SettingsRepository $settings;

    public function __construct(
        private PDO $pdo,
        private string $dbPath,
        ?BackupService $backups = null,
        ?SettingsRepository $settings = null,
    ) {
        $this->backups  = $backups ?? new BackupService();
        $this->settings = $settings ?? new SettingsRepository($pdo);
    }

    /**
     * Register a post-response hook that runs the due check. Cheap to call on every
     * request: the only cost is registering a closure; the heavy check is throttled.
     */
    public static function arm(Config $config, PDO $pdo): void
    {
        register_shutdown_function(static function () use ($config, $pdo): void {
            // Flush the response first so the backup never adds request latency.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                (new self($pdo, $config->dbPath()))->maybeRun();
            } catch (\Throwable $e) {
                // The scheduler must never surface as a request error.
                error_log('[davyn] backup scheduler: ' . $e->getMessage());
            }
        });
    }

    /**
     * Throttle + lock + due-check + prune. Safe to call frequently and concurrently.
     */
    public function maybeRun(): void
    {
        $freq = $this->settings->get('backup_auto_frequency');
        if (!isset(self::INTERVALS[$freq])) {
            return; // 'off' or unknown — nothing to do
        }

        $dir = $this->backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        // Throttle: skip the real work if we checked recently.
        $stamp = $dir . '/.scheduler-check';
        if (is_file($stamp) && (time() - (int) @filemtime($stamp)) < self::THROTTLE_SECONDS) {
            return;
        }
        @touch($stamp);

        // Lock: never run two backups at once (VACUUM INTO is heavy).
        $lock = @fopen($dir . '/.scheduler.lock', 'c');
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }

        try {
            $this->runDue($freq);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runDue(string $freq): void
    {
        if ($this->isDue($freq)) {
            $file = $this->backups->create($this->pdo, $this->dbPath);
            $this->settings->set('backup_last_run_at', gmdate('Y-m-d\TH:i:s\Z'));
            (new ActivityLog($this->pdo))->log(
                null,
                'admin.backup.auto',
                'Automatic backup created ' . basename($file),
                'backup',
                basename($file),
            );
        }
        $this->pruneOld();
    }

    /** A backup is due when none exist yet, or the newest is older than the interval. */
    public function isDue(string $freq): bool
    {
        $interval = self::INTERVALS[$freq] ?? null;
        if ($interval === null) {
            return false;
        }
        $newest = $this->newestBackupTime();
        return $newest === null || (time() - $newest) >= $interval;
    }

    /** ISO timestamp of the next scheduled backup, or null when frequency is off. */
    public function nextDueAt(string $freq): ?string
    {
        $interval = self::INTERVALS[$freq] ?? null;
        if ($interval === null) {
            return null;
        }
        $newest = $this->newestBackupTime();
        if ($newest === null) {
            return gmdate('Y-m-d\TH:i:s\Z'); // due immediately
        }
        return gmdate('Y-m-d\TH:i:s\Z', $newest + $interval);
    }

    public function newestBackupTime(): ?int
    {
        $files = $this->backups->list($this->dbPath); // newest first
        if ($files === []) {
            return null;
        }
        $t = @filemtime($files[0]);
        return $t !== false ? $t : null;
    }

    private function pruneOld(): void
    {
        $retentionDays = (int) $this->settings->get('backup_retention_days');
        if ($retentionDays <= 0) {
            return; // 0 = keep forever
        }
        $minKeep = max(1, (int) $this->settings->get('backup_min_keep'));
        $this->backups->prune($this->dbPath, $retentionDays, $minKeep);
    }

    private function backupDir(): string
    {
        return dirname($this->dbPath) . '/backups';
    }
}
