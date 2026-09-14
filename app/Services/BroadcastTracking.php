<?php
declare(strict_types=1);

namespace Wwm\Services;

final class BroadcastTracking
{
    /**
     * @param list<string> $excludeUrls exact URLs not to wrap (unsubscribe, etc.)
     * @return list<array{url: string, label: string}>
     */
    public static function trackableLinks(string $text, ?string $html, array $excludeUrls = []): array
    {
        $exclude = [];
        foreach ($excludeUrls as $url) {
            $url = trim($url);
            if ($url !== '') {
                $exclude[$url] = true;
            }
        }

        $found = [];
        $add = static function (string $url) use (&$found, $exclude): void {
            $url = trim($url);
            if ($url === '' || isset($exclude[$url])) {
                return;
            }
            if (str_starts_with(strtolower($url), 'mailto:')) {
                return;
            }
            if (str_contains($url, '/t/c/') || str_contains($url, '/t/o/')) {
                return;
            }
            if (str_contains($url, '/email/unsubscribe')) {
                return;
            }
            if (!preg_match('#^https?://#i', $url)) {
                return;
            }
            $found[$url] = true;
        };

        if ($html !== null && $html !== '') {
            if (preg_match_all('#\bhref\s*=\s*("|\')([^"\']+)(\1)#i', $html, $matches)) {
                foreach ($matches[2] as $href) {
                    $add(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        }

        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $add(rtrim($url, '.,);]'));
            }
        }

        $links = [];
        $n = 0;
        foreach (array_keys($found) as $url) {
            $n++;
            $host = (string)parse_url($url, PHP_URL_HOST);
            $links[] = [
                'url' => $url,
                'label' => $host !== '' ? $host : ('Link ' . $n),
            ];
        }

        return $links;
    }
}
