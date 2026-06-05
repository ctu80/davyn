<?php
declare(strict_types=1);

namespace Davyn\Database;

use PDO;
use RuntimeException;

class MigrationRunner
{
    private string $migrationsPath;

    public function __construct(private readonly PDO $pdo, string $migrationsPath)
    {
        $this->migrationsPath = rtrim($migrationsPath, '/');
    }

    public function run(): array
    {
        $this->ensureMigrationsTable();

        $files = $this->getMigrationFiles();
        $applied = [];

        foreach ($files as $file) {
            $version = basename($file, '.sql');

            if ($this->isApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration file: {$file}");
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $this->recordMigration($version);
                $this->pdo->commit();
                $applied[] = $version;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Migration '{$version}' failed: " . $e->getMessage(), 0, $e);
            }
        }

        return $applied;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version    TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )
        ');
    }

    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files);
        return $files;
    }

    private function isApplied(string $version): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
        $stmt->execute([$version]);
        return $stmt->fetchColumn() !== false;
    }

    private function recordMigration(string $version): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)'
        );
        $stmt->execute([$version, date('Y-m-d\TH:i:s\Z')]);
    }
}
