<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class LessonProgress
{
    public static function touch(PDO $pdo, int $userId, string $courseSlug, int $lessonNum): void
    {
        $now = self::now();
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_progress (user_id, course_slug, lesson_num, last_opened_at, completed_at)
             VALUES (?, ?, ?, ?, NULL)
             ON CONFLICT(user_id, course_slug, lesson_num) DO UPDATE SET last_opened_at = excluded.last_opened_at'
        );
        $stmt->execute([$userId, $courseSlug, $lessonNum, $now]);
    }

    public static function setCompleted(PDO $pdo, int $userId, string $courseSlug, int $lessonNum, bool $done): void
    {
        $completedAt = $done ? self::now() : null;
        $stmt = $pdo->prepare(
            'INSERT INTO lesson_progress (user_id, course_slug, lesson_num, last_opened_at, completed_at)
             VALUES (?, ?, ?, NULL, ?)
             ON CONFLICT(user_id, course_slug, lesson_num) DO UPDATE SET completed_at = excluded.completed_at'
        );
        $stmt->execute([$userId, $courseSlug, $lessonNum, $completedAt]);
    }

    public static function lastOpenedNum(PDO $pdo, int $userId, string $courseSlug): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT lesson_num FROM lesson_progress
             WHERE user_id = ? AND course_slug = ? AND last_opened_at IS NOT NULL
             ORDER BY last_opened_at DESC
             LIMIT 1'
        );
        $stmt->execute([$userId, $courseSlug]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }
        $num = (int)$value;

        return $num > 0 ? $num : null;
    }

    /**
     * @param array<int, array<string, mixed>> $lessonAccess
     */
    public static function resumeLessonNum(PDO $pdo, int $userId, string $courseSlug, array $lessonAccess): ?int
    {
        $num = self::lastOpenedNum($pdo, $userId, $courseSlug);
        if ($num === null) {
            return null;
        }
        $access = $lessonAccess[$num] ?? null;
        if (!is_array($access) || empty($access['can_view_lesson'])) {
            return null;
        }

        return $num;
    }

    /**
     * @return array<int, true>
     */
    public static function completedMap(PDO $pdo, int $userId, string $courseSlug): array
    {
        $stmt = $pdo->prepare(
            'SELECT lesson_num FROM lesson_progress
             WHERE user_id = ? AND course_slug = ? AND completed_at IS NOT NULL'
        );
        $stmt->execute([$userId, $courseSlug]);
        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int)$row['lesson_num']] = true;
        }

        return $map;
    }

    /** Fixed-width UTC timestamp so two opens in the same second still sort in order. */
    private static function now(): string
    {
        $micro = microtime(true);
        $seconds = (int)floor($micro);
        $fraction = (int)round(($micro - $seconds) * 1000000);
        if ($fraction >= 1000000) {
            $seconds++;
            $fraction -= 1000000;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%06dZ', $fraction);
    }
}
