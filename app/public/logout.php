<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\SessionManager;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Http\Csrf;

$config  = new Config();
$pdo     = ConnectionFactory::create($config);
$session = new SessionManager($config);
$session->setPdo($pdo);
$csrf    = new Csrf($session);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$csrf->verify($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit;
}

// Log the sign-out while the session still identifies the user.
$uid = $session->currentUserId();
if ($uid !== null) {
    $u = (new \Davyn\User\UserRepository($pdo))->findById($uid);
    (new \Davyn\Audit\ActivityLog($pdo))->log(
        $uid, 'auth.logout', "User '" . ($u?->username ?? 'unknown') . "' signed out", 'user', $u?->username
    );
}

$session->logout();

header('Location: /login');
exit;
