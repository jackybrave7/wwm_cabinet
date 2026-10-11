<?php
declare(strict_types=1);

namespace Wwm\Auth;

final class Password
{
    /** bcrypt cost — balance security vs login latency (no rehash on every login). */
    public const BCRYPT_COST = 11;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /** Readable random password (no 0/O, 1/l). */
    public static function generateReadable(int $length = 8): string
    {
        if ($length < 6) {
            $length = 6;
        }

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }
}
