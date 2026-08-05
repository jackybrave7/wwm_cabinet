<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\Access;

final class AccessChecker
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{can_view_course: bool, can_view_lesson: bool, access_label: string}
     */
    public function lesson(int $userId, array $course, array $lesson): array
    {
        $slug = (string)$course['slug'];
        $state = Access::courseState($this->pdo, $userId, $slug);
        $isDemoLesson = !empty($lesson['demo']);

        if ($state['has_paid']) {
            return [
                'can_view_course' => true,
                'can_view_lesson' => true,
                'access_label' => 'Full access',
            ];
        }

        if ($state['demo_active'] && $isDemoLesson) {
            return [
                'can_view_course' => true,
                'can_view_lesson' => true,
                'access_label' => 'Demo access',
            ];
        }

        if ($state['demo_active'] && !$isDemoLesson) {
            return [
                'can_view_course' => true,
                'can_view_lesson' => false,
                'access_label' => 'Demo — first lesson only',
            ];
        }

        return [
            'can_view_course' => false,
            'can_view_lesson' => false,
            'access_label' => 'No access',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function coursesForDashboard(int $userId, CourseCatalog $catalog): array
    {
        $allAccess = Access::forUser($this->pdo, $userId);
        $bySlug = [];
        foreach ($allAccess as $row) {
            $bySlug[$row['course_slug']][] = $row;
        }

        $result = [];
        foreach ($catalog->all() as $course) {
            $slug = (string)$course['slug'];
            $state = Access::courseState($this->pdo, $userId, $slug);
            if (!$state['has_paid'] && !$state['demo_active']) {
                continue;
            }
            $result[] = array_merge($course, [
                'access' => $state,
            ]);
        }
        return $result;
    }
}
