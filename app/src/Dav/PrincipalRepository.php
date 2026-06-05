<?php
declare(strict_types=1);

namespace Davyn\Dav;

use Davyn\User\User;
use Davyn\User\UserRepository;

class PrincipalRepository
{
    public function __construct(private readonly UserRepository $users) {}

    public function listPrincipals(): array
    {
        return array_values(array_filter(array_map(
            fn(User $u) => $u->isActive ? $this->toArray($u) : null,
            $this->users->listUsers()
        )));
    }

    public function findPrincipalByUsername(string $username): ?array
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user->isActive) {
            return null;
        }
        return $this->toArray($user);
    }

    private function toArray(User $user): array
    {
        return [
            'uri'          => 'principals/' . $user->username,
            'username'     => $user->username,
            'display_name' => $user->displayName,
        ];
    }
}
