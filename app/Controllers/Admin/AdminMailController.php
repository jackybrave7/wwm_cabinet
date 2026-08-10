<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Services\EmailTemplateCatalog;
use Wwm\Services\EmailTracker;
use Wwm\Services\Mailer;

final class AdminMailController
{
    public function index(): void
    {
        Session::requireAdmin();

        $mail = wwm_config()['mail'] ?? [];
        $webhooks = wwm_config()['webhooks'] ?? [];

        wwm_render_admin('emails', [
            'title' => 'Email templates — Admin',
            'adminNav' => 'emails',
            'templates' => EmailTemplateCatalog::all(),
            'mailEnabled' => !empty($mail['enabled']),
            'fromEmail' => (string)($mail['from_email'] ?? ''),
            'webhooksEnabled' => !empty($webhooks['enabled']),
            'mailWebhookUrl' => wwm_base_url() . '/api/mail',
        ]);
    }

    public function preview(string $id): void
    {
        Session::requireAdmin();

        $meta = EmailTemplateCatalog::find($id);
        if ($meta === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        try {
            $message = EmailTemplateCatalog::preview($id);
        } catch (\InvalidArgumentException) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Email template not found.']);
            return;
        }

        wwm_render_admin('email-preview', [
            'title' => $meta['label'] . ' — Email preview',
            'adminNav' => 'emails',
            'template' => $meta,
            'message' => $message,
            'mailWebhookUrl' => wwm_base_url() . '/api/mail',
        ]);
    }

    public function test(): void
    {
        Session::requireAdmin();

        $to = trim((string)($_GET['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            wwm_json_response(400, ['ok' => false, 'error' => 'invalid_email']);
        }

        $cfg = wwm_config()['mail'] ?? [];
        $smtpUser = trim((string)($cfg['smtp_user'] ?? ''));
        $smtpPass = (string)($cfg['smtp_pass'] ?? '');
        $body = "This is a test message from WWM Cabinet.\n\nIf you received it, SMTP is working.\n";
        $sent = EmailTracker::compose(null, $to, 'test', 'WWM Cabinet test email')
            ->deliver($body, null, []);

        wwm_json_response(200, [
            'ok' => $sent,
            'mail_enabled' => !empty($cfg['enabled']),
            'smtp_host' => (string)($cfg['smtp_host'] ?? ''),
            'smtp_user' => $smtpUser,
            'smtp_pass_length' => strlen($smtpPass),
            'from_email' => (string)($cfg['from_email'] ?? ''),
            'error' => Mailer::lastError(),
        ]);
    }
}
