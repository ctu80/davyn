<?php
declare(strict_types=1);

namespace Davyn\Setup;

use Davyn\User\User;
use Davyn\User\UserRepository;
use InvalidArgumentException;
use PDO;

/**
 * First-run initialisation. Davyn is considered "initialized" once at least one
 * active admin user exists; until then the web setup wizard (and the create-admin
 * CLI) may create that very first admin. Every check here is server-side — the UI
 * gating is only a convenience.
 */
final class SetupService
{
    /** Mirrors Davyn's documented web password minimum (see user/change-password). */
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
    ) {}

    /** True once at least one active admin exists — setup is then complete and locked. */
    public function isInitialized(): bool
    {
        return $this->users->countActiveAdmins() > 0;
    }

    /**
     * Create the first admin. Refuses with SetupAlreadyDoneException if an admin
     * already exists. The existence check is repeated inside a write-locked
     * transaction (BEGIN IMMEDIATE) so two concurrent setup requests cannot both
     * succeed in creating an admin.
     *
     * @throws SetupAlreadyDoneException when Davyn is already initialized
     * @throws InvalidArgumentException  on an invalid username/password
     */
    public function createFirstAdmin(string $username, string $displayName, string $password): User
    {
        $username    = trim($username);
        $displayName = trim($displayName);

        if ($username === '') {
            throw new InvalidArgumentException('Username must not be empty.');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }

        // Fast pre-check so the common "already done" case fails clearly without
        // taking a write lock.
        if ($this->isInitialized()) {
            throw new SetupAlreadyDoneException();
        }

        // Race-safe path: grab SQLite's write lock up front (BEGIN IMMEDIATE),
        // re-check, then insert. exec() is used (not PDO::beginTransaction) so the
        // lock is reserved immediately rather than on first write.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->isInitialized()) {
                throw new SetupAlreadyDoneException();
            }
            $user = $this->users->createUser(
                $username,
                $displayName !== '' ? $displayName : $username,
                $password,
                'admin',
            );
            $this->pdo->exec('COMMIT');
            return $user;
        } catch (\Throwable $e) {
            // Roll back defensively; swallow "no transaction is active" if COMMIT
            // already ran or the BEGIN never took.
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $e;
        }
    }
}
