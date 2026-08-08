<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailMessage;

final class EmailTracker
{
    private const TRANSPARENT_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private int $messageId;
    private string $openToken;

    private function __construct(
        private string $to,
        private string $subject,
        private string $type,
        private ?int $userId
    ) {
    }

    public static function compose(?int $userId, string $to, string $type, string $subject): self
    {
        return new self($to, $subject, $type, $userId);
    }

    /**
     * @param list<array{url: string, label?: string}> $links
     */
    public function deliver(string $textBody, ?string $htmlBody, array $links = []): bool
    {
        $pdo = wwm_pdo();
        $created = EmailMessage::create($pdo, $this->userId, $this->to, $this->type, $this->subject);
        $this->messageId = $created['id'];
        $this->openToken = $created['open_token'];

        foreach ($links as $link) {
            $url = trim((string)($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $tracked = $this->registerLink($url, (string)($link['label'] ?? ''));
            $textBody = str_replace($url, $tracked, $textBody);
            if ($htmlBody !== null && $htmlBody !== '') {
                $htmlBody = str_replace($url, $tracked, $htmlBody);
            }
        }

        if ($htmlBody !== null && $htmlBody !== '') {
            $htmlBody = $this->injectOpenPixel($htmlBody);
        }

        $ok = Mailer::send($this->to, $this->subject, $textBody, $htmlBody);
        EmailMessage::markStatus($pdo, $this->messageId, $ok, $ok ? null : Mailer::lastError());

        return $ok;
    }

    public static function openPixelBody(): string
    {
        return (string)base64_decode(self::TRANSPARENT_GIF, true);
    }

    public static function recordOpen(string $openToken): void
    {
        EmailMessage::recordOpen(wwm_pdo(), $openToken);
    }

    public static function clickTarget(string $linkToken): ?string
    {
        return EmailMessage::clickTarget(wwm_pdo(), $linkToken);
    }

    private function registerLink(string $targetUrl, string $label): string
    {
        $token = bin2hex(random_bytes(16));
        EmailMessage::addLink(wwm_pdo(), $this->messageId, $token, $targetUrl, $label);
        return wwm_base_url() . '/t/c/' . $token;
    }

    private function injectOpenPixel(string $html): string
    {
        $pixel = '<img src="' . htmlspecialchars(
            wwm_base_url() . '/t/o/' . $this->openToken,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ) . '" width="1" height="1" alt="" style="display:block;border:0;outline:none;width:1px;height:1px;">';

        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $pixel . '</body>', $html);
        }

        return $html . $pixel;
    }
}
