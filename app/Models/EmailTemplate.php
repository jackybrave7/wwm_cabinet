<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailTemplate
{
    /**
     * @return array{template_id: string, subject: string, body_text: string, body_html: ?string, updated_at: string}|null
     */
    public static function find(PDO $pdo, string $templateId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT template_id, subject, body_text, body_html, updated_at
             FROM email_templates
             WHERE template_id = ?
             LIMIT 1'
        );
        $stmt->execute([$templateId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $html = $row['body_html'] ?? null;
        if (is_string($html) && trim($html) === '') {
            $html = null;
        }

        return [
            'template_id' => (string)$row['template_id'],
            'subject' => (string)$row['subject'],
            'body_text' => (string)$row['body_text'],
            'body_html' => is_string($html) ? $html : null,
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    public static function save(
        PDO $pdo,
        string $templateId,
        string $subject,
        string $bodyText,
        ?string $bodyHtml
    ): void {
        $html = $bodyHtml !== null && trim($bodyHtml) !== '' ? $bodyHtml : null;
        $stmt = $pdo->prepare(
            'INSERT INTO email_templates (template_id, subject, body_text, body_html, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(template_id) DO UPDATE SET
               subject = excluded.subject,
               body_text = excluded.body_text,
               body_html = excluded.body_html,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([
            $templateId,
            $subject,
            $bodyText,
            $html,
            gmdate('c'),
        ]);
    }

    public static function delete(PDO $pdo, string $templateId): void
    {
        $stmt = $pdo->prepare('DELETE FROM email_templates WHERE template_id = ?');
        $stmt->execute([$templateId]);
    }

    /**
     * @return list<string>
     */
    public static function customizedIds(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT template_id FROM email_templates ORDER BY template_id ASC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids[] = (string)$row['template_id'];
        }

        return $ids;
    }
}
