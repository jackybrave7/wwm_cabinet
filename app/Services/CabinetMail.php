<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Access;
use Wwm\Models\User;
use Wwm\Services\EmailTemplateRenderer;

final class CabinetMail
{
    /**
     * @return array{
     *   email_sent: bool,
     *   user_id: int,
     *   template: string,
     *   course_slug: string
     * }
     */
    public function sendTemplate(
        string $template,
        string $email,
        ?string $name = null,
        ?string $courseSlug = null,
        ?int $avoContactId = null
    ): array {
        if (EmailTemplateCatalog::find($template) === null) {
            throw new \InvalidArgumentException('Unknown template');
        }

        if (!in_array($template, EmailTemplateCatalog::webhookTemplateIds(), true)) {
            throw new \InvalidArgumentException('Template is not available via webhook');
        }

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }

        $courseSlug = $this->resolveCourseSlug($courseSlug);
        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($courseSlug);
        if ($course === null) {
            throw new \RuntimeException('Course not found');
        }

        $pdo = wwm_pdo();
        $user = User::findByEmail($pdo, $email);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        $userId = (int)$user['id'];
        if ($name !== null && $name !== '' && trim((string)($user['name'] ?? '')) === '') {
            User::updateName($pdo, $userId, $name);
            $user = User::findById($pdo, $userId) ?? $user;
        }
        if ($avoContactId !== null && $avoContactId > 0) {
            User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $avoContactId]);
        }

        $message = $this->buildMessage($template, $user, $course, $courseSlug);
        $links = $this->trackedLinks($template, $message, $course);

        $emailSent = EmailTracker::compose($userId, $email, $template, $message['subject'])
            ->deliver($message['text'], $message['html'], $links);

        wwm_log(sprintf(
            'cabinet mail template=%s user_id=%d course=%s sent=%s',
            $template,
            $userId,
            $courseSlug,
            $emailSent ? 'yes' : 'no'
        ));

        return [
            'email_sent' => $emailSent,
            'user_id' => $userId,
            'template' => $template,
            'course_slug' => $courseSlug,
        ];
    }

    private function resolveCourseSlug(?string $courseSlug): string
    {
        $courseSlug = trim((string)$courseSlug);
        if ($courseSlug !== '') {
            $normalized = preg_replace('/[^a-z0-9\-]/', '', $courseSlug);
            if (is_string($normalized) && $normalized !== '') {
                return $normalized;
            }
        }

        return 'elke-en';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $course
     * @return array{subject: string, text: string, html: ?string, login_url: string, buy_url: string}
     */
    private function buildMessage(string $template, array $user, array $course, string $courseSlug): array
    {
        $name = trim((string)($user['name'] ?? ''));
        $email = (string)$user['email'];
        $courseTitle = (string)($course['title'] ?? $courseSlug);
        $coverUrl = wwm_course_cover_url(isset($course['cover_image']) ? (string)$course['cover_image'] : null);
        $coursePageUrl = trim((string)($course['buy_url'] ?? ''));
        if ($coursePageUrl !== '' && !str_starts_with($coursePageUrl, 'https://')) {
            $coursePageUrl = '';
        }

        $nextPath = DemoAccess::defaultNextPath($courseSlug);
        $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
        $loginUrl = $password !== ''
            ? wwm_login_url($email, $password, $nextPath)
            : wwm_base_url() . '/login?email=' . rawurlencode($email) . '&next=' . rawurlencode($nextPath);

        $expiresLabel = $this->demoExpiresLabel(wwm_pdo(), (int)$user['id'], $courseSlug);
        $couponCode = trim((string)(wwm_config()['sale_coupon_code'] ?? 'SPECWWM4'));
        if ($couponCode === '') {
            $couponCode = 'SPECWWM4';
        }

        $context = [
            'name' => $name,
            'email' => $email,
            'course_title' => $courseTitle,
            'cover_url' => $coverUrl ?? '',
            'logo_url' => wwm_email_logo_url(),
            'course_page_url' => $coursePageUrl,
            'buy_url' => $coursePageUrl,
            'login_url' => $loginUrl,
            'password' => $password,
            'expires_label' => $expiresLabel,
            'coupon_code' => $couponCode,
        ];

        $message = EmailTemplateRenderer::render($template, $context);

        return $message + ['login_url' => $loginUrl, 'buy_url' => $coursePageUrl];
    }

    private function demoExpiresLabel(\PDO $pdo, int $userId, string $courseSlug): string
    {
        $grant = Access::findGrant($pdo, $userId, $courseSlug, 'demo');
        $expiresAt = is_array($grant) ? trim((string)($grant['expires_at'] ?? '')) : '';
        if ($expiresAt === '') {
            $hours = (int)(wwm_config()['demo_hours'] ?? 48);
            return gmdate('M j, Y H:i', time() + max(1, $hours) * 3600) . ' UTC';
        }

        $ts = strtotime($expiresAt);
        return $ts ? gmdate('M j, Y H:i', $ts) . ' UTC' : $expiresAt . ' UTC';
    }

    /**
     * @param array{subject: string, text: string, html: ?string, login_url: string, buy_url: string} $message
     * @param array<string, mixed> $course
     * @return list<array{url: string, label: string}>
     */
    private function trackedLinks(string $template, array $message, array $course): array
    {
        $saleTemplates = ['sale_demo_discount_24h', 'sale_demo_discount_3h'];
        if (in_array($template, $saleTemplates, true)) {
            $buyUrl = trim((string)($message['buy_url'] ?? ''));
            if ($buyUrl !== '' && str_starts_with($buyUrl, 'https://')) {
                return [['url' => $buyUrl, 'label' => 'Purchase course']];
            }
        }

        $links = [
            ['url' => $message['login_url'], 'label' => 'Sign in'],
        ];
        $coursePageUrl = trim((string)($course['buy_url'] ?? ''));
        if ($coursePageUrl !== '' && str_starts_with($coursePageUrl, 'https://')) {
            $links[] = ['url' => $coursePageUrl, 'label' => 'Course page'];
        }

        return $links;
    }
}
