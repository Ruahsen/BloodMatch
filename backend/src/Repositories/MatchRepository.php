<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class MatchRepository
{
    public function findByIdDetailed(int $matchId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, br.status AS request_status, br.request_chapter_id, br.quantity_units
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByIdForUpdate(int $matchId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, br.status AS request_status, br.request_chapter_id, br.quantity_units
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.id = ? LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function setStatus(int $matchId, string $status): void
    {
        $allowed = ['POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED', 'CLOSED'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid match status.');
        }
        $stmt = Database::pdo()->prepare('UPDATE matches SET status = ? WHERE id = ?');
        $stmt->execute([$status, $matchId]);
    }

    public function findByRequestAndDonor(int $requestId, int $donorId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, generation, status FROM matches WHERE request_id = ? AND donor_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId, $donorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function countCompleted(int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM matches WHERE request_id = ? AND status = 'COMPLETED'"
        );
        $stmt->execute([$requestId]);
        return (int) $stmt->fetchColumn();
    }

    public static function fulfillAndCloseUnresolved(int $requestId): int
    {
        $close = Database::pdo()->prepare(
            "UPDATE matches SET status = 'CLOSED'
             WHERE request_id = ? AND status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED')"
        );
        $close->execute([$requestId]);
        $closedCount = $close->rowCount();

        $upd = Database::pdo()->prepare(
            "UPDATE blood_requests SET status = 'FULFILLED' WHERE id = ?"
        );
        $upd->execute([$requestId]);

        return $closedCount;
    }
}
