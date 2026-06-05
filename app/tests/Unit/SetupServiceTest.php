<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Setup\SetupAlreadyDoneException;
use Davyn\Setup\SetupService;
use Davyn\Tests\Support\Db;
use Davyn\User\UserRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetupServiceTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private SetupService $setup;

    protected function setUp(): void
    {
        $this->pdo   = Db::migratedMemory();
        $this->users = new UserRepository($this->pdo);
        $this->setup = new SetupService($this->pdo, $this->users);
    }

    public function testFreshInstallIsNotInitialized(): void
    {
        self::assertFalse($this->setup->isInitialized());
    }

    public function testCreatesFirstAdminActiveAndInitialized(): void
    {
        $user = $this->setup->createFirstAdmin('admin', 'Administrator', 'sup3rsecret');

        self::assertSame('admin', $user->username);
        self::assertSame('Administrator', $user->displayName);
        self::assertSame('admin', $user->role);
        self::assertTrue($user->isActive);
        self::assertTrue($this->setup->isInitialized());
    }

    public function testDisplayNameDefaultsToUsername(): void
    {
        $user = $this->setup->createFirstAdmin('admin', '', 'sup3rsecret');
        self::assertSame('admin', $user->displayName);
    }

    public function testSecondCreateIsBlockedOnceAdminExists(): void
    {
        $this->setup->createFirstAdmin('admin', 'Administrator', 'sup3rsecret');

        $this->expectException(SetupAlreadyDoneException::class);
        $this->setup->createFirstAdmin('admin2', 'Second', 'sup3rsecret');
    }

    public function testExistingAdminCountsAsInitialized(): void
    {
        Db::insertUser($this->pdo, 'root', 'admin');
        self::assertTrue($this->setup->isInitialized());
    }

    public function testInactiveAdminDoesNotCountAsInitialized(): void
    {
        Db::insertUser($this->pdo, 'root', 'admin', 0);
        self::assertFalse($this->setup->isInitialized(), 'a disabled admin must not lock setup');
    }

    public function testNonAdminUsersDoNotCountAsInitialized(): void
    {
        Db::insertUser($this->pdo, 'alice', 'user');
        Db::insertUser($this->pdo, 'bob', 'read_only');
        self::assertFalse($this->setup->isInitialized());
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->setup->createFirstAdmin('admin', 'Administrator', 'short'); // < 8 chars
        self::assertFalse($this->setup->isInitialized());
    }

    public function testRejectsEmptyUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->setup->createFirstAdmin('   ', 'Administrator', 'sup3rsecret');
    }
}
