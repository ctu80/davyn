<?php
declare(strict_types=1);

namespace Davyn\Auth;

use PDO;

/**
 * Lightweight failed-attempt throttle backed by the auth_attempts table.
 *
 * All database access fails open: if the table is missing or a query errors,
 * the limiter never blocks a request. This keeps authentication available even
 * if the 0013 migration has not been applied; the error is logged instead.
 */
class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAttempts   = 10,
        private readonly int $windowSeconds = 900,
    ) {}

    /** True if this identifier has reached the failed-attempt limit within the window. */
    public function isBlocked(string $scope, string $identifier): bool
    {
        $identifier = $this->normalize($identifier);
        try {
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - $this->windowSeconds);
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = ? AND identifier = ? AND success = 0 AND attempted_at >= ?'
            );
            $stmt->execute([$scope, $identifier, $since]);
            return (int) $stmt->fetchColumn() >= $this->maxAttempts;
        } catch (\Throwable $e) {
            error_log('RateLimiter.isBlocked degraded: ' . $e->getMessage());
            return false;
        }
    }

    public function recordFailure(string $scope, string $identifier): void
    {
        $identifier = $this->normalize($identifier);
        try {
            $this->pdo->prepare(
                'INSERT INTO auth_attempts (scope, identifier, attempted_at, success) VALUES (?, ?, ?, 0)'
            )->execute([$scope, $identifier, gmdate('Y-m-d\TH:i:s\Z')]);
            // Opportunistic cleanup of rows older than a day.
            $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
            $this->pdo->prepare('DELETE FROM auth_attempts WHERE attempted_at < ?')->execute([$cutoff]);
        } catch (\Throwable $e) {
            error_log('RateLimiter.recordFailure degraded: ' . $e->getMessage());
        }
    }

    /** Clear the failure history for an identifier after a successful auth. */
    public function recordSuccess(string $scope, string $identifier): void
    {
        $identifier = $this->normalize($identifier);
        try {
            $this->pdo->prepare('DELETE FROM auth_attempts WHERE scope = ? AND identifier = ?')
                ->execute([$scope, $identifier]);
        } catch (\Throwable $e) {
            error_log('RateLimiter.recordSuccess degraded: ' . $e->getMessage());
        }
    }

    private function normalize(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }
}
