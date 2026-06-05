<?php
declare(strict_types=1);

namespace Davyn\Birthday;

use PDO;

/**
 * Fire-and-forget bridge that keeps the generated birthday calendar in sync when a
 * contact changes. Call these AFTER the contact write has committed — never inside the
 * addressbook transaction (the generated-object writer opens its own transaction and
 * SQLite cannot nest). They never throw: a birthday-generation failure must not break a
 * contact save / DAVx5 sync.
 *
 * The birthday calendar belongs to the addressbook *owner* (relevant for shared books),
 * so the owner is resolved from `addressbooks.user_id`, not the authenticated actor.
 */
final class BirthdayTrigger
{
    public static function onContactSaved(PDO $pdo, int $addressbookId, string $contactUri, string $vcard): void
    {
        try {
            $userId = self::ownerOf($pdo, $addressbookId);
            if ($userId !== null) {
                (new BirthdayService($pdo))->syncContact($userId, $contactUri, $addressbookId, $vcard);
            }
        } catch (\Throwable $e) {
            error_log('BirthdayTrigger::onContactSaved failed: ' . $e->getMessage());
        }
    }

    public static function onContactDeleted(PDO $pdo, int $addressbookId, string $contactUri): void
    {
        try {
            $userId = self::ownerOf($pdo, $addressbookId);
            if ($userId !== null) {
                (new BirthdayService($pdo))->removeForContact($userId, $contactUri);
            }
        } catch (\Throwable $e) {
            error_log('BirthdayTrigger::onContactDeleted failed: ' . $e->getMessage());
        }
    }

    private static function ownerOf(PDO $pdo, int $addressbookId): ?int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM addressbooks WHERE id = ?');
        $stmt->execute([$addressbookId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
