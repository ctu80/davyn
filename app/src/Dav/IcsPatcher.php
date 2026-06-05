<?php
declare(strict_types=1);

namespace Davyn\Dav;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Lossless read/patch helper for calendar objects (VEVENT).
 *
 * The golden rule: never rebuild an ICS from scratch when editing. Load the
 * original raw object, patch only the fields the WebUI understands, and leave
 * everything else (VTIMEZONE, ATTENDEE/ORGANIZER, X-* properties, unknown
 * recurrence details, custom VALARM data, …) untouched.
 *
 * Date handling mirrors the existing app convention:
 *   - all-day events use VALUE=DATE with an *exclusive* DTEND (last day + 1);
 *     the WebUI works with an inclusive end date.
 *   - timed events use a floating local time (no TZ) when created here, but an
 *     existing TZID parameter or UTC "Z" suffix is preserved on edit.
 */
final class IcsPatcher
{
    private const PRODID = '-//Davyn//Davyn Calendar//EN';

    /** Recurrence parts we can faithfully round-trip through the WebUI. */
    private const SUPPORTED_RRULE_PARTS = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST'];

    /**
     * Extract the WebUI field set from a raw ICS string.
     *
     * @return array<string,mixed>
     */
    public static function read(string $raw): array
    {
        $base = [
            'summary'              => '',
            'all_day'              => false,
            'dtstart'              => '',
            'dtend'                => '',
            'location'             => '',
            'description'          => '',
            'status'               => '',
            'categories'           => [],
            'recurring'            => false,
            'recurrence'           => ['freq' => '', 'interval' => 1, 'end' => ['type' => 'never', 'until' => '', 'count' => 0]],
            'rrule_raw'            => '',
            'recurrence_supported' => true,
            'reminders'            => [],
            'reminders_supported'  => true,
        ];

        try {
            $vcal = Reader::read($raw);
        } catch (\Throwable) {
            return $base;
        }

        $vevent = self::masterEvent($vcal);
        if ($vevent === null) {
            return $base;
        }

        $base['summary']     = (string) ($vevent->SUMMARY ?? '');
        $base['location']    = (string) ($vevent->LOCATION ?? '');
        $base['description'] = (string) ($vevent->DESCRIPTION ?? '');
        $status              = strtoupper(trim((string) ($vevent->STATUS ?? '')));
        $base['status']      = in_array($status, ['CONFIRMED', 'TENTATIVE', 'CANCELLED'], true) ? $status : '';

        if (isset($vevent->CATEGORIES)) {
            $cats = [];
            foreach ($vevent->select('CATEGORIES') as $c) {
                foreach ($c->getParts() as $p) {
                    $p = trim((string) $p);
                    if ($p !== '') $cats[] = $p;
                }
            }
            $base['categories'] = array_values(array_unique($cats));
        }

        // ── Dates ──
        $dtstart = $vevent->DTSTART;
        $allDay  = $dtstart !== null && self::isDateOnly($dtstart);
        $base['all_day'] = $allDay;
        if ($dtstart !== null) {
            $rawStart = (string) $dtstart->getValue();
            if ($allDay) {
                $base['dtstart'] = self::isoDate($rawStart);
                $rawEnd = isset($vevent->DTEND) ? (string) $vevent->DTEND->getValue() : '';
                $base['dtend'] = $rawEnd !== '' ? self::isoDate(self::shiftDate($rawEnd, -1)) : $base['dtstart'];
            } else {
                $base['dtstart'] = self::isoDateTime($rawStart);
                $rawEnd = isset($vevent->DTEND) ? (string) $vevent->DTEND->getValue() : '';
                $base['dtend'] = $rawEnd !== '' ? self::isoDateTime($rawEnd) : $base['dtstart'];
            }
        }

        // ── Recurrence ──
        if (isset($vevent->RRULE)) {
            $rruleRaw          = (string) $vevent->RRULE;
            $base['rrule_raw'] = $rruleRaw;
            $base['recurring'] = true;
            $parts = self::parseRrule($rruleRaw);

            $freq = strtoupper($parts['FREQ'] ?? '');
            $supported = in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true);
            foreach (array_keys($parts) as $k) {
                if (!in_array($k, self::SUPPORTED_RRULE_PARTS, true)) $supported = false;
            }
            $base['recurrence_supported'] = $supported;

            if ($supported) {
                $rec = ['freq' => $freq, 'interval' => max(1, (int) ($parts['INTERVAL'] ?? 1)), 'end' => ['type' => 'never', 'until' => '', 'count' => 0]];
                if (isset($parts['COUNT'])) {
                    $rec['end'] = ['type' => 'count', 'until' => '', 'count' => (int) $parts['COUNT']];
                } elseif (isset($parts['UNTIL'])) {
                    $rec['end'] = ['type' => 'until', 'until' => self::isoDate($parts['UNTIL']), 'count' => 0];
                }
                $base['recurrence'] = $rec;
            }
        }

        // ── Alarms / reminders ──
        $alarms = [];
        $supported = true;
        foreach ($vevent->select('VALARM') as $alarm) {
            $minutes = self::alarmMinutesBefore($alarm);
            if ($minutes === null) { $supported = false; continue; }
            $alarms[] = ['minutes' => $minutes];
        }
        $base['reminders']           = $alarms;
        $base['reminders_supported'] = $supported;

        return $base;
    }

    /**
     * Produce an updated ICS string. When $raw is non-null the original object
     * is patched in place (lossless); otherwise a fresh object is created.
     *
     * @param array<string,mixed> $f field set from the WebUI
     */
    public static function patch(?string $raw, string $uid, array $f): string
    {
        $isEdit = false;
        $vcal   = null;
        $vevent = null;

        if ($raw !== null && trim($raw) !== '') {
            try {
                $vcal   = Reader::read($raw);
                $vevent = self::masterEvent($vcal);
            } catch (\Throwable) {
                $vcal = null;
            }
        }

        if ($vcal === null || $vevent === null) {
            $vcal = new VCalendar();
            $vcal->PRODID = self::PRODID;
            $vcal->VERSION = '2.0';
            // Drop the auto-created empty VEVENT, if any, then add ours.
            foreach ($vcal->select('VEVENT') as $e) $vcal->remove($e);
            $vevent = $vcal->add('VEVENT', ['UID' => $uid]);
        } else {
            $isEdit = true;
            if (!isset($vevent->UID)) $vevent->UID = $uid;
        }

        $summary = trim((string) ($f['summary'] ?? ''));
        $vevent->SUMMARY = $summary;

        self::setOrRemove($vevent, 'LOCATION', trim((string) ($f['location'] ?? '')));
        self::setOrRemove($vevent, 'DESCRIPTION', trim((string) ($f['description'] ?? '')));

        $status = strtoupper(trim((string) ($f['status'] ?? '')));
        self::setOrRemove($vevent, 'STATUS', in_array($status, ['CONFIRMED', 'TENTATIVE', 'CANCELLED'], true) ? $status : '');

        // Categories (replace; we fully manage this field)
        $vevent->remove('CATEGORIES');
        $cats = array_values(array_filter(array_map('trim', (array) ($f['categories'] ?? []))));
        if ($cats) $vevent->add('CATEGORIES', $cats);

        // ── Dates ──
        $allDay  = (bool) ($f['all_day'] ?? false);
        $dtstart = trim((string) ($f['dtstart'] ?? ''));
        $dtend   = trim((string) ($f['dtend'] ?? ''));
        self::applyDates($vevent, $allDay, $dtstart, $dtend);

        // ── Recurrence (only when the WebUI was allowed to manage it) ──
        if (!empty($f['patch_recurrence'])) {
            $vevent->remove('RRULE');
            $rec  = (array) ($f['recurrence'] ?? []);
            $freq = strtoupper((string) ($rec['freq'] ?? ''));
            if (in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
                $parts    = ['FREQ=' . $freq];
                $interval = max(1, (int) ($rec['interval'] ?? 1));
                if ($interval > 1) $parts[] = 'INTERVAL=' . $interval;
                $end = (array) ($rec['end'] ?? []);
                $type = (string) ($end['type'] ?? 'never');
                if ($type === 'count' && (int) ($end['count'] ?? 0) > 0) {
                    $parts[] = 'COUNT=' . (int) $end['count'];
                } elseif ($type === 'until' && trim((string) ($end['until'] ?? '')) !== '') {
                    $parts[] = 'UNTIL=' . self::untilValue((string) $end['until'], $allDay);
                }
                $vevent->add('RRULE', implode(';', $parts));
            }
        }

        // ── Reminders (only when the WebUI was allowed to manage them) ──
        if (!empty($f['patch_reminders'])) {
            foreach ($vevent->select('VALARM') as $alarm) $vevent->remove($alarm);
            foreach ((array) ($f['reminders'] ?? []) as $r) {
                $min = (int) ($r['minutes'] ?? 0);
                if ($min < 0) continue;
                $valarm = $vevent->add('VALARM');
                $valarm->add('ACTION', 'DISPLAY');
                $valarm->add('DESCRIPTION', $summary !== '' ? $summary : 'Reminder');
                $valarm->add('TRIGGER', self::minutesToTrigger($min));
            }
        }

        // ── Metadata ──
        $now = gmdate('Ymd\THis\Z');
        $vevent->remove('DTSTAMP');
        $vevent->add('DTSTAMP', $now);
        $vevent->remove('LAST-MODIFIED');
        $vevent->add('LAST-MODIFIED', $now);
        $seq = $isEdit ? ((int) (string) ($vevent->SEQUENCE ?? 0)) + 1 : 0;
        $vevent->SEQUENCE = $seq;

        return $vcal->serialize();
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function masterEvent(VCalendar $vcal): ?VEvent
    {
        $first = null;
        foreach ($vcal->select('VEVENT') as $ve) {
            if ($first === null) $first = $ve;
            // Prefer the master (no RECURRENCE-ID) for editing.
            if (!isset($ve->{'RECURRENCE-ID'})) return $ve;
        }
        return $first;
    }

    private static function isDateOnly($prop): bool
    {
        $v = $prop['VALUE'] ?? null;
        if ($v !== null && strtoupper((string) $v) === 'DATE') return true;
        return (bool) preg_match('/^\d{8}$/', (string) $prop->getValue());
    }

    private static function setOrRemove(VEvent $c, string $name, string $value): void
    {
        $c->remove($name);
        if ($value !== '') $c->add($name, $value);
    }

    private static function applyDates(VEvent $vevent, bool $allDay, string $dtstart, string $dtend): void
    {
        if ($dtstart === '') return;

        // Preserve TZID / UTC kind of the existing timed properties.
        $startTzid = self::tzidOf($vevent->DTSTART ?? null);
        $startUtc  = self::isUtc($vevent->DTSTART ?? null);
        $endTzid   = self::tzidOf($vevent->DTEND ?? null) ?? $startTzid;
        $endUtc    = ($vevent->DTEND ?? null) !== null ? self::isUtc($vevent->DTEND) : $startUtc;

        $vevent->remove('DTSTART');
        $vevent->remove('DTEND');

        if ($allDay) {
            $s = $vevent->add('DTSTART', self::toIcsDate($dtstart));
            $s['VALUE'] = 'DATE';
            $endInclusive = $dtend !== '' ? $dtend : $dtstart;
            $e = $vevent->add('DTEND', self::shiftDate(self::toIcsDate($endInclusive), 1));
            $e['VALUE'] = 'DATE';
            return;
        }

        $sVal = self::toIcsDateTime($dtstart) . ($startUtc ? 'Z' : '');
        $s = $vevent->add('DTSTART', $sVal);
        if ($startTzid !== null) $s['TZID'] = $startTzid;

        if ($dtend !== '') {
            $eVal = self::toIcsDateTime($dtend) . ($endUtc ? 'Z' : '');
            $e = $vevent->add('DTEND', $eVal);
            if ($endTzid !== null) $e['TZID'] = $endTzid;
        }
    }

    private static function tzidOf($prop): ?string
    {
        if ($prop === null) return null;
        $t = $prop['TZID'] ?? null;
        return $t !== null ? (string) $t : null;
    }

    private static function isUtc($prop): bool
    {
        if ($prop === null) return false;
        return str_ends_with((string) $prop->getValue(), 'Z');
    }

    /** @return array<string,string> */
    private static function parseRrule(string $rrule): array
    {
        $out = [];
        foreach (explode(';', $rrule) as $seg) {
            if ($seg === '') continue;
            [$k, $v] = array_pad(explode('=', $seg, 2), 2, '');
            $out[strtoupper(trim($k))] = trim($v);
        }
        return $out;
    }

    /** Returns whole-minutes-before-start for a simple relative DISPLAY/AUDIO alarm, or null if unsupported. */
    private static function alarmMinutesBefore($alarm): ?int
    {
        $trigger = $alarm->TRIGGER ?? null;
        if ($trigger === null) return null;

        // Absolute triggers (VALUE=DATE-TIME) are not representable as "minutes before".
        $valueType = $trigger['VALUE'] ?? null;
        if ($valueType !== null && strtoupper((string) $valueType) === 'DATE-TIME') return null;
        // RELATED=END is a different anchor; treat as unsupported to stay safe.
        $related = $trigger['RELATED'] ?? null;
        if ($related !== null && strtoupper((string) $related) !== 'START') return null;

        $dur = trim((string) $trigger->getValue());
        if (!preg_match('/^-?P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $dur, $m)) return null;
        if (!str_starts_with($dur, '-') && $dur !== 'PT0S' && $dur !== 'P0D') {
            // A positive trigger fires *after* the start — not a "remind before".
            return null;
        }
        $days = (int) ($m[1] ?? 0); $h = (int) ($m[2] ?? 0); $min = (int) ($m[3] ?? 0); $s = (int) ($m[4] ?? 0);
        if ($s % 60 !== 0) return null;
        return $days * 1440 + $h * 60 + $min + intdiv($s, 60);
    }

    private static function minutesToTrigger(int $min): string
    {
        if ($min === 0) return '-PT0M';
        if ($min % 1440 === 0) return '-P' . intdiv($min, 1440) . 'D';
        if ($min % 60 === 0) return '-PT' . intdiv($min, 60) . 'H';
        return '-PT' . $min . 'M';
    }

    private static function untilValue(string $iso, bool $allDay): string
    {
        $d = self::toIcsDate($iso);
        // RFC 5545: UNTIL must match DTSTART's value type. For timed events use a
        // UTC date-time at the end of the day so the final occurrence is included.
        return $allDay ? $d : $d . 'T235959Z';
    }

    // ── date format helpers (mirror api/user/events.php) ──

    private static function toIcsDate(string $dt): string
    {
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $dt, $m)) return "{$m[1]}{$m[2]}{$m[3]}";
        return preg_replace('/[-:]/', '', substr($dt, 0, 10));
    }

    private static function toIcsDateTime(string $dt): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $dt, $m)) {
            return "{$m[1]}{$m[2]}{$m[3]}T{$m[4]}{$m[5]}00";
        }
        return preg_replace('/[-:]/', '', rtrim($dt, 'Z'));
    }

    private static function isoDate(string $dt): string
    {
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $dt, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return $dt;
    }

    private static function isoDateTime(string $dt): string
    {
        $dt = preg_replace('/Z$/', '', $dt);
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})T(\d{2}):?(\d{2})/', $dt, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}";
        }
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $dt, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}T00:00";
        return $dt;
    }

    private static function shiftDate(string $ymd, int $days): string
    {
        $iso = self::isoDate($ymd);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));
        if ($d === false) return self::toIcsDate($ymd);
        return $d->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Ymd');
    }
}
