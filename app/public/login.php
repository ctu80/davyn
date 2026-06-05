<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\AuthService;
use Davyn\Auth\SessionManager;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Http\Csrf;
use Davyn\User\UserRepository;

$config  = new Config();
$pdo     = ConnectionFactory::create($config);
$users   = new UserRepository($pdo);
$session = new SessionManager($config);
$session->setPdo($pdo);
$csrf    = new Csrf($session);
$auth    = new AuthService($users, $session);
$limiter = new \Davyn\Auth\RateLimiter($pdo);

if ($session->isLoggedIn()) {
    header('Location: /app/');
    exit;
}

// First run: with no admin yet there is nobody to sign in as — funnel everyone to
// the setup wizard instead. Once an admin exists this guard is inert, so existing
// installations behave exactly as before.
if ($users->countActiveAdmins() === 0) {
    header('Location: /setup');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? null;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$csrf->verify($token)) {
        $error = 'Invalid request. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif ($limiter->isBlocked('login', $username)) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } elseif (!$auth->login($username, $password)) {
        $limiter->recordFailure('login', $username);
        $error = 'Invalid username or password.';
    } else {
        $limiter->recordSuccess('login', $username);
        $loggedIn = $users->findByUsername($username);
        // Maintenance mode: only admins may sign in (they manage / switch it off).
        if ($loggedIn !== null
            && $loggedIn->role !== 'admin'
            && \Davyn\Maintenance\MaintenanceMode::fromConfig($config)->isEnabled()
        ) {
            $session->logout();
            $error = 'Davyn is in maintenance mode. Please try again later.';
        } else {
            if ($loggedIn !== null) {
                (new \Davyn\Audit\ActivityLog($pdo))->log(
                    (int) $loggedIn->id, 'auth.login', "User '{$username}' signed in", 'user', $username
                );
            }
            header('Location: /app/');
            exit;
        }
    }
}

$csrfToken = $csrf->token();
// Prefer the admin-configured instance name; fall back to the app name.
$settings  = (new \Davyn\Settings\SettingsRepository($pdo))->getAll();
$appName   = htmlspecialchars(($settings['instance_name'] ?? '') !== '' ? $settings['instance_name'] : $config->appName());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> — Login</title>
    <link rel="icon" type="image/png" href="/assets/davyn-dark.png">
    <link rel="icon" type="image/png" href="/assets/davyn-dark.png" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/png" href="/assets/davyn-light.png" media="(prefers-color-scheme: light)">
    <link rel="apple-touch-icon" href="/assets/davyn-dark.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root { --accent: 129 110 247; --accent2: 34 211 238; }
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #080b14; color: #e5e9f4;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; margin: 0; padding: 1.5rem; position: relative; overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        body::before {
            content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(45rem 45rem at 15% -10%, rgb(99 102 241 / .25), transparent 60%),
                radial-gradient(40rem 40rem at 100% 10%, rgb(34 211 238 / .18), transparent 55%),
                radial-gradient(45rem 45rem at 50% 120%, rgb(168 85 247 / .18), transparent 60%);
        }
        .card {
            position: relative; z-index: 1; width: 100%; max-width: 380px;
            background: linear-gradient(180deg, rgb(255 255 255 / .07), rgb(255 255 255 / .03));
            border: 1px solid rgb(255 255 255 / .1);
            backdrop-filter: blur(18px) saturate(140%); -webkit-backdrop-filter: blur(18px) saturate(140%);
            border-radius: 1.5rem; padding: 2.5rem 2rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / .3), 0 24px 64px -24px rgb(0 0 0 / .7);
        }
        .brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 1.75rem; }
        .mark {
            display: block; width: 2.75rem; height: 2.75rem; border-radius: .9rem;
            object-fit: cover;
            box-shadow: 0 8px 32px -8px rgb(var(--accent) / .7);
            border: 1px solid rgb(255 255 255 / .15);
        }
        .brand-name { font-size: 1.15rem; font-weight: 600; letter-spacing: -.01em; }
        .brand-sub { font-size: .72rem; color: #8b97b3; }
        h1 { font-size: 1.4rem; margin: 0 0 .35rem; letter-spacing: -.02em; }
        .lead { margin: 0 0 1.75rem; color: #8b97b3; font-size: .9rem; }
        label { display: block; font-size: .8rem; font-weight: 500; margin-bottom: .4rem; color: #aeb8d0; }
        input[type=text], input[type=password] {
            display: block; width: 100%; padding: .7rem .85rem; margin-bottom: 1.1rem;
            background: rgb(255 255 255 / .05); border: 1px solid rgb(255 255 255 / .1);
            border-radius: .75rem; font-size: .95rem; color: #e5e9f4; transition: box-shadow .15s, border-color .15s;
        }
        input::placeholder { color: #5e6883; }
        input:focus { outline: none; border-color: transparent; box-shadow: 0 0 0 2px rgb(var(--accent) / .6); }
        button[type=submit] {
            width: 100%; padding: .8rem; margin-top: .25rem; cursor: pointer; color: #fff;
            font-size: .95rem; font-weight: 600; border: none; border-radius: .75rem;
            background: linear-gradient(120deg, rgb(var(--accent)), rgb(168 85 247));
            box-shadow: 0 8px 24px -8px rgb(var(--accent) / .6); transition: filter .15s, transform .1s;
        }
        button[type=submit]:hover { filter: brightness(1.1); }
        button[type=submit]:active { transform: scale(.98); }
        .error {
            background: rgb(248 113 113 / .12); border: 1px solid rgb(248 113 113 / .35); color: #fca5a5;
            padding: .7rem .85rem; border-radius: .75rem; font-size: .85rem; margin-bottom: 1.25rem;
        }
        .foot { margin-top: 1.5rem; text-align: center; font-size: .72rem; color: #5e6883; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <img class="mark" src="/assets/davyn-dark.png" alt="<?= $appName ?>">
        <div>
            <div class="brand-name"><?= $appName ?></div>
            <div class="brand-sub">Private Sync Hub</div>
        </div>
    </div>
    <h1>Welcome back</h1>
    <p class="lead">Sign in to your private cloud.</p>
    <?php if ($error !== null): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="/login">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" placeholder="you" required autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
        <button type="submit">Sign in</button>
    </form>
    <div class="foot">Davyn · self-hosted CalDAV &amp; CardDAV</div>
</div>
</body>
</html>
