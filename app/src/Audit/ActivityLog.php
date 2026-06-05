<?php
declare(strict_types=1);

namespace Davyn\Audit;

use Davyn\Http\ClientIp;

class ActivityLog
{
    public function __construct(private readonly \PDO $pdo) {}

    public function log(
        ?int   $actorUserId,
        string $action,
        string $summary,
        ?string $targetType = null,
        ?string $targetId   = null,
    ): void {
        try {
            // Capture the originating IP (null for CLI / no request context).
            $ip = ClientIp::get();
            $this->pdo->prepare(
                'INSERT INTO activity_log (actor_user_id, action, target_type, target_id, summary, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$actorUserId, $action, $targetType, $targetId, $summary, $ip, gmdate('Y-m-d\TH:i:s\Z')]);
        } catch (\Throwable) {
            // Never break the main flow for logging failures
        }
    }

    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT al.*, u.username AS actor_username
             FROM activity_log al
             LEFT JOIN users u ON u.id = al.actor_user_id
             ORDER BY al.id DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    }
}
