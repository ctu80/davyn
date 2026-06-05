<?php
declare(strict_types=1);

namespace Davyn\Maintenance;

use RuntimeException;

class MaintenanceMode
{
    private string $lockFile;

    public function __construct(string $dataDir)
    {
        $this->lockFile = rtrim($dataDir, '/') . '/maintenance.lock';
    }

    public function enable(string $reason): void
    {
        $payload = json_encode([
            'reason'     => $reason,
            'enabled_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        if (file_put_contents($this->lockFile, $payload) === false) {
            throw new RuntimeException("Could not write maintenance lock file: {$this->lockFile}");
        }
    }

    public function disable(): void
    {
        if (file_exists($this->lockFile)) {
            if (!@unlink($this->lockFile)) {
                throw new RuntimeException("Could not remove maintenance lock file: {$this->lockFile}");
            }
        }
    }

    public function isEnabled(): bool
    {
        return file_exists($this->lockFile);
    }

    public function status(): array
    {
        if (!$this->isEnabled()) {
            return ['enabled' => false, 'reason' => null, 'enabled_at' => null];
        }

        $raw  = file_get_contents($this->lockFile);
        $data = $raw !== false ? json_decode($raw, true) : null;

        return [
            'enabled'    => true,
            'reason'     => is_array($data) ? ($data['reason']     ?? null) : null,
            'enabled_at' => is_array($data) ? ($data['enabled_at'] ?? null) : null,
        ];
    }

    public static function fromConfig(\Davyn\Config\Config $config): self
    {
        return new self(dirname($config->dbPath()));
    }
}
