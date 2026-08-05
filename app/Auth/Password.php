<?php
declare(strict_types=1);

namespace Wwm\Auth;

final class Password
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }
}
