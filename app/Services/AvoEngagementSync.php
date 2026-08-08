<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\User;

final class AvoEngagementSync
{
    public static function onLogin(int $userId): void
    {
        self::assignTag($userId, 'logged_in', 'avo_logged_in_tagged');
    }

    public static function onDemoLessonOpen(int $userId): void
    {
        self::assignTag($userId, 'demo_opened', 'avo_demo_opened_tagged');
    }

    private static function assignTag(int $userId, string $tagName, string $flagColumn): void
    {
        try {
            $client = new AvoClient();
            if (!$client->isEnabled()) {
                return;
            }

            $tagId = $client->tagId($tagName);
            if ($tagId <= 0) {
                return;
            }

            $pdo = wwm_pdo();
            $user = User::findById($pdo, $userId);
            if ($user === null) {
                return;
            }

            if (!empty($user[$flagColumn])) {
                return;
            }

            $contactId = self::resolveContactId($pdo, $user, $client);
            if ($contactId === null) {
                wwm_log(sprintf(
                    'avo tag %s skipped user_id=%d: contact not found (%s)',
                    $tagName,
                    $userId,
                    $client->lastError() ?? 'unknown'
                ));
                return;
            }

            if (!$client->assignTag($contactId, $tagId)) {
                wwm_log(sprintf(
                    'avo tag %s failed user_id=%d contact_id=%d: %s',
                    $tagName,
                    $userId,
                    $contactId,
                    $client->lastError() ?? 'unknown'
                ));
                return;
            }

            User::setAvoFlags($pdo, $userId, [
                'avo_contact_id' => $contactId,
                $flagColumn => 1,
            ]);

            wwm_log(sprintf(
                'avo tag %s assigned user_id=%d contact_id=%d tag_id=%d',
                $tagName,
                $userId,
                $contactId,
                $tagId
            ));
        } catch (\Throwable $e) {
            wwm_log('avo engagement sync failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function resolveContactId(\PDO $pdo, array $user, AvoClient $client): ?int
    {
        $stored = (int)($user['avo_contact_id'] ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        $contactId = $client->findContactIdByEmail($email);
        if ($contactId !== null && $contactId > 0) {
            User::setAvoFlags($pdo, (int)$user['id'], ['avo_contact_id' => $contactId]);
        }

        return $contactId;
    }
}
