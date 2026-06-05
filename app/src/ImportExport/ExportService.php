<?php
declare(strict_types=1);

namespace Davyn\ImportExport;

use PDO;
use RuntimeException;

class ExportService
{
    public function exportCalendar(int $calendarId, PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT ics FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        $stmt->execute([$calendarId]);
        $rows = $stmt->fetchAll();

        $timezones  = [];   // TZID => block (deduplicated)
        $components = [];

        foreach ($rows as $row) {
            $ics = $row['ics'];

            // Preserve VTIMEZONE blocks, deduplicated by TZID
            preg_match_all('/BEGIN:VTIMEZONE\r?\n.*?END:VTIMEZONE/s', $ics, $tzMatches);
            foreach ($tzMatches[0] as $tzBlock) {
                if (preg_match('/^TZID:(.+)$/m', $tzBlock, $idMatch)) {
                    $tzId = trim($idMatch[1]);
                    $timezones[$tzId] = rtrim($tzBlock);
                }
            }

            // Extract event/todo/journal components
            preg_match_all(
                '/BEGIN:(VEVENT|VTODO|VJOURNAL)\r?\n.*?END:\1/s',
                $ics,
                $matches
            );
            foreach ($matches[0] as $block) {
                $components[] = rtrim($block);
            }
        }

        $parts = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Davyn//Export//EN',
        ];
        foreach ($timezones as $tzBlock) {
            $parts[] = $tzBlock;
        }
        foreach ($components as $component) {
            $parts[] = $component;
        }
        $parts[] = 'END:VCALENDAR';

        return implode("\r\n", $parts) . "\r\n";
    }

    public function exportAddressBook(int $addressBookId, PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT vcard FROM addressbook_objects WHERE addressbook_id = ? AND deleted_at IS NULL ORDER BY id'
        );
        $stmt->execute([$addressBookId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = rtrim($row['vcard']);
        }

        return implode("\r\n", $parts) . "\r\n";
    }

    /** Raw ICS for a single calendar object (already a complete VCALENDAR), or null if absent. */
    public function exportCalendarObject(int $calendarId, string $objectUri, PDO $pdo): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT ics FROM calendar_objects WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$calendarId, $objectUri]);
        $ics = $stmt->fetchColumn();
        return $ics === false ? null : (string) $ics;
    }

    /** Raw vCard for a single addressbook object, or null if absent. */
    public function exportAddressBookObject(int $addressBookId, string $objectUri, PDO $pdo): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT vcard FROM addressbook_objects WHERE addressbook_id = ? AND uri = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$addressBookId, $objectUri]);
        $vcard = $stmt->fetchColumn();
        return $vcard === false ? null : (string) $vcard;
    }

    public function resolveCalendarId(string $username, string $calendarUri, PDO $pdo): int
    {
        $row = $pdo->prepare(
            'SELECT c.id FROM calendars c
             JOIN users u ON u.id = c.user_id
             WHERE u.username = ? AND c.uri = ?'
        );
        $row->execute([$username, $calendarUri]);
        $id = $row->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Calendar '{$calendarUri}' not found for user '{$username}'.");
        }
        return (int) $id;
    }

    public function resolveAddressBookId(string $username, string $addressBookUri, PDO $pdo): int
    {
        $row = $pdo->prepare(
            'SELECT ab.id FROM addressbooks ab
             JOIN users u ON u.id = ab.user_id
             WHERE u.username = ? AND ab.uri = ?'
        );
        $row->execute([$username, $addressBookUri]);
        $id = $row->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Addressbook '{$addressBookUri}' not found for user '{$username}'.");
        }
        return (int) $id;
    }

    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
    }
}
