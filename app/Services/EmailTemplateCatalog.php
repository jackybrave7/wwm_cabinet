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
        $course = ['{{course_title}}', '{{cover_url}}', '{{course_page_url}}', '{{expires_label}}'];

        return match ($id) {
            'demo', 'paid', 'reminder_demo_no_login', 'reminder_demo_no_lesson', 'reminder_demo_expiring'
                => array_merge($common, $course),
            'magic' => ['{{name}}', '{{email}}', '{{base_url}}', '{{magic_link}}'],
            'reset' => ['{{email}}', '{{base_url}}', '{{reset_link}}'],
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
        $name = trim((string)($context['name'] ?? ''));
        $email = (string)($context['email'] ?? '');
        $courseTitle = (string)($context['course_title'] ?? '');
        $coverUrl = isset($context['cover_url']) ? (string)$context['cover_url'] : null;
        $coursePageUrl = isset($context['course_page_url']) ? (string)$context['course_page_url'] : null;
        if ($coursePageUrl === '') {
            $coursePageUrl = null;
        }
        $loginUrl = (string)($context['login_url'] ?? wwm_base_url() . '/login');
        $password = trim((string)($context['password'] ?? ''));
        $expiresLabel = (string)($context['expires_label'] ?? '');
        $magicLink = (string)($context['magic_link'] ?? wwm_base_url() . '/auth/magic?token=sample');
        $resetLink = (string)($context['reset_link'] ?? wwm_base_url() . '/reset?token=sample');

        return match ($id) {
            'demo' => TransactionalEmail::demoAccess(
                $name,
                $email,
                $courseTitle,
                $coverUrl !== '' ? $coverUrl : null,
                $coursePageUrl,
                $loginUrl,
                $expiresLabel !== '' ? $expiresLabel : gmdate('M j, Y H:i', time() + 48 * 3600) . ' UTC',
                $password !== '' ? $password : null
            ),
            'paid' => TransactionalEmail::paidAccess(
                $name,
                $email,
                $courseTitle,
                $coverUrl !== '' ? $coverUrl : null,
                $coursePageUrl,
                $loginUrl,
                $password !== '' ? $password : 'your-password'
            ),
            'reminder_demo_no_login' => TransactionalEmail::reminderDemoNoLogin(
                $name,
                $email,
                $courseTitle,
                $coverUrl !== '' ? $coverUrl : null,
                $coursePageUrl,
                $loginUrl,
                $password !== '' ? $password : null
            ),
            'reminder_demo_no_lesson' => TransactionalEmail::reminderDemoNoLesson(
                $name,
                $email,
                $courseTitle,
                $coverUrl !== '' ? $coverUrl : null,
                $coursePageUrl,
                $loginUrl,
                $password !== '' ? $password : null
            ),
            'reminder_demo_expiring' => TransactionalEmail::reminderDemoExpiring(
                $name,
                $email,
                $courseTitle,
                $coverUrl !== '' ? $coverUrl : null,
                $coursePageUrl,
                $loginUrl,
                $expiresLabel !== '' ? $expiresLabel : gmdate('M j, Y H:i', time() + 12 * 3600) . ' UTC',
                $password !== '' ? $password : null
            ),
            'magic' => TransactionalEmail::magicLinkMessage($name, $magicLink),
            'reset' => TransactionalEmail::passwordResetMessage($resetLink),
            'test' => [
                'subject' => 'WWM Cabinet test email',
                'text' => "This is a test message from WWM Cabinet.\n\nIf you received it, SMTP is working.\n",
                'html' => null,
            ],
            default => throw new \InvalidArgumentException('Unknown email template: ' . $id),
        };
    }

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function preview(string $id): array
    {
        return EmailTemplateRenderer::render($id, self::sampleContext());
    }

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public static function placeholderDraft(string $id): array
    {
        $context = self::sampleContext();
        $rendered = self::builtInMessage($id, $context);
        $pairs = [];
        foreach ($context as $key => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $pairs[] = [$value, '{{' . $key . '}}'];
        }
        usort($pairs, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        $replace = static function (?string $text) use ($pairs): ?string {
            if ($text === null || $text === '') {
                return $text;
            }
            $search = array_column($pairs, 0);
            $values = array_column($pairs, 1);

            return str_replace($search, $values, $text);
        };

        return [
            'subject' => (string)$replace($rendered['subject']),
            'text' => (string)$replace($rendered['text']),
            'html' => $replace($rendered['html']),
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   email: string,
     *   course_title: string,
     *   cover_url: string,
     *   course_page_url: string,
     *   login_url: string,
     *   expires_label: string,
     *   password: string,
     *   magic_link: string,
     *   reset_link: string
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
            'cover_url' => $coverUrl ?? '',
            'course_page_url' => $coursePageUrl ?? '',
            'login_url' => wwm_login_url('student@example.com', $password, '/c/elke-en/1'),
            'expires_label' => gmdate('M j, Y H:i', time() + 12 * 3600) . ' UTC',
            'password' => $password,
            'magic_link' => wwm_base_url() . '/auth/magic?token=sample-token-for-preview',
            'reset_link' => wwm_base_url() . '/reset?token=sample-token-for-preview',
        ];
    }
}
