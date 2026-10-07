<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class EmailOtpRepository
{
    public function create(int $userId, string $otpHash, string $expiresAt): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO email_verification_otps (user_id, otp_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $otpHash, $expiresAt]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Latest still-usable challenge for a user, if any.
     */
    public function findActiveForUser(int $userId, string $nowUtc): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, otp_hash, expires_at, attempt_count, used_at, created_at
              FROM email_verification_otps
              WHERE user_id = ? AND used_at IS NULL AND expires_at > ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$userId, $nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, otp_hash, expires_at, attempt_count, used_at, created_at
              FROM email_verification_otps
              WHERE user_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function countActiveForUser(int $userId, string $nowUtc): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM email_verification_otps
              WHERE user_id = ? AND used_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([$userId, $nowUtc]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * A newly issued OTP supersedes all prior unused challenges for the
     * user: exactly one OTP is ever usable, so a leaked earlier code dies
     * the moment a replacement is generated.
     */
    public function deleteUnusedForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM email_verification_otps WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);
    }

    public function deleteById(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM email_verification_otps WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_verification_otps SET attempt_count = attempt_count + 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function markUsed(int $id, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_verification_otps SET used_at = ? WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$nowUtc, $id]);
    }

    /**
     * Atomic single-use consumption of a known row: of N concurrent
     * verifiers holding the same code, exactly one wins the row lock and
     * observes rowCount 1; the rest observe an already-consumed row.
     * Must be called inside a transaction after SELECT ... FOR UPDATE.
     */
    public function consumeRow(int $id, int $maxAttempts, string $nowUtc): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_verification_otps SET used_at = ?
              WHERE id = ? AND used_at IS NULL AND expires_at > ? AND attempt_count < ?'
        );
        $stmt->execute([$nowUtc, $id, $nowUtc, $maxAttempts]);
        return $stmt->rowCount() === 1;
    }

    public function lockRow(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, otp_hash, expires_at, attempt_count, used_at
              FROM email_verification_otps
              WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function hashIsPlaintextLeak(string $nowUtc): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM email_verification_otps
              WHERE created_at > ? AND otp_hash NOT REGEXP '^[0-9a-f]{64}$'"
        );
        $stmt->execute([$nowUtc]);
        return (int) $stmt->fetchColumn();
    }
}
