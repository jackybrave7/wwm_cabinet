<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * RUB per 1 USD from CBR (cbr-xml-daily.ru archive), cached by calendar date.
 */
final class CbrUsdRubRates
{
    /** @var array<string, float> Y-m-d => RUB per USD */
    private static array $memory = [];

    /**
     * @param list<array<string, mixed>> $payments
     */
    public static function warmCacheForPayments(array $payments): void
    {
        $dates = [];
        foreach ($payments as $pay) {
            if (self::positiveFloat($pay['amount_original'] ?? null) !== null) {
                continue;
            }
            $date = self::paymentDateKey($pay);
            if ($date !== '') {
                $dates[$date] = true;
            }
        }
        foreach (array_keys($dates) as $date) {
            self::rubPerUsdForDate($date);
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    public static function rubPerUsdForPayment(array $payment): float
    {
        $stored = self::positiveFloat($payment['fx_rate'] ?? null);
        if ($stored !== null) {
            return $stored;
        }

        $date = self::paymentDateKey($payment);
        if ($date !== '') {
            $fromCbr = self::rubPerUsdForDate($date);
            if ($fromCbr !== null) {
                return $fromCbr;
            }
        }

        return self::fallbackRate();
    }

    public static function rubPerUsdForDate(string $ymd): ?float
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        if (isset(self::$memory[$ymd])) {
            return self::$memory[$ymd];
        }

        $disk = self::readDiskCache();
        if (isset($disk[$ymd])) {
            self::$memory[$ymd] = (float)$disk[$ymd];

            return self::$memory[$ymd];
        }

        $rate = self::fetchArchiveRate($ymd);
        if ($rate === null) {
            return null;
        }

        $disk[$ymd] = $rate;
        self::writeDiskCache($disk);
        self::$memory[$ymd] = $rate;

        return $rate;
    }

    public static function fallbackRate(): float
    {
        $rate = (float)(wwm_config()['payment_usd_rub_fallback_rate'] ?? 0);

        return $rate > 0 ? $rate : 85.0;
    }

    /**
     * @param array<string, mixed> $payment
     */
    private static function paymentDateKey(array $payment): string
    {
        foreach (['paid_at', 'ordered_at', 'created_at'] as $key) {
            $raw = trim((string)($payment[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $dt = new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                continue;
            }

            return $dt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        }

        return '';
    }

    private static function fetchArchiveRate(string $ymd): ?float
    {
        [$y, $m, $d] = explode('-', $ymd);
        $url = sprintf('https://www.cbr-xml-daily.ru/archive/%s/%s/%s/daily_json.js', $y, $m, $d);
        $raw = self::httpGet($url);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['Valute']['USD']) || !is_array($data['Valute']['USD'])) {
            return null;
        }
        $row = $data['Valute']['USD'];
        $nominal = max(1, (int)($row['Nominal'] ?? 1));
        $value = (float)($row['Value'] ?? 0);
        if ($value <= 0) {
            return null;
        }

        return round($value / $nominal, 6);
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                return $body;
            }
        }

        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $body = @file_get_contents($url, false, $ctx);

        return is_string($body) && $body !== '' ? $body : null;
    }

    /** @return array<string, float> */
    private static function readDiskCache(): array
    {
        $path = self::cachePath();
        if (!is_readable($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, float> $rates */
    private static function writeDiskCache(array $rates): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents($path, json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function cachePath(): string
    {
        return WWM_ROOT . '/data/cbr_usd_rub_by_date.json';
    }

    private static function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = (float)str_replace([' ', ','], ['', '.'], trim((string)$value));

        return $num > 0 ? $num : null;
    }
}
