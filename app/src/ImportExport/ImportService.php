<?php
declare(strict_types=1);

namespace Davyn\ImportExport;

use Davyn\Birthday\BirthdayService;
use Davyn\Dav\AddressBookObjectRepository;
use Davyn\Dav\CalendarObjectRepository;
use PDO;
use RuntimeException;

class ImportService
{
    /**
     * Import an .ics file into a calendar resolved by owner username + uri.
     * Thin wrapper around {@see importCalendarData()} for CLI use.
     */
    public function importCalendarFile(
        string $username,
        string $calendarUri,
        string $filePath,
        PDO $pdo
    ): array {
        $raw        = $this->readFile($filePath);
        $calendarId = $this->resolveCalendarId($username, $calendarUri, $pdo);
        return $this->importCalendarData($calendarId, $raw, $pdo);
    }

    /**
     * Import raw iCalendar text into a calendar by id. Upsert by UID
     * (same UID → update, new UID → create, missing UID → random UID).
     */
    public function importCalendarData(int $calendarId, string $raw, PDO $pdo): array
    {
        if ($raw === '') {
            throw new RuntimeException('File is empty or could not be read.');
        }

        $components = $this->splitCalendarComponents($raw);
        if (empty($components)) {
            throw new RuntimeException("No VEVENT or VTODO components found in file.");
        }

        $repo     = new CalendarObjectRepository($pdo);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];
        $uris     = [];

        foreach ($components as $component) {
            try {
                $uid = $this->extractField($component, 'UID');
                if ($uid === null || $uid === '') {
                    $uid = $this->generateUid();
                }
                $uri = $this->sanitizeUri($uid) . '.ics';

                // Check for existing object by URI (same UID → same URI)
                $existing = $repo->getObject($calendarId, $uri);

                $ics = $this->wrapInVCalendar($component);
                $repo->putObject($calendarId, $uri, $ics);

                if ($existing === null) {
                    $created++;
                } else {
                    $updated++;
                }
                $uris[] = $uri;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = $e->getMessage();
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'uris'    => $uris,
        ];
    }

    /**
     * Import a .vcf file into an addressbook resolved by owner username + uri.
     * Thin wrapper around {@see importAddressBookData()} for CLI use.
     */
    public function importAddressBookFile(
        string $username,
        string $addressBookUri,
        string $filePath,
        PDO $pdo
    ): array {
        $raw           = $this->readFile($filePath);
        $addressBookId = $this->resolveAddressBookId($username, $addressBookUri, $pdo);
        return $this->importAddressBookData($addressBookId, $raw, $pdo);
    }

    /**
     * Import raw vCard text into an addressbook by id. Upsert by UID. After the
     * batch, regenerate the addressbook owner's birthday calendar once.
     */
    public function importAddressBookData(int $addressBookId, string $raw, PDO $pdo): array
    {
        if ($raw === '') {
            throw new RuntimeException('File is empty or could not be read.');
        }

        $vcards = $this->splitVCards($raw);
        if (empty($vcards)) {
            throw new RuntimeException("No VCARD objects found in file.");
        }

        $repo    = new AddressBookObjectRepository($pdo);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];
        $uris    = [];

        foreach ($vcards as $vcard) {
            try {
                $uid = $this->extractField($vcard, 'UID');
                if ($uid === null || $uid === '') {
                    $uid   = $this->generateUid();
                    $vcard = $this->insertVCardUid($vcard, $uid);
                }
                $uri = $this->sanitizeUri($uid) . '.vcf';

                $existing = $repo->getObject($addressBookId, $uri);

                $repo->putObject($addressBookId, $uri, $vcard);

                if ($existing === null) {
                    $created++;
                } else {
                    $updated++;
                }
                $uris[] = $uri;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = $e->getMessage();
            }
        }

        // A bulk import may add many contacts with birthdays — rebuild the generated
        // birthday calendar once at the end rather than per row. Never break the import.
        try {
            $owner = $this->resolveAddressBookOwnerId($addressBookId, $pdo);
            if ($owner !== null) {
                (new BirthdayService($pdo))->regenerate($owner);
            }
        } catch (\Throwable $e) {
            $errors[] = 'birthday regeneration: ' . $e->getMessage();
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'uris'    => $uris,
        ];
    }

    private function readFile(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or not readable: {$filePath}");
        }
        $raw = file_get_contents($filePath);
        if ($raw === false || $raw === '') {
            throw new RuntimeException("File is empty or could not be read: {$filePath}");
        }
        return $raw;
    }

    private function resolveAddressBookOwnerId(int $addressBookId, PDO $pdo): ?int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM addressbooks WHERE id = ?');
        $stmt->execute([$addressBookId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function splitCalendarComponents(string $raw): array
    {
        preg_match_all(
            '/BEGIN:(VEVENT|VTODO|VJOURNAL)\r?\n.*?END:\1/s',
            $raw,
            $matches
        );
        return $matches[0] ?? [];
    }

    private function splitVCards(string $raw): array
    {
        preg_match_all('/BEGIN:VCARD\r?\n.*?END:VCARD/s', $raw, $matches);
        return $matches[0] ?? [];
    }

    /**
     * Insert a UID line just before the closing END:VCARD, preserving the
     * card's line endings. The split regex captures up to (not past) the first
     * END:VCARD with no trailing newline, so a naive append would land the UID
     * *outside* the card — hence the targeted splice.
     */
    private function insertVCardUid(string $vcard, string $uid): string
    {
        $replaced = preg_replace(
            '/(\r?\n)END:VCARD\s*$/',
            '${1}UID:' . $uid . '${1}END:VCARD',
            $vcard,
            1
        );
        return $replaced ?? $vcard;
    }

    private function wrapInVCalendar(string $component): string
    {
        $lines = [
            "BEGIN:VCALENDAR",
            "VERSION:2.0",
            "PRODID:-//Davyn//Import//EN",
            rtrim($component),
            "END:VCALENDAR",
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    private function extractField(string $text, string $field): ?string
    {
        if (preg_match('/^' . preg_quote($field, '/') . ':(.+)$/m', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function sanitizeUri(string $uid): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-_.]/', '-', $uid);
        return substr((string) $safe, 0, 200);
    }

    private function generateUid(): string
    {
        return sprintf(
            '%s-%04x-%04x',
            gmdate('Ymd\THis\Z'),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function resolveCalendarId(string $username, string $calendarUri, PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'SELECT c.id FROM calendars c
             JOIN users u ON u.id = c.user_id
             WHERE u.username = ? AND c.uri = ?'
        );
        $stmt->execute([$username, $calendarUri]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Calendar '{$calendarUri}' not found for user '{$username}'.");
        }
        return (int) $id;
    }

    private function resolveAddressBookId(string $username, string $addressBookUri, PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'SELECT ab.id FROM addressbooks ab
             JOIN users u ON u.id = ab.user_id
             WHERE u.username = ? AND ab.uri = ?'
        );
        $stmt->execute([$username, $addressBookUri]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Addressbook '{$addressBookUri}' not found for user '{$username}'.");
        }
        return (int) $id;
    }
}
