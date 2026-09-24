<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use PDO;
use RuntimeException;

final class SystemSettingsService
{
    private const SPEC = [
        'standby_hours' => [1, 720],
        'cooldown_days' => [1, 1825],
    ];

    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Database::pdo()
            ->query('SELECT setting_key, value FROM system_settings')
            ->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['value'];
        }

        foreach (self::SPEC as $key => [$min, $max]) {
            if (!isset($settings[$key]) || !preg_match('/^\d+$/', (string) $settings[$key])) {
                throw new RuntimeException("System setting '{$key}' is missing or invalid.");
            }
            $int = (int) $settings[$key];
            if ($int < $min || $int > $max) {
                throw new RuntimeException("System setting '{$key}' is out of range ($min-$max).");
            }
            $settings[$key] = $int;
        }

        self::$cache = $settings;
        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
