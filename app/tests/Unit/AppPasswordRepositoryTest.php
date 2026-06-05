<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Auth\AppPasswordRepository;
use Davyn\Tests\Support\Db;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AppPasswordRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AppPasswordRepository $repo;

    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->pdo = Db::migratedMemory();
        Db::insertUser($this->pdo, 'alice');
        $this->repo = new AppPasswordRepository($this->pdo);
    }

    public function testRejectsTooShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->createForUser('alice', 'phone', 'short'); // < 12 chars
    }

    public function testCreateThenVerifyRoundTrip(): void
    {
        $this->repo->createForUser('alice', 'phone', 'correct-horse-battery');
        self::assertTrue($this->repo->verify('alice', 'correct-horse-battery'));
        self::assertFalse($this->repo->verify('alice', 'wrong-password-value'));
    }

    public function testRevokedPasswordNoLongerVerifies(): void
    {
        $this->repo->createForUser('alice', 'phone', 'correct-horse-battery');
        $this->repo->revokeForUser('alice', 'phone');
        self::assertFalse($this->repo->verify('alice', 'correct-horse-battery'));
    }

    public function testDuplicateNameRejected(): void
    {
        $this->repo->createForUser('alice', 'phone', 'correct-horse-battery');
        $this->expectException(InvalidArgumentException::class);
        $this->repo->createForUser('alice', 'phone', 'another-long-secret');
    }

    /** Validates the central PRAGMA foreign_keys=ON: deleting a user cascades. */
    public function testForeignKeyCascadeRemovesAppPasswords(): void
    {
        $this->repo->createForUser('alice', 'phone', 'correct-horse-battery');
        $this->pdo->prepare('DELETE FROM users WHERE username = ?')->execute(['alice']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM app_passwords')->fetchColumn();
        self::assertSame(0, $count, 'app_passwords must be cascade-deleted with the user');
    }
}
