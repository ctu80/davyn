<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Sharing\CollectionNotFoundException;
use Davyn\Sharing\NotCollectionOwnerException;
use Davyn\Sharing\SharingService;
use Davyn\Tests\Support\Db;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SharingServiceTest extends TestCase
{
    private PDO $pdo;
    private SharingService $svc;
    private int $alice;
    private int $bob;
    private int $calId;   // owned by alice
    private int $abId;    // owned by alice

    protected function setUp(): void
    {
        $this->pdo = Db::migratedMemory();
        $this->alice = Db::insertUser($this->pdo, 'alice');
        $this->bob   = Db::insertUser($this->pdo, 'bob');
        $this->calId = $this->insertCalendar($this->alice, 'cal-a');
        $this->abId  = $this->insertAddressBook($this->alice, 'ab-a');
        $this->svc   = new SharingService($this->pdo);
    }

    private function insertCalendar(int $userId, string $uri): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, created_at, updated_at) VALUES (?,?,?,?,?)'
        )->execute([$userId, $uri, ucfirst($uri), $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertAddressBook(int $userId, string $uri): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO addressbooks (user_id, uri, display_name, created_at, updated_at) VALUES (?,?,?,?,?)'
        )->execute([$userId, $uri, ucfirst($uri), $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Ownership detection ──────────────────────────────────────────────────

    public function testOwnerHasOwnerPermission(): void
    {
        self::assertSame('owner', $this->svc->getCalendarPermission($this->alice, $this->calId));
        self::assertSame('none',  $this->svc->getCalendarPermission($this->bob, $this->calId));
    }

    public function testGetCollectionOwnerId(): void
    {
        self::assertSame($this->alice, $this->svc->getCollectionOwnerId('calendar', $this->calId));
        self::assertNull($this->svc->getCollectionOwnerId('calendar', 9999));
    }

    // ── User can share own collection ────────────────────────────────────────

    public function testOwnerSharesOwnCalendar(): void
    {
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->bob, 'read_only');
        self::assertSame('read_only', $this->svc->getCalendarPermission($this->bob, $this->calId));

        // Upgrade to read_write.
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->bob, 'read_write');
        self::assertSame('read_write', $this->svc->getCalendarPermission($this->bob, $this->calId));
    }

    public function testOwnerSharesOwnAddressBook(): void
    {
        $this->svc->shareAsOwner($this->alice, 'addressbook', $this->abId, $this->bob, 'read_write');
        self::assertSame('read_write', $this->svc->getAddressBookPermission($this->bob, $this->abId));
    }

    // ── User cannot share a collection they do not own ───────────────────────

    public function testNonOwnerCannotShare(): void
    {
        $this->expectException(NotCollectionOwnerException::class);
        $this->svc->shareAsOwner($this->bob, 'calendar', $this->calId, $this->alice, 'read_only');
    }

    public function testSharingMissingCollectionThrowsNotFound(): void
    {
        $this->expectException(CollectionNotFoundException::class);
        $this->svc->shareAsOwner($this->alice, 'calendar', 9999, $this->bob, 'read_only');
    }

    public function testCannotShareWithYourself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->alice, 'read_only');
    }

    public function testInvalidPermissionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->bob, 'none');
    }

    // ── User can change/remove own share, but not others' ────────────────────

    public function testOwnerRemovesOwnShare(): void
    {
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->bob, 'read_only');
        $this->svc->removeShareAsOwner($this->alice, 'calendar', $this->calId, $this->bob);
        self::assertSame('none', $this->svc->getCalendarPermission($this->bob, $this->calId));
    }

    public function testNonOwnerCannotRemoveShare(): void
    {
        $this->svc->shareAsOwner($this->alice, 'calendar', $this->calId, $this->bob, 'read_only');
        $this->expectException(NotCollectionOwnerException::class);
        // bob is the share recipient, not the owner — he cannot manage the share.
        $this->svc->removeShareAsOwner($this->bob, 'calendar', $this->calId, $this->bob);
    }

    public function testAssertOwnedByForForeignCollection(): void
    {
        $bobCal = $this->insertCalendar($this->bob, 'cal-b');
        $this->expectException(NotCollectionOwnerException::class);
        $this->svc->assertOwnedBy($this->alice, 'calendar', $bobCal);
    }
}
