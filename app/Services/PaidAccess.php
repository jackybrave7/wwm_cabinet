<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\LoginLink;
use Wwm\Models\User;
use Wwm\Services\Mailer;

final class PaidAccess
{
    /**
     * @return array{
     *   user_id: int,
     *   created: bool,
     *   paid_granted: bool,
     *   already_paid: bool,
     *   email_sent: bool,
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
        ?int $avoContactId = null,
        ?bool $sendEmail = null
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
            $password = bin2hex(random_bytes(12));
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
                'paid_granted' => false,
                'already_paid' => true,
                'email_sent' => false,
                'course_slug' => $courseSlug,
                'login_url' => null,
            ];
        }

        Access::grant($pdo, $userId, $courseSlug, 'paid', null, $source, $sourceRef);
        wwm_log(sprintf(
            'paid granted user_id=%d course=%s source=%s ref=%s',
            $userId,
            $courseSlug,
            $source,
            $sourceRef ?? ''
        ));

        $nextPath = self::courseNextPath($courseSlug);
        $loginUrl = LoginLink::issue($pdo, $userId, $nextPath, LoginLink::ttlSeconds());

        $shouldSend = $sendEmail ?? self::shouldSendPaidEmail($courseSlug);
        $emailSent = false;
        if ($shouldSend) {
            $emailSent = $this->sendPaidAccessEmail($user, $course, $loginUrl);
        }

        return [
            'user_id' => $userId,
            'created' => $created,
            'paid_granted' => true,
            'already_paid' => false,
            'email_sent' => $emailSent,
            'course_slug' => $courseSlug,
            'login_url' => $loginUrl,
        ];
    }

    public static function shouldSendPaidEmail(string $courseSlug): bool
    {
        $slugs = wwm_config()['paid_email_slugs'] ?? null;
        if (!is_array($slugs) || $slugs === []) {
            $slugs = ['elke-en', 'elke-de', 'alvaro'];
        }

        return in_array($courseSlug, $slugs, true);
    }

    public static function courseNextPath(string $courseSlug): string
    {
        return '/c/' . rawurlencode($courseSlug);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $course
     */
    private function sendPaidAccessEmail(array $user, array $course, string $loginUrl): bool
    {
        $courseTitle = (string)($course['title'] ?? $course['slug'] ?? 'your course');
        $name = trim((string)($user['name'] ?? ''));
        $greeting = $name !== '' ? "Hello {$name}," : 'Hello,';

        $body = implode("\n", [
            $greeting,
            '',
            'Thank you for your purchase!',
            '',
            "Your full access to \"{$courseTitle}\" is ready.",
            '',
            'Open this link to sign in and start watching:',
            $loginUrl,
            '',
            'You can also sign in manually at:',
            wwm_base_url() . '/login',
            '',
            'Questions? support@worldwatercolormasters.art',
            '',
            'Happy painting!',
            'World Watercolor Masters',
        ]);

        return Mailer::send(
            (string)$user['email'],
            'Your course access — World Watercolor Masters',
            $body
        );
    }
}
