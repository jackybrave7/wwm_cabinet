<?php
declare(strict_types=1);

namespace Wwm\Services;

final class CourseCatalog
{
    private string $coursesDir;

    /** @var array<string, array{0: ?array, 1: int}> */
    private static array $fileCache = [];

    public function __construct(?string $coursesDir = null)
    {
        $this->coursesDir = $coursesDir ?? (WWM_ROOT . '/data/courses');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $courses = [];
        if (!is_dir($this->coursesDir)) {
            return [];
        }
        foreach (glob($this->coursesDir . '/*.json') ?: [] as $file) {
            $course = $this->loadFile($file);
            if ($course !== null) {
                $courses[] = $course;
            }
        }
        usort($courses, static fn(array $a, array $b): int => strcmp((string)$a['title'], (string)$b['title']));
        return $courses;
    }

    public function get(string $slug): ?array
    {
        $course = $this->loadSlug($slug);
        if ($course === null) {
            return null;
        }
        if (!CourseWriter::isPublished($course)) {
            return null;
        }
        return $course;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdmin(string $slug): ?array
    {
        return $this->loadSlug($slug);
    }

    private function loadSlug(string $slug): ?array
    {
        $file = $this->coursesDir . '/' . preg_replace('/[^a-z0-9\-]/', '', $slug) . '.json';
        if (!is_readable($file)) {
            return null;
        }
        return $this->loadFile($file);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFile(string $file): ?array
    {
        $mtime = @filemtime($file) ?: 0;
        if (isset(self::$fileCache[$file]) && self::$fileCache[$file][1] === $mtime) {
            return self::$fileCache[$file][0];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            self::$fileCache[$file] = [null, $mtime];
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['slug'])) {
            self::$fileCache[$file] = [null, $mtime];
            return null;
        }

        self::$fileCache[$file] = [$data, $mtime];
        return $data;
    }
}
