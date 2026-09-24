<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use PDO;
use RuntimeException;

final class BloodCompatibilityService
{
    private static ?array $cache = null;

    public static function getCompatibleDonorTypes(string $recipientType): array
    {
        if (self::$cache === null) {
            $rows = Database::pdo()
                ->query('SELECT recipient_type, allowed_donor_types FROM compatibility_matrix')
                ->fetchAll(PDO::FETCH_ASSOC);
            self::$cache = [];
            foreach ($rows as $row) {
                self::$cache[$row['recipient_type']] = array_map(
                    static fn (string $t): string => trim($t),
                    explode(',', (string) $row['allowed_donor_types'])
                );
            }
        }

        if (!isset(self::$cache[$recipientType])) {
            throw new RuntimeException("Unknown recipient blood type: {$recipientType}");
        }

        return self::$cache[$recipientType];
    }

    public static function fullMatrix(): array
    {
        if (self::$cache === null) {
            self::getCompatibleDonorTypes('O-');
        }
        return self::$cache;
    }
}
