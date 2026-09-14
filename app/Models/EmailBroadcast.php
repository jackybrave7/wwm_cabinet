<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;
use Wwm\Services\AdminStudentListFilter;

final class EmailBroadcast
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listRecent(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $pdo->query(
            'SELECT b.*, u.email AS creator_email, u.name AS creator_name
             FROM email_broadcasts b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.id DESC
             LIMIT ' . $limit
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM email_broadcasts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{title?: string, subject: string, body_text: string, body_html?: string, audience?: string, audience_filter_json?: string} $data
     */
    public static function createDraft(PDO $pdo, array $data, ?int $createdBy): int
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO email_broadcasts (
                title, subject, body_text, body_html, audience, audience_filter_json, status, created_by, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?)'
        );
        $stmt->execute([
            trim((string)($data['title'] ?? '')),
            trim((string)$data['subject']),
            (string)$data['body_text'],
            (string)($data['body_html'] ?? ''),
            self::normalizeAudience((string)($data['audience'] ?? 'all_students')),
            (string)($data['audience_filter_json'] ?? ''),
            $createdBy,
            $now,
            $now,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array{title?: string, subject?: string, body_text?: string, body_html?: string, audience?: string, audience_filter_json?: string, scheduled_at?: ?string} $data
     */
    public static function update(PDO $pdo, int $id, array $data): bool
    {
        $row = self::find($pdo, $id);
        if ($row === null || !in_array((string)$row['status'], ['draft', 'scheduled'], true)) {
            return false;
        }

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'UPDATE email_broadcasts SET
                title = ?, subject = ?, body_text = ?, body_html = ?, audience = ?, audience_filter_json = ?,
                scheduled_at = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim((string)($data['title'] ?? $row['title'])),
            trim((string)($data['subject'] ?? $row['subject'])),
            (string)($data['body_text'] ?? $row['body_text']),
            (string)($data['body_html'] ?? $row['body_html']),
            self::normalizeAudience((string)($data['audience'] ?? $row['audience'])),
            (string)($data['audience_filter_json'] ?? $row['audience_filter_json'] ?? ''),
            $data['scheduled_at'] ?? $row['scheduled_at'],
            $now,
            $id,
        ]);

        return true;
    }

    public static function markScheduled(PDO $pdo, int $id, string $scheduledAtUtc): bool
    {
        $row = self::find($pdo, $id);
        if ($row === null || !in_array((string)$row['status'], ['draft', 'scheduled'], true)) {
            return false;
        }

        $stmt = $pdo->prepare(
            'UPDATE email_broadcasts SET status = \'scheduled\', scheduled_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$scheduledAtUtc, gmdate('c'), $id]);

        return true;
    }

    public static function markCancelled(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE email_broadcasts SET status = \'cancelled\', updated_at = ? WHERE id = ? AND status IN (\'draft\', \'scheduled\', \'sending\')'
        );
        $stmt->execute([gmdate('c'), $id]);

        return $stmt->rowCount() > 0;
    }

    public static function normalizeAudience(string $audience): string
    {
        return match ($audience) {
            'with_access' => 'with_access',
            'filtered' => 'filtered',
            default => 'all_students',
        };
    }

    public static function encodeAudienceFilter(AdminStudentListFilter $filter): string
    {
        $json = json_encode($filter->toStorageArray(), JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    public static function decodeAudienceFilter(string $json): AdminStudentListFilter
    {
        if ($json === '') {
            return AdminStudentListFilter::fromArray([]);
        }
        $data = json_decode($json, true);

        return AdminStudentListFilter::fromArray(is_array($data) ? $data : []);
    }

    public static function audienceLabel(array $broadcast): string
    {
        $audience = self::normalizeAudience((string)($broadcast['audience'] ?? 'all_students'));
        if ($audience === 'with_access') {
            return 'Students with course access';
        }
        if ($audience === 'filtered') {
            $filter = self::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));
            return $filter->isActive() ? 'Custom student filter' : 'All students (filter empty)';
        }

        return 'All student accounts';
    }
}
