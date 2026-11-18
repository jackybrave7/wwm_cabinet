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

    /**
     * Mail settings for a marketing broadcast. Course-access mail keeps the
     * main mailbox. A separate From is used only when that mailbox has its own
     * SMTP login, because the host accepts mail only from the authenticated user.
     *
     * @return array<string, mixed>
     */
    public static function broadcastConfig(): array
    {
        $cfg = wwm_config()['mail'] ?? [];
        if (!is_array($cfg)) {
            return [];
        }
        if (!self::broadcastUsesOwnMailbox()) {
            return $cfg;
        }

        $user = trim((string)($cfg['broadcast_smtp_user'] ?? ''));
        $from = trim((string)($cfg['broadcast_from_email'] ?? ''));
        $name = trim((string)($cfg['broadcast_from_name'] ?? ''));
        $cfg['smtp_user'] = $user;
        $cfg['smtp_pass'] = (string)($cfg['broadcast_smtp_pass'] ?? '');
        $cfg['from_email'] = ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) ? $from : $user;
        if ($name !== '') {
            $cfg['from_name'] = $name;
        }

        return $cfg;
    }

    public static function broadcastUsesOwnMailbox(): bool
    {
        $cfg = wwm_config()['mail'] ?? [];
        if (!is_array($cfg)) {
            return false;
        }
        $user = trim((string)($cfg['broadcast_smtp_user'] ?? ''));
        $pass = (string)($cfg['broadcast_smtp_pass'] ?? '');

        return $user !== '' && $pass !== '';
    }

    public static function broadcastFromAddress(): string
    {
        $cfg = self::broadcastConfig();

        return trim((string)($cfg['from_email'] ?? ''));
    }

    /**
     * Headers every outgoing message must carry. Message-ID is generated here so
     * Gmail does not have to add one (SMTPIN_ADDED_MISSING).
     *
     * @param list<string> $extraHeaders
     * @return list<string>
     */
    public static function standardHeaders(array $extraHeaders = []): array
    {
        $hasMessageId = false;
        $hasReplyTo = false;
        foreach ($extraHeaders as $line) {
            $name = strtolower(strtok(trim($line), ':'));
            if ($name === 'message-id') {
                $hasMessageId = true;
            } elseif ($name === 'reply-to') {
                $hasReplyTo = true;
            }
        }

        $headers = [];
        if (!$hasMessageId) {
            $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@worldwatercolormasters.art>';
        }
        if (!$hasReplyTo) {
            $headers[] = 'Reply-To: support@worldwatercolormasters.art';
        }

        foreach ($extraHeaders as $line) {
            $line = trim($line);
            if ($line !== '') {
                $headers[] = $line;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>|null $mailConfig
     */
    public static function send(string $to, string $subject, string $body, ?string $htmlBody = null, array $extraHeaders = [], ?array $mailConfig = null): bool
    {
        self::$lastError = null;
        $extraHeaders = self::standardHeaders($extraHeaders);
        $cfg = $mailConfig ?? (wwm_config()['mail'] ?? []);
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
            $ok = $client->send($cfg, $to, $subject, $body, $htmlBody, $extraHeaders);
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
        foreach ($extraHeaders as $line) {
            $line = trim($line);
            if ($line !== '') {
                $headers[] = $line;
            }
        }
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
