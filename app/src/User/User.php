<?php
declare(strict_types=1);

namespace Davyn\User;

class User
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $username,
        public readonly string  $displayName,
        public readonly string  $passwordHash,
        public readonly string  $role,
        public readonly bool    $isActive,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $lastLoginAt,
        // Per-user preferences; null = follow the instance default.
        public readonly ?string $locale = null,
        public readonly ?string $theme = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int)  $row['id'],
            username:            $row['username'],
            displayName:         $row['display_name'],
            passwordHash:        $row['password_hash'],
            role:                $row['role'],
            isActive:     (bool) $row['is_active'],
            createdAt:           $row['created_at'],
            updatedAt:           $row['updated_at'],
            lastLoginAt:         $row['last_login_at'] ?? null,
            locale:              isset($row['locale']) && $row['locale'] !== '' ? (string) $row['locale'] : null,
            theme:               isset($row['theme'])  && $row['theme']  !== '' ? (string) $row['theme']  : null,
        );
    }
}
