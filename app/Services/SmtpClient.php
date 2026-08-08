<?php
declare(strict_types=1);

namespace Wwm\Services;

final class SmtpClient
{
    /** @var resource|null */
    private $socket;

    /**
     * @param array<string, mixed> $cfg
     */
    public function send(array $cfg, string $to, string $subject, string $body): bool
    {
        $host = trim((string)($cfg['smtp_host'] ?? ''));
        $user = trim((string)($cfg['smtp_user'] ?? ''));
        $pass = (string)($cfg['smtp_pass'] ?? '');
        $port = (int)($cfg['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }

        if ($host === '' || $user === '' || $pass === '') {
            return false;
        }

        $fromEmail = trim((string)($cfg['from_email'] ?? $user));
        $fromName = trim((string)($cfg['from_name'] ?? ''));
        $encryption = strtolower(trim((string)($cfg['smtp_encryption'] ?? '')));
        if ($encryption === '') {
            $encryption = $port === 465 ? 'ssl' : 'tls';
        }

        try {
            $this->connect($host, $port, $encryption);
            $this->expect([220]);
            $this->command('EHLO ' . $this->clientHost(), [250]);
            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $this->command('EHLO ' . $this->clientHost(), [250]);
            }
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334]);
            $this->command(base64_encode($pass), [235]);
            $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);

            $encodedSubject = $this->encodeHeader($subject);
            $fromHeader = $fromName !== ''
                ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>'
                : $fromEmail;

            $message = implode("\r\n", [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . $fromHeader,
                'To: <' . $to . '>',
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $this->normalizeBody($body),
                '',
            ]) . "\r\n.";

            $this->command($message, [250]);
            $this->command('QUIT', [221]);

            return true;
        } catch (\Throwable $e) {
            wwm_log('smtp failed: ' . $e->getMessage());
            return false;
        } finally {
            $this->disconnect();
        }
    }

    private function connect(string $host, int $port, string $encryption): void
    {
        $remote = $encryption === 'ssl'
            ? 'ssl://' . $host . ':' . $port
            : 'tcp://' . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ])
        );

        if (!is_resource($socket)) {
            throw new \RuntimeException('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 15);
        $this->socket = $socket;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @param list<int> $expectedCodes
     */
    private function command(string $command, array $expectedCodes): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP socket is not connected');
        }

        $payload = str_contains($command, "\n") ? $command : $command . "\r\n";
        if (@fwrite($this->socket, $payload) === false) {
            throw new \RuntimeException('SMTP write failed');
        }

        $this->expect($expectedCodes);
    }

    /**
     * @param list<int> $expectedCodes
     */
    private function expect(array $expectedCodes): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP socket is not connected');
        }

        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new \RuntimeException('SMTP empty response');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException('SMTP unexpected response: ' . trim($response));
        }
    }

    private function clientHost(): string
    {
        $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }

        return $host;
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        $normalized = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
            $normalized[] = $line;
        }

        return implode("\r\n", $normalized);
    }
}
