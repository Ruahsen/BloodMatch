<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class AuthThrottleRepository
{
    public function find(string $identifier): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM auth_throttle WHERE identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function recordFailure(string $identifier, int $maxAttempts, int $lockMinutes, string $nowUtc): void
    {
        $lockAt = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') + $lockMinutes * 60);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO auth_throttle (identifier, failed_count, locked_until)
             VALUES (?, 1, NULL)
             ON DUPLICATE KEY UPDATE
                failed_count = IF(locked_until IS NULL OR locked_until < ?, failed_count + 1, failed_count),
                locked_until = IF(failed_count + 1 >= ?, ?, NULL)'
        );
        $stmt->execute([$identifier, $nowUtc, $maxAttempts, $lockAt]);
    }

    public function isLocked(string $identifier, string $nowUtc): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT locked_until FROM auth_throttle
             WHERE identifier = ? AND locked_until IS NOT NULL AND locked_until > ?
             LIMIT 1'
        );
        $stmt->execute([$identifier, $nowUtc]);
        return $stmt->fetchColumn() !== false;
    }

    public function clear(string $identifier): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM auth_throttle WHERE identifier = ?');
        $stmt->execute([$identifier]);
    }

    public function hitAndCheckRateLimit(string $identifier, int $maxAttempts, int $windowMinutes, string $nowUtc): bool
    {
        if ($this->isLocked($identifier, $nowUtc)) {
            return false;
        }

        $this->recordFailure($identifier, $maxAttempts, $windowMinutes, $nowUtc);

        return !$this->isLocked($identifier, $nowUtc);
    }
}
