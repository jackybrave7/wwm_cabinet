<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\LoginLink;
use Wwm\Models\User;
use Wwm\Services\Mailer;
use Wwm\Services\StudentAttribution;

final class DemoAccess
{
    /**
     * @return array{
     *   user_id: int,
     *   created: bool,
     *   paid: bool,
     *   demo_granted: bool,
     *   demo_active: bool,
     *   expires_at: string|null,
     *   course_slug: string,
     *   login_url: string|null
     * }
     */
    public function grant(
        string $email,
        string $name,
        string $courseSlug,
        string $source = 'avo',
        ?string $sourceRef = null,
        array $utm = [],
        ?int $avoContactId = null
    ): array {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }

        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($courseSlug);
        if ($course === null) {
            throw new \RuntimeException('Course not found');
        }

        $pdo = wwm_pdo();
        $user = User::findByEmail($pdo, $email);
        $created = false;

        if ($user === null) {
            $password = $this->demoDefaultPassword() ?? bin2hex(random_bytes(12));
            $userId = User::create($pdo, $email, $password, $name);
            $user = User::findById($pdo, $userId);
            $created = true;
        } elseif ($name !== '' && trim((string)($user['name'] ?? '')) === '') {
            User::updateName($pdo, (int)$user['id'], $name);
        }

        if ($user === null) {
            throw new \RuntimeException('Failed to create user');
        }

        $userId = (int)$user['id'];
        if ($avoContactId !== null && $avoContactId > 0) {
            User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $avoContactId]);
        }
        StudentAttribution::recordForUser($pdo, $userId, $created, $utm);
        $state = Access::courseState($pdo, $userId, $courseSlug);

        if ($state['has_paid']) {
            return [
                'user_id' => $userId,
                'created' => $created,
                'paid' => true,
                'demo_granted' => false,
                'demo_active' => false,
                'expires_at' => null,
                'course_slug' => $courseSlug,
            ];
        }

        $demoHours = (int)($course['demo_hours'] ?? wwm_config()['demo_hours'] ?? 48);
        if ($demoHours < 1) {
            $demoHours = 48;
        }

        $expiresAt = gmdate('c', time() + $demoHours * 3600);
        $demoGranted = false;

        if (!$state['demo_active']) {
            Access::grant($pdo, $userId, $courseSlug, 'demo', $expiresAt, $source, $sourceRef);
            $demoGranted = true;
            wwm_log(sprintf(
                'demo granted user_id=%d course=%s expires=%s source=%s',
                $userId,
                $courseSlug,
                $expiresAt,
                $source
            ));
        }

        $loginUrl = null;
        if ($state['demo_active'] || $demoGranted) {
            $nextPath = self::defaultNextPath($courseSlug);
            $demoPassword = $this->demoDefaultPassword();
            if ($demoPassword !== null) {
                $loginUrl = wwm_login_url($email, $demoPassword, $nextPath);
            } else {
                $loginUrl = LoginLink::issue(
                    $pdo,
                    $userId,
                    $nextPath,
                    LoginLink::ttlSeconds()
                );
            }
        }

        if ($demoGranted && $loginUrl !== null) {
            $this->sendDemoLoginEmail($user, $course, $expiresAt, $loginUrl);
        }

        return [
            'user_id' => $userId,
            'created' => $created,
            'paid' => false,
            'demo_granted' => $demoGranted,
            'demo_active' => true,
            'expires_at' => $state['demo_active']
                ? $this->currentDemoExpiresAt($pdo, $userId, $courseSlug)
                : $expiresAt,
            'course_slug' => $courseSlug,
            'login_url' => $loginUrl,
        ];
    }

    public static function resolveCourseSlug(?string $courseSlug, ?int $goodsId): ?string
    {
        $courseSlug = trim((string)$courseSlug);
        if ($courseSlug !== '') {
            return preg_replace('/[^a-z0-9\-]/', '', $courseSlug) ?: null;
        }

        if ($goodsId === null || $goodsId <= 0) {
            return null;
        }

        $map = wwm_config()['avo_goods_to_course'] ?? [];
        if (!is_array($map)) {
            return null;
        }

        return isset($map[$goodsId]) ? (string)$map[$goodsId] : null;
    }

    public static function defaultNextPath(string $courseSlug): string
    {
        $catalog = new CourseCatalog();
        $course = $catalog->get($courseSlug);
        if ($course === null) {
            return '/';
        }

        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        foreach ($lessons as $lesson) {
            if (!is_array($lesson) || empty($lesson['demo'])) {
                continue;
            }
            $num = (int)($lesson['num'] ?? 0);
            if ($num > 0) {
                return '/c/' . rawurlencode($courseSlug) . '/' . $num;
            }
        }

        return '/c/' . rawurlencode($courseSlug);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $course
     */
    private function sendDemoLoginEmail(array $user, array $course, string $expiresAt, string $loginUrl): void
    {
        $courseTitle = (string)($course['title'] ?? $course['slug'] ?? '');
        $coverUrl = wwm_course_cover_url(isset($course['cover_image']) ? (string)$course['cover_image'] : null);
        $coursePageUrl = trim((string)($course['buy_url'] ?? ''));
        if ($coursePageUrl !== '' && !str_starts_with($coursePageUrl, 'https://')) {
            $coursePageUrl = '';
        }
        $expiresLocal = gmdate('Y-m-d H:i', strtotime($expiresAt)) . ' UTC';

        $message = TransactionalEmail::demoAccess(
            trim((string)($user['name'] ?? '')),
            (string)$user['email'],
            $courseTitle,
            $coverUrl,
            $coursePageUrl !== '' ? $coursePageUrl : null,
            $loginUrl,
            $expiresLocal,
            $this->demoDefaultPassword()
        );

        Mailer::send(
            (string)$user['email'],
            $message['subject'],
            $message['text'],
            $message['html']
        );
    }

    private function demoDefaultPassword(): ?string
    {
        $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
        return $password !== '' ? $password : null;
    }

    private function currentDemoExpiresAt(\PDO $pdo, int $userId, string $courseSlug): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT expires_at FROM access
             WHERE user_id = ? AND course_slug = ? AND access_type = ? LIMIT 1'
        );
        $stmt->execute([$userId, $courseSlug, 'demo']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $expires = $row['expires_at'] ?? null;
        return is_string($expires) && $expires !== '' ? $expires : null;
    }
}
