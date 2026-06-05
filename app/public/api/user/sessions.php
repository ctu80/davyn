<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Auth\WebSessionRepository;

apiMethodGuard('GET');
['pdo' => $pdo, 'user' => $user, 'session' => $session, 'config' => $config] = apiUserGuard();

$repo        = new WebSessionRepository($pdo);
$rows        = $repo->listForUser($user->id);
$currentHash = hash('sha256', $session->currentSessionId());
$activeSince = gmdate('Y-m-d\TH:i:s\Z', time() - $config->sessionActiveWindowMinutes() * 60);

$out = [];
foreach ($rows as $r) {
    $notRevoked = $r['revoked_at'] === null;
    $out[] = [
        'id'             => $r['id'],
        'user_agent'     => $r['user_agent'],
        'ip'             => $r['ip'] ?? null,
        'created_at'     => $r['created_at'],
        'last_seen_at'   => $r['last_seen_at'],
        'revoked'        => !$notRevoked,
        'current'        => $r['session_id_hash'] === $currentHash && $notRevoked,
        'recently_active' => $notRevoked && ($r['last_seen_at'] ?? '') >= $activeSince,
    ];
}

apiJson(['sessions' => $out]);
