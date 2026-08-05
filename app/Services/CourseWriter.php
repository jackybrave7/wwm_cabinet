<?php
declare(strict_types=1);

namespace Wwm\Services;

final class CourseWriter
{
    private string $coursesDir;

    public function __construct(?string $coursesDir = null)
    {
        $this->coursesDir = $coursesDir ?? (WWM_ROOT . '/data/courses');
    }

    /**
     * @param array<string, mixed> $course
     */
    public function save(string $slug, array $course): void
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('Invalid course slug');
        }
        $course['slug'] = $slug;

        if (!is_dir($this->coursesDir)) {
            @mkdir($this->coursesDir, 0750, true);
        }

        $file = $this->coursesDir . '/' . $slug . '.json';
        $json = json_encode($course, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode course JSON');
        }

        if (is_readable($file)) {
            @copy($file, $file . '.bak');
        }

        if (file_put_contents($file, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write course file');
        }
    }

    /**
     * @param array<string, mixed> $course
     * @param array<string, mixed> $lesson
     * @return array<string, mixed>|null
     */
    public static function findLesson(array $course, int $num): ?array
    {
        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        foreach ($lessons as $lesson) {
            if (is_array($lesson) && (int)($lesson['num'] ?? 0) === $num) {
                return $lesson;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $course
     */
    public static function lessonCount(array $course): int
    {
        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        return count(array_filter($lessons, static fn($l) => is_array($l)));
    }

    /**
     * @param array<string, mixed> $course
     */
    public static function sectionCount(array $course): int
    {
        $sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];
        return count(array_filter($sections, static fn($s) => is_array($s)));
    }

    public static function isPublished(array $course): bool
    {
        $status = strtolower(trim((string)($course['status'] ?? 'published')));
        return $status !== 'draft';
    }

    /**
     * @param array<string, mixed> $course
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function applyLessonOrder(array $course, array $post): array
    {
        $sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];

        if ($sections !== []) {
            $posted = $post['section_lessons'] ?? null;
            if (!is_array($posted)) {
                return $course;
            }
            foreach ($sections as $i => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $key = (string)$i;
                if (!isset($posted[$key]) || !is_array($posted[$key])) {
                    continue;
                }
                $nums = array_values(array_filter(array_map('intval', $posted[$key])));
                if ($nums !== []) {
                    $course['sections'][$i]['lessons'] = $nums;
                }
            }
            return $course;
        }

        $order = $post['lesson_order'] ?? null;
        if (!is_array($order) || $order === []) {
            return $course;
        }

        $orderNums = array_values(array_filter(array_map('intval', $order)));
        $byNum = [];
        foreach ($course['lessons'] ?? [] as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $num = (int)($lesson['num'] ?? 0);
            if ($num > 0) {
                $byNum[$num] = $lesson;
            }
        }

        $reordered = [];
        foreach ($orderNums as $num) {
            if (isset($byNum[$num])) {
                $reordered[] = $byNum[$num];
                unset($byNum[$num]);
            }
        }
        foreach ($byNum as $lesson) {
            $reordered[] = $lesson;
        }

        $course['lessons'] = $reordered;

        return $course;
    }

    /**
     * @param array<string, mixed> $course
     */
    public static function sectionIndexForLesson(array $course, int $lessonNum): ?int
    {
        $sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];
        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $refs = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];
            foreach ($refs as $ref) {
                $num = is_array($ref) ? (int)($ref['num'] ?? 0) : (int)$ref;
                if ($num === $lessonNum) {
                    return (int)$i;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $course
     * @return array<string, mixed>
     */
    public static function setLessonSection(array $course, int $lessonNum, int $sectionIndex): array
    {
        $sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];
        if ($sections === []) {
            return $course;
        }

        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $refs = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];
            $sections[$i]['lessons'] = array_values(array_filter(
                $refs,
                static fn($ref) => (is_array($ref) ? (int)($ref['num'] ?? 0) : (int)$ref) !== $lessonNum
            ));
        }

        if (!isset($sections[$sectionIndex]) || !is_array($sections[$sectionIndex])) {
            return $course;
        }

        $target = is_array($sections[$sectionIndex]['lessons'] ?? null)
            ? $sections[$sectionIndex]['lessons']
            : [];
        $target[] = $lessonNum;
        $sections[$sectionIndex]['lessons'] = $target;
        $course['sections'] = $sections;

        return $course;
    }
}
