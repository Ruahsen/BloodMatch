<?php

declare(strict_types=1);

namespace BloodMatch\Config;

final class AppConfig
{
    public static function env(): string
    {
        return Env::get('APP_ENV', 'production');
    }

    public static function debug(): bool
    {
        return Env::get('APP_DEBUG', 'false') === 'true';
    }

    public static function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corsOrigins(): array
    {
        $raw = Env::get('APP_CORS_ORIGINS', 'http://localhost:5173');
        $origins = array_map(static fn (string $o): string => trim($o), explode(',', $raw));
        return array_values(array_filter($origins, static fn (string $o): bool => $o !== ''));
    }

    public static function secureCookies(): bool
    {
        return Env::get('APP_SECURE_COOKIES', 'false') === 'true';
    }

    public static function logPath(): string
    {
        return self::basePath() . '/logs/app.log';
    }
}
