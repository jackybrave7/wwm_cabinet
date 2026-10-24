<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * UTM from a payment webhook. "+" stays a plus: query and cookies are split
 * by hand and decoded with rawurldecode, never parse_str/urldecode.
 */
final class PaymentUtmExtractor
{
    /** @var list<string> */
    public const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** @var list<string> */
    private const URL_FIELDS = ['referer', 'formurl', 'tildaformurl', 'page_url'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function extract(array $payload): array
    {
        $utm = self::fromFields($payload);
        $nested = is_array($payload['utm'] ?? null) ? $payload['utm'] : [];
        self::fillGaps($utm, self::fromFields($nested));
        self::fillGaps($utm, self::fromPayment($payload));
        self::fillGaps($utm, self::fromUrls($payload));
        self::fillGaps($utm, self::fromCookies($payload));
        self::fillGaps($utm, self::fromAdvertising($payload));

        return $utm;
    }

    /**
     * payload when tags came from the webhook body (including cookies/referer).
     * avo when the only tags are advertising_channel_* fields.
     */
    public static function bodyOrigin(array $payload): string
    {
        $withoutAds = $payload;
        unset(
            $withoutAds['advertising_channel_type_traffic'],
            $withoutAds['advertising_channel_keyword'],
            $withoutAds['advertising_channel_location']
        );
        if (self::extract($withoutAds) !== []) {
            return 'payload';
        }
        if (self::fromAdvertising($payload) !== []) {
            return 'avo';
        }

        return 'empty';
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private static function fromFields(array $fields): array
    {
        $utm = [];
        foreach (self::KEYS as $key) {
            $value = self::clip(self::scalar(self::ci($fields, $key)));
            if ($value !== '') {
                $utm[$key] = $value;
            }
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function fromPayment(array $payload): array
    {
        $payment = self::paymentArray($payload);
        if ($payment === null) {
            return [];
        }
        $utm = self::fromFields($payment);
        $products = $payment['products'] ?? $payment['Products'] ?? null;
        if (!is_array($products)) {
            return $utm;
        }
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            self::fillGaps($utm, self::fromFields($product));
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function fromUrls(array $payload): array
    {
        $utm = [];
        foreach (self::sources($payload) as $source) {
            foreach (self::URL_FIELDS as $field) {
                $raw = self::scalar(self::ci($source, $field));
                if ($raw === '') {
                    continue;
                }
                self::fillGaps($utm, self::fromQuery(self::queryOf($raw)));
            }
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function fromCookies(array $payload): array
    {
        $utm = [];
        foreach (self::sources($payload) as $source) {
            $direct = self::scalar(self::ci($source, 'TILDAUTM'));
            if ($direct !== '') {
                self::fillGaps($utm, self::parseTildaUtm($direct));
            }
            $cookies = self::scalar(self::ci($source, 'COOKIES'));
            if ($cookies === '') {
                continue;
            }
            $cookieUtm = self::cookieValue($cookies, 'TILDAUTM');
            if ($cookieUtm !== '') {
                self::fillGaps($utm, self::parseTildaUtm($cookieUtm));
            }
        }

        return $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function fromAdvertising(array $payload): array
    {
        $map = [
            'utm_medium' => 'advertising_channel_type_traffic',
            'utm_term' => 'advertising_channel_keyword',
            'utm_content' => 'advertising_channel_location',
        ];
        $utm = [];
        foreach ($map as $utmKey => $field) {
            $value = self::clip(self::scalar($payload[$field] ?? null));
            if ($value !== '') {
                $utm[$utmKey] = $value;
            }
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private static function fromQuery(string $query): array
    {
        $utm = [];
        $query = ltrim($query, '?');
        if ($query === '') {
            return $utm;
        }
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $bits = explode('=', $pair, 2);
            $key = strtolower(self::rawDecode($bits[0]));
            if (!in_array($key, self::KEYS, true)) {
                continue;
            }
            $value = self::clip(self::rawDecode($bits[1] ?? ''));
            if ($value !== '' && !isset($utm[$key])) {
                $utm[$key] = $value;
            }
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    private static function parseTildaUtm(string $raw): array
    {
        $raw = self::rawDecode($raw);
        if ($raw === '') {
            return [];
        }
        $chunks = str_contains($raw, '|||') ? explode('|||', $raw) : explode('&', $raw);
        $joined = [];
        foreach ($chunks as $chunk) {
            if (!str_contains($chunk, '=')) {
                continue;
            }
            $joined[] = $chunk;
        }

        return self::fromQuery(implode('&', $joined));
    }

    private static function cookieValue(string $cookies, string $name): string
    {
        $cookies = self::rawDecode($cookies);
        foreach (preg_split('/;\s*/', $cookies) ?: [] as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$cookieName, $cookieValue] = explode('=', $pair, 2);
            if (strcasecmp(trim($cookieName), $name) !== 0) {
                continue;
            }

            return self::rawDecode(trim($cookieValue));
        }

        return '';
    }

    private static function queryOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['query']) && is_string($parts['query'])) {
            return $parts['query'];
        }
        $pos = strpos($url, '?');
        if ($pos === false) {
            return '';
        }

        return substr($url, $pos + 1);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function sources(array $payload): array
    {
        $sources = [$payload];
        $payment = self::paymentArray($payload);
        if ($payment !== null) {
            $sources[] = $payment;
        }

        return $sources;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function paymentArray(array $payload): ?array
    {
        $payment = $payload['payment'] ?? $payload['Payment'] ?? null;
        if (is_array($payment)) {
            return $payment;
        }
        if (!is_string($payment) || trim($payment) === '') {
            return null;
        }
        $decoded = json_decode($payment, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $utm
     * @param array<string, string> $extra
     */
    private static function fillGaps(array &$utm, array $extra): void
    {
        foreach (self::KEYS as $key) {
            if (isset($utm[$key]) && $utm[$key] !== '') {
                continue;
            }
            $value = $extra[$key] ?? '';
            if ($value !== '') {
                $utm[$key] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function ci(array $fields, string $key): mixed
    {
        if (array_key_exists($key, $fields)) {
            return $fields[$key];
        }
        foreach ($fields as $name => $value) {
            if (is_string($name) && strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function scalar(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string)$value);
        }

        return '';
    }

    private static function rawDecode(string $value): string
    {
        $value = trim($value);
        for ($i = 0; $i < 2; $i++) {
            $next = rawurldecode($value);
            if ($next === $value) {
                break;
            }
            $value = $next;
        }

        return $value;
    }

    private static function clip(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 255);
    }
}
