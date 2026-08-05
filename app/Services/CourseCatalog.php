<?php
declare(strict_types=1);

namespace Wwm\Services;

final class CourseCatalog
{
    private string $coursesDir;

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
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['slug'])) {
            return null;
        }
        return $data;
    }
}
