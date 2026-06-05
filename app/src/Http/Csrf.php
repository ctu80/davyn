<?php
declare(strict_types=1);

namespace Davyn\Http;

use Davyn\Auth\SessionManager;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly SessionManager $session) {}

    public function token(): string
    {
        $this->session->start();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function verify(?string $token): bool
    {
        $this->session->start();

        if ($token === null || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }
}
