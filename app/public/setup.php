<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Davyn\Auth\SessionManager;
use Davyn\Config\Config;
use Davyn\Database\ConnectionFactory;
use Davyn\Http\Csrf;
use Davyn\Setup\SetupService;
use Davyn\User\UserRepository;

$config  = new Config();
$pdo     = ConnectionFactory::create($config);
$users   = new UserRepository($pdo);
$session = new SessionManager($config);
$session->setPdo($pdo);
$setup   = new SetupService($pdo, $users);

// Setup is a one-time door: once an admin exists it is closed for good.
if ($setup->isInitialized()) {
    header('Location: /login');
    exit;
}

$csrf    = new Csrf($session);
$token   = $csrf->token();
$appName = htmlspecialchars($config->appName());

// HTTPS hint: true if the browser reached us over TLS directly or via a proxy.
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $appName ?> — Setup</title>
    <link rel="icon" type="image/png" href="/assets/davyn-dark.png">
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
            position: relative; z-index: 1; width: 100%; max-width: 420px;
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
        .lead { margin: 0 0 1.5rem; color: #8b97b3; font-size: .9rem; line-height: 1.5; }
        label { display: block; font-size: .8rem; font-weight: 500; margin-bottom: .4rem; color: #aeb8d0; }
        label .opt { color: #5e6883; font-weight: 400; }
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
        button[type=submit]:disabled { opacity: .6; cursor: progress; }
        .msg { padding: .7rem .85rem; border-radius: .75rem; font-size: .85rem; margin-bottom: 1.25rem; }
        .error { background: rgb(248 113 113 / .12); border: 1px solid rgb(248 113 113 / .35); color: #fca5a5; }
        .ok     { background: rgb(52 211 153 / .12); border: 1px solid rgb(52 211 153 / .35); color: #6ee7b7; }
        .warn {
            background: rgb(251 191 36 / .1); border: 1px solid rgb(251 191 36 / .3); color: #fcd34d;
            padding: .7rem .85rem; border-radius: .75rem; font-size: .82rem; margin-bottom: 1.25rem; line-height: 1.5;
        }
        .note {
            margin-top: 1.5rem; padding: .85rem .9rem; border-radius: .75rem; line-height: 1.55;
            background: rgb(255 255 255 / .03); border: 1px solid rgb(255 255 255 / .08);
            font-size: .76rem; color: #8b97b3;
        }
        .note b { color: #aeb8d0; font-weight: 600; }
        .foot { margin-top: 1.5rem; text-align: center; font-size: .72rem; color: #5e6883; }
        .hidden { display: none; }
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
    <h1>Welcome to <?= $appName ?></h1>
    <p class="lead">Create the first administrator. This account manages the instance — users, collections, backups and security.</p>

    <?php if (!$isHttps): ?>
        <div class="warn">
            <b>You are on plain HTTP.</b> For production, serve <?= $appName ?> over HTTPS — ideally behind a
            reverse proxy (Nginx Proxy Manager, Traefik, Caddy, nginx, Apache…) that terminates TLS — and set
            <b>Base URL</b> to your public <code>https://</code> address.
        </div>
    <?php endif; ?>

    <div id="msg" class="msg hidden"></div>

    <form id="setup-form" autocomplete="off">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" placeholder="admin" required autofocus>

        <label for="display_name">Display name <span class="opt">(optional)</span></label>
        <input type="text" id="display_name" name="display_name" autocomplete="name" placeholder="Administrator">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" required>

        <label for="password_confirm">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" placeholder="Repeat password" required>

        <button type="submit" id="submit-btn">Create administrator</button>
    </form>

    <div class="note">
        <b>Deployment tip.</b> Run <?= $appName ?> behind any reverse proxy and enable HTTPS for production.
        Point <b>BASE_URL</b> at your public HTTPS address so DAVx5 / Thunderbird and shared links use the
        correct URL. You can change this later under <b>Settings → Security</b>.
    </div>

    <div class="foot"><?= $appName ?> · self-hosted CalDAV &amp; CardDAV</div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode($token) ?>;
    var form = document.getElementById('setup-form');
    var btn  = document.getElementById('submit-btn');
    var msg  = document.getElementById('msg');

    function show(kind, text) {
        msg.className = 'msg ' + kind;
        msg.textContent = text;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var username = document.getElementById('username').value.trim();
        var display  = document.getElementById('display_name').value.trim();
        var pw        = document.getElementById('password').value;
        var pw2       = document.getElementById('password_confirm').value;

        if (pw.length < 8)  { show('error', 'Password must be at least 8 characters.'); return; }
        if (pw !== pw2)      { show('error', 'Passwords do not match.'); return; }

        btn.disabled = true;
        msg.className = 'msg hidden';

        fetch('/api/setup/create-admin', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ username: username, display_name: display, password: pw, password_confirm: pw2 })
        }).then(function (res) {
            return res.json().then(function (body) { return { ok: res.ok, body: body }; });
        }).then(function (r) {
            if (!r.ok) {
                btn.disabled = false;
                show('error', (r.body && r.body.error) || 'Setup failed. Please try again.');
                return;
            }
            show('ok', 'Administrator created. Redirecting…');
            window.location.href = (r.body && r.body.redirect) || '/login';
        }).catch(function () {
            btn.disabled = false;
            show('error', 'Network error. Please try again.');
        });
    });
})();
</script>
</body>
</html>
