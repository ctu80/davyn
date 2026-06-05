<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Sharing\SharingService;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user] = apiUserGuard();

$today   = gmdate('Y-m-d');
$in7days = gmdate('Y-m-d', strtotime('+7 days'));
$todayY  = (int) substr($today, 0, 4);

// ── Accessible calendars (owned + shared, including generated) ────────────
$svc       = new SharingService($pdo);
$calendars = $svc->listAccessibleCalendarsForUser((int) $user->id);

// ── Upcoming events (today + 7 days, recurrence-aware) ───────────────────
$upcoming = [];
foreach ($calendars as $cal) {
    $calId = (int) $cal['id'];
    $rows  = $pdo->prepare(
        'SELECT uri, ics FROM calendar_objects
         WHERE calendar_id = ? AND deleted_at IS NULL AND component_type = "VEVENT"
         ORDER BY rowid ASC LIMIT 500'
    );
    $rows->execute([$calId]);
    foreach ($rows->fetchAll() as $obj) {
        $ics     = $obj['ics'] ?? '';
        $dtstart = parseDtstart($ics);
        if ($dtstart === null) continue;

        $allDay  = !str_contains($dtstart, 'T');
        $dateStr = substr($dtstart, 0, 8); // YYYYMMDD
        if (strlen($dateStr) < 8 || !is_numeric($dateStr)) continue;

        $origDate = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);

        // For YEARLY recurring events (birthdays, annual events): find the next
        // occurrence within the window — DTSTART may be years in the past.
        $rrule = strtoupper(parseLine($ics, 'RRULE'));
        if (str_contains($rrule, 'FREQ=YEARLY')) {
            $mm = substr($dateStr, 4, 2);
            $dd = substr($dateStr, 6, 2);
            $occurrenceDate = null;
            foreach ([$todayY, $todayY + 1] as $yr) {
                $candidate = sprintf('%04d-%02d-%02d', $yr, (int)$mm, (int)$dd);
                if (!checkdate((int)$mm, (int)$dd, $yr)) {
                    $candidate = sprintf('%04d-03-01', $yr); // Feb-29 fallback
                }
                if ($candidate >= $today && $candidate <= $in7days) {
                    $occurrenceDate = $candidate;
                    break;
                }
            }
            if ($occurrenceDate === null) continue;
        } else {
            if ($origDate < $today || $origDate > $in7days) continue;
            $occurrenceDate = $origDate;
        }

        // Parse start time
        $time = '';
        if (!$allDay) {
            $tp = substr($dtstart, 9, 6);
            $hh = substr($tp, 0, 2);
            $mn = substr($tp, 2, 2);
            if (is_numeric($hh) && is_numeric($mn)) {
                $time = $hh . ':' . $mn;
            }
        }

        // Parse end time from DTEND
        $timeEnd = '';
        if (!$allDay) {
            $dtend = parseLine($ics, 'DTEND');
            if ($dtend !== '' && str_contains($dtend, 'T')) {
                $ep = substr(ltrim($dtend), 9, 6);
                $eh = substr($ep, 0, 2);
                $em = substr($ep, 2, 2);
                if (is_numeric($eh) && is_numeric($em)) {
                    $timeEnd = $eh . ':' . $em;
                }
            }
        }

        $upcoming[] = [
            'calendar_name' => $cal['display_name'],
            'cal_uri'       => $cal['uri'],
            'color'         => $cal['color'] ?? null,
            'uri'           => $obj['uri'],
            'summary'       => parseLine($ics, 'SUMMARY') ?: '(no title)',
            'location'      => parseLine($ics, 'LOCATION'),
            'description'   => mb_strimwidth(parseLine($ics, 'DESCRIPTION'), 0, 300, '…'),
            'date'          => $occurrenceDate,
            'all_day'       => $allDay,
            'time'          => $time,
            'time_end'      => $timeEnd,
            'dtstart_raw'   => $dtstart,
            '_sort'         => $occurrenceDate . ($time ?: '00:00'),
        ];
    }
}

usort($upcoming, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));
$upcoming = array_map(static function (array $e): array { unset($e['_sort']); return $e; }, $upcoming);

// ── Recent user activity ──────────────────────────────────────────────────
$recentActivity = [];
$existingTables = array_column(
    $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_ASSOC),
    'name'
);
if (in_array('activity_log', $existingTables, true)) {
    $stmt = $pdo->prepare(
        'SELECT action, summary, created_at FROM activity_log
         WHERE actor_user_id = ?
         ORDER BY id DESC LIMIT 10'
    );
    $stmt->execute([(int) $user->id]);
    $recentActivity = $stmt->fetchAll();
}

apiJson([
    'today'           => $today,
    'upcoming'        => array_values($upcoming),
    'recent_activity' => $recentActivity,
]);

// ── Helpers ───────────────────────────────────────────────────────────────
function parseDtstart(string $ics): ?string
{
    if (preg_match('/^DTSTART[^:]*:([^\r\n]+)/m', $ics, $m)) {
        return trim($m[1]);
    }
    return null;
}

function parseLine(string $ics, string $prop): string
{
    if (preg_match('/^' . preg_quote($prop, '/') . '[^:]*:([^\r\n]+)/m', $ics, $m)) {
        return trim($m[1]);
    }
    return '';
}
