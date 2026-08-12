<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\User;

final class EmailSampleSender
{
    /**
     * @return list<array{id: string, ok: bool, subject: string, error: ?string}>
     */
    public function sendAll(string $email, ?string $name = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }

        $displayName = trim((string)$name);
        if ($displayName === '') {
            $user = User::findByEmail(wwm_pdo(), $email);
            $displayName = trim((string)($user['name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'Evgeny';
        }

        $context = EmailTemplateCatalog::sampleContext('demo');
        $context['email'] = $email;
        $context['name'] = $displayName;
        $context['login_url'] = wwm_login_url(
            $email,
            (string)$context['password'],
            '/c/elke-en/1'
        );
        $context['magic_link'] = wwm_base_url() . '/auth/magic?token=sample-preview-token';
        $context['reset_link'] = wwm_base_url() . '/reset?token=sample-preview-token';

        $results = [];
        foreach (EmailTemplateCatalog::all() as $meta) {
            $id = (string)$meta['id'];
            if ($id === 'test') {
                continue;
            }

            try {
                $message = EmailTemplateRenderer::render($id, $context);
                $subject = '[SAMPLE] ' . $message['subject'];
                $links = $this->trackedLinks($id, $message, $context);
                $userId = User::findByEmail(wwm_pdo(), $email);
                $userIdInt = is_array($userId) ? (int)$userId['id'] : null;

                $ok = EmailTracker::compose($userIdInt, $email, $id, $subject)
                    ->deliver($message['text'], $message['html'], $links);

                $results[] = [
                    'id' => $id,
                    'ok' => $ok,
                    'subject' => $subject,
                    'error' => $ok ? null : (Mailer::lastError() ?? 'send_failed'),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $id,
                    'ok' => false,
                    'subject' => '',
                    'error' => $e->getMessage(),
                ];
            }

            usleep(250000);
        }

        return $results;
    }

    /**
     * @param array{subject: string, text: string, html: ?string} $message
     * @param array<string, string> $context
     * @return list<array{url: string, label: string}>
     */
    private function trackedLinks(string $templateId, array $message, array $context): array
    {
        if (in_array($templateId, ['sale_demo_discount_24h', 'sale_demo_discount_3h'], true)) {
            $buyUrl = trim((string)($context['buy_url'] ?? ''));
            if ($buyUrl !== '' && str_starts_with($buyUrl, 'https://')) {
                return [['url' => $buyUrl, 'label' => 'Purchase course']];
            }
        }

        if ($templateId === 'magic') {
            return [['url' => (string)$context['magic_link'], 'label' => 'Sign in']];
        }

        if ($templateId === 'reset') {
            return [['url' => (string)$context['reset_link'], 'label' => 'Reset password']];
        }

        $links = [];
        $loginUrl = trim((string)($context['login_url'] ?? ''));
        if ($loginUrl !== '') {
            $links[] = ['url' => $loginUrl, 'label' => 'Sign in'];
        }

        $coursePageUrl = trim((string)($context['course_page_url'] ?? ''));
        if ($coursePageUrl !== '' && str_starts_with($coursePageUrl, 'https://')) {
            $links[] = ['url' => $coursePageUrl, 'label' => 'Course page'];
        }

        return $links;
    }
}
