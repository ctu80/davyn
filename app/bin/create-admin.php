<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Setup\SetupAlreadyDoneException;
use Davyn\Setup\SetupService;
use Davyn\User\UserRepository;

// CLI fallback for the web setup wizard (/setup): creates the very first admin.
// Refuses if an admin already exists — use create-user.php for additional users.
$opts = getopt('', ['username:', 'display-name:', 'password:', 'force']);

$username    = isset($opts['username'])     ? (string) $opts['username']     : null;
$displayName = isset($opts['display-name']) ? (string) $opts['display-name'] : '';
$password    = isset($opts['password'])     ? (string) $opts['password']     : null;
$force       = array_key_exists('force', $opts);

if ($username === null || $username === '') {
    echo "Davyn — create the first admin (CLI fallback for /setup)" . PHP_EOL;
    echo "Usage: php create-admin.php --username <u> [--display-name <n>] [--password <p>] [--force]" . PHP_EOL;
    echo "  --display-name  defaults to the username" . PHP_EOL;
    echo "  --password      prompted interactively if omitted (min 8 chars)" . PHP_EOL;
    echo "  --force         create another admin even if one already exists" . PHP_EOL;
    exit(1);
}

if ($password === null) {
    if (function_exists('readline')) {
        $raw = readline('Password: ');
    } else {
        echo 'Password: ';
        $raw = fgets(STDIN);
    }
    $password = ($raw !== false) ? trim((string) $raw) : '';
}

try {
    $config = new Config();
    $pdo    = ConnectionFactory::create($config);
    $users  = new UserRepository($pdo);
    $setup  = new SetupService($pdo, $users);

    if ($force) {
        // Bypass the first-run guard but keep the password rule.
        if (mb_strlen($password) < SetupService::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(
                'Password must be at least ' . SetupService::MIN_PASSWORD_LENGTH . ' characters.'
            );
        }
        $user = $users->createUser($username, $displayName !== '' ? $displayName : $username, $password, 'admin');
    } else {
        $user = $setup->createFirstAdmin($username, $displayName, $password);
    }

    echo "Created admin: {$user->username}" . PHP_EOL;
    echo "Display name : {$user->displayName}" . PHP_EOL;
    echo "Open /setup is now closed; sign in at /login." . PHP_EOL;
} catch (SetupAlreadyDoneException) {
    echo "ERROR: Davyn already has an admin. Use create-user.php to add more users, "
        . "or pass --force to add another admin." . PHP_EOL;
    exit(1);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
