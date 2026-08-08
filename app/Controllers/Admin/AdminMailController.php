<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Services\EmailTracker;
use Wwm\Services\Mailer;

final class AdminMailController
{
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
