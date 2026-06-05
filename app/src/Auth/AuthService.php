<?php
declare(strict_types=1);

namespace Davyn\Auth;

use Davyn\User\UserRepository;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionManager $session,
    ) {}

    public function login(string $username, string $password): bool
    {
        $user = $this->users->findByUsername($username);

        if ($user === null || !$user->isActive) {
            return false;
        }

        if (!password_verify($password, $user->passwordHash)) {
            return false;
        }

        $this->users->updateLastLogin($user->id);
        $this->session->login($user);

        return true;
    }
}
