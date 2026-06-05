<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Dav\AddressBookRepository;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$displayName = trim((string) ($body['display_name'] ?? ''));

if ($displayName === '') apiError('display_name is required', 400);

// Derive a valid, unique URI from the display name (the user never types one).
$base = slugify($displayName) ?: 'addressbook';
$repo = new AddressBookRepository($pdo);

$uri = $base;
for ($n = 1; ; $n++) {
    try {
        $repo->createAddressBookForUser($user->username, $uri, $displayName);
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
    (int) $user->id, 'user.addressbook.create',
    "Created address book '{$displayName}'", 'addressbook', $uri
);

apiJson(['ok' => true, 'uri' => $uri]);

function slugify(string $name): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    return substr($slug, 0, 40);
}
