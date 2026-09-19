<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailAutomation
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listAll(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT a.*,
                (SELECT COUNT(*) FROM email_automation_runs r WHERE r.automation_id = a.id AND r.status = \'active\') AS active_runs
             FROM email_automations a
             ORDER BY a.updated_at DESC, a.id DESC'
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM email_automations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public static function findBySlug(PDO $pdo, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM email_automations WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public static function findActiveForCourse(PDO $pdo, string $courseSlug): ?array
    {
        $courseSlug = trim($courseSlug);
        if ($courseSlug === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM email_automations WHERE course_slug = ? AND is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$courseSlug]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null decoded definition
     */
    public static function definition(array $row): ?array
    {
        $raw = (string)($row['definition_json'] ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{slug: string, title: string, description?: string, course_slug: string, is_active?: bool, definition_json: string, avo_export_json?: ?string} $data
     */
    public static function create(PDO $pdo, array $data): int
    {
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO email_automations (slug, title, description, course_slug, is_active, definition_json, avo_export_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['slug'],
            $data['title'],
            $data['description'] ?? '',
            $data['course_slug'],
            !empty($data['is_active']) ? 1 : 0,
            $data['definition_json'],
            $data['avo_export_json'] ?? null,
            $now,
            $now,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array{title?: string, description?: string, course_slug?: string, is_active?: bool, definition_json?: string, avo_export_json?: ?string} $data
     */
    public static function update(PDO $pdo, int $id, array $data): void
    {
        $row = self::find($pdo, $id);
        if ($row === null) {
            return;
        }

        $pdo->prepare(
            'UPDATE email_automations SET
                title = ?, description = ?, course_slug = ?, is_active = ?,
                definition_json = ?, avo_export_json = COALESCE(?, avo_export_json), updated_at = ?
             WHERE id = ?'
        )->execute([
            $data['title'] ?? $row['title'],
            $data['description'] ?? $row['description'],
            $data['course_slug'] ?? $row['course_slug'],
            isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : (int)$row['is_active'],
            $data['definition_json'] ?? $row['definition_json'],
            $data['avo_export_json'] ?? null,
            gmdate('c'),
            $id,
        ]);
    }
}
