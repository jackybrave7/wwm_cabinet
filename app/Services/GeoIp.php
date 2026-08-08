<?php
declare(strict_types=1);

namespace Wwm\Services;

final class GeoIp
{
    /**
     * @return array{country: ?string, city: ?string}
     */
    public static function lookup(?string $ip): array
    {
        if ($ip === null || $ip === '' || !self::isPublicIp($ip)) {
            return ['country' => null, 'city' => null];
        }

        $country = self::countryFromCloudflare();
        $city = null;

        $remote = self::lookupRemote($ip);
        if ($remote['country'] !== null) {
            $country = $remote['country'];
        }
        if ($remote['city'] !== null) {
            $city = $remote['city'];
        }

        return [
            'country' => $country,
            'city' => $city,
        ];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function countryFromCloudflare(): ?string
    {
        $code = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if ($code === '' || $code === 'XX' || $code === 'T1') {
            return null;
        }

        return $code;
    }

    /**
     * @return array{country: ?string, city: ?string}
     */
    private static function lookupRemote(string $ip): array
    {
        $url = 'https://ipwho.is/' . rawurlencode($ip);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
                'header' => "User-Agent: WWM-Cabinet/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            return ['country' => null, 'city' => null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['success'])) {
            return ['country' => null, 'city' => null];
        }

        $country = trim((string)($data['country'] ?? ''));
        $city = trim((string)($data['city'] ?? ''));

        return [
            'country' => $country !== '' ? $country : null,
            'city' => $city !== '' ? $city : null,
        ];
    }
}
