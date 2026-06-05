<?php
declare(strict_types=1);

namespace Davyn\Holiday;

use Yasumi\Exception\UnknownLocaleException;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * Turns a provider + year into deterministic, all-day holiday VEVENTs via Yasumi.
 *
 * Determinism is essential: the same provider/year must always produce byte-identical
 * ICS so re-running generation is a no-op (no duplicates, no sync-token churn). To that
 * end the object URI/UID derive from Yasumi's locale-independent holiday *key*, and
 * DTSTAMP is pinned to the holiday date rather than the wall clock.
 */
final class HolidayGenerator
{
    /**
     * Build the holiday events for one provider key and year.
     *
     * @return list<array{uri:string, uid:string, ics:string, date:string, name:string}>
     * @throws \InvalidArgumentException if the provider key is not allowlisted
     */
    public function eventsFor(string $providerKey, int $year, ?string $locale = null): array
    {
        $descriptor = HolidayProviderRegistry::resolve($providerKey);
        if ($descriptor === null) {
            throw new \InvalidArgumentException("Unknown holiday provider: {$providerKey}");
        }

        $locale = $locale ?: $descriptor['default_locale'];
        try {
            $provider = Yasumi::create($descriptor['yasumi_class'], $year, $locale);
        } catch (UnknownLocaleException) {
            // Never let a misconfigured locale break generation — English is always present.
            $provider = Yasumi::create($descriptor['yasumi_class'], $year, 'en_US');
        }

        $slug   = strtolower($descriptor['provider_key']);
        $events = [];

        // Which Yasumi holiday types count as "public holidays" for this provider.
        // Default official+bank (bank matters for the UK); a few providers override
        // this in the registry — e.g. Switzerland adds 'other', because Yasumi tags
        // its cantonal public holidays that way. Observance/season are excluded.
        $allowed = $descriptor['types'] ?? [Holiday::TYPE_OFFICIAL, Holiday::TYPE_BANK];

        foreach ($provider->getHolidays() as $holiday) {
            if (!in_array($holiday->getType(), $allowed, true)) {
                continue;
            }
            $key  = $holiday->getKey();
            $date = $holiday->format('Ymd');
            $name = $holiday->getName();
            if ($name === '') {
                $name = $key;
            }

            // Stable, path-safe slug from the locale-independent key (handles
            // substitute-holiday keys like "substituteHoliday:secondChristmasDay").
            $keySlug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $key), '-');

            $uid = sprintf('holiday-%s-%s-%s@davyn.local', $slug, $date, $keySlug);
            $uri = sprintf('holiday-%s-%s-%s.ics', $slug, $date, $keySlug);

            $events[] = [
                'uri'  => $uri,
                'uid'  => $uid,
                'ics'  => $this->buildVevent($uid, $date, $name, $descriptor['provider_key'], $year),
                'date' => $holiday->format('Y-m-d'),
                'name' => $name,
            ];
        }

        return $events;
    }

    /** Convenience for previews: number of official holidays for a provider/year. */
    public function countFor(string $providerKey, int $year, ?string $locale = null): int
    {
        return count($this->eventsFor($providerKey, $year, $locale));
    }

    private function buildVevent(string $uid, string $dateYmd, string $name, string $providerKey, int $year): string
    {
        $ts    = strtotime($dateYmd . ' 00:00:00 UTC');
        $dtend = gmdate('Ymd', $ts + 86400); // exclusive end (all-day, next day)

        // DTSTAMP is pinned to the holiday date (not the wall clock) so the ICS is
        // byte-stable across regenerations -> identical ETag -> no sync churn.
        $dtstamp = $dateYmd . 'T000000Z';
        $summary = $this->escapeText($name);

        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Davyn//Holiday Calendar//EN\r\n"
            . "CALSCALE:GREGORIAN\r\n"
            . "METHOD:PUBLISH\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:{$dtstamp}\r\n"
            . "DTSTART;VALUE=DATE:{$dateYmd}\r\n"
            . "DTEND;VALUE=DATE:{$dtend}\r\n"
            . "SUMMARY:{$summary}\r\n"
            . "CATEGORIES:Holiday\r\n"
            . "TRANSP:TRANSPARENT\r\n"
            . "X-DAVYN-GENERATED:HOLIDAY\r\n"
            . "X-DAVYN-HOLIDAY-PROVIDER:{$providerKey}\r\n"
            . "X-DAVYN-HOLIDAY-YEAR:{$year}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    /** RFC 5545 TEXT escaping for property values. */
    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        return $value;
    }
}
