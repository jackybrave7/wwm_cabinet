<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class SiteSettings
{
    public const KEY_ANALYTICS_HEAD = 'analytics_head';
    public const KEY_ANALYTICS_BODY = 'analytics_body';

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(PDO $pdo, string $key, string $default = ''): string
    {
        $all = self::all($pdo);

        return $all[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public static function all(PDO $pdo): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)($row['setting_key'] ?? '')] = (string)($row['setting_value'] ?? '');
        }

        self::$cache = $settings;

        return $settings;
    }

    public static function set(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT(setting_key) DO UPDATE SET
               setting_value = excluded.setting_value,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([$key, $value, gmdate('c')]);
        self::$cache = null;
    }

    public static function analyticsHead(PDO $pdo): string
    {
        return self::get($pdo, self::KEY_ANALYTICS_HEAD);
    }

    public static function analyticsBody(PDO $pdo): string
    {
        return self::get($pdo, self::KEY_ANALYTICS_BODY);
    }
}
