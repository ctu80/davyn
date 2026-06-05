<?php
declare(strict_types=1);

namespace Davyn\Auth;

class WebSessionRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    private function hashId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function upsert(int $userId, string $sessionId, ?string $userAgent, ?string $ip = null): void
    {
        $hash = $this->hashId($sessionId);
        $now  = gmdate('Y-m-d\TH:i:s\Z');

        $existing = $this->pdo->prepare(
            'SELECT id FROM web_sessions WHERE session_id_hash = ? AND revoked_at IS NULL'
        );
        $existing->execute([$hash]);

        if ($existing->fetch()) {
            // Refresh last_seen_at and the latest IP; keep the original user_agent.
            $this->pdo->prepare(
                'UPDATE web_sessions SET last_seen_at = ?, ip = COALESCE(?, ip) WHERE session_id_hash = ?'
            )->execute([$now, $ip, $hash]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO web_sessions (user_id, session_id_hash, user_agent, ip, created_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $hash, $userAgent, $ip, $now, $now]);
        }
    }

    public function revoke(string $sessionId): void
    {
        $this->pdo->prepare(
            'UPDATE web_sessions SET revoked_at = ? WHERE session_id_hash = ?'
        )->execute([gmdate('Y-m-d\TH:i:s\Z'), $this->hashId($sessionId)]);
    }

    public function revokeById(int $sessionRowId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE web_sessions SET revoked_at = ?
             WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([gmdate('Y-m-d\TH:i:s\Z'), $sessionRowId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * True if the session has exceeded its idle or absolute lifetime.
     * Unknown/legacy session rows are treated as not-expired (fail-open) so
     * pre-existing sessions are never force-logged-out by this check alone.
     */
    public function isExpired(string $sessionId, int $idleSeconds, int $absoluteSeconds): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT created_at, last_seen_at FROM web_sessions
             WHERE session_id_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$this->hashId($sessionId)]);
        $row = $stmt->fetch();
        if ($row === false) return false;

        $now      = time();
        $lastSeen = isset($row['last_seen_at']) ? (strtotime((string) $row['last_seen_at']) ?: 0) : 0;
        $created  = isset($row['created_at'])   ? (strtotime((string) $row['created_at'])   ?: 0) : 0;

        if ($idleSeconds > 0 && $lastSeen > 0 && ($now - $lastSeen) > $idleSeconds) {
            return true;
        }
        if ($absoluteSeconds > 0 && $created > 0 && ($now - $created) > $absoluteSeconds) {
            return true;
        }
        return false;
    }

    /** Revoke every active session for a user except the given one. Returns count revoked. */
    public function revokeAllForUserExcept(int $userId, string $exceptSessionId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE web_sessions SET revoked_at = ?
             WHERE user_id = ? AND session_id_hash != ? AND revoked_at IS NULL'
        );
        $stmt->execute([gmdate('Y-m-d\TH:i:s\Z'), $userId, $this->hashId($exceptSessionId)]);
        return $stmt->rowCount();
    }

    /** Permanently remove all revoked sessions for a user. Returns rows deleted. */
    public function deleteRevokedForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM web_sessions WHERE user_id = ? AND revoked_at IS NOT NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function isRevoked(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT revoked_at FROM web_sessions WHERE session_id_hash = ?'
        );
        $stmt->execute([$this->hashId($sessionId)]);
        $row = $stmt->fetch();
        if ($row === false) return false;
        return $row['revoked_at'] !== null;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, session_id_hash, user_agent, ip, created_at, last_seen_at, revoked_at
             FROM web_sessions WHERE user_id = ?
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getHashForCurrentSession(string $sessionId): string
    {
        return $this->hashId($sessionId);
    }

    /**
     * Delete old revoked sessions and long-abandoned inactive sessions for a user.
     * Called on login to keep the table clean without blocking normal requests.
     */
    public function cleanupForUser(int $userId, int $revokedDays, int $inactiveDays): void
    {
        $cutoffRevoked  = gmdate('Y-m-d\TH:i:s\Z', time() - $revokedDays  * 86400);
        $cutoffInactive = gmdate('Y-m-d\TH:i:s\Z', time() - $inactiveDays * 86400);

        $this->pdo->prepare(
            'DELETE FROM web_sessions WHERE user_id = ? AND revoked_at IS NOT NULL AND revoked_at < ?'
        )->execute([$userId, $cutoffRevoked]);

        $this->pdo->prepare(
            'DELETE FROM web_sessions WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at < ?'
        )->execute([$userId, $cutoffInactive]);
    }
}
