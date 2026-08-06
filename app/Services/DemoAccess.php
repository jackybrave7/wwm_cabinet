<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\PasswordReset;
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
     *   course_slug: string
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

        if ($created && $this->demoDefaultPassword() === null) {
            $this->sendWelcomeEmail($user, $course, $expiresAt);
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

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $course
     */
    private function sendWelcomeEmail(array $user, array $course, string $expiresAt): void
    {
        $token = bin2hex(random_bytes(32));
        PasswordReset::create(wwm_pdo(), (int)$user['id'], $token, 72 * 3600);

        $base = rtrim((string)wwm_config()['base_url'], '/');
        $setPasswordUrl = $base . '/reset?token=' . urlencode($token);
        $loginUrl = $base . '/login';
        $courseTitle = (string)($course['title'] ?? $course['slug'] ?? 'course');
        $name = trim((string)($user['name'] ?? ''));
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';

        $body = implode("\n", [
            $greeting,
            '',
            "Your demo access to \"{$courseTitle}\" is ready.",
            '',
            'Set your password and open lesson 1:',
            $setPasswordUrl,
            '',
            'Or sign in later at:',
            $loginUrl,
            '',
            'Demo access expires: ' . gmdate('Y-m-d H:i') . ' UTC',
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
