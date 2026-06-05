<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\ImportExport\ImportService;
use Davyn\Tests\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;

final class ImportServiceTest extends TestCase
{
    private PDO $pdo;
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->pdo = Db::migratedMemory();
        $uid = Db::insertUser($this->pdo, 'alice');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, 'default', 'Default', $now, $now]);
        $this->pdo->prepare(
            'INSERT INTO addressbooks (user_id, uri, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, 'contacts', 'Contacts', $now, $now]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'davyn_imp_test_');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function ics(string $uid, string $summary = 'Event'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\n"
            . "SUMMARY:$summary\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function vcard(?string $uid, string $fn = 'Jane Doe'): string
    {
        $uidLine = $uid !== null ? "UID:$uid\r\n" : '';
        return "BEGIN:VCARD\r\nVERSION:3.0\r\n{$uidLine}FN:$fn\r\nN:Doe;Jane;;;\r\nEND:VCARD\r\n";
    }

    private function calObjectCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_objects WHERE deleted_at IS NULL')->fetchColumn();
    }

    private function cardObjectCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM addressbook_objects WHERE deleted_at IS NULL')->fetchColumn();
    }

    public function testCalendarSameUidUpdatesNotDuplicates(): void
    {
        $svc = new ImportService();

        $r1 = $svc->importCalendarFile('alice', 'default', $this->file($this->ics('uid-1', 'First')), $this->pdo);
        $this->assertSame(1, $r1['created']);
        $this->assertSame(0, $r1['updated']);
        $this->assertSame(1, $this->calObjectCount());

        // Same UID again → updated, no duplicate.
        $r2 = $svc->importCalendarFile('alice', 'default', $this->file($this->ics('uid-1', 'Second')), $this->pdo);
        $this->assertSame(0, $r2['created']);
        $this->assertSame(1, $r2['updated']);
        $this->assertSame(1, $this->calObjectCount());
    }

    public function testCalendarDifferentUidLeavesExistingIntact(): void
    {
        $svc = new ImportService();
        $svc->importCalendarFile('alice', 'default', $this->file($this->ics('uid-a')), $this->pdo);
        $r = $svc->importCalendarFile('alice', 'default', $this->file($this->ics('uid-b')), $this->pdo);

        $this->assertSame(1, $r['created']);
        $this->assertSame(0, $r['updated']);
        $this->assertSame(2, $this->calObjectCount());
    }

    public function testAddressBookSameUidUpdatesNotDuplicates(): void
    {
        $svc = new ImportService();
        $svc->importAddressBookFile('alice', 'contacts', $this->file($this->vcard('c-1', 'Jane A')), $this->pdo);
        $r = $svc->importAddressBookFile('alice', 'contacts', $this->file($this->vcard('c-1', 'Jane B')), $this->pdo);

        $this->assertSame(0, $r['created']);
        $this->assertSame(1, $r['updated']);
        $this->assertSame(1, $this->cardObjectCount());
    }

    public function testUidlessVcardDuplicatesOnReimport(): void
    {
        // No UID → a fresh UID is generated each import, so re-importing makes a copy.
        $svc = new ImportService();
        $svc->importAddressBookFile('alice', 'contacts', $this->file($this->vcard(null)), $this->pdo);
        $svc->importAddressBookFile('alice', 'contacts', $this->file($this->vcard(null)), $this->pdo);

        $this->assertSame(2, $this->cardObjectCount());
    }

    public function testUidlessVcardStoredAsValidCard(): void
    {
        // A UID-less vCard must get its generated UID spliced *inside* the card
        // (before END:VCARD) — not dangling after it — and stay a single card.
        $svc = new ImportService();
        $svc->importAddressBookFile('alice', 'contacts', $this->file($this->vcard(null, 'No Uid')), $this->pdo);

        $stored = (string) $this->pdo->query(
            'SELECT vcard FROM addressbook_objects WHERE deleted_at IS NULL ORDER BY id LIMIT 1'
        )->fetchColumn();

        $this->assertSame(1, substr_count($stored, 'BEGIN:VCARD'), 'exactly one card');
        $this->assertSame(1, substr_count($stored, 'END:VCARD'), 'exactly one terminator');
        $this->assertSame(1, preg_match('/\r?\nUID:/', $stored), 'has a UID line');
        // UID must appear before the terminator, i.e. inside the card.
        $this->assertLessThan(
            strpos($stored, 'END:VCARD'),
            strpos($stored, 'UID:'),
            'UID must sit before END:VCARD',
        );
    }

    public function testImportCalendarDataByIdUpserts(): void
    {
        // The id-based core method (used by the web upload endpoint, incl. shared
        // collections) must behave like the username-based wrapper.
        $calId = (int) $this->pdo->query(
            "SELECT id FROM calendars WHERE uri = 'default'"
        )->fetchColumn();

        $svc = new ImportService();
        $r1  = $svc->importCalendarData($calId, $this->ics('data-1', 'First'), $this->pdo);
        $this->assertSame(1, $r1['created']);
        $this->assertSame(1, $this->calObjectCount());

        $r2 = $svc->importCalendarData($calId, $this->ics('data-1', 'Second'), $this->pdo);
        $this->assertSame(0, $r2['created']);
        $this->assertSame(1, $r2['updated']);
        $this->assertSame(1, $this->calObjectCount());
    }
}
