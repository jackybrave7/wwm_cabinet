<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\EmailBroadcast;
use Wwm\Models\EmailSuppression;
use Wwm\Models\User;

final class BroadcastRunner
{
    public const BATCH_SIZE = 20;

    public static function processDueScheduled(PDO $pdo): int
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'SELECT id FROM email_broadcasts
             WHERE status = \'scheduled\' AND scheduled_at IS NOT NULL AND scheduled_at <= ?
             ORDER BY scheduled_at ASC
             LIMIT 5'
        );
        $stmt->execute([$now]);
        $started = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (self::beginSending($pdo, (int)$id)) {
                $started++;
            }
        }

        return $started;
    }

    public static function beginSending(PDO $pdo, int $broadcastId): bool
    {
        $row = EmailBroadcast::find($pdo, $broadcastId);
        if ($row === null) {
            return false;
        }

        $status = (string)$row['status'];
        if ($status === 'draft') {
            $pdo->prepare(
                'UPDATE email_broadcasts SET status = \'sending\', started_at = ?, scheduled_at = NULL, updated_at = ? WHERE id = ? AND status = \'draft\''
            )->execute([gmdate('c'), gmdate('c'), $broadcastId]);
        } elseif ($status === 'scheduled') {
            $pdo->prepare(
                'UPDATE email_broadcasts SET status = \'sending\', started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status = \'scheduled\''
            )->execute([gmdate('c'), gmdate('c'), $broadcastId]);
        } elseif ($status !== 'sending') {
            return false;
        }

        self::ensureRecipientsQueued($pdo, $broadcastId, $row);

        return true;
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    private static function ensureRecipientsQueued(PDO $pdo, int $broadcastId, array $broadcast): void
    {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ?');
        $countStmt->execute([$broadcastId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            return;
        }

        $recipients = BroadcastAudience::recipientsForBroadcast($pdo, $broadcast);
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO broadcast_recipients (broadcast_id, user_id, email, status) VALUES (?, ?, ?, \'pending\')'
        );
        foreach ($recipients as $r) {
            $insert->execute([$broadcastId, $r['id'], $r['email']]);
        }

        $total = count($recipients);
        $pdo->prepare(
            'UPDATE email_broadcasts SET recipients_total = ?, updated_at = ? WHERE id = ?'
        )->execute([$total, gmdate('c'), $broadcastId]);
    }

    public static function processBatch(PDO $pdo, int $broadcastId, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(100, $limit));
        $broadcast = EmailBroadcast::find($pdo, $broadcastId);
        if ($broadcast === null || (string)$broadcast['status'] !== 'sending') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => true];
        }

        $stmt = $pdo->prepare(
            'SELECT id, user_id, email FROM broadcast_recipients
             WHERE broadcast_id = ? AND status = \'pending\'
             ORDER BY id ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$broadcastId]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($pending as $row) {
            $recipientId = (int)$row['id'];
            $userId = (int)$row['user_id'];
            $email = (string)$row['email'];

            if (EmailSuppression::isSuppressed($pdo, $email)) {
                self::markRecipient($pdo, $recipientId, 'skipped', null);
                $skipped++;
                self::incrementCounter($pdo, $broadcastId, 'skipped_unsub_count');
                continue;
            }

            $user = User::findById($pdo, $userId);
            $name = is_array($user) ? trim((string)($user['name'] ?? '')) : '';

            $ok = self::sendToRecipient($broadcast, $userId, $email, $name);
            if ($ok) {
                self::markRecipient($pdo, $recipientId, 'sent', null);
                $sent++;
                self::incrementCounter($pdo, $broadcastId, 'sent_count');
            } else {
                $err = Mailer::lastError() ?? 'Send failed';
                self::markRecipient($pdo, $recipientId, 'failed', $err);
                $failed++;
                self::incrementCounter($pdo, $broadcastId, 'failed_count');
            }

            usleep(150_000);
        }

        self::maybeComplete($pdo, $broadcastId);

        $remainingStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ? AND status = \'pending\''
        );
        $remainingStmt->execute([$broadcastId]);
        $remaining = (int)$remainingStmt->fetchColumn();

        return [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'done' => $remaining === 0,
        ];
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    public static function sendToRecipient(array $broadcast, int $userId, string $email, string $name): bool
    {
        $subject = self::renderPersonalization((string)$broadcast['subject'], $userId, $email, $name);
        $text = self::renderPersonalization((string)$broadcast['body_text'], $userId, $email, $name);
        $htmlRaw = trim((string)($broadcast['body_html'] ?? ''));
        $html = $htmlRaw !== ''
            ? self::renderPersonalization(BroadcastHtmlSanitizer::sanitize($htmlRaw), $userId, $email, $name)
            : null;

        $text = self::appendPlainUnsubscribeFooter($text, $userId, $email);
        if ($html !== null && $html !== '') {
            $html = self::appendHtmlUnsubscribeFooter($html, $userId, $email);
        }

        $unsubUrl = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        $headers = self::complianceHeaders($unsubUrl, (int)$broadcast['id']);

        return Mailer::send($email, $subject, $text, $html, $headers);
    }

    private static function renderPersonalization(string $body, int $userId, string $email, string $name): string
    {
        $unsub = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        $replacements = [
            '{{name}}' => $name !== '' ? $name : 'there',
            '{{email}}' => $email,
            '{{unsubscribe_url}}' => $unsub,
            '{{base_url}}' => wwm_base_url(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }

    private static function appendPlainUnsubscribeFooter(string $text, int $userId, string $email): string
    {
        $url = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        $footer = "\n\n—\nTo stop marketing emails from World Watercolor Masters, unsubscribe:\n" . $url;

        if (str_contains($text, $url)) {
            return $text;
        }

        return rtrim($text) . $footer;
    }

    private static function appendHtmlUnsubscribeFooter(string $html, int $userId, string $email): string
    {
        $url = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        if (str_contains($html, $url)) {
            return $html;
        }

        $footer = '<p style="margin-top:24px;font-size:12px;color:#666;">'
            . 'You received this because you have a World Watercolor Masters account. '
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Unsubscribe</a> from marketing emails.</p>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $footer . '</body>', $html, 1) ?? ($html . $footer);
        }

        return $html . $footer;
    }

    /**
     * @return list<string>
     */
    private static function complianceHeaders(string $unsubscribeUrl, int $broadcastId): array
    {
        $cfg = wwm_config()['mail'] ?? [];
        $from = trim((string)($cfg['from_email'] ?? ''));
        $headers = [
            'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'List-ID: <broadcast.' . $broadcastId . '.' . preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url(wwm_base_url(), PHP_URL_HOST)) . '>',
            'Precedence: bulk',
        ];
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $headers[0] = 'List-Unsubscribe: <' . $unsubscribeUrl . '>, <mailto:' . $from . '?subject=unsubscribe>';
        }

        return $headers;
    }

    private static function markRecipient(PDO $pdo, int $recipientId, string $status, ?string $error): void
    {
        $pdo->prepare(
            'UPDATE broadcast_recipients SET status = ?, error_message = ?, sent_at = ? WHERE id = ?'
        )->execute([
            $status,
            $error,
            $status === 'sent' ? gmdate('c') : null,
            $recipientId,
        ]);
    }

    private static function incrementCounter(PDO $pdo, int $broadcastId, string $column): void
    {
        $allowed = ['sent_count', 'failed_count', 'skipped_unsub_count'];
        if (!in_array($column, $allowed, true)) {
            return;
        }
        $pdo->prepare('UPDATE email_broadcasts SET ' . $column . ' = ' . $column . ' + 1, updated_at = ? WHERE id = ?')
            ->execute([gmdate('c'), $broadcastId]);
    }

    private static function maybeComplete(PDO $pdo, int $broadcastId): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ? AND status = \'pending\''
        );
        $stmt->execute([$broadcastId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $pdo->prepare(
            'UPDATE email_broadcasts SET status = \'sent\', completed_at = ?, updated_at = ? WHERE id = ? AND status = \'sending\''
        )->execute([gmdate('c'), gmdate('c'), $broadcastId]);
    }
}
