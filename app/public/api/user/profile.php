<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

// Self-service profile: a user updates their OWN display name, language and
// theme. Username, role and global branding are intentionally not editable here.
apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$users = new UserRepository($pdo);

$updated = [];
try {
    if (array_key_exists('display_name', $body)) {
        $users->updateDisplayName($user->username, (string) $body['display_name']);
        $updated[] = 'display_name';
    }

    $locale = array_key_exists('locale', $body) ? (string) $body['locale'] : null;
    $theme  = array_key_exists('theme',  $body) ? (string) $body['theme']  : null;
    if ($locale !== null || $theme !== null) {
        $users->updatePreferences($user->username, $locale, $theme);
        if ($locale !== null) $updated[] = 'locale';
        if ($theme  !== null) $updated[] = 'theme';
    }
} catch (\InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'already in use') ? 409 : 400;
    apiError($e->getMessage(), $status);
} catch (\Throwable $e) {
    error_log("[davyn] 500 " . __FILE__ . ": " . $e->getMessage());
    apiError('Internal server error', 500);
}

if ($updated === []) {
    apiError('Nothing to update', 400);
}

if (in_array('display_name', $updated, true)) {
    (new ActivityLog($pdo))->log(
        (int) $user->id, 'user.profile.update', 'Updated own display name', 'user', $user->username
    );
}

$fresh = $users->findById((int) $user->id);
apiJson([
    'ok'           => true,
    'updated'      => $updated,
    'display_name' => $fresh?->displayName,
    'locale'       => $fresh?->locale,
    'theme'        => $fresh?->theme,
]);
