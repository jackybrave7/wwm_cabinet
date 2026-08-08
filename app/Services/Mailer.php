<?php
declare(strict_types=1);

namespace Wwm\Services;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $cfg = wwm_config()['mail'] ?? [];
        if (empty($cfg['enabled'])) {
            $logLine = sprintf("TO=%s SUBJECT=%s\n%s\n---\n", $to, $subject, $body);
            @file_put_contents(WWM_ROOT . '/data/mail.log', $logLine, FILE_APPEND | LOCK_EX);
            wwm_log('mail (log only): ' . $to . ' — ' . $subject);
            return true;
        }

        $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
        if ($smtpHost !== '') {
            $ok = (new SmtpClient())->send($cfg, $to, $subject, $body);
            if (!$ok) {
                wwm_log('mail send failed via SMTP to ' . $to . ' — ' . $subject);
            }
            return $ok;
        }

        $from = (string)($cfg['from_email'] ?? 'noreply@localhost');
        $fromName = (string)($cfg['from_name'] ?? 'WWM');
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/plain; charset=utf-8',
            'From: ' . $fromName . ' <' . $from . '>',
        ];

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
