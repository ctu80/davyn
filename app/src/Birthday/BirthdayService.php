<?php
declare(strict_types=1);

namespace Davyn\Birthday;

use Davyn\Dav\CalendarObjectRepository;

/**
 * Generates a per-user, read-only "Birthdays" calendar from contacts' vCard BDAY.
 *
 * Each contact with a BDAY produces exactly one all-day, yearly-recurring VEVENT
 * (RRULE:FREQ=YEARLY). The calendar is generated (generated_type='birthdays'), so
 * CalDAV/web PUT/DELETE are rejected and the WebUI marks it read-only.
 *
 * Object writes go through {@see CalendarObjectRepository::putGeneratedObject()} /
 * deleteGeneratedObject() so ETag, sync-token and change-log bookkeeping stay correct
 * (DAVx5 sync-collection REPORT works). Generation is deterministic and idempotent:
 * re-running produces byte-identical ICS, so unchanged contacts cause no sync churn.
 *
 * Birthday objects are keyed on the contact's *URI* (sans .vcf), not the vCard UID,
 * so the targeted syncContact()/removeForContact() trigger — the latter only has the
 * URI of a just-deleted contact — always compute the same object URI.
 */
class BirthdayService
{
    private const CALENDAR_URI     = 'birthdays';
    private const CALENDAR_NAME    = 'Birthdays';
    private const GENERATED_TYPE   = 'birthdays';
    private const COLOR            = '#e879f9';
    /** Year used for BDAY values without a year (vCard `--MMDD`). Leap year keeps Feb-29 valid. */
    private const PLACEHOLDER_YEAR = 1604;

    private CalendarObjectRepository $objects;

    public function __construct(private \PDO $pdo)
    {
        $this->objects = new CalendarObjectRepository($pdo);
    }

    /**
     * Full rebuild of a user's birthday calendar. Idempotent; prunes events whose
     * contact no longer exists or no longer has a BDAY. Used by the CLI and enable().
     *
     * @param ?int $addressbookId When set, only this addressbook is (re)generated and
     *                            pruning is skipped (additive scope), so other books'
     *                            birthdays are left untouched.
     */
    public function regenerate(int $userId, ?int $addressbookId = null): array
    {
        if (!$this->isEnabled($userId)) {
            return ['calendar_id' => null, 'generated' => 0, 'removed' => 0];
        }

        $calendarId = $this->ensureCalendar($userId);
        $contacts   = $this->fetchContactsWithBday($userId, $addressbookId);
        $generated  = 0;
        $keepUris   = [];

        foreach ($contacts as $c) {
            $bday = $this->parseBday((string) ($c['vcard'] ?? ''));
            if ($bday === null) {
                continue;
            }
            $uri = $this->birthdayUri((string) $c['uri']);
            $keepUris[$uri] = true;

            $ics = $this->buildVevent(
                $uri, $bday, (string) $c['vcard'],
                (string) ($c['uid'] ?? ''), (int) $c['addressbook_id']
            );
            $this->objects->putGeneratedObject($calendarId, $uri, $ics);
            $generated++;
        }

        $removed = 0;
        if ($addressbookId === null) {
            $removed = $this->pruneStale($calendarId, array_keys($keepUris));
        }

        $this->touchSettings($userId, $calendarId);

        return ['calendar_id' => $calendarId, 'generated' => $generated, 'removed' => $removed];
    }

    /**
     * Targeted upsert for a single contact (fired after a contact create/update from
     * WebUI or CardDAV). Upserts the birthday event, or removes it when the contact has
     * no parseable BDAY. No-op when the feature is disabled.
     */
    public function syncContact(int $userId, string $contactUri, int $addressbookId, string $vcard): void
    {
        if (!$this->isEnabled($userId)) {
            return;
        }
        $uri  = $this->birthdayUri($contactUri);
        $bday = $this->parseBday($vcard);

        if ($bday === null) {
            // BDAY absent/removed → drop the event if the calendar already exists.
            $calId = $this->findCalendar($userId);
            if ($calId !== null) {
                $this->objects->deleteGeneratedObject($calId, $uri);
            }
            return;
        }

        $calId = $this->ensureCalendar($userId);
        $uid   = $this->parseVcardField($vcard, 'UID');
        $ics   = $this->buildVevent($uri, $bday, $vcard, $uid, $addressbookId);
        $this->objects->putGeneratedObject($calId, $uri, $ics);
        $this->touchSettings($userId, $calId);
    }

    /** Remove the birthday event for a deleted contact (fired after a contact delete). */
    public function removeForContact(int $userId, string $contactUri): void
    {
        $calId = $this->findCalendar($userId);
        if ($calId !== null) {
            $this->objects->deleteGeneratedObject($calId, $this->birthdayUri($contactUri));
        }
    }

    /** Status payload for the WebUI / API. */
    public function status(int $userId): array
    {
        $calId   = $this->findCalendar($userId);
        $enabled = $this->isEnabled($userId);
        $count   = 0;
        if ($calId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$calId]);
            $count = (int) $stmt->fetchColumn();
        }
        $s = $this->pdo->prepare('SELECT last_generated_at FROM birthday_calendar_settings WHERE user_id = ?');
        $s->execute([$userId]);

        return [
            'enabled'           => $enabled,
            'calendar_id'       => $calId,
            'event_count'       => $count,
            'last_generated_at' => $s->fetchColumn() ?: null,
            'read_only'         => true,
        ];
    }

    /** Turn the feature on and (re)build the calendar. */
    public function enable(int $userId): array
    {
        $this->setEnabledFlag($userId, true);
        $this->regenerate($userId);
        return $this->status($userId);
    }

    /** Turn the feature off and delete the generated calendar (regenerable later). */
    public function disable(int $userId): void
    {
        $this->setEnabledFlag($userId, false);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM calendars WHERE user_id = ? AND uri = ? AND generated_type = ?'
            )->execute([$userId, self::CALENDAR_URI, self::GENERATED_TYPE]);
            $this->pdo->prepare(
                'UPDATE birthday_calendar_settings SET calendar_id = NULL, updated_at = ? WHERE user_id = ?'
            )->execute([$this->now(), $userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---- internals -------------------------------------------------------

    /** No settings row ⇒ enabled (feature works out of the box). */
    private function isEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT enabled FROM birthday_calendar_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return $val === false || (int) $val === 1;
    }

    private function setEnabledFlag(int $userId, bool $enabled): void
    {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO birthday_calendar_settings (user_id, enabled, created_at, updated_at)
             VALUES (:uid, :en, :now, :now)
             ON CONFLICT(user_id) DO UPDATE SET enabled = :en, updated_at = :now'
        )->execute([':uid' => $userId, ':en' => $enabled ? 1 : 0, ':now' => $now]);
    }

    private function touchSettings(int $userId, int $calendarId): void
    {
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO birthday_calendar_settings (user_id, enabled, calendar_id, last_generated_at, created_at, updated_at)
             VALUES (:uid, 1, :cal, :now, :now, :now)
             ON CONFLICT(user_id) DO UPDATE SET calendar_id = :cal, last_generated_at = :now, updated_at = :now'
        )->execute([':uid' => $userId, ':cal' => $calendarId, ':now' => $now]);
    }

    private function findCalendar(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM calendars WHERE user_id = ? AND uri = ? AND generated_type = ?'
        );
        $stmt->execute([$userId, self::CALENDAR_URI, self::GENERATED_TYPE]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function ensureCalendar(int $userId): int
    {
        $existing = $this->findCalendar($userId);
        if ($existing !== null) {
            return $existing;
        }
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, color, sync_token, created_at, updated_at, generated_type)
             VALUES (:uid, :uri, :name, :color, 1, :now, :now, :gtype)'
        )->execute([
            ':uid'   => $userId,
            ':uri'   => self::CALENDAR_URI,
            ':name'  => self::CALENDAR_NAME,
            ':color' => self::COLOR,
            ':now'   => $now,
            ':gtype' => self::GENERATED_TYPE,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fetchContactsWithBday(int $userId, ?int $addressbookId = null): array
    {
        if ($addressbookId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT uri, uid, vcard, addressbook_id FROM addressbook_objects ao
                 WHERE ao.addressbook_id = ?
                   AND ao.addressbook_id IN (SELECT id FROM addressbooks WHERE user_id = ?)
                   AND ao.deleted_at IS NULL AND ao.vcard LIKE '%BDAY%'"
            );
            $stmt->execute([$addressbookId, $userId]);
            return $stmt->fetchAll();
        }

        $abStmt = $this->pdo->prepare('SELECT id FROM addressbooks WHERE user_id = ?');
        $abStmt->execute([$userId]);
        $abIds = array_column($abStmt->fetchAll(), 'id');
        if (!$abIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($abIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT uri, uid, vcard, addressbook_id FROM addressbook_objects
             WHERE addressbook_id IN ($placeholders) AND deleted_at IS NULL AND vcard LIKE '%BDAY%'"
        );
        $stmt->execute($abIds);
        return $stmt->fetchAll();
    }

    /** @return array{0:?int,1:int,2:int}|null [year|null, month, day] */
    private function parseBday(string $vcard): ?array
    {
        if (!preg_match('/^BDAY[^:]*:([^\r\n]+)/m', $vcard, $m)) {
            return null;
        }
        $raw = trim($m[1]);
        // With year: 19900315 / 1990-03-15
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $raw, $d)) {
            $y = (int) $d[1]; $mo = (int) $d[2]; $da = (int) $d[3];
            return checkdate($mo, $da, $y) ? [$y, $mo, $da] : null;
        }
        // Year unknown: --0315 / --03-15
        if (preg_match('/^--(\d{2})-?(\d{2})$/', $raw, $d)) {
            $mo = (int) $d[1]; $da = (int) $d[2];
            return checkdate($mo, $da, self::PLACEHOLDER_YEAR) ? [null, $mo, $da] : null;
        }
        return null;
    }

    private function birthdayUri(string $contactUri): string
    {
        return 'birthday-' . $this->contactKey($contactUri) . '.ics';
    }

    private function contactKey(string $contactUri): string
    {
        $key = preg_replace('/\.vcf$/i', '', $contactUri);
        $key = preg_replace('/[^A-Za-z0-9\-_.@]/', '-', (string) $key);
        return substr((string) $key, 0, 180);
    }

    /**
     * @param array{0:?int,1:int,2:int} $bday [year|null, month, day]
     */
    private function buildVevent(string $uri, array $bday, string $vcard, string $contactUid, int $addressbookId): string
    {
        [$year, $month, $day] = $bday;
        $startYear = $year ?? self::PLACEHOLDER_YEAR;
        $ts        = mktime(0, 0, 0, $month, $day, $startYear);
        $dtstart   = date('Ymd', $ts);
        $dtend     = date('Ymd', strtotime('+1 day', $ts));
        // Deterministic DTSTAMP (pinned to the start date) → idempotent ICS, no sync churn.
        $dtstamp   = $dtstart . 'T000000Z';

        $fn      = $this->parseVcardField($vcard, 'FN');
        $summary = $fn !== '' ? $fn . "'s Birthday" : 'Birthday';

        // uri is always "birthday-<key>.ics" → stable UID "birthday-<key>@davyn.local".
        $uid = substr($uri, 0, -4) . '@davyn.local';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Davyn//Birthday Calendar//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART;VALUE=DATE:' . $dtstart,
            'DTEND;VALUE=DATE:' . $dtend,
            'RRULE:FREQ=YEARLY',
            'TRANSP:TRANSPARENT',
            'CATEGORIES:Birthday',
            'SUMMARY:' . $this->escapeText($summary),
            'X-DAVYN-GENERATED:BIRTHDAY',
        ];
        if ($contactUid !== '') {
            $lines[] = 'X-DAVYN-CONTACT-UID:' . $this->escapeText($contactUid);
        }
        $lines[] = 'X-DAVYN-SOURCE-ADDRESSBOOK:' . $addressbookId;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function pruneStale(int $calendarId, array $keepUris): int
    {
        $keep = array_flip($keepUris);
        $stmt = $this->pdo->prepare(
            "SELECT uri FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL AND uri LIKE 'birthday-%'"
        );
        $stmt->execute([$calendarId]);
        $removed = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uri) {
            if (!isset($keep[$uri])) {
                $this->objects->deleteGeneratedObject($calendarId, (string) $uri);
                $removed++;
            }
        }
        return $removed;
    }

    private function parseVcardField(string $vcard, string $field): string
    {
        if (preg_match('/^' . preg_quote($field, '/') . '[^:]*:([^\r\n]+)/m', $vcard, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Escape a text value per RFC 5545 (backslash, comma, semicolon, newline). */
    private function escapeText(string $v): string
    {
        return str_replace(
            ['\\', ',', ';', "\r\n", "\n", "\r"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'],
            $v
        );
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
