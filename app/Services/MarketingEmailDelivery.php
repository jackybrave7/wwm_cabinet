<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\EmailSuppression;

/**
 * Marketing / cross-sell emails with List-Unsubscribe and suppression list (same as broadcasts).
 */
final class MarketingEmailDelivery
{
    public static function crosssellCouponCode(): string
    {
        $code = trim((string)(wwm_config()['crosssell_coupon_code'] ?? 'WWM5'));

        return $code !== '' ? $code : 'WWM5';
    }

    /**
     * @return list<string>
     */
    public static function automationMarketingTemplateIds(): array
    {
        return [
            'sale_crosssell_50_offer',
            'sale_crosssell_50_reminder',
        ];
    }

    public static function isMarketingTemplate(string $templateId): bool
    {
        return in_array($templateId, self::automationMarketingTemplateIds(), true);
    }

    /**
     * @param array{subject: string, text: string, html: ?string, buy_url?: string} $message
     * @param list<array{url: string, label?: string}> $links
     */
    public static function deliver(
        int $userId,
        string $email,
        string $templateId,
        array $message,
        array $links,
        string $listIdSuffix,
        string $name = '',
    ): bool {
        $pdo = wwm_pdo();
        if (EmailSuppression::isSuppressed($pdo, $email)) {
            wwm_log(sprintf('marketing mail skipped (suppressed) template=%s user_id=%d', $templateId, $userId));

            return false;
        }

        $unsubUrl = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        $text = self::personalize($message['text'], $userId, $email, $name);
        $html = $message['html'] !== null && $message['html'] !== ''
            ? self::personalize($message['html'], $userId, $email, $name)
            : null;

        $text = self::appendPlainUnsubscribeFooter($text, $unsubUrl);
        if ($html !== null && $html !== '') {
            $html = self::appendHtmlUnsubscribeFooter($html, $unsubUrl);
        }

        $headers = self::complianceHeaders($unsubUrl, $listIdSuffix);
        $trackLinks = BroadcastTracking::trackableLinks($text, $html, [$unsubUrl]);
        foreach ($links as $link) {
            $trackLinks[] = $link;
        }

        $tracker = EmailTracker::compose($userId, $email, $templateId, $message['subject']);

        return $tracker->deliver($text, $html, $trackLinks, $headers);
    }

    private static function personalize(string $body, int $userId, string $email, string $name): string
    {
        $unsub = BroadcastUnsubscribe::unsubscribeUrl($userId, $email);
        $replacements = [
            '{{name}}' => $name !== '' ? $name : 'there',
            '{{email}}' => $email,
            '{{unsubscribe_url}}' => $unsub,
            '{{base_url}}' => wwm_base_url(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }

    private static function appendPlainUnsubscribeFooter(string $text, string $url): string
    {
        if (str_contains($text, $url)) {
            return $text;
        }

        return rtrim($text) . "\n\n—\nTo stop marketing emails from World Watercolor Masters, unsubscribe:\n" . $url;
    }

    private static function appendHtmlUnsubscribeFooter(string $html, string $url): string
    {
        if (str_contains($html, $url)) {
            return $html;
        }

        $footer = '<p style="margin-top:24px;font-size:12px;color:#666;">'
            . 'You received this because you have a World Watercolor Masters account. '
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Unsubscribe</a> from marketing emails.</p>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $footer . '</body>', $html, 1) ?? ($html . $footer);
        }

        return $html . $footer;
    }

    /**
     * @return list<string>
     */
    private static function complianceHeaders(string $unsubscribeUrl, string $listIdSuffix): array
    {
        $cfg = wwm_config()['mail'] ?? [];
        $from = trim((string)($cfg['from_email'] ?? ''));
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url(wwm_base_url(), PHP_URL_HOST)) ?: 'wwm';
        $headers = [
            'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'List-ID: <' . $listIdSuffix . '.' . $host . '>',
            'Precedence: bulk',
        ];
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $headers[0] = 'List-Unsubscribe: <' . $unsubscribeUrl . '>, <mailto:' . $from . '?subject=unsubscribe>';
        }

        return $headers;
    }
}
