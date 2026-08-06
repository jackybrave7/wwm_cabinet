<?php
declare(strict_types=1);

namespace Wwm\Services;

final class AccessPeriod
{
    /** @return array<string, string> */
    public static function presets(): array
    {
        return [
            '48h' => '48 hours',
            '7d' => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
            '365d' => '1 year',
            'unlimited' => 'No expiry',
            'custom' => 'Until date…',
        ];
    }

    public static function resolve(?string $preset, ?string $customDate = null): ?string
    {
        $preset = trim((string)$preset);
        if ($preset === 'custom') {
            return self::resolveCustomDate($customDate);
        }
        if ($preset === '' || $preset === 'unlimited') {
            return null;
        }

        $seconds = match ($preset) {
            '48h' => 48 * 3600,
            '7d' => 7 * 24 * 3600,
            '30d' => 30 * 24 * 3600,
            '90d' => 90 * 24 * 3600,
            '365d' => 365 * 24 * 3600,
            default => 0,
        };

        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Invalid access period');
        }

        return gmdate('c', time() + $seconds);
    }

    public static function resolveCustomDate(?string $date): string
    {
        $date = trim((string)$date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Invalid access date');
        }

        $ts = strtotime($date . ' 23:59:59 UTC');
        if ($ts === false || $ts <= time()) {
            throw new \InvalidArgumentException('Access date must be in the future');
        }

        return gmdate('c', $ts);
    }
}
