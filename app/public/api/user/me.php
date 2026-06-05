<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Http\Csrf;
use Davyn\Http\PublicUrl;
use Davyn\Maintenance\MaintenanceMode;

apiMethodGuard('GET');
// Allowed during maintenance so a blocked (non-admin) user can still load their
// identity + CSRF, see the maintenance screen and sign out.
['config' => $config, 'pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard(true);

$base    = PublicUrl::base($config, $pdo);
$un      = $user->username;
$mm      = MaintenanceMode::fromConfig($config)->status();

apiJson([
    'username'           => $un,
    'display_name'       => $user->displayName,
    'role'               => $user->role,
    'public_base_url'    => $base,
    'dav_base'           => $base . '/dav/',
    'caldav_url'         => $base . '/dav/calendars/' . $un . '/',
    'carddav_url'        => $base . '/dav/addressbooks/' . $un . '/',
    'csrf_token'         => (new Csrf($session))->token(),
    'maintenance'        => $mm['enabled'],
    'maintenance_reason' => $mm['reason'] ?? null,
    // Per-user preferences; null = follow the instance default (default_locale /
    // default_theme from /api/user/settings).
    'locale'             => $user->locale,
    'theme'              => $user->theme,
]);
