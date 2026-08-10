<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailMessage
{
    /**
     * @return array{id: int, open_token: string}
     */
    public static function create(PDO $pdo, ?int $userId, string $toEmail, string $type, string $subject): array
    {
        $openToken = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare(
            'INSERT INTO email_messages (user_id, to_email, email_type, subject, status, sent_at, open_token)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            strtolower(trim($toEmail)),
            $type,
            $subject,
            'pending',
            gmdate('c'),
            $openToken,
        ]);

        return [
            'id' => (int)$pdo->lastInsertId(),
            'open_token' => $openToken,
        ];
    }

    public static function addLink(PDO $pdo, int $messageId, string $token, string $targetUrl, string $label): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO email_links (message_id, token, target_url, link_label)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$messageId, $token, $targetUrl, $label]);
    }

    public static function markStatus(PDO $pdo, int $messageId, bool $sent, ?string $error = null): void
    {
        $stmt = $pdo->prepare(
            'UPDATE email_messages SET status = ?, error_message = ? WHERE id = ?'
        );
        $stmt->execute([
            $sent ? 'sent' : 'failed',
            $error,
            $messageId,
        ]);
    }

    public static function recordOpen(PDO $pdo, string $openToken): void
    {
        $stmt = $pdo->prepare(
            "UPDATE email_messages
             SET open_count = open_count + 1,
                 opened_at = COALESCE(opened_at, ?)
             WHERE open_token = ?"
        );
        $stmt->execute([gmdate('c'), $openToken]);
    }

    public static function clickTarget(PDO $pdo, string $linkToken): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT id, target_url FROM email_links WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$linkToken]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "UPDATE email_links
             SET click_count = click_count + 1,
                 clicked_at = COALESCE(clicked_at, ?)
             WHERE id = ?"
        );
        $stmt->execute([gmdate('c'), (int)$row['id']]);

        return (string)$row['target_url'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(PDO $pdo, int $userId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM email_messages
             WHERE user_id = ?
             ORDER BY sent_at DESC
             LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        $messages = $stmt->fetchAll() ?: [];
        if ($messages === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $messages);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $linkStmt = $pdo->prepare(
            "SELECT * FROM email_links WHERE message_id IN ($placeholders) ORDER BY id ASC"
        );
        $linkStmt->execute($ids);
        $linksByMessage = [];
        foreach ($linkStmt->fetchAll() ?: [] as $link) {
            $messageId = (int)$link['message_id'];
            $linksByMessage[$messageId][] = $link;
        }

        foreach ($messages as &$message) {
            $message['links'] = $linksByMessage[(int)$message['id']] ?? [];
        }
        unset($message);

        return $messages;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'paid' => 'Paid access',
            'demo' => 'Demo access',
            'magic' => 'Sign-in link',
            'reset' => 'Password reset',
            'test' => 'Test email',
            'reminder_demo_no_login' => 'Reminder — no login',
            'reminder_demo_no_lesson' => 'Reminder — no lesson',
            'reminder_demo_expiring' => 'Reminder — expiring',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
