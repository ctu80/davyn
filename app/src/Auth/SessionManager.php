<?php
declare(strict_types=1);

namespace Davyn\Auth;

use Davyn\Config\Config;
use Davyn\Http\ClientIp;
use Davyn\User\User;

class SessionManager
{
    private bool $started = false;
    private ?\PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    public function setPdo(\PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        session_name($this->config->sessionName());

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->config->cookieSecure(),
            'httponly' => true,
            'samesite' => $this->config->cookieSameSite(),
        ]);

        session_start();
        $this->started = true;
    }

    public function login(User $user): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;

        if ($this->pdo !== null) {
            $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $repo = new WebSessionRepository($this->pdo);
            $repo->upsert($user->id, session_id(), $ua, ClientIp::get());
            $repo->cleanupForUser(
                $user->id,
                $this->config->sessionCleanupRevokedDays(),
                $this->config->sessionCleanupInactiveDays()
            );
        }
    }

    public function touchSession(): void
    {
        if ($this->pdo === null) return;
        $this->start();
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) return;
        $repo = new WebSessionRepository($this->pdo);
        $repo->upsert((int) $userId, session_id(), $_SERVER['HTTP_USER_AGENT'] ?? null, ClientIp::get());
    }

    public function currentSessionId(): string
    {
        $this->start();
        return session_id();
    }

    public function logout(): void
    {
        $this->start();

        if ($this->pdo !== null && isset($_SESSION['user_id'])) {
            $repo = new WebSessionRepository($this->pdo);
            $repo->revoke(session_id());
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    public function currentUserId(): ?int
    {
        $this->start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->currentUserId() !== null;
    }
}
