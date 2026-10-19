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
        if ($name !== null && $name !== '') {
            AvoContactName::syncForUser($pdo, $userId, $name);
            $user = User::findById($pdo, $userId) ?? $user;
        }
        if ($avoContactId !== null && $avoContactId > 0) {
            User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $avoContactId]);
        }

        $message = $this->buildMessage($template, $user, $course, $courseSlug);
        $links = $this->trackedLinks($template, $message, $course);

        return $this->deliverComposed($userId, $email, $template, $message, $links, $courseSlug);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{subject: string, text: string, html: ?string, login_url: string, buy_url: string}
     */
    public function composeForAutomation(string $template, array $user, string $courseSlug): array
    {
        $courseSlug = $this->resolveCourseSlug($courseSlug);
        $course = (new CourseCatalog())->getAdmin($courseSlug);
        if ($course === null) {
            throw new \RuntimeException('Course not found');
        }

        return $this->buildMessage($template, $user, $course, $courseSlug);
    }

    /**
     * @param array{subject: string, text: string, html: ?string, login_url: string, buy_url: string} $message
     * @param list<array{url: string, label: string}> $links
     * @return array{email_sent: bool, user_id: int, template: string, course_slug: string}
     */
    private function deliverComposed(
        int $userId,
        string $email,
        string $template,
        array $message,
        array $links,
        string $courseSlug,
    ): array {

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

    /**
     * @param array<string, mixed> $user
     */
    public function sendAutomationMarketingTemplate(
        string $template,
        array $user,
        string $courseSlug,
        int $automationId,
    ): bool {
        if (!MarketingEmailDelivery::isMarketingTemplate($template)) {
            throw new \InvalidArgumentException('Not a marketing automation template');
        }
        if (EmailTemplateCatalog::find($template) === null) {
            return false;
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || $email === '') {
            return false;
        }

        $courseSlug = $this->resolveCourseSlug($courseSlug);
        $course = (new CourseCatalog())->getAdmin($courseSlug);
        if ($course === null) {
            return false;
        }

        $message = $this->buildMessage($template, $user, $course, $courseSlug);
        $links = $this->trackedLinks($template, $message, $course);
        $name = trim((string)($user['name'] ?? ''));
        $listId = 'automation.' . $automationId;

        return MarketingEmailDelivery::deliver(
            $userId,
            $email,
            $template,
            $message,
            $links,
            $listId,
            $name,
        );
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
        if ($coursePageUrl === '') {
            // Sale CTAs must never ship with href="" (looks like a button, not clickable).
            $coursePageUrl = 'https://worldwatercolormasters.art';
            wwm_log(sprintf(
                'cabinet mail missing buy_url course=%s title=%s — fallback site root',
                $courseSlug,
                (string)($course['title'] ?? '')
            ));
        }

        $nextPath = DemoAccess::defaultNextPath($courseSlug);
        $userId = (int)($user['id'] ?? 0);
        $demoEnds = $this->demoExpiresTimestamp(wwm_pdo(), $userId, $courseSlug);
        $ttl = $demoEnds !== null ? max(3600, $demoEnds - time()) : \Wwm\Models\LoginLink::ttlSeconds();
        $loginUrl = $userId > 0
            ? \Wwm\Models\LoginLink::issueReusable(wwm_pdo(), $userId, $nextPath, $ttl)
            : wwm_base_url() . '/login?next=' . rawurlencode($nextPath);

        $expiresLabel = $this->demoExpiresLabel(wwm_pdo(), $userId, $courseSlug);
        $opened = $userId > 0 && (new StudentEngagement())->forEmail($email, $courseSlug)['demo_lesson_opened'];
        if (in_array($template, ['sale_crosssell_50_offer', 'sale_crosssell_50_reminder'], true)) {
            $couponCode = MarketingEmailDelivery::crosssellCouponCode();
        } else {
            $couponCode = trim((string)(wwm_config()['sale_coupon_code'] ?? 'SPECWWM4'));
            if ($couponCode === '') {
                $couponCode = 'SPECWWM4';
            }
        }
        $couponExpiresTs = time() + 24 * 3600;
        $couponExpiresLabel = gmdate('M j, Y H:i', $couponExpiresTs) . ' UTC';
        if (in_array($template, ['sale_demo_discount_24h', 'sale_demo_discount_3h'], true)) {
            $coursePageUrl = self::saleCheckoutUrl($coursePageUrl, $couponCode, $template);
        }

        $context = [
            'name' => $name,
            'email' => $email,
            'course_title' => $courseTitle,
            'cover_url' => $coverUrl ?? '',
            'logo_url' => wwm_email_logo_url_for_template($template),
            'course_page_url' => $coursePageUrl,
            'buy_url' => $coursePageUrl,
            'login_url' => $loginUrl,
            'password' => '',
            'expires_label' => $expiresLabel,
            'coupon_code' => $couponCode,
            'coupon_expires_label' => $couponExpiresLabel,
            'sale_intro' => $opened
                ? 'We hope you enjoyed the demo lesson from "' . $courseTitle . '".'
                : 'Your demo of "' . $courseTitle . '" is still waiting if you want to look before the offer ends.',
            'expiring_subject' => $opened
                ? 'Your demo ends soon — continue watching'
                : "Haven't watched yet? Your demo ends soon",
            'expiring_heading' => $opened ? 'Your demo ends soon' : "Haven't watched yet?",
            'expiring_line' => $opened
                ? 'Your demo of ' . $courseTitle . ' ends soon — continue watching while access is active.'
                : 'You have not opened the demo lesson yet, and access to ' . $courseTitle . ' ends soon.',
        ];

        $message = EmailTemplateRenderer::render($template, $context);

        return $message + ['login_url' => $loginUrl, 'buy_url' => $coursePageUrl];
    }

    private function demoExpiresLabel(\PDO $pdo, int $userId, string $courseSlug): string
    {
        $ts = $this->demoExpiresTimestamp($pdo, $userId, $courseSlug);
        if ($ts === null) {
            $hours = (int)(wwm_config()['demo_hours'] ?? 48);
            return gmdate('M j, Y H:i', time() + max(1, $hours) * 3600) . ' UTC';
        }

        return gmdate('M j, Y H:i', $ts) . ' UTC';
    }

    private function demoExpiresTimestamp(\PDO $pdo, int $userId, string $courseSlug): ?int
    {
        $grant = Access::findGrant($pdo, $userId, $courseSlug, 'demo');
        $expiresAt = is_array($grant) ? trim((string)($grant['expires_at'] ?? '')) : '';
        if ($expiresAt === '') {
            return null;
        }
        $ts = strtotime($expiresAt);

        return $ts !== false ? $ts : null;
    }

    private static function saleCheckoutUrl(string $buyUrl, string $coupon, string $nodeHint): string
    {
        if ($buyUrl === '' || !str_starts_with($buyUrl, 'https://')) {
            return $buyUrl;
        }
        $extra = [
            'promo' => $coupon,
            'utm_source' => 'email',
            'utm_campaign' => 'elke-demo',
            'utm_content' => $nodeHint,
        ];
        $sep = str_contains($buyUrl, '?') ? '&' : '?';

        return $buyUrl . $sep . http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array{subject: string, text: string, html: ?string, login_url: string, buy_url: string} $message
     * @param array<string, mixed> $course
     * @return list<array{url: string, label: string}>
     */
    private function trackedLinks(string $template, array $message, array $course): array
    {
        $saleTemplates = [
            'sale_demo_discount_24h',
            'sale_demo_discount_3h',
            'sale_crosssell_50_offer',
            'sale_crosssell_50_reminder',
        ];
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
