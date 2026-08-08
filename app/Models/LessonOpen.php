<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class LessonOpen
{
    public static function record(PDO $pdo, int $userId, string $courseSlug, int $lessonNum): bool
    {
        $check = $pdo->prepare(
            'SELECT 1 FROM lesson_opens WHERE user_id = ? AND course_slug = ? AND lesson_num = ? LIMIT 1'
        );
        $check->execute([$userId, $courseSlug, $lessonNum]);
        $isFirst = $check->fetchColumn() === false;

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_opens (user_id, course_slug, lesson_num, first_opened_at, last_opened_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id, course_slug, lesson_num) DO UPDATE SET last_opened_at = excluded.last_opened_at'
        );
        $stmt->execute([$userId, $courseSlug, $lessonNum, $now, $now]);

        return $isFirst;
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

    /**
     * @return array<int, array<string, int>>
     */
    public static function openCountsGrouped(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT user_id, course_slug, COUNT(*) AS cnt FROM lesson_opens GROUP BY user_id, course_slug'
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['user_id']][(string)$row['course_slug']] = (int)$row['cnt'];
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public static function lastActivityGrouped(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT user_id, MAX(last_opened_at) AS last_activity FROM lesson_opens GROUP BY user_id'
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $map = [];
        foreach ($rows as $row) {
            $value = $row['last_activity'] ?? null;
            if (is_string($value) && $value !== '') {
                $map[(int)$row['user_id']] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string, array<int, array{first_opened_at: string, last_opened_at: string}>>
     */
    public static function groupedForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT course_slug, lesson_num, first_opened_at, last_opened_at FROM lesson_opens
             WHERE user_id = ? ORDER BY course_slug, lesson_num'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $slug = (string)$row['course_slug'];
            $map[$slug][(int)$row['lesson_num']] = [
                'first_opened_at' => (string)$row['first_opened_at'],
                'last_opened_at' => (string)$row['last_opened_at'],
            ];
        }

        return $map;
    }
}
