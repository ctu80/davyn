<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Holiday\HolidayProviderRegistry;
use Davyn\Holiday\HolidaySubscriptionService;
use Davyn\User\UserRepository;

/**
 * Rolling holiday generator + provider management.
 *
 *   --list-providers              List every supported provider key (grouped) and exit
 *
 *   --user=NAME                   Act for a single user (by username)
 *   --all-users                   Process every user that has holiday subscriptions
 *
 *   --provider=DE-BW              Target a provider by key …
 *   --country=DE [--region=BW]    … or by country (+ optional region)
 *
 *   --year=2027                   Generate exactly this year (additive, no pruning)
 *   --from-year=2026 --to-year=2028   Generate this range (additive, no pruning)
 *   --dry-run                     Report what would happen; write nothing
 *
 * Behaviour:
 *   • With a provider/country target and --user: subscribe that user (idempotent)
 *     and generate. A year/range additionally generates those years.
 *   • Without a provider target: (re)generate existing subscriptions for the given
 *     user(s). No range → canonical rolling window [year-back, year+ahead] + prune.
 */
$opts = getopt('', [
    'user:', 'all-users', 'provider:', 'country:', 'region:',
    'year:', 'from-year:', 'to-year:', 'dry-run', 'list-providers', 'help',
]);

if (isset($opts['help'])) {
    echo "Usage:\n";
    echo "  php bin/generate-holidays.php --list-providers\n";
    echo "  php bin/generate-holidays.php --user=NAME (--provider=KEY | --country=CC [--region=RR])\n";
    echo "  php bin/generate-holidays.php (--user=NAME | --all-users) [--year=YYYY | --from-year=YYYY --to-year=YYYY] [--dry-run]\n";
    exit(0);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$svc    = new HolidaySubscriptionService($pdo, $config);

// ── --list-providers ─────────────────────────────────────────────────────────
if (isset($opts['list-providers'])) {
    $cat = HolidayProviderRegistry::catalog();
    foreach ($cat['groups'] as $group) {
        echo "\n[{$group}]\n";
        foreach ($cat['countries'] as $c) {
            if ($c['group'] !== $group) {
                continue;
            }
            printf("  %-6s %s%s\n", $c['national_provider_key'], $c['label'],
                $c['has_regions'] ? ' (' . count($c['regions']) . ' regions)' : '');
            foreach ($c['regions'] as $r) {
                printf("    %-8s %s\n", $r['provider_key'], $r['label']);
            }
        }
    }
    echo "\n";
    exit(0);
}

// ── resolve target provider key (optional) ───────────────────────────────────
$targetKey = null;
if (isset($opts['provider'])) {
    $targetKey = strtoupper((string) $opts['provider']);
} elseif (isset($opts['country'])) {
    $country   = strtoupper((string) $opts['country']);
    $region    = isset($opts['region']) ? strtoupper((string) $opts['region']) : null;
    $targetKey = $region ? ($country . '-' . $region) : $country;
}
if ($targetKey !== null && !HolidayProviderRegistry::isValid($targetKey)) {
    fwrite(STDERR, "ERROR: Unknown provider '{$targetKey}'. Use --list-providers.\n");
    exit(1);
}

$dryRun   = isset($opts['dry-run']);
$year     = isset($opts['year'])      ? (int) $opts['year']      : null;
$fromYear = isset($opts['from-year']) ? (int) $opts['from-year'] : null;
$toYear   = isset($opts['to-year'])   ? (int) $opts['to-year']   : null;
if ($year !== null) { $fromYear = $year; $toYear = $year; }

// ── Mode B: explicit provider target → subscribe + generate for one user ──────
if ($targetKey !== null) {
    if (!isset($opts['user'])) {
        fwrite(STDERR, "ERROR: --provider/--country requires --user=NAME.\n");
        exit(1);
    }
    $u = $users->findByUsername((string) $opts['user']);
    if ($u === null) {
        fwrite(STDERR, "ERROR: User '{$opts['user']}' not found.\n");
        exit(1);
    }
    $label = HolidayProviderRegistry::resolve($targetKey)['label'];

    if ($dryRun) {
        echo "[dry-run] would subscribe user '{$opts['user']}' to {$label} ({$targetKey}).\n";
        exit(0);
    }

    $res = $svc->subscribe((int) $u->id, $targetKey);
    $generated = $res['generated'];

    // Optional explicit range on top of the canonical window.
    if ($fromYear !== null || $toYear !== null) {
        $sub = null;
        foreach ($svc->listForUser((int) $u->id) as $s) {
            if ($s['provider_key'] === $targetKey) {
                // fetch the raw row for generate()
                $sub = ['id' => $s['id'], 'calendar_id' => $s['calendar_id'], 'provider_key' => $s['provider_key'],
                        'locale' => $s['locale'], 'years_back' => $s['years_back'], 'years_ahead' => $s['years_ahead'],
                        'last_year_to' => $s['generated_years'] ? end($s['generated_years']) : 0];
            }
        }
        if ($sub) {
            $r = $svc->generate($sub, $fromYear, $toYear);
            $generated += $r['generated'];
        }
    }

    echo "Subscribed '{$opts['user']}' to {$label} ({$targetKey}). Events written: {$generated}.\n";
    exit(0);
}

// ── Mode C: bulk (re)generation of existing subscriptions ─────────────────────
$userIds = [];
if (isset($opts['all-users'])) {
    foreach ($pdo->query('SELECT DISTINCT user_id FROM holiday_calendar_subscriptions') as $row) {
        $userIds[] = (int) $row['user_id'];
    }
    foreach ($pdo->query("SELECT DISTINCT user_id FROM calendars WHERE generated_type = 'holidays'") as $row) {
        $userIds[] = (int) $row['user_id'];
    }
    $userIds = array_values(array_unique($userIds));
} elseif (isset($opts['user'])) {
    $u = $users->findByUsername((string) $opts['user']);
    if ($u === null) {
        fwrite(STDERR, "ERROR: User '{$opts['user']}' not found.\n");
        exit(1);
    }
    $userIds = [(int) $u->id];
} else {
    fwrite(STDERR, "ERROR: specify --user=NAME or --all-users (or a --provider/--country target).\n");
    exit(1);
}

$totalGen = 0;
$totalRem = 0;
$touched  = 0;

foreach ($userIds as $userId) {
    $svc->adoptOrphans($userId);

    $subs = $pdo->prepare('SELECT * FROM holiday_calendar_subscriptions WHERE user_id = ? ORDER BY provider_key');
    $subs->execute([$userId]);
    foreach ($subs->fetchAll() as $sub) {
        if (!$sub['enabled']) {
            continue;
        }
        $label = HolidayProviderRegistry::resolve((string) $sub['provider_key'])['label']
            ?? (string) $sub['provider_key'];

        if ($dryRun) {
            $current = (int) gmdate('Y');
            $from    = $fromYear ?? ($current - (int) $sub['years_back']);
            $to      = $toYear   ?? ($current + (int) $sub['years_ahead']);
            echo "[dry-run] user={$userId} {$label}: years {$from}–{$to}"
                . (($fromYear === null && $toYear === null) ? " (prune stale)" : " (additive)") . "\n";
            continue;
        }

        $r = $svc->generate($sub, $fromYear, $toYear);
        $totalGen += $r['generated'];
        $totalRem += $r['removed'];
        $touched++;
        echo "user={$userId} {$label}: generated={$r['generated']} removed={$r['removed']} "
            . "years=" . implode(',', $r['years']) . "\n";
    }
}

echo $dryRun
    ? "Dry run complete.\n"
    : "Done. Subscriptions touched={$touched}, events written={$totalGen}, stale removed={$totalRem}.\n";
