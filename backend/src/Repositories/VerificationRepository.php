<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class VerificationRepository
{
    public function addDecision(int $targetUserId, ?int $officerId, string $decision, ?string $reason, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO verification_decisions (target_user_id, officer_id, decision, reason, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$targetUserId, $officerId, $decision, $reason, $nowUtc]);
    }

    public function historyFor(int $targetUserId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT decision, reason, created_at, officer_id
             FROM verification_decisions WHERE target_user_id = ? ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([$targetUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setVerificationStatus(int $targetUserId, string $status): void
    {
        $allowed = ['unverified', 'pending', 'verified', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid verification status.');
        }
        $stmt = Database::pdo()->prepare('UPDATE users SET verification_status = ? WHERE id = ?');
        $stmt->execute([$status, $targetUserId]);
    }
}
