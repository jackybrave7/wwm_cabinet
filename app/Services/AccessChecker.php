<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\Access;

final class AccessChecker
{
    private PDO $pdo;
    private ?int $loadedUserId = null;

    /** @var array<string, array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool}> */
    private array $userStateMap = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool}
     */
    public function courseState(int $userId, string $courseSlug): array
    {
        $this->ensureUserStates($userId);

        return $this->userStateMap[$courseSlug] ?? [
            'has_paid' => false,
            'has_demo' => false,
            'demo_active' => false,
            'paid_active' => false,
        ];
    }

    /**
     * @return array{can_view_course: bool, can_view_lesson: bool, access_label: string}
     */
    public function lesson(int $userId, array $course, array $lesson): array
    {
        $slug = (string)$course['slug'];
        $state = $this->courseState($userId, $slug);
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
        $this->ensureUserStates($userId);

        $result = [];
        foreach ($catalog->all() as $course) {
            $slug = (string)$course['slug'];
            $state = $this->courseState($userId, $slug);
            if (!$state['has_paid'] && !$state['demo_active']) {
                continue;
            }
            $result[] = array_merge($course, [
                'access' => $state,
            ]);
        }

        return $result;
    }

    private function ensureUserStates(int $userId): void
    {
        if ($this->loadedUserId === $userId) {
            return;
        }

        $this->userStateMap = Access::stateMapForUser($this->pdo, $userId);
        $this->loadedUserId = $userId;
    }
}
