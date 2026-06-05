<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\ImportExport\ExportService;
use Davyn\ImportExport\ImportService;
use Davyn\Tests\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExportServiceTest extends TestCase
{
    private PDO $pdo;
    private int $calendarId;
    private int $addressBookId;
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->pdo = Db::migratedMemory();
        $uid = Db::insertUser($this->pdo, 'alice');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, 'default', 'Default', $now, $now]);
        $this->calendarId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO addressbooks (user_id, uri, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, 'contacts', 'Contacts', $now, $now]);
        $this->addressBookId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'davyn_exp_test_');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    public function testExportCalendarObjectReturnsStoredDocument(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\n"
            . "SUMMARY:Lunch\r\nDTSTART:20260101T120000Z\r\nDTEND:20260101T130000Z\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
        (new ImportService())->importCalendarFile('alice', 'default', $this->file($ics), $this->pdo);

        $svc = new ExportService();
        $out = $svc->exportCalendarObject($this->calendarId, 'evt-1.ics', $this->pdo);
        $this->assertNotNull($out);
        $this->assertStringContainsString('UID:evt-1', $out);
        $this->assertStringContainsString('SUMMARY:Lunch', $out);

        $this->assertNull($svc->exportCalendarObject($this->calendarId, 'missing.ics', $this->pdo));
    }

    public function testExportAddressBookObjectReturnsStoredDocument(): void
    {
        $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c-1\r\nFN:Jane Doe\r\nN:Doe;Jane;;;\r\nEND:VCARD\r\n";
        (new ImportService())->importAddressBookFile('alice', 'contacts', $this->file($vcard), $this->pdo);

        $svc = new ExportService();
        $out = $svc->exportAddressBookObject($this->addressBookId, 'c-1.vcf', $this->pdo);
        $this->assertNotNull($out);
        $this->assertStringContainsString('FN:Jane Doe', $out);

        $this->assertNull($svc->exportAddressBookObject($this->addressBookId, 'missing.vcf', $this->pdo));
    }

    public function testExportCalendarWrapsAllObjectsInOneVCalendar(): void
    {
        $mk = fn(string $uid) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\n"
            . "SUMMARY:$uid\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $svc = new ImportService();
        $svc->importCalendarFile('alice', 'default', $this->file($mk('a')), $this->pdo);
        $svc->importCalendarFile('alice', 'default', $this->file($mk('b')), $this->pdo);

        $out = (new ExportService())->exportCalendar($this->calendarId, $this->pdo);
        $this->assertSame(1, substr_count($out, 'BEGIN:VCALENDAR'));
        $this->assertSame(2, substr_count($out, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('UID:a', $out);
        $this->assertStringContainsString('UID:b', $out);
    }
}
