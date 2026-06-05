<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
} catch (\Throwable $e) {
    echo "ERROR: Cannot connect to database: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$issues  = [];
$infos   = [];
$ok      = true;

function check(bool $pass, string $label, string $detail): void
{
    global $issues, $infos, $ok;
    if ($pass) {
        $infos[] = "  OK   $label";
    } else {
        $issues[] = "  WARN $label: $detail";
        $ok = false;
    }
}

function info(string $label, string $value): void
{
    global $infos;
    $infos[] = "  INFO $label: $value";
}

echo "=== Davyn Validate Data ===" . PHP_EOL . PHP_EOL;

// ── Orphan shares (collection_id doesn't match any calendar/addressbook) ───
$orphanCalShares = (int) $pdo->query(
    "SELECT COUNT(*) FROM collection_shares WHERE collection_type='calendar'
     AND collection_id NOT IN (SELECT id FROM calendars)"
)->fetchColumn();
check($orphanCalShares === 0, 'Orphan calendar shares', "$orphanCalShares found");

$orphanAbShares = (int) $pdo->query(
    "SELECT COUNT(*) FROM collection_shares WHERE collection_type='addressbook'
     AND collection_id NOT IN (SELECT id FROM addressbooks)"
)->fetchColumn();
check($orphanAbShares === 0, 'Orphan addressbook shares', "$orphanAbShares found");

// ── Invalid permissions in collection_shares ────────────────────────────────
$invalidPerms = (int) $pdo->query(
    "SELECT COUNT(*) FROM collection_shares
     WHERE permission NOT IN ('owner','read_write','read_only','none')"
)->fetchColumn();
check($invalidPerms === 0, 'Invalid share permissions', "$invalidPerms found");

// ── Owner shares count (should equal number of collection owners) ───────────
$ownerShares = (int) $pdo->query(
    "SELECT COUNT(*) FROM collection_shares WHERE permission = 'owner'"
)->fetchColumn();
info('Owner share entries', (string) $ownerShares);

// ── Shared URI collision risk (username starts with 'shared-') ──────────────
$riskyUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE username LIKE 'shared-%'"
)->fetchColumn();
check($riskyUsers === 0, 'No users with shared- prefix (URI collision risk)', "$riskyUsers found");

// ── Missing sync_token (calendars/addressbooks with NULL or 0 sync_token) ───
$missingCalToken = (int) $pdo->query(
    "SELECT COUNT(*) FROM calendars WHERE sync_token IS NULL OR sync_token = 0"
)->fetchColumn();
check($missingCalToken === 0, 'Calendars with missing sync_token', "$missingCalToken found");

$missingAbToken = (int) $pdo->query(
    "SELECT COUNT(*) FROM addressbooks WHERE sync_token IS NULL OR sync_token = 0"
)->fetchColumn();
check($missingAbToken === 0, 'Addressbooks with missing sync_token', "$missingAbToken found");

// ── Duplicate object URIs per collection (active objects only) ──────────────
$dupCalUris = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT calendar_id, uri, COUNT(*) c FROM calendar_objects
        WHERE deleted_at IS NULL GROUP BY calendar_id, uri HAVING c > 1
    )'
)->fetchColumn();
check($dupCalUris === 0, 'Duplicate calendar object URIs', "$dupCalUris found");

$dupAbUris = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT addressbook_id, uri, COUNT(*) c FROM addressbook_objects
        WHERE deleted_at IS NULL GROUP BY addressbook_id, uri HAVING c > 1
    )'
)->fetchColumn();
check($dupAbUris === 0, 'Duplicate addressbook object URIs', "$dupAbUris found");

// ── Duplicate UIDs per collection ───────────────────────────────────────────
$dupCalUids = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT calendar_id, uid, COUNT(*) c FROM calendar_objects
        WHERE uid IS NOT NULL AND uid != \'\' AND deleted_at IS NULL
        GROUP BY calendar_id, uid HAVING c > 1
    )'
)->fetchColumn();
check($dupCalUids === 0, 'Duplicate calendar UIDs per collection', "$dupCalUids found");

$dupAbUids = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT addressbook_id, uid, COUNT(*) c FROM addressbook_objects
        WHERE uid IS NOT NULL AND uid != \'\' AND deleted_at IS NULL
        GROUP BY addressbook_id, uid HAVING c > 1
    )'
)->fetchColumn();
check($dupAbUids === 0, 'Duplicate addressbook UIDs per collection', "$dupAbUids found");

// ── Duplicate events (same calendar, same SUMMARY+DTSTART) ──────────────────
$dupEvents = 0;
$calIds = $pdo->query('SELECT DISTINCT id FROM calendars')->fetchAll(\PDO::FETCH_COLUMN);
foreach ($calIds as $cid) {
    $objs = $pdo->prepare('SELECT ics FROM calendar_objects WHERE calendar_id = ? AND deleted_at IS NULL');
    $objs->execute([$cid]);
    $groups = [];
    foreach ($objs->fetchAll(\PDO::FETCH_COLUMN) as $ics) {
        $summary = '';
        $dtstart = '';
        if (preg_match('/^SUMMARY[^:]*:([^\r\n]+)/m', $ics, $m)) $summary = strtolower(trim($m[1]));
        if (preg_match('/^DTSTART[^:]*:([^\r\n]+)/m', $ics, $m)) $dtstart = trim($m[1]);
        $key = $summary . '|' . $dtstart;
        if (!$key || $key === '|') continue;
        $groups[$key] = ($groups[$key] ?? 0) + 1;
    }
    $dupEvents += count(array_filter($groups, fn($c) => $c > 1));
}
info('Duplicate event groups', (string) $dupEvents);

// ── Soft-deleted (trash) counts ──────────────────────────────────────────────
$calTrash = (int) $pdo->query(
    "SELECT COUNT(*) FROM calendar_objects WHERE deleted_at IS NOT NULL"
)->fetchColumn();
info('Calendar objects in trash', (string) $calTrash);

$abTrash = (int) $pdo->query(
    "SELECT COUNT(*) FROM addressbook_objects WHERE deleted_at IS NOT NULL"
)->fetchColumn();
info('Addressbook objects in trash', (string) $abTrash);

// ── object_versions count ────────────────────────────────────────────────────
$tables = array_column(
    $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_ASSOC),
    'name'
);
if (in_array('object_versions', $tables, true)) {
    $versionCount = (int) $pdo->query("SELECT COUNT(*) FROM object_versions")->fetchColumn();
    info('Stored object versions', (string) $versionCount);
} else {
    info('object_versions table', 'not present');
}

// ── activity_log count ───────────────────────────────────────────────────────
if (in_array('activity_log', $tables, true)) {
    $logCount = (int) $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
    info('Activity log entries', (string) $logCount);
} else {
    info('activity_log table', 'not present');
}

// ── web_sessions count ───────────────────────────────────────────────────────
if (in_array('web_sessions', $tables, true)) {
    $activeSessions  = (int) $pdo->query("SELECT COUNT(*) FROM web_sessions WHERE revoked_at IS NULL")->fetchColumn();
    $revokedSessions = (int) $pdo->query("SELECT COUNT(*) FROM web_sessions WHERE revoked_at IS NOT NULL")->fetchColumn();
    info('Active web sessions', (string) $activeSessions);
    info('Revoked web sessions', (string) $revokedSessions);
}

// ── Output ────────────────────────────────────────────────────────────────────
foreach ($infos as $line) echo $line . PHP_EOL;
echo PHP_EOL;
if (empty($issues)) {
    echo "All checks passed." . PHP_EOL;
} else {
    echo "Issues found:" . PHP_EOL;
    foreach ($issues as $line) echo $line . PHP_EOL;
    echo PHP_EOL . count($issues) . " issue(s) found." . PHP_EOL;
    exit(1);
}
