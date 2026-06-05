<?php
declare(strict_types=1);

namespace Davyn\Sharing;

use InvalidArgumentException;
use PDO;

class SharingService
{
    private const VALID_PERMISSIONS = ['read_write', 'read_only', 'none'];
    private const ALL_PERMISSIONS   = ['owner', 'read_write', 'read_only', 'none'];
    private const VALID_TYPES       = ['calendar', 'addressbook'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Returns the effective permission for $userId on a calendar.
     * Returns 'owner' if the user owns the calendar, the share permission if shared,
     * or 'none' if no access.
     */
    public function getCalendarPermission(int $userId, int $calendarId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM calendars WHERE id = ?'
        );
        $stmt->execute([$calendarId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) {
            return 'none';
        }
        if ((int) $ownerId === $userId) {
            return 'owner';
        }
        return $this->getSharePermission('calendar', $calendarId, $userId);
    }

    /**
     * Returns the effective permission for $userId on an addressbook.
     */
    public function getAddressBookPermission(int $userId, int $addressBookId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM addressbooks WHERE id = ?'
        );
        $stmt->execute([$addressBookId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) {
            return 'none';
        }
        if ((int) $ownerId === $userId) {
            return 'owner';
        }
        return $this->getSharePermission('addressbook', $addressBookId, $userId);
    }

    /**
     * Set or update a share for a calendar. The calling code must verify admin privileges.
     * Owner permission cannot be set via shares — ownership is determined by calendars.user_id.
     * Cannot set the owner to 'none'.
     */
    public function shareCalendar(int $calendarId, int $targetUserId, string $permission): void
    {
        $this->validateSharePermission($permission);
        $this->guardNotOwner('calendar', $calendarId, $targetUserId, $permission);

        $now = $this->now();
        $this->pdo->prepare('
            INSERT INTO collection_shares (collection_type, collection_id, user_id, permission, created_at, updated_at)
            VALUES (\'calendar\', ?, ?, ?, ?, ?)
            ON CONFLICT (collection_type, collection_id, user_id)
            DO UPDATE SET permission = excluded.permission, updated_at = excluded.updated_at
        ')->execute([$calendarId, $targetUserId, $permission, $now, $now]);
    }

    /**
     * Set or update a share for an addressbook.
     */
    public function shareAddressBook(int $addressBookId, int $targetUserId, string $permission): void
    {
        $this->validateSharePermission($permission);
        $this->guardNotOwner('addressbook', $addressBookId, $targetUserId, $permission);

        $now = $this->now();
        $this->pdo->prepare('
            INSERT INTO collection_shares (collection_type, collection_id, user_id, permission, created_at, updated_at)
            VALUES (\'addressbook\', ?, ?, ?, ?, ?)
            ON CONFLICT (collection_type, collection_id, user_id)
            DO UPDATE SET permission = excluded.permission, updated_at = excluded.updated_at
        ')->execute([$addressBookId, $targetUserId, $permission, $now, $now]);
    }

    /**
     * Remove a share entry. Cannot remove the owner record (there isn't one in this table).
     */
    public function removeShare(string $collectionType, int $collectionId, int $userId): void
    {
        $this->validateType($collectionType);
        $this->guardRemoveNotOwner($collectionType, $collectionId, $userId);

        $this->pdo->prepare('
            DELETE FROM collection_shares
            WHERE collection_type = ? AND collection_id = ? AND user_id = ?
        ')->execute([$collectionType, $collectionId, $userId]);
    }

    /**
     * List all share rows for a collection (excludes the owner who is not in this table).
     * Returns rows: user_id, username, display_name, permission, updated_at.
     */
    public function listSharesForCollection(string $collectionType, int $collectionId): array
    {
        $this->validateType($collectionType);

        $stmt = $this->pdo->prepare('
            SELECT cs.user_id, u.username, u.display_name, cs.permission, cs.updated_at
            FROM collection_shares cs
            JOIN users u ON u.id = cs.user_id
            WHERE cs.collection_type = ? AND cs.collection_id = ?
            ORDER BY u.username
        ');
        $stmt->execute([$collectionType, $collectionId]);
        return $stmt->fetchAll();
    }

    /**
     * List all calendars accessible to a user: owned + shared (permission != 'none').
     * Returns rows with: id, uri, display_name, color, owner_user_id, owner_username, permission.
     */
    public function listAccessibleCalendarsForUser(int $userId): array
    {
        // Owned calendars
        $stmt = $this->pdo->prepare('
            SELECT c.id, c.uri, c.display_name, c.color, c.generated_type,
                   c.user_id AS owner_user_id,
                   u.username AS owner_username,
                   \'owner\' AS permission
            FROM calendars c
            JOIN users u ON u.id = c.user_id
            WHERE c.user_id = ?
            ORDER BY c.id
        ');
        $stmt->execute([$userId]);
        $owned = $stmt->fetchAll();

        // Shared calendars (permission != 'none')
        $stmt = $this->pdo->prepare('
            SELECT c.id, c.uri, c.display_name, c.color, c.generated_type,
                   c.user_id AS owner_user_id,
                   u.username AS owner_username,
                   cs.permission
            FROM collection_shares cs
            JOIN calendars c ON c.id = cs.collection_id
            JOIN users u ON u.id = c.user_id
            WHERE cs.collection_type = \'calendar\'
              AND cs.user_id = ?
              AND cs.permission != \'none\'
            ORDER BY c.id
        ');
        $stmt->execute([$userId]);
        $shared = $stmt->fetchAll();

        return array_merge($owned, $shared);
    }

    /**
     * List all addressbooks accessible to a user: owned + shared (permission != 'none').
     */
    public function listAccessibleAddressBooksForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ab.id, ab.uri, ab.display_name,
                   ab.user_id AS owner_user_id,
                   u.username AS owner_username,
                   \'owner\' AS permission
            FROM addressbooks ab
            JOIN users u ON u.id = ab.user_id
            WHERE ab.user_id = ?
            ORDER BY ab.id
        ');
        $stmt->execute([$userId]);
        $owned = $stmt->fetchAll();

        $stmt = $this->pdo->prepare('
            SELECT ab.id, ab.uri, ab.display_name,
                   ab.user_id AS owner_user_id,
                   u.username AS owner_username,
                   cs.permission
            FROM collection_shares cs
            JOIN addressbooks ab ON ab.id = cs.collection_id
            JOIN users u ON u.id = ab.user_id
            WHERE cs.collection_type = \'addressbook\'
              AND cs.user_id = ?
              AND cs.permission != \'none\'
            ORDER BY ab.id
        ');
        $stmt->execute([$userId]);
        $shared = $stmt->fetchAll();

        return array_merge($owned, $shared);
    }

    /**
     * Count shares for a collection (for display in admin UI).
     */
    public function countSharesForCollection(string $collectionType, int $collectionId): int
    {
        $this->validateType($collectionType);
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM collection_shares
            WHERE collection_type = ? AND collection_id = ?
        ');
        $stmt->execute([$collectionType, $collectionId]);
        return (int) $stmt->fetchColumn();
    }

    /** Owner user id of a collection, or null if it does not exist. */
    public function getCollectionOwnerId(string $collectionType, int $collectionId): ?int
    {
        $this->validateType($collectionType);
        $table = $collectionType === 'calendar' ? 'calendars' : 'addressbooks';
        $stmt  = $this->pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
        $stmt->execute([$collectionId]);
        $owner = $stmt->fetchColumn();
        return $owner === false ? null : (int) $owner;
    }

    /**
     * Assert that $userId owns the collection. Throws CollectionNotFoundException
     * when it does not exist and NotCollectionOwnerException when owned by someone
     * else — so the user-facing endpoints can map them to 404 / 403.
     */
    public function assertOwnedBy(int $userId, string $collectionType, int $collectionId): void
    {
        $owner = $this->getCollectionOwnerId($collectionType, $collectionId);
        if ($owner === null) {
            throw new CollectionNotFoundException('Collection not found.');
        }
        if ($owner !== $userId) {
            throw new NotCollectionOwnerException('You do not own this collection.');
        }
    }

    /**
     * Owner-enforced share for the user-facing API: the caller may only share a
     * collection they own, only to another user, with a concrete permission
     * (read_only|read_write — 'none' is not a user grant; use removeShareAsOwner).
     */
    public function shareAsOwner(
        int $ownerUserId,
        string $collectionType,
        int $collectionId,
        int $targetUserId,
        string $permission,
    ): void {
        $this->validateType($collectionType);
        if (!in_array($permission, ['read_only', 'read_write'], true)) {
            throw new InvalidArgumentException('permission must be read_only or read_write.');
        }
        $this->assertOwnedBy($ownerUserId, $collectionType, $collectionId);
        if ($targetUserId === $ownerUserId) {
            throw new InvalidArgumentException('Cannot share a collection with yourself.');
        }
        if ($collectionType === 'calendar') {
            $this->shareCalendar($collectionId, $targetUserId, $permission);
        } else {
            $this->shareAddressBook($collectionId, $targetUserId, $permission);
        }
    }

    /** Owner-enforced share removal for the user-facing API. */
    public function removeShareAsOwner(
        int $ownerUserId,
        string $collectionType,
        int $collectionId,
        int $targetUserId,
    ): void {
        $this->validateType($collectionType);
        $this->assertOwnedBy($ownerUserId, $collectionType, $collectionId);
        $this->removeShare($collectionType, $collectionId, $targetUserId);
    }

    // --- private helpers ---

    private function getSharePermission(string $type, int $collectionId, int $userId): string
    {
        $stmt = $this->pdo->prepare('
            SELECT permission FROM collection_shares
            WHERE collection_type = ? AND collection_id = ? AND user_id = ?
        ');
        $stmt->execute([$type, $collectionId, $userId]);
        $perm = $stmt->fetchColumn();
        return $perm !== false ? (string) $perm : 'none';
    }

    private function guardNotOwner(string $type, int $collectionId, int $userId, string $permission): void
    {
        $table = $type === 'calendar' ? 'calendars' : 'addressbooks';
        $stmt  = $this->pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
        $stmt->execute([$collectionId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) {
            throw new InvalidArgumentException('Collection not found.');
        }
        if ((int) $ownerId === $userId && $permission === 'none') {
            throw new InvalidArgumentException('Cannot set collection owner permission to none.');
        }
    }

    private function guardRemoveNotOwner(string $type, int $collectionId, int $userId): void
    {
        $table = $type === 'calendar' ? 'calendars' : 'addressbooks';
        $stmt  = $this->pdo->prepare("SELECT user_id FROM $table WHERE id = ?");
        $stmt->execute([$collectionId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId !== false && (int) $ownerId === $userId) {
            throw new InvalidArgumentException('Cannot remove the collection owner from sharing.');
        }
    }

    private function validateSharePermission(string $permission): void
    {
        if (!in_array($permission, self::VALID_PERMISSIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid permission '$permission'. Allowed: " . implode(', ', self::VALID_PERMISSIONS)
            );
        }
    }

    private function validateType(string $type): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(
                "Invalid collection type '$type'. Allowed: " . implode(', ', self::VALID_TYPES)
            );
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
