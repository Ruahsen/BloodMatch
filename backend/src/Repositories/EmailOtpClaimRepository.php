<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class EmailOtpClaimRepository
{
    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO email_otp_claim_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findValidByHash(string $tokenHash, string $nowUtc): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id, expires_at, used_at
              FROM email_otp_claim_tokens
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?
              LIMIT 1'
        );
        $stmt->execute([$tokenHash, $nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Single-use consumption: exactly one concurrent claimant wins.
     */
    public function consume(int $id, string $nowUtc): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE email_otp_claim_tokens SET used_at = ?
              WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$nowUtc, $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * One live token per account: a fresh registration supersedes any
     * leftover unused token (e.g. abandoned re-registration attempts -
     * the unique-email gate normally prevents these, this is belt-and-braces).
     */
    public function deleteUnusedForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM email_otp_claim_tokens WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);
    }
}
