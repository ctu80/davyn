<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Birthday\BirthdayService;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\User\UserRepository;

/**
 * Regenerate the generated, read-only "Birthdays" calendar from contacts' vCard BDAY.
 *
 *   --user=NAME            Act for a single user (by username)
 *   --all-users            Process every user that owns an addressbook or a birthday calendar
 *   --addressbook=URI      Only this addressbook (requires --user; additive, no pruning)
 *   --dry-run              Report what would happen; write nothing
 *
 * Idempotent: running twice produces no duplicates and no sync churn.
 */
$opts = getopt('', ['user:', 'all-users', 'addressbook:', 'dry-run', 'help']);

if (isset($opts['help']) || (!isset($opts['user']) && !isset($opts['all-users']))) {
    echo "Usage:\n";
    echo "  php bin/regenerate-birthdays.php --user=NAME [--addressbook=URI] [--dry-run]\n";
    echo "  php bin/regenerate-birthdays.php --all-users [--dry-run]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$config = new Config();
$pdo    = ConnectionFactory::create($config);
$users  = new UserRepository($pdo);
$svc    = new BirthdayService($pdo);
$dryRun = isset($opts['dry-run']);

// Resolve the set of user ids to process.
$userIds = [];
if (isset($opts['all-users'])) {
    foreach ($pdo->query('SELECT DISTINCT user_id FROM addressbooks') as $r) {
        $userIds[] = (int) $r['user_id'];
    }
    foreach ($pdo->query("SELECT DISTINCT user_id FROM calendars WHERE generated_type = 'birthdays'") as $r) {
        $userIds[] = (int) $r['user_id'];
    }
    foreach ($pdo->query('SELECT user_id FROM birthday_calendar_settings') as $r) {
        $userIds[] = (int) $r['user_id'];
    }
    $userIds = array_values(array_unique($userIds));
} else {
    $u = $users->findByUsername((string) $opts['user']);
    if ($u === null) {
        fwrite(STDERR, "ERROR: User '{$opts['user']}' not found.\n");
        exit(1);
    }
    $userIds = [(int) $u->id];
}

// Optional addressbook scope (requires a single --user).
$abId = null;
if (isset($opts['addressbook'])) {
    if (isset($opts['all-users'])) {
        fwrite(STDERR, "ERROR: --addressbook requires --user, not --all-users.\n");
        exit(1);
    }
    $stmt = $pdo->prepare('SELECT id FROM addressbooks WHERE user_id = ? AND uri = ?');
    $stmt->execute([$userIds[0], (string) $opts['addressbook']]);
    $abId = $stmt->fetchColumn();
    if ($abId === false) {
        fwrite(STDERR, "ERROR: Addressbook '{$opts['addressbook']}' not found for that user.\n");
        exit(1);
    }
    $abId = (int) $abId;
}

$totalGen = 0;
$totalRem = 0;
foreach ($userIds as $userId) {
    if ($dryRun) {
        // Count contacts that would yield a birthday without writing anything.
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM addressbook_objects ao
             WHERE ao.addressbook_id IN (SELECT id FROM addressbooks WHERE user_id = ?)
               AND ao.deleted_at IS NULL AND ao.vcard LIKE '%BDAY%'"
            . ($abId !== null ? ' AND ao.addressbook_id = ' . $abId : '')
        );
        $cnt->execute([$userId]);
        echo "[dry-run] user_id={$userId}: ~" . (int) $cnt->fetchColumn() . " contacts with BDAY\n";
        continue;
    }

    $r = $svc->regenerate($userId, $abId);
    $totalGen += $r['generated'];
    $totalRem += $r['removed'];
    echo "user_id={$userId}: generated={$r['generated']} removed={$r['removed']}"
        . ($r['calendar_id'] === null ? " (disabled — skipped)" : " calendar_id={$r['calendar_id']}") . "\n";
}

echo $dryRun
    ? "Dry run complete.\n"
    : "Done. Events written={$totalGen}, stale removed={$totalRem}.\n";
