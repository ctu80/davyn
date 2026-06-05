<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Http\Csrf;

apiMethodGuard('GET', 'POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT pl.id, pl.token, pl.token_prefix, pl.name, pl.created_at, pl.revoked_at, c.uri as calendar_uri, c.display_name
         FROM public_calendar_links pl
         JOIN calendars c ON c.id = pl.calendar_id
         WHERE c.user_id = ?
         ORDER BY pl.id DESC'
    );
    $stmt->execute([(int) $user->id]);
    apiJson(['links' => $stmt->fetchAll()]);
}

// POST — create link
$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$calUri = trim((string) ($body['calendar_uri'] ?? ''));
$name   = trim((string) ($body['name'] ?? $calUri));

if (!$calUri) apiError('calendar_uri is required', 400);

// Verify ownership
$stmt = $pdo->prepare('SELECT id FROM calendars WHERE user_id = ? AND uri = ?');
$stmt->execute([(int) $user->id, $calUri]);
$cal = $stmt->fetch();
if (!$cal) apiError('Calendar not found or not owned', 404);

$calId = (int) $cal['id'];
$now   = gmdate('Y-m-d\TH:i:s\Z');

// Already shared? Reuse the existing active link instead of creating a duplicate.
$existing = $pdo->prepare(
    'SELECT id, token, token_prefix FROM public_calendar_links
     WHERE calendar_id = ? AND revoked_at IS NULL LIMIT 1'
);
$existing->execute([$calId]);
$row = $existing->fetch();

if ($row) {
    // Legacy rows (created before tokens were stored) can't be reshown — mint a
    // fresh token in place so the link becomes copyable, without duplicating it.
    if (empty($row['token'])) {
        $tokenRaw  = bin2hex(random_bytes(32));
        $pdo->prepare(
            'UPDATE public_calendar_links SET token = ?, token_hash = ?, token_prefix = ? WHERE id = ?'
        )->execute([$tokenRaw, hash('sha256', $tokenRaw), substr($tokenRaw, 0, 8), (int) $row['id']]);
    } else {
        $tokenRaw = (string) $row['token'];
    }
    apiJson([
        'ok'       => true,
        'id'       => (int) $row['id'],
        'token'    => $tokenRaw,
        'prefix'   => substr($tokenRaw, 0, 8),
        'existing' => true,
        'note'     => 'This calendar is already shared — showing the existing link.',
    ]);
}

$tokenRaw  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $tokenRaw);
$prefix    = substr($tokenRaw, 0, 8);

$pdo->prepare(
    'INSERT INTO public_calendar_links (calendar_id, token, token_hash, token_prefix, name, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$calId, $tokenRaw, $tokenHash, $prefix, $name ?: $calUri, $now]);

$id = $pdo->lastInsertId();
apiJson(['ok' => true, 'id' => $id, 'token' => $tokenRaw, 'prefix' => $prefix, 'existing' => false]);
