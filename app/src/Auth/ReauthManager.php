<?php
declare(strict_types=1);

namespace Davyn\Auth;

class ReauthManager
{
    private const SESSION_KEY = 'admin_reauth_at';
    private const WINDOW_SECONDS = 600; // 10 minutes

    public function __construct(private readonly SessionManager $session) {}

    public function confirm(): void
    {
        $this->session->start();
        $_SESSION[self::SESSION_KEY] = time();
    }

    public function isValid(): bool
    {
        $this->session->start();
        $at = $_SESSION[self::SESSION_KEY] ?? null;
        if ($at === null) return false;
        return (time() - (int) $at) < self::WINDOW_SECONDS;
    }

    public function expiresAt(): ?string
    {
        $this->session->start();
        $at = $_SESSION[self::SESSION_KEY] ?? null;
        if ($at === null) return null;
        $exp = (int) $at + self::WINDOW_SECONDS;
        return gmdate('Y-m-d\TH:i:s\Z', $exp);
    }

    public function clear(): void
    {
        $this->session->start();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
