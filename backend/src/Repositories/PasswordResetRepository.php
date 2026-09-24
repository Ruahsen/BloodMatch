<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class PasswordResetRepository
{
    public function create(int $userId, string $tokenHash, string $expiresAt): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
    }

    public function findValidByHash(string $tokenHash, string $nowUtc): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash, $nowUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function markUsed(int $id, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$nowUtc, $id]);
    }

    public function deleteOtherUnused(int $userId, int $keepId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL AND id <> ?'
        );
        $stmt->execute([$userId, $keepId]);
    }
}
