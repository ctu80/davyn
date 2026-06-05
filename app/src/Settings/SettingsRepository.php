<?php
declare(strict_types=1);

namespace Davyn\Settings;

class SettingsRepository
{
    private const DEFAULTS = [
        'instance_name'        => 'Davyn',
        'default_locale'       => 'en',
        'default_theme'        => 'system',
        'accent_color'         => '#1a56db',
        // Public/base URL used for generated links (DAV setup, public calendars,
        // QR codes). Empty = derive from the request origin in the browser.
        'public_base_url'      => '',
        // How the active internal-HTTPS cert was produced: http | selfsigned | custom.
        'tls_mode'             => 'http',
        // ISO timestamp set whenever the cert changes; signals "restart Caddy".
        'tls_restart_pending_at' => '',
        // Plain-HTTP listener behaviour: enabled (serve app on :8080) | redirect
        // (308 to HTTPS). 'redirect' is only honoured once HTTPS is configured.
        'http_mode'            => 'enabled',
        // Automatic backups. Frequency: off | daily | weekly | monthly. Shipped off
        // (admin opts in). Retention 0 = keep forever; min_keep is a safety floor that
        // is always retained regardless of age. last_run_at is written by the scheduler.
        'backup_auto_frequency' => 'off',
        'backup_retention_days' => '30',
        'backup_min_keep'       => '5',
        'backup_last_run_at'    => '',
    ];

    public function __construct(private \PDO $pdo) {}

    public function getAll(): array
    {
        $keys = array_keys(self::DEFAULTS);
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare("SELECT key, value FROM app_meta WHERE key IN ($in)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        return array_merge(self::DEFAULTS, $rows);
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting key: $key");
        }
        $stmt = $this->pdo->prepare('SELECT value FROM app_meta WHERE key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : self::DEFAULTS[$key];
    }

    public function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting key: $key");
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_meta (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }
}
