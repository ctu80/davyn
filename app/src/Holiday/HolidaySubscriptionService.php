<?php
declare(strict_types=1);

namespace Davyn\Holiday;

use Davyn\Config\Config;
use Davyn\Dav\CalendarObjectRepository;
use PDO;

/**
 * Per-user holiday calendar subscriptions: create, list, regenerate, roll forward,
 * and remove. Each subscription owns one generated, read-only CalDAV calendar whose
 * all-day holiday events are produced deterministically by {@see HolidayGenerator}.
 *
 * All object writes go through {@see CalendarObjectRepository::putGeneratedObject()} /
 * deleteGeneratedObject(), so ETag, sync-token and change-log stay correct and CalDAV
 * clients (DAVx5 etc.) see the calendars and their events.
 */
final class HolidaySubscriptionService
{
    private const GENERATED_TYPE = 'holidays';
    private const COLOR          = '#16a34a';

    private HolidayGenerator $generator;
    private CalendarObjectRepository $objects;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->generator = new HolidayGenerator();
        $this->objects   = new CalendarObjectRepository($pdo);
    }

    /**
     * Subscribe a user to a holiday provider (idempotent on (user, provider)).
     * Creates/adopts the generated calendar, persists the subscription, and
     * generates the rolling window.
     */
    public function subscribe(int $userId, string $providerKey, ?string $locale = null, ?int $yearsAhead = null, ?int $yearsBack = null): array
    {
        $d = HolidayProviderRegistry::resolve($providerKey);
        if ($d === null) {
            throw new \InvalidArgumentException("Unknown holiday provider: {$providerKey}");
        }

        $locale     = $locale ?: $d['default_locale'];
        $yearsAhead = $yearsAhead ?? $this->config->holidayYearsAhead();
        $yearsBack  = $yearsBack ?? $this->config->holidayYearsBack();
        $now        = $this->now();

        $calendarId = $this->ensureCalendar($userId, $d);

        $existing = $this->findSubscription($userId, $d['provider_key']);
        if ($existing) {
            $this->pdo->prepare(
                'UPDATE holiday_calendar_subscriptions
                    SET calendar_id = ?, locale = ?, years_back = ?, years_ahead = ?, enabled = 1, updated_at = ?
                  WHERE id = ?'
            )->execute([$calendarId, $locale, $yearsBack, $yearsAhead, $now, $existing['id']]);
            $subId = (int) $existing['id'];
        } else {
            $this->pdo->prepare(
                'INSERT INTO holiday_calendar_subscriptions
                    (user_id, calendar_id, provider_key, country_code, region_code, locale,
                     years_back, years_ahead, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([
                $userId, $calendarId, $d['provider_key'], $d['country_code'], $d['region_code'],
                $locale, $yearsBack, $yearsAhead, $now, $now,
            ]);
            $subId = (int) $this->pdo->lastInsertId();
        }

        $sub    = $this->getSubscription($subId);
        $result = $this->generate($sub);

        return ['subscription' => $this->getSubscription($subId), 'generated' => $result['generated']];
    }

    /**
     * Generate holidays for a subscription.
     *
     * Default (no range): regenerates the canonical rolling window
     * [year-years_back, year+years_ahead] and prunes stale objects — fully idempotent.
     * With an explicit range (CLI --year / --from-year/--to-year): additive, no pruning,
     * so existing years are preserved.
     *
     * @return array{generated:int, removed:int, years:array<int>}
     */
    public function generate(array $sub, ?int $fromYear = null, ?int $toYear = null): array
    {
        $prune   = ($fromYear === null && $toYear === null);
        $current = (int) gmdate('Y');
        $from    = $fromYear ?? ($current - (int) $sub['years_back']);
        $to      = $toYear   ?? ($current + (int) $sub['years_ahead']);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $calId    = (int) $sub['calendar_id'];
        $key      = (string) $sub['provider_key'];
        $locale   = (string) $sub['locale'];
        $years    = range($from, $to);
        $keepUris = [];
        $generated = 0;

        foreach ($years as $year) {
            foreach ($this->generator->eventsFor($key, $year, $locale) as $ev) {
                $keepUris[$ev['uri']] = true;
                $this->objects->putGeneratedObject($calId, $ev['uri'], $ev['ics']);
                $generated++;
            }
        }

        $removed = 0;
        if ($prune) {
            $removed = $this->pruneStale($calId, $key, array_keys($keepUris));
        }

        $now      = $this->now();
        $lastTo   = $prune ? $to : max($to, (int) ($sub['last_year_to'] ?? 0));
        $this->pdo->prepare(
            'UPDATE holiday_calendar_subscriptions SET last_generated_at = ?, last_year_to = ?, updated_at = ? WHERE id = ?'
        )->execute([$now, $lastTo, $now, (int) $sub['id']]);

        return ['generated' => $generated, 'removed' => $removed, 'years' => $years];
    }

    /** Force a full regenerate of the canonical window for one subscription. */
    public function regenerate(int $userId, int $subId): array
    {
        $sub = $this->getSubscription($subId);
        if ($sub === null || (int) $sub['user_id'] !== $userId) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
        return $this->generate($sub);
    }

    /**
     * Rolling guard: regenerate only subscriptions whose window no longer reaches
     * far enough ahead (or were never generated). Cheap when nothing is due.
     */
    public function runRolling(int $userId): array
    {
        $this->adoptOrphans($userId);

        $current = (int) gmdate('Y');
        $rolled  = 0;
        foreach ($this->listSubscriptions($userId) as $sub) {
            if (!$sub['enabled']) {
                continue;
            }
            $target = $current + (int) $sub['years_ahead'];
            if ($sub['last_generated_at'] === null || (int) ($sub['last_year_to'] ?? 0) < $target) {
                $this->generate($sub);
                $rolled++;
            }
        }
        return ['rolled' => $rolled];
    }

    /** Enable/disable a subscription (does not delete the calendar). */
    public function setEnabled(int $userId, int $subId, bool $enabled): void
    {
        $sub = $this->getSubscription($subId);
        if ($sub === null || (int) $sub['user_id'] !== $userId) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
        $this->pdo->prepare(
            'UPDATE holiday_calendar_subscriptions SET enabled = ?, updated_at = ? WHERE id = ?'
        )->execute([$enabled ? 1 : 0, $this->now(), $subId]);
    }

    /** Remove a subscription and its generated calendar (cascades objects/changes). */
    public function remove(int $userId, int $subId): void
    {
        $sub = $this->getSubscription($subId);
        if ($sub === null || (int) $sub['user_id'] !== $userId) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM holiday_calendar_subscriptions WHERE id = ?')->execute([$subId]);
            // Only delete the calendar if it is the generated holiday calendar we own.
            $this->pdo->prepare(
                "DELETE FROM calendars WHERE id = ? AND user_id = ? AND generated_type = ?"
            )->execute([(int) $sub['calendar_id'], $userId, self::GENERATED_TYPE]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * List a user's subscriptions enriched with calendar metadata, object counts
     * and the generated-year range. Lazily adopts pre-existing holiday calendars.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId): array
    {
        $this->adoptOrphans($userId);

        $current = (int) gmdate('Y');
        $out     = [];
        foreach ($this->listSubscriptions($userId) as $sub) {
            $d = HolidayProviderRegistry::resolve((string) $sub['provider_key']);
            $cal = $this->pdo->prepare('SELECT uri, display_name, color FROM calendars WHERE id = ?');
            $cal->execute([(int) $sub['calendar_id']]);
            $c = $cal->fetch() ?: ['uri' => null, 'display_name' => null, 'color' => null];

            $cnt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL'
            );
            $cnt->execute([(int) $sub['calendar_id']]);

            $lastTo = (int) ($sub['last_year_to'] ?? 0);
            $years  = $lastTo > 0 ? range($current - (int) $sub['years_back'], $lastTo) : [];

            $out[] = [
                'id'                => (int) $sub['id'],
                'provider_key'      => (string) $sub['provider_key'],
                'country_code'      => (string) $sub['country_code'],
                'region_code'       => $sub['region_code'],
                'label'             => $d['label'] ?? (string) $sub['provider_key'],
                'locale'            => (string) $sub['locale'],
                'years_back'        => (int) $sub['years_back'],
                'years_ahead'       => (int) $sub['years_ahead'],
                'enabled'           => (bool) $sub['enabled'],
                'calendar_id'       => (int) $sub['calendar_id'],
                'calendar_uri'      => $c['uri'],
                'calendar_name'     => $c['display_name'],
                'color'             => $c['color'],
                'event_count'       => (int) $cnt->fetchColumn(),
                'generated_years'   => $years,
                'last_generated_at' => $sub['last_generated_at'],
                'read_only'         => true,
            ];
        }
        return $out;
    }

    /** Provider catalog for the UI picker. */
    public function catalog(): array
    {
        return HolidayProviderRegistry::catalog();
    }

    /**
     * Detect generated holiday calendars without a subscription row and adopt them,
     * inferring the provider from the calendar URI. Migrates the pre-existing
     * Baden-Württemberg calendar transparently without creating a duplicate.
     */
    public function adoptOrphans(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.uri FROM calendars c
              WHERE c.user_id = ? AND c.generated_type = ?
                AND NOT EXISTS (SELECT 1 FROM holiday_calendar_subscriptions s WHERE s.calendar_id = c.id)"
        );
        $stmt->execute([$userId, self::GENERATED_TYPE]);

        $adopted = 0;
        $now     = $this->now();
        foreach ($stmt->fetchAll() as $cal) {
            $key = HolidayProviderRegistry::providerKeyFromUri((string) $cal['uri']);
            if ($key === null) {
                continue; // unknown layout — leave it alone
            }
            $d = HolidayProviderRegistry::resolve($key);
            // last_year_to stays NULL so the next rolling pass regenerates it onto the
            // deterministic object scheme (replacing any legacy objects).
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO holiday_calendar_subscriptions
                    (user_id, calendar_id, provider_key, country_code, region_code, locale,
                     years_back, years_ahead, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([
                $userId, (int) $cal['id'], $d['provider_key'], $d['country_code'], $d['region_code'],
                $d['default_locale'], $this->config->holidayYearsBack(), $this->config->holidayYearsAhead(),
                $now, $now,
            ]);
            $adopted++;
        }
        return $adopted;
    }

    // ---- internals -------------------------------------------------------

    private function ensureCalendar(int $userId, array $descriptor): int
    {
        $uri = HolidayProviderRegistry::calendarUri($descriptor['provider_key']);

        $stmt = $this->pdo->prepare(
            'SELECT id FROM calendars WHERE user_id = ? AND uri = ?'
        );
        $stmt->execute([$userId, $uri]);
        $row = $stmt->fetch();
        if ($row) {
            // Make sure it is flagged generated/read-only (adopting an older row).
            $this->pdo->prepare(
                "UPDATE calendars SET generated_type = ? WHERE id = ? AND (generated_type IS NULL OR generated_type = '')"
            )->execute([self::GENERATED_TYPE, (int) $row['id']]);
            return (int) $row['id'];
        }

        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO calendars (user_id, uri, display_name, color, sync_token, created_at, updated_at, generated_type)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([
            $userId, $uri, HolidayProviderRegistry::calendarName($descriptor['provider_key']),
            self::COLOR, $now, $now, self::GENERATED_TYPE,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function pruneStale(int $calendarId, string $providerKey, array $keepUris): int
    {
        $prefix = 'holiday-' . strtolower($providerKey) . '-';
        $stmt   = $this->pdo->prepare(
            "SELECT uri FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL AND uri LIKE 'holiday-%'"
        );
        $stmt->execute([$calendarId]);

        $keep    = array_flip($keepUris);
        $removed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uri) {
            if (isset($keep[$uri])) {
                continue;
            }
            // Remove our own provider's stale objects AND any legacy holiday objects
            // (old URI scheme) so adoption cleans up after itself.
            if (str_starts_with((string) $uri, $prefix) || !$this->looksLikeNewScheme((string) $uri)) {
                $this->objects->deleteGeneratedObject($calendarId, (string) $uri);
                $removed++;
            }
        }
        return $removed;
    }

    /** New scheme: holiday-<providerkey>-<YYYYMMDD>-<key>.ics (providerkey is country[-region]). */
    private function looksLikeNewScheme(string $uri): bool
    {
        return (bool) preg_match('/^holiday-[a-z]{2}(-[a-z]{2,4})?-\d{8}-/', $uri);
    }

    private function findSubscription(int $userId, string $providerKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM holiday_calendar_subscriptions WHERE user_id = ? AND provider_key = ?'
        );
        $stmt->execute([$userId, $providerKey]);
        return $stmt->fetch() ?: null;
    }

    private function getSubscription(int $subId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM holiday_calendar_subscriptions WHERE id = ?');
        $stmt->execute([$subId]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function listSubscriptions(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM holiday_calendar_subscriptions WHERE user_id = ? ORDER BY provider_key'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
