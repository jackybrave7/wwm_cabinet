<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\LoginLink;
use Wwm\Models\User;
use Wwm\Services\Mailer;

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
        ?string $sourceRef = null
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
            $loginUrl = LoginLink::issue(
                $pdo,
                $userId,
                self::defaultNextPath($courseSlug),
                LoginLink::ttlSeconds()
            );
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
        $courseSlug = (string)($course['slug'] ?? '');
        $courseTitle = (string)($course['title'] ?? $courseSlug);
        $name = trim((string)($user['name'] ?? ''));
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';
        $expiresLocal = gmdate('Y-m-d H:i', strtotime($expiresAt)) . ' UTC';

        $body = implode("\n", [
            $greeting,
            '',
            "Your demo access to \"{$courseTitle}\" is ready.",
            '',
            'Open this link to sign in instantly (no password needed):',
            $loginUrl,
            '',
            'You can also sign in with email and password at:',
            rtrim((string)wwm_config()['base_url'], '/') . '/login',
            '',
            'Demo access expires: ' . $expiresLocal,
            '',
            'World Watercolor Masters',
        ]);

        Mailer::send(
            (string)$user['email'],
            'Your demo access — World Watercolor Masters',
            $body
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
