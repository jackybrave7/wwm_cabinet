<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Public demo-lead form: copy, allowed embed origins, UTM from the landing page.
 */
final class DemoLead
{
    /**
     * @param array<string, mixed> $course
     * @return array{
     *   kicker: string,
     *   title: string,
     *   text: string,
     *   email_placeholder: string,
     *   name_placeholder: string,
     *   button: string,
     *   note: string,
     *   success: string,
     *   error_email: string,
     *   error_rate: string,
     *   error_generic: string,
     *   thanks_url: string
     * }
     */
    public static function copy(array $course): array
    {
        $hours = (int)($course['demo_hours'] ?? wwm_config()['demo_hours'] ?? 48);
        if ($hours < 1) {
            $hours = 48;
        }
        $custom = is_array($course['demo_lead'] ?? null) ? $course['demo_lead'] : [];

        return [
            'kicker' => self::text($custom, 'kicker', 'Not sure yet?', 80),
            'title' => self::text($custom, 'title', $hours . ' hours of access — free.', 140),
            'text' => self::text(
                $custom,
                'text',
                'Watch the open lessons. If it is not what you expected, just close the tab. No card required.',
                500
            ),
            'email_placeholder' => self::text($custom, 'email_placeholder', 'Email', 40),
            'name_placeholder' => self::text($custom, 'name_placeholder', 'Your name', 40),
            'button' => self::text($custom, 'button', 'Send me the demo', 60),
            'note' => self::text($custom, 'note', 'We send course updates occasionally · Unsubscribe anytime', 180),
            'success' => self::text($custom, 'success', 'Check your email — the demo link is on its way.', 180),
            'error_email' => self::text($custom, 'error_email', 'Enter a valid email.', 120),
            'error_rate' => self::text($custom, 'error_rate', 'Please wait a little and try again.', 120),
            'error_generic' => self::text($custom, 'error_generic', 'Something went wrong. Please try again.', 120),
            'thanks_url' => self::thanksUrl($custom),
        ];
    }

    /**
     * Marketing thanks page used for Meta/Pinterest conversion attribution.
     *
     * @param array<string, mixed> $custom
     */
    public static function thanksUrl(array $custom = []): string
    {
        $url = trim((string)($custom['thanks_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)(wwm_config()['demo_lead_thanks_url'] ?? ''));
        }
        if ($url === '') {
            $url = 'https://worldwatercolormasters.art/thanks';
        }
        if (!preg_match('#^https://worldwatercolormasters\.art(/|$)#i', $url)) {
            return 'https://worldwatercolormasters.art/thanks';
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $custom
     */
    private static function text(array $custom, string $key, string $fallback, int $max): string
    {
        $value = trim(strip_tags((string)($custom[$key] ?? '')));
        if ($value === '') {
            $value = $fallback;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function utmFromPost(array $post): array
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = trim((string)($post[$key] ?? ''));
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
            if ($value === '') {
                continue;
            }
            $utm[$key] = mb_substr($value, 0, 255);
        }

        return $utm;
    }

    public static function originAllowed(string $origin): bool
    {
        $origin = trim($origin);
        if ($origin === '' || preg_match('#\s#', $origin)) {
            return false;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($local) {
            return $scheme === 'http' || $scheme === 'https';
        }

        return $scheme === 'https' && in_array($host, self::allowedHosts(), true);
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = [
            'worldwatercolormasters.art',
            'www.worldwatercolormasters.art',
        ];
        $baseHost = parse_url(wwm_base_url(), PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '') {
            $hosts[] = strtolower($baseHost);
        }

        $extra = wwm_config()['demo_lead_origins'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $host) {
                $host = strtolower(trim((string)$host));
                if ($host !== '' && !str_contains($host, '/')) {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }
}
