<?php

declare(strict_types=1);

namespace BloodMatch\Utils;

use BloodMatch\Http\Session;

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        Session::start();
        $stored = $_SESSION['csrf_token'] ?? '';
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return is_string($token) && $token !== '' && hash_equals($stored, $token);
    }
}
