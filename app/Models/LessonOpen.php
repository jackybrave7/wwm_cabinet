<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class LessonOpen
{
    public static function record(PDO $pdo, int $userId, string $courseSlug, int $lessonNum): void
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_opens (user_id, course_slug, lesson_num, first_opened_at, last_opened_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id, course_slug, lesson_num) DO UPDATE SET last_opened_at = excluded.last_opened_at'
        );
        $stmt->execute([$userId, $courseSlug, $lessonNum, $now, $now]);
    }

    public static function countForUserCourse(PDO $pdo, int $userId, string $courseSlug): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lesson_opens WHERE user_id = ? AND course_slug = ?'
        );
        $stmt->execute([$userId, $courseSlug]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int, array{first_opened_at: string, last_opened_at: string}>
     */
    public static function forUserCourse(PDO $pdo, int $userId, string $courseSlug): array
    {
        $stmt = $pdo->prepare(
            'SELECT lesson_num, first_opened_at, last_opened_at FROM lesson_opens
             WHERE user_id = ? AND course_slug = ? ORDER BY lesson_num'
        );
        $stmt->execute([$userId, $courseSlug]);
        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['lesson_num']] = [
                'first_opened_at' => (string)$row['first_opened_at'],
                'last_opened_at' => (string)$row['last_opened_at'],
            ];
        }
        return $map;
    }

    public static function lastActivity(PDO $pdo, int $userId): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT MAX(last_opened_at) FROM lesson_opens WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }
}
