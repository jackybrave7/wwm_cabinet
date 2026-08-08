<?php
declare(strict_types=1);

namespace Wwm\Services;

final class Mailer
{
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function send(string $to, string $subject, string $body, ?string $htmlBody = null): bool
    {
        self::$lastError = null;
        $cfg = wwm_config()['mail'] ?? [];
        if (empty($cfg['enabled'])) {
            $logLine = sprintf("TO=%s SUBJECT=%s\n%s\n---\n", $to, $subject, $body);
            if ($htmlBody !== null && $htmlBody !== '') {
                $logLine .= "HTML:\n" . $htmlBody . "\n---\n";
            }
            @file_put_contents(WWM_ROOT . '/data/mail.log', $logLine, FILE_APPEND | LOCK_EX);
            wwm_log('mail (log only): ' . $to . ' — ' . $subject);
            return true;
        }

        $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
        if ($smtpHost !== '') {
            $client = new SmtpClient();
            $ok = $client->send($cfg, $to, $subject, $body, $htmlBody);
            if (!$ok) {
                self::$lastError = $client->lastError() ?? 'SMTP send failed';
                wwm_log('mail send failed via SMTP to ' . $to . ' — ' . $subject . ' | ' . self::$lastError);
            }
            return $ok;
        }

        $from = (string)($cfg['from_email'] ?? 'noreply@localhost');
        $fromName = (string)($cfg['from_name'] ?? 'WWM');
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $fromName . ' <' . $from . '>',
        ];
        if ($htmlBody !== null && $htmlBody !== '') {
            $boundary = 'wwm_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $message = implode("\r\n", [
                'This is a multi-part message in MIME format.',
                '',
                '--' . $boundary,
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $body,
                '',
                '--' . $boundary,
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $htmlBody,
                '',
                '--' . $boundary . '--',
            ]);
        } else {
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
            $message = $body;
        }

        $ok = @mail($to, $subject, $message, implode("\r\n", $headers));
        if (!$ok) {
            self::$lastError = 'PHP mail() failed';
        }

        return $ok;
    }
}
