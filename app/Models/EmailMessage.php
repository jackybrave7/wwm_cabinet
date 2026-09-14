<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailMessage
{
    /**
     * @return array{id: int, open_token: string}
     */
    public static function create(
        PDO $pdo,
        ?int $userId,
        string $toEmail,
        string $type,
        string $subject,
        ?int $broadcastId = null,
    ): array {
        $openToken = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare(
            'INSERT INTO email_messages (user_id, to_email, email_type, subject, status, sent_at, open_token, broadcast_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            strtolower(trim($toEmail)),
            $type,
            $subject,
            'pending',
            gmdate('c'),
            $openToken,
            $broadcastId,
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

    /**
     * @return array{
     *   tracked_sent: int,
     *   unique_opens: int,
     *   total_opens: int,
     *   unique_clickers: int,
     *   total_clicks: int
     * }
     */
    public static function broadcastEngagementSummary(PDO $pdo, int $broadcastId): array
    {
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS tracked_sent,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS unique_opens,
                COALESCE(SUM(open_count), 0) AS total_opens
             FROM email_messages
             WHERE broadcast_id = ? AND status = \'sent\''
        );
        $stmt->execute([$broadcastId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $clickStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(el.click_count), 0) AS total_clicks,
                COUNT(DISTINCT CASE WHEN el.clicked_at IS NOT NULL THEN em.id END) AS unique_clickers
             FROM email_links el
             INNER JOIN email_messages em ON em.id = el.message_id
             WHERE em.broadcast_id = ? AND em.status = \'sent\''
        );
        $clickStmt->execute([$broadcastId]);
        $clicks = $clickStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'tracked_sent' => (int)($row['tracked_sent'] ?? 0),
            'unique_opens' => (int)($row['unique_opens'] ?? 0),
            'total_opens' => (int)($row['total_opens'] ?? 0),
            'unique_clickers' => (int)($clicks['unique_clickers'] ?? 0),
            'total_clicks' => (int)($clicks['total_clicks'] ?? 0),
        ];
    }

    /**
     * @return list<array{target_url: string, link_label: string, total_clicks: int, unique_clickers: int}>
     */
    public static function broadcastLinkStats(PDO $pdo, int $broadcastId): array
    {
        $stmt = $pdo->prepare(
            'SELECT el.target_url, el.link_label,
                    COALESCE(SUM(el.click_count), 0) AS total_clicks,
                    COUNT(CASE WHEN el.clicked_at IS NOT NULL THEN 1 END) AS unique_clickers
             FROM email_links el
             INNER JOIN email_messages em ON em.id = el.message_id
             WHERE em.broadcast_id = ? AND em.status = \'sent\'
             GROUP BY el.target_url, el.link_label
             ORDER BY total_clicks DESC, el.target_url ASC'
        );
        $stmt->execute([$broadcastId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'target_url' => (string)$row['target_url'],
                'link_label' => (string)$row['link_label'],
                'total_clicks' => (int)$row['total_clicks'],
                'unique_clickers' => (int)$row['unique_clickers'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function broadcastRecipientEngagement(PDO $pdo, int $broadcastId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $pdo->prepare(
            'SELECT br.email, br.status AS delivery_status, br.sent_at,
                    em.opened_at, em.open_count, em.id AS message_id,
                    (SELECT COALESCE(SUM(el.click_count), 0) FROM email_links el WHERE el.message_id = em.id) AS click_count
             FROM broadcast_recipients br
             LEFT JOIN email_messages em ON em.id = br.email_message_id
             WHERE br.broadcast_id = ?
             ORDER BY br.id ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$broadcastId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'paid' => 'Paid access',
            'demo' => 'Demo access',
            'magic' => 'Sign-in link',
            'reset' => 'Password reset',
            'test' => 'Test email',
            'broadcast' => 'Marketing broadcast',
            'reminder_demo_no_login' => 'Reminder — no login',
            'reminder_demo_no_lesson' => 'Reminder — no lesson',
            'reminder_demo_expiring' => 'Reminder — expiring',
            'sale_demo_discount_24h' => 'Sale — 40% off (24h)',
            'sale_demo_discount_3h' => 'Sale — final reminder (3h)',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
