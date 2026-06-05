<?php
declare(strict_types=1);

namespace Davyn\Holiday;

class HolidayService
{
    private const GENERATED_TYPE = 'holidays';

    public function __construct(private \PDO $pdo) {}

    public function generate(int $userId, string $country, ?string $state, int $year): array
    {
        $country = strtolower($country);
        if ($country !== 'de') {
            throw new \InvalidArgumentException("Only country 'DE' is currently supported.");
        }

        $state   = $state ? strtolower($state) : null;
        $calUri  = 'holidays-' . $country . ($state ? '-' . $state : '');
        $calName = 'Holidays DE' . ($state ? ' ' . strtoupper($state) : '');

        $calendarId = $this->ensureCalendar($userId, $calUri, $calName);
        $holidays   = $this->getDeHolidays($year, $state);
        $generated  = 0;

        $keepUris = [];
        foreach ($holidays as [$date, $name]) {
            $uri = 'holiday-' . $date . '-' . preg_replace('/[^a-z0-9]/', '-', strtolower($name)) . '.ics';
            $keepUris[] = $uri;
            $dtstart = str_replace('-', '', $date);
            $ts      = mktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
            $dtend   = date('Ymd', strtotime('+1 day', $ts));
            $uid     = 'holiday-' . $country . '-' . ($state ?? 'national') . '-' . $date . '-' . md5($name);
            $ics     = $this->buildVevent($uid, $dtstart, $dtend, $name);
            $this->upsertObject($calendarId, $uri, $ics);
            $generated++;
        }

        // Also generate for next year
        $holidaysNext = $this->getDeHolidays($year + 1, $state);
        foreach ($holidaysNext as [$date, $name]) {
            $uri = 'holiday-' . $date . '-' . preg_replace('/[^a-z0-9]/', '-', strtolower($name)) . '.ics';
            $keepUris[] = $uri;
            $dtstart = str_replace('-', '', $date);
            $ts      = mktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
            $dtend   = date('Ymd', strtotime('+1 day', $ts));
            $uid     = 'holiday-' . $country . '-' . ($state ?? 'national') . '-' . $date . '-' . md5($name);
            $ics     = $this->buildVevent($uid, $dtstart, $dtend, $name);
            $this->upsertObject($calendarId, $uri, $ics);
            $generated++;
        }

        $this->removeStaleObjects($calendarId, $keepUris);

        return ['calendar_id' => $calendarId, 'calendar_uri' => $calUri, 'generated' => $generated];
    }

    private function getDeHolidays(int $year, ?string $state): array
    {
        $easter = $this->easter($year);

        $holidays = [
            [date('Y-m-d', mktime(0, 0, 0, 1, 1, $year)),   'Neujahr'],
            [date('Y-m-d', $easter + (-2 * 86400)),           'Karfreitag'],
            [date('Y-m-d', $easter),                          'Ostersonntag'],
            [date('Y-m-d', $easter + (1 * 86400)),            'Ostermontag'],
            [date('Y-m-d', mktime(0, 0, 0, 5, 1, $year)),    'Tag der Arbeit'],
            [date('Y-m-d', $easter + (39 * 86400)),           'Christi Himmelfahrt'],
            [date('Y-m-d', $easter + (49 * 86400)),           'Pfingstsonntag'],
            [date('Y-m-d', $easter + (50 * 86400)),           'Pfingstmontag'],
            [date('Y-m-d', mktime(0, 0, 0, 10, 3, $year)),   'Tag der Deutschen Einheit'],
            [date('Y-m-d', mktime(0, 0, 0, 12, 25, $year)),  '1. Weihnachtstag'],
            [date('Y-m-d', mktime(0, 0, 0, 12, 26, $year)),  '2. Weihnachtstag'],
        ];

        // State-specific additions
        if (in_array($state, ['bw', 'by', 'st'], true)) {
            // Heilige Drei Könige
            array_unshift($holidays, [date('Y-m-d', mktime(0, 0, 0, 1, 6, $year)), 'Heilige Drei Könige']);
        }
        if (in_array($state, ['bw', 'by', 'nw', 'rp', 'sl', 'sn', 'th', 'he'], true)) {
            // Fronleichnam
            $holidays[] = [date('Y-m-d', $easter + (60 * 86400)), 'Fronleichnam'];
        }
        if (in_array($state, ['bw', 'by', 'nw', 'rp', 'sl', 'sn', 'th'], true)) {
            // Allerheiligen
            $holidays[] = [date('Y-m-d', mktime(0, 0, 0, 11, 1, $year)), 'Allerheiligen'];
        }
        if ($state === 'by') {
            // Mariä Himmelfahrt
            $holidays[] = [date('Y-m-d', mktime(0, 0, 0, 8, 15, $year)), 'Mariä Himmelfahrt'];
        }
        if ($state === 'be') {
            // Internationaler Frauentag
            $holidays[] = [date('Y-m-d', mktime(0, 0, 0, 3, 8, $year)), 'Internationaler Frauentag'];
        }
        if (in_array($state, ['bb', 'mv', 'sn', 'st', 'th'], true)) {
            // Reformationstag
            $holidays[] = [date('Y-m-d', mktime(0, 0, 0, 10, 31, $year)), 'Reformationstag'];
        }

        usort($holidays, fn($a, $b) => strcmp($a[0], $b[0]));
        return $holidays;
    }

    private function easter(int $year): int
    {
        // Gaussian algorithm for Easter Sunday (Gregorian calendar)
        $a = $year % 19;
        $b = (int) ($year / 100);
        $c = $year % 100;
        $d = (int) ($b / 4);
        $e = $b % 4;
        $f = (int) (($b + 8) / 25);
        $g = (int) (($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = (int) ($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = (int) (($a + 11 * $h + 22 * $l) / 451);
        $month = (int) (($h + $l - 7 * $m + 114) / 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return mktime(0, 0, 0, $month, $day, $year);
    }

    private function ensureCalendar(int $userId, string $uri, string $name): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM calendars WHERE user_id = ? AND uri = ? AND generated_type = ?"
        );
        $stmt->execute([$userId, $uri, self::GENERATED_TYPE]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, color, sync_token, created_at, updated_at, generated_type)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([$userId, $uri, $name, '#16a34a', $now, $now, self::GENERATED_TYPE]);

        return (int) $this->pdo->lastInsertId();
    }

    private function buildVevent(string $uid, string $dtstart, string $dtend, string $summary): string
    {
        $dtstamp = gmdate('Ymd\THis\Z');
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davyn//Holiday Calendar//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:$uid\r\n" .
            "DTSTAMP:{$dtstamp}\r\n" .
            "DTSTART;VALUE=DATE:$dtstart\r\n" .
            "DTEND;VALUE=DATE:$dtend\r\n" .
            "SUMMARY:$summary\r\n" .
            "TRANSP:TRANSPARENT\r\n" .
            "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function upsertObject(int $calendarId, string $uri, string $ics): void
    {
        $etag = '"' . sha1($ics) . '"';
        $size = strlen($ics);
        $now  = gmdate('Y-m-d\TH:i:s\Z');

        $existing = $this->pdo->prepare(
            'SELECT id, etag FROM calendar_objects WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
        );
        $existing->execute([$calendarId, $uri]);
        $row = $existing->fetch();

        if ($row) {
            if ($row['etag'] === $etag) return;
            $this->pdo->prepare(
                'UPDATE calendar_objects SET ics = ?, etag = ?, size = ?, updated_at = ?, component_type = "VEVENT"
                 WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
            )->execute([$ics, $etag, $size, $now, $calendarId, $uri]);
        } else {
            $uid = preg_match('/^UID:([^\r\n]+)/m', $ics, $m) ? trim($m[1]) : $uri;
            $this->pdo->prepare(
                'INSERT INTO calendar_objects (calendar_id, uri, uid, ics, etag, size, component_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, "VEVENT", ?, ?)'
            )->execute([$calendarId, $uri, $uid, $ics, $etag, $size, $now, $now]);
        }

        // Bump sync token
        $tokStmt = $this->pdo->prepare('SELECT sync_token FROM calendars WHERE id = ?');
        $tokStmt->execute([$calendarId]);
        $token = (int) $tokStmt->fetchColumn() + 1;
        $this->pdo->prepare('UPDATE calendars SET sync_token = ?, updated_at = ? WHERE id = ?')
            ->execute([$token, $now, $calendarId]);
    }

    private function removeStaleObjects(int $calendarId, array $keepUris): void
    {
        $now  = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            "SELECT uri FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL AND uri LIKE 'holiday-%'"
        );
        $stmt->execute([$calendarId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uri) {
            if (!in_array($uri, $keepUris, true)) {
                $this->pdo->prepare(
                    'UPDATE calendar_objects SET deleted_at = ?, updated_at = ? WHERE calendar_id = ? AND uri = ? AND deleted_at IS NULL'
                )->execute([$now, $now, $calendarId, $uri]);
            }
        }
    }
}
