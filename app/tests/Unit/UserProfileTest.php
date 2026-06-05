<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Tests\Support\Db;
use Davyn\User\UserRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->pdo   = Db::migratedMemory();
        $this->users = new UserRepository($this->pdo);
        Db::insertUser($this->pdo, 'alice');
        Db::insertUser($this->pdo, 'bob');
    }

    // ── Display name ─────────────────────────────────────────────────────────

    public function testUpdateDisplayName(): void
    {
        $this->users->updateDisplayName('alice', 'Alice Cooper');
        self::assertSame('Alice Cooper', $this->users->findByUsername('alice')?->displayName);
    }

    public function testUsernameStaysStableOnRename(): void
    {
        $this->users->updateDisplayName('alice', 'Renamed');
        self::assertNotNull($this->users->findByUsername('alice'), 'username must remain the lookup key');
    }

    public function testDuplicateDisplayNameBlocked(): void
    {
        $this->users->updateDisplayName('alice', 'Shared Name');
        $this->expectException(InvalidArgumentException::class);
        $this->users->updateDisplayName('bob', 'shared name'); // case-insensitive clash
    }

    public function testRenamingToOwnCurrentNameIsAllowed(): void
    {
        $this->users->updateDisplayName('alice', 'Alice');
        $this->users->updateDisplayName('alice', 'Alice'); // no clash with self
        self::assertSame('Alice', $this->users->findByUsername('alice')?->displayName);
    }

    public function testEmptyDisplayNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users->updateDisplayName('alice', '   ');
    }

    // ── Preferences ──────────────────────────────────────────────────────────

    public function testUpdateLocaleAndTheme(): void
    {
        $this->users->updatePreferences('alice', 'de', 'light');
        $u = $this->users->findByUsername('alice');
        self::assertSame('de', $u?->locale);
        self::assertSame('light', $u?->theme);
    }

    public function testDefaultsAreNull(): void
    {
        $u = $this->users->findByUsername('bob');
        self::assertNull($u?->locale, 'no personal locale = follow instance default');
        self::assertNull($u?->theme);
    }

    public function testInvalidLocaleRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users->updatePreferences('alice', 'fr', null);
    }

    public function testInvalidThemeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users->updatePreferences('alice', null, 'sepia');
    }

    public function testPartialPreferenceUpdateKeepsOther(): void
    {
        $this->users->updatePreferences('alice', 'de', 'dark');
        $this->users->updatePreferences('alice', 'en', null); // only locale
        $u = $this->users->findByUsername('alice');
        self::assertSame('en', $u?->locale);
        self::assertSame('dark', $u?->theme, 'theme must be untouched when null is passed');
    }

    public function testListShareTargetsExcludesSelf(): void
    {
        $alice = $this->users->findByUsername('alice');
        $targets = $this->users->listShareTargets((int) $alice?->id);
        $names = array_column($targets, 'username');
        self::assertContains('bob', $names);
        self::assertNotContains('alice', $names);
    }
}
