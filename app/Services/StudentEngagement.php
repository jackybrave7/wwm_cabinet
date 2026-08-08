<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\LessonOpen;
use Wwm\Models\User;

final class StudentEngagement
{
    /**
     * @return array<string, mixed>
     */
    public function forEmail(string $email, string $courseSlug): array
    {
        $email = strtolower(trim($email));
        $pdo = wwm_pdo();
        $user = User::findByEmail($pdo, $email);

        if ($user === null) {
            return $this->emptyStatus($email, $courseSlug, found: false);
        }

        $userId = (int)$user['id'];
        $lastLoginAt = isset($user['last_login_at']) && is_string($user['last_login_at']) && $user['last_login_at'] !== ''
            ? $user['last_login_at']
            : null;
        $hasLoggedIn = $lastLoginAt !== null;

        $state = Access::courseState($pdo, $userId, $courseSlug);
        $hasAccess = $state['has_paid'] || $state['demo_active'];
        $accessType = $state['has_paid'] ? 'paid' : ($state['demo_active'] ? 'demo' : null);

        $grant = null;
        if ($accessType === 'demo') {
            $grant = Access::findGrant($pdo, $userId, $courseSlug, 'demo');
        } elseif ($accessType === 'paid') {
            $grant = Access::findGrant($pdo, $userId, $courseSlug, 'paid');
        }

        $accessGrantedAt = null;
        if (is_array($grant) && isset($grant['granted_at']) && is_string($grant['granted_at']) && $grant['granted_at'] !== '') {
            $accessGrantedAt = $grant['granted_at'];
        }

        $opens = LessonOpen::forUserCourse($pdo, $userId, $courseSlug);
        $lessonsOpened = count($opens);
        $demoLessonOpened = $this->hasDemoLessonOpened($courseSlug, $opens);
        $firstLessonOpenedAt = $this->earliestOpenedAt($opens);
        $lastLessonActivityAt = $this->latestOpenedAt($opens);

        $loggedInAfterAccess = false;
        if ($hasLoggedIn && $accessGrantedAt !== null) {
            $loggedInAfterAccess = strtotime($lastLoginAt) >= strtotime($accessGrantedAt);
        } elseif ($hasLoggedIn) {
            $loggedInAfterAccess = true;
        }

        return [
            'ok' => true,
            'found' => true,
            'email' => $email,
            'user_id' => $userId,
            'course' => $courseSlug,
            'has_logged_in' => $hasLoggedIn,
            'logged_in_after_access' => $loggedInAfterAccess,
            'last_login_at' => $lastLoginAt,
            'has_access' => $hasAccess,
            'access_type' => $accessType,
            'demo_active' => $state['demo_active'],
            'paid_active' => $state['has_paid'],
            'access_granted_at' => $accessGrantedAt,
            'lessons_opened' => $lessonsOpened,
            'demo_lesson_opened' => $demoLessonOpened,
            'first_lesson_opened_at' => $firstLessonOpenedAt,
            'last_lesson_activity_at' => $lastLessonActivityAt,
        ];
    }

    /**
     * @param array<int, array{first_opened_at: string, last_opened_at: string}> $opens
     */
    private function hasDemoLessonOpened(string $courseSlug, array $opens): bool
    {
        if ($opens === []) {
            return false;
        }

        $catalog = new CourseCatalog();
        $course = $catalog->get($courseSlug);
        if ($course === null) {
            return false;
        }

        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        foreach ($lessons as $lesson) {
            if (!is_array($lesson) || empty($lesson['demo'])) {
                continue;
            }
            $num = (int)($lesson['num'] ?? 0);
            if ($num > 0 && isset($opens[$num])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{first_opened_at: string, last_opened_at: string}> $opens
     */
    private function earliestOpenedAt(array $opens): ?string
    {
        $earliest = null;
        foreach ($opens as $open) {
            $at = (string)($open['first_opened_at'] ?? '');
            if ($at === '') {
                continue;
            }
            if ($earliest === null || $at < $earliest) {
                $earliest = $at;
            }
        }

        return $earliest;
    }

    /**
     * @param array<int, array{first_opened_at: string, last_opened_at: string}> $opens
     */
    private function latestOpenedAt(array $opens): ?string
    {
        $latest = null;
        foreach ($opens as $open) {
            $at = (string)($open['last_opened_at'] ?? '');
            if ($at === '') {
                continue;
            }
            if ($latest === null || $at > $latest) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatus(string $email, string $courseSlug, bool $found): array
    {
        return [
            'ok' => true,
            'found' => $found,
            'email' => $email,
            'user_id' => null,
            'course' => $courseSlug,
            'has_logged_in' => false,
            'logged_in_after_access' => false,
            'last_login_at' => null,
            'has_access' => false,
            'access_type' => null,
            'demo_active' => false,
            'paid_active' => false,
            'access_granted_at' => null,
            'lessons_opened' => 0,
            'demo_lesson_opened' => false,
            'first_lesson_opened_at' => null,
            'last_lesson_activity_at' => null,
        ];
    }
}
