<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\CalendarRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$displayName = trim((string) ($body['display_name'] ?? ''));
$color       = isset($body['color']) ? trim((string) $body['color']) : null;

if ($displayName === '') apiError('display_name is required', 400);
if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = null;

// Derive a valid, unique URI from the display name (the user never types one).
$base = slugify($displayName) ?: 'calendar';
$repo = new CalendarRepository($pdo);

$uri = $base;
for ($n = 1; ; $n++) {
    try {
        $repo->createCalendarForUser($user->username, $uri, $displayName, null, $color);
        break;
    } catch (\InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'already exists') && $n < 100) {
            $uri = $base . '-' . ($n + 1);
            continue;
        }
        apiError($e->getMessage(), 400);
    }
}

(new ActivityLog($pdo))->log(
    (int) $user->id, 'user.calendar.create',
    "Created calendar '{$displayName}'", 'calendar', $uri
);

apiJson(['ok' => true, 'uri' => $uri]);

function slugify(string $name): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    return substr($slug, 0, 40);
}
