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
        $windowStart = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') - $lockMinutes * 60);

        $row = $this->find($identifier);
        if ($row === null) {
            $isLocked = ($maxAttempts <= 1);
            $stmt = Database::pdo()->prepare(
                'INSERT INTO auth_throttle (identifier, failed_count, locked_until)
                 VALUES (?, 1, ?)'
            );
            $stmt->execute([$identifier, $isLocked ? $lockAt : null]);
            return;
        }

        // If currently locked and lock has not expired, remain locked
        if (!empty($row['locked_until']) && $row['locked_until'] > $nowUtc) {
            return;
        }

        // If previous lock expired OR last attempt was outside the window, reset count to 1
        $isExpired = (!empty($row['locked_until']) && $row['locked_until'] <= $nowUtc)
            || (empty($row['locked_until']) && !empty($row['updated_at']) && $row['updated_at'] < $windowStart);

        $newCount = $isExpired ? 1 : ((int) $row['failed_count'] + 1);
        $newLock = ($newCount >= $maxAttempts) ? $lockAt : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE auth_throttle 
             SET failed_count = ?, locked_until = ?, updated_at = UTC_TIMESTAMP()
             WHERE identifier = ?'
        );
        $stmt->execute([$newCount, $newLock, $identifier]);
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

    public function setLock(string $identifier, int $lockSeconds, string $nowUtc): void
    {
        $lockAt = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') + $lockSeconds);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO auth_throttle (identifier, failed_count, locked_until)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                locked_until = VALUES(locked_until)'
        );
        $stmt->execute([$identifier, $lockAt]);
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

    public function recordFailureSeconds(string $identifier, int $maxAttempts, int $lockSeconds, string $nowUtc): void
    {
        $lockAt = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') + $lockSeconds);
        $windowStart = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') - $lockSeconds);

        $row = $this->find($identifier);
        if ($row === null) {
            $isLocked = ($maxAttempts < 1);
            $stmt = Database::pdo()->prepare(
                'INSERT INTO auth_throttle (identifier, failed_count, locked_until)
                 VALUES (?, 1, ?)'
            );
            $stmt->execute([$identifier, $isLocked ? $lockAt : null]);
            return;
        }

        if (!empty($row['locked_until']) && $row['locked_until'] > $nowUtc) {
            return;
        }

        $isExpired = (!empty($row['locked_until']) && $row['locked_until'] <= $nowUtc)
            || (empty($row['locked_until']) && !empty($row['updated_at']) && $row['updated_at'] < $windowStart);

        $newCount = $isExpired ? 1 : ((int) $row['failed_count'] + 1);
        $newLock = ($newCount > $maxAttempts) ? $lockAt : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE auth_throttle 
             SET failed_count = ?, locked_until = ?, updated_at = UTC_TIMESTAMP()
             WHERE identifier = ?'
        );
        $stmt->execute([$newCount, $newLock, $identifier]);
    }

    public function hitAndCheckRateLimitSeconds(string $identifier, int $maxAttempts, int $windowSeconds, string $nowUtc): bool
    {
        if ($this->isLocked($identifier, $nowUtc)) {
            return false;
        }

        $this->recordFailureSeconds($identifier, $maxAttempts, $windowSeconds, $nowUtc);

        return !$this->isLocked($identifier, $nowUtc);
    }
}
