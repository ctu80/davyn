<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Http\Csrf;
use Davyn\Http\PublicUrl;
use Davyn\Settings\SettingsRepository;

apiMethodGuard('GET', 'POST');
['pdo' => $pdo, 'session' => $session] = apiAdminGuard();

$repo = new SettingsRepository($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apiJson($repo->getAll());
}

// POST — update settings
$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$allowed = ['instance_name', 'default_locale', 'default_theme', 'accent_color', 'public_base_url'];
$updated = [];
foreach ($allowed as $key) {
    if (!isset($body[$key])) continue;
    $value = trim((string) $body[$key]);

    if ($key === 'public_base_url') {
        $value = rtrim($value, '/');
        if (!PublicUrl::isValid($value)) {
            apiError('Invalid public base URL (must be an absolute http(s) URL or empty)', 400);
        }
    }
    if ($key === 'default_locale' && !in_array($value, ['en', 'de'], true)) {
        apiError("Invalid locale: $value", 400);
    }
    if ($key === 'default_theme' && !in_array($value, ['light', 'dark', 'system'], true)) {
        apiError("Invalid theme: $value", 400);
    }
    if ($key === 'accent_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        apiError("Invalid accent_color (must be #rrggbb)", 400);
    }
    if ($key === 'instance_name') {
        // Strip control characters and markup-significant characters so the
        // value is safe in any HTML/JS context it is later rendered into.
        $value = preg_replace('/[\x00-\x1F\x7F<>"\'\\\\]/', '', $value);
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64) {
            apiError("instance_name must be 1–64 characters (no control or markup characters)", 400);
        }
    }

    $repo->set($key, $value);
    $updated[] = $key;
}

apiJson(['ok' => true, 'updated' => $updated, 'settings' => $repo->getAll()]);
