<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailMessage;

final class EmailTracker
{
    private const TRANSPARENT_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private int $messageId = 0;
    private string $openToken = '';
    private string $lastTextBody = '';
    private ?string $lastHtmlBody = null;

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

    public function lastMessageId(): int
    {
        return $this->messageId;
    }

    public function lastTextBody(): string
    {
        return $this->lastTextBody;
    }

    public function lastHtmlBody(): ?string
    {
        return $this->lastHtmlBody;
    }

    /**
     * @param list<array{url: string, label?: string}> $links
     * @param list<string> $extraHeaders
     */
    public function deliver(
        string $textBody,
        ?string $htmlBody,
        array $links = [],
        array $extraHeaders = [],
        ?int $broadcastId = null,
    ): bool {
        $pdo = wwm_pdo();
        $created = EmailMessage::create($pdo, $this->userId, $this->to, $this->type, $this->subject, $broadcastId);
        $this->messageId = $created['id'];
        $this->openToken = $created['open_token'];

        $trackedByUrl = $this->trackedUrls($links);
        $textBody = $this->replaceTextUrls($textBody, $trackedByUrl);
        if ($htmlBody !== null && $htmlBody !== '') {
            $htmlBody = $this->replaceHtmlHrefs($htmlBody, $trackedByUrl);
        }

        if ($htmlBody !== null && $htmlBody !== '') {
            $htmlBody = wwm_repair_email_html($htmlBody) ?? $htmlBody;
            $htmlBody = $this->injectOpenPixel($htmlBody);
        }

        $this->lastTextBody = $textBody;
        $this->lastHtmlBody = $htmlBody;

        $ok = Mailer::send($this->to, $this->subject, $textBody, $htmlBody, $extraHeaders);
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

    /**
     * @param list<array{url: string, label?: string}> $links
     * @return array<string, string> original URL => tracked URL
     */
    private function trackedUrls(array $links): array
    {
        $trackedByUrl = [];
        foreach ($links as $link) {
            $url = trim((string)($link['url'] ?? ''));
            if ($url === '' || isset($trackedByUrl[$url])) {
                continue;
            }
            $trackedByUrl[$url] = $this->registerLink($url, (string)($link['label'] ?? ''));
        }

        return $trackedByUrl;
    }

    /**
     * @param array<string, string> $trackedByUrl
     */
    private function replaceHtmlHrefs(string $html, array $trackedByUrl): string
    {
        $rewritten = preg_replace_callback(
            "#\\bhref\\s*=\\s*(\"|')([^\"']*)\\1#i",
            static function (array $match) use ($trackedByUrl): string {
                $decoded = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!isset($trackedByUrl[$decoded])) {
                    return $match[0];
                }
                $tracked = htmlspecialchars($trackedByUrl[$decoded], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return 'href=' . $match[1] . $tracked . $match[1];
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Replace a whole URL only (whitespace, start/end, or parentheses), never a prefix.
     *
     * @param array<string, string> $trackedByUrl
     */
    private function replaceTextUrls(string $text, array $trackedByUrl): string
    {
        $urls = array_keys($trackedByUrl);
        usort($urls, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($urls as $url) {
            $tracked = $trackedByUrl[$url];
            $pattern = '#(^|[\s(])' . preg_quote($url, '#') . '(?=$|[\s)])#m';
            $text = preg_replace_callback(
                $pattern,
                static fn (array $match): string => $match[1] . $tracked,
                $text
            ) ?? $text;
        }

        return $text;
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
