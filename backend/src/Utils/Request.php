<?php

declare(strict_types=1);

namespace BloodMatch\Utils;

final class Request
{
    private static ?array $jsonCache = null;
    private static bool $parsed = false;

    public static function json(): array
    {
        if (self::$parsed) {
            return self::$jsonCache ?? [];
        }
        self::$parsed = true;

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$jsonCache = [];
            return [];
        }

        self::$jsonCache = $decoded;
        return self::$jsonCache;
    }

    public static function str(string $key, ?array $from = null): ?string
    {
        $data = $from ?? self::json();
        $value = $data[$key] ?? ($from === null ? ($_GET[$key] ?? null) : null);
        if ($value === null) {
            return null;
        }
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    public static function int(string $key, ?array $from = null): ?int
    {
        $value = self::str($key, $from);
        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return null;
        }
        return (int) $value;
    }
}
