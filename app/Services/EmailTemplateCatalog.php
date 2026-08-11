<?php
declare(strict_types=1);

namespace Wwm\Services;

final class EmailTemplateCatalog
{
    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   category: string,
     *   description: string,
     *   trigger: string,
     *   has_html: bool,
     *   webhook: bool
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'demo',
                'label' => 'Demo access',
                'category' => 'Access',
                'description' => 'Sent when demo access is granted via /api/demo.',
                'trigger' => 'AVO webhook → /api/demo',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'paid',
                'label' => 'Paid access',
                'category' => 'Access',
                'description' => 'Sent after payment when cabinet grants full course access.',
                'trigger' => 'AVO webhook → /api/payment',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'reminder_demo_no_login',
                'label' => 'Demo reminder — no login',
                'category' => 'Reminder',
                'description' => 'Student received demo access but has not signed in yet.',
                'trigger' => 'AVO BP → /api/mail?template=reminder_demo_no_login',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'reminder_demo_no_lesson',
                'label' => 'Demo reminder — no lesson',
                'category' => 'Reminder',
                'description' => 'Student signed in but has not opened the demo lesson.',
                'trigger' => 'AVO BP → /api/mail?template=reminder_demo_no_lesson',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'reminder_demo_expiring',
                'label' => 'Demo reminder — expiring',
                'category' => 'Reminder',
                'description' => 'Demo access is about to expire.',
                'trigger' => 'AVO BP → /api/mail?template=reminder_demo_expiring',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'sale_demo_discount_24h',
                'label' => 'Sale — 40% off (24h)',
                'category' => 'Sales',
                'description' => 'Special 40% discount offer after demo — expires in 24 hours.',
                'trigger' => 'AVO BP → /api/mail?template=sale_demo_discount_24h',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'sale_demo_discount_3h',
                'label' => 'Sale — final reminder (3h)',
                'category' => 'Sales',
                'description' => 'Final reminder that the 40% discount expires in 3 hours.',
                'trigger' => 'AVO BP → /api/mail?template=sale_demo_discount_3h',
                'has_html' => true,
                'webhook' => true,
            ],
            [
                'id' => 'magic',
                'label' => 'Sign-in link',
                'category' => 'Account',
                'description' => 'One-time magic link from the login page.',
                'trigger' => 'Student requests link on /login',
                'has_html' => false,
                'webhook' => false,
            ],
            [
                'id' => 'reset',
                'label' => 'Password reset',
                'category' => 'Account',
                'description' => 'Password reset link from /forgot.',
                'trigger' => 'Student requests reset on /forgot',
                'has_html' => false,
                'webhook' => false,
            ],
            [
                'id' => 'test',
                'label' => 'SMTP test',
                'category' => 'System',
                'description' => 'Plain-text smoke test from /admin/mail-test.',
                'trigger' => 'Admin mail test',
                'has_html' => false,
                'webhook' => false,
            ],
        ];
    }

    /**
     * @return array{id: string, label: string, category: string, description: string, trigger: string, has_html: bool, webhook: bool}|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function webhookTemplateIds(): array
    {
        $ids = [];
        foreach (self::all() as $template) {
            if ($template['webhook']) {
                $ids[] = $template['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public static function variables(string $id): array
    {
        $common = ['{{name}}', '{{email}}', '{{base_url}}', '{{login_url}}', '{{password}}'];
        $course = ['{{course_title}}', '{{cover_url}}', '{{logo_url}}', '{{course_page_url}}', '{{expires_label}}'];

        return match ($id) {
            'demo', 'reminder_demo_expiring'
                => array_merge($common, $course),
            'paid', 'reminder_demo_no_login', 'reminder_demo_no_lesson'
                => array_merge($common, array_diff($course, ['{{expires_label}}'])),
            'sale_demo_discount_24h', 'sale_demo_discount_3h'
                => ['{{name}}', '{{course_title}}', '{{cover_url}}', '{{logo_url}}', '{{buy_url}}', '{{coupon_code}}'],
            'magic' => ['{{name}}', '{{magic_link}}', '{{magic_link_hours}}'],
            'reset' => ['{{reset_link}}'],
            'test' => ['{{base_url}}'],
            default => $common,
        };
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function builtInMessage(string $id, array $context): array
    {
        if ($id === 'test') {
            return [
                'subject' => 'WWM Cabinet test email',
                'text' => "This is a test message from WWM Cabinet.\n\nIf you received it, SMTP is working.\n",
                'html' => null,
            ];
        }

        $vars = self::contextToVars($id, $context);
        $draft = EmailTemplateDrafts::draft($id);

        $message = [
            'subject' => EmailTemplateRenderer::applyVars($draft['subject'], $vars),
            'text' => EmailTemplateRenderer::applyVars($draft['text'], $vars),
            'html' => $draft['html'] !== null
                ? EmailTemplateRenderer::applyVars($draft['html'], $vars)
                : null,
        ];

        $message['html'] = EmailTemplateDrafts::finalizeHtml($message['html'], $vars);

        return $message;
    }

    /**
     * @param array<string, scalar|null> $context
     * @return array<string, string>
     */
    private static function contextToVars(string $id, array $context): array
    {
        $name = trim((string)($context['name'] ?? ''));
        $email = (string)($context['email'] ?? '');
        $courseTitle = (string)($context['course_title'] ?? '');
        $coverUrl = isset($context['cover_url']) ? trim((string)$context['cover_url']) : '';
        $coursePageUrl = isset($context['course_page_url']) ? trim((string)$context['course_page_url']) : '';
        $loginUrl = (string)($context['login_url'] ?? wwm_base_url() . '/login');
        $password = trim((string)($context['password'] ?? ''));
        $expiresLabel = (string)($context['expires_label'] ?? '');
        $buyUrl = trim((string)($context['buy_url'] ?? $context['course_page_url'] ?? ''));
        $couponCode = trim((string)($context['coupon_code'] ?? 'SPECWWM4'));
        if ($couponCode === '') {
            $couponCode = 'SPECWWM4';
        }
        $magicLink = (string)($context['magic_link'] ?? wwm_base_url() . '/auth/magic?token=sample');
        $resetLink = (string)($context['reset_link'] ?? wwm_base_url() . '/reset?token=sample');

        if ($password === '' && in_array($id, ['demo', 'reminder_demo_no_login', 'reminder_demo_no_lesson', 'reminder_demo_expiring'], true)) {
            $configured = trim((string)(wwm_config()['demo_default_password'] ?? ''));
            $password = $configured !== '' ? $configured : '(use the sign-in link above)';
        }
        if ($password === '' && $id === 'paid') {
            $password = 'your-password';
        }
        if ($expiresLabel === '' && in_array($id, ['demo'], true)) {
            $expiresLabel = gmdate('M j, Y H:i', time() + 48 * 3600) . ' UTC';
        }
        if ($expiresLabel === '' && $id === 'reminder_demo_expiring') {
            $expiresLabel = gmdate('M j, Y H:i', time() + 12 * 3600) . ' UTC';
        }

        return EmailTemplateRenderer::normalizeVars([
            'name' => $name,
            'email' => $email,
            'course_title' => $courseTitle,
            'cover_url' => $coverUrl,
            'logo_url' => wwm_email_logo_url_for_template($id),
            'course_page_url' => $coursePageUrl,
            'login_url' => $loginUrl,
            'password' => $password,
            'expires_label' => $expiresLabel,
            'buy_url' => $buyUrl,
            'coupon_code' => $couponCode,
            'magic_link' => $magicLink,
            'reset_link' => $resetLink,
            'magic_link_hours' => (string)max(1, (int)(\Wwm\Models\LoginLink::ttlSeconds() / 3600)),
        ]);
    }

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function preview(string $id): array
    {
        return EmailTemplateRenderer::render($id, self::sampleContext($id));
    }

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function placeholderDraft(string $id): array
    {
        if ($id === 'test') {
            return [
                'subject' => 'WWM Cabinet test email',
                'text' => "This is a test message from WWM Cabinet.\n\nIf you received it, SMTP is working.\n",
                'html' => null,
            ];
        }

        return EmailTemplateDrafts::draft($id);
    }

    /**
     * @return array{
     *   name: string,
     *   email: string,
     *   course_title: string,
     *   cover_url: string,
     *   logo_url: string,
     *   course_page_url: string,
     *   login_url: string,
     *   expires_label: string,
     *   password: string,
     *   magic_link: string,
     *   reset_link: string,
     *   buy_url: string,
     *   coupon_code: string
     * }
     */
    public static function sampleContext(?string $templateId = null): array
    {
        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin('elke-en') ?? $catalog->getAdmin('alvaro') ?? [];
        $courseTitle = (string)($course['title'] ?? 'Elke Memmler\'s video course \'Watercolor Expressionism\'');
        $coverUrl = wwm_email_sample_cover_url();
        $coursePageUrl = trim((string)($course['buy_url'] ?? ''));
        if ($coursePageUrl !== '' && !str_starts_with($coursePageUrl, 'https://')) {
            $coursePageUrl = 'https://worldwatercolormasters.art/elke-memmler';
        }
        $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
        if ($password === '') {
            $password = 'sample-password';
        }

        return [
            'name' => 'Alexandra',
            'email' => 'student@example.com',
            'course_title' => $courseTitle,
            'cover_url' => $coverUrl,
            'logo_url' => wwm_email_logo_url_for_template($templateId ?? ''),
            'course_page_url' => $coursePageUrl ?? '',
            'login_url' => wwm_login_url('student@example.com', $password, '/c/elke-en/1'),
            'expires_label' => gmdate('M j, Y H:i', time() + 12 * 3600) . ' UTC',
            'password' => $password,
            'magic_link' => wwm_base_url() . '/auth/magic?token=sample-token-for-preview',
            'reset_link' => wwm_base_url() . '/reset?token=sample-token-for-preview',
            'buy_url' => $coursePageUrl ?? '',
            'coupon_code' => 'SPECWWM4',
        ];
    }
}
