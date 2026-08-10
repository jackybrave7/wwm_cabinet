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
                'webhook' => false,
            ],
            [
                'id' => 'paid',
                'label' => 'Paid access',
                'category' => 'Access',
                'description' => 'Sent after payment when cabinet grants full course access.',
                'trigger' => 'AVO webhook → /api/payment',
                'has_html' => true,
                'webhook' => false,
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
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function preview(string $id): array
    {
        $context = self::sampleContext();

        return match ($id) {
            'demo' => TransactionalEmail::demoAccess(
                $context['name'],
                $context['email'],
                $context['course_title'],
                $context['cover_url'],
                $context['course_page_url'],
                $context['login_url'],
                $context['expires_label'],
                $context['demo_password']
            ),
            'paid' => TransactionalEmail::paidAccess(
                $context['name'],
                $context['email'],
                $context['course_title'],
                $context['cover_url'],
                $context['course_page_url'],
                $context['login_url'],
                $context['demo_password']
            ),
            'reminder_demo_no_login' => TransactionalEmail::reminderDemoNoLogin(
                $context['name'],
                $context['email'],
                $context['course_title'],
                $context['cover_url'],
                $context['course_page_url'],
                $context['login_url'],
                $context['demo_password']
            ),
            'reminder_demo_no_lesson' => TransactionalEmail::reminderDemoNoLesson(
                $context['name'],
                $context['email'],
                $context['course_title'],
                $context['cover_url'],
                $context['course_page_url'],
                $context['login_url'],
                $context['demo_password']
            ),
            'reminder_demo_expiring' => TransactionalEmail::reminderDemoExpiring(
                $context['name'],
                $context['email'],
                $context['course_title'],
                $context['cover_url'],
                $context['course_page_url'],
                $context['login_url'],
                $context['expires_label'],
                $context['demo_password']
            ),
            'magic' => TransactionalEmail::magicLinkPreview($context['name']),
            'reset' => TransactionalEmail::passwordResetPreview(),
            'test' => [
                'subject' => 'WWM Cabinet test email',
                'text' => "This is a test message from WWM Cabinet.\n\nIf you received it, SMTP is working.\n",
                'html' => null,
            ],
            default => throw new \InvalidArgumentException('Unknown email template: ' . $id),
        };
    }

    /**
     * @return array{
     *   name: string,
     *   email: string,
     *   course_title: string,
     *   cover_url: ?string,
     *   course_page_url: ?string,
     *   login_url: string,
     *   expires_label: string,
     *   demo_password: string
     * }
     */
    public static function sampleContext(): array
    {
        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin('elke-en') ?? $catalog->getAdmin('alvaro') ?? [];
        $courseTitle = (string)($course['title'] ?? 'Elke Memmler\'s video course \'Watercolor Expressionism\'');
        $coverUrl = wwm_course_cover_url(isset($course['cover_image']) ? (string)$course['cover_image'] : null);
        $coursePageUrl = trim((string)($course['buy_url'] ?? ''));
        if ($coursePageUrl !== '' && !str_starts_with($coursePageUrl, 'https://')) {
            $coursePageUrl = 'https://worldwatercolormasters.art/elke-memmler';
        }
        $password = trim((string)(wwm_config()['demo_default_password'] ?? 'Gh45tyhf'));
        if ($password === '') {
            $password = 'sample-password';
        }

        return [
            'name' => 'Alexandra',
            'email' => 'student@example.com',
            'course_title' => $courseTitle,
            'cover_url' => $coverUrl,
            'course_page_url' => $coursePageUrl !== '' ? $coursePageUrl : null,
            'login_url' => wwm_login_url('student@example.com', $password, '/c/elke-en/1'),
            'expires_label' => gmdate('M j, Y H:i', time() + 12 * 3600) . ' UTC',
            'demo_password' => $password,
        ];
    }
}
