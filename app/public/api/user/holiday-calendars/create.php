<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

use Davyn\Audit\ActivityLog;
use Davyn\Holiday\HolidayGenerator;
use Davyn\Holiday\HolidayProviderRegistry;
use Davyn\Holiday\HolidaySubscriptionService;
use Davyn\Http\Csrf;

apiMethodGuard('POST');
['pdo' => $pdo, 'user' => $user, 'session' => $session, 'config' => $config] = apiUserGuard();

$csrf = new Csrf($session);
if (!$csrf->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    apiError('Invalid CSRF token', 403);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$providerKey = trim((string) ($body['provider_key'] ?? ''));
$locale      = isset($body['locale']) && $body['locale'] !== '' ? (string) $body['locale'] : null;
$yearsAhead  = isset($body['years_ahead']) ? (int) $body['years_ahead'] : null;
$dryRun      = (bool) ($body['dry_run'] ?? false);

if ($providerKey === '') apiError('provider_key is required', 400);

$descriptor = HolidayProviderRegistry::resolve($providerKey);
if ($descriptor === null) apiError('Unsupported holiday provider', 400);

// Preview: count holidays for this year + next without persisting anything.
if ($dryRun) {
    $gen     = new HolidayGenerator();
    $current = (int) gmdate('Y');
    try {
        apiJson([
            'ok'           => true,
            'preview'      => true,
            'provider_key' => $descriptor['provider_key'],
            'label'        => $descriptor['label'],
            'this_year'    => ['year' => $current,     'count' => $gen->countFor($descriptor['provider_key'], $current, $locale)],
            'next_year'    => ['year' => $current + 1, 'count' => $gen->countFor($descriptor['provider_key'], $current + 1, $locale)],
        ]);
    } catch (\Throwable $e) {
        apiError('Preview failed: ' . $e->getMessage(), 400);
    }
}

try {
    $svc    = new HolidaySubscriptionService($pdo, $config);
    $result = $svc->subscribe((int) $user->id, $providerKey, $locale, $yearsAhead);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 400);
}

(new ActivityLog($pdo))->log(
    (int) $user->id,
    'holiday.subscribe',
    'Added holiday calendar ' . $descriptor['label'],
    'calendar',
    (string) ($result['subscription']['calendar_id'] ?? '')
);

apiJson(['ok' => true, 'subscription' => $result['subscription'], 'generated' => $result['generated']]);
