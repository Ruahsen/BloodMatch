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
            'SELECT * FROM matches WHERE id = ? LIMIT 1
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

    public function findByRequestAndDonorForUpdate(int $requestId, int $donorId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_id, donor_id, generation, status
               FROM matches
              WHERE request_id = ? AND donor_id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$requestId, $donorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function maxGenerationForRequest(int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(generation), 0) FROM matches WHERE request_id = ?'
        );
        $stmt->execute([$requestId]);
        return (int) $stmt->fetchColumn();
    }

    public function insertResponded(int $requestId, int $donorId, int $generation): int
    {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO matches (request_id, donor_id, generation, status)
             VALUES (?, ?, ?, 'RESPONDED')"
        );
        $stmt->execute([$requestId, $donorId, max(1, $generation)]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function profileViewRelation(int $viewerId, int $targetId): ?string
    {
        $donorToRequester = Database::pdo()->prepare(
            "SELECT 1
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.donor_id = ? AND br.requester_id = ?
               AND br.status = 'OPEN'
               AND m.status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED')
             LIMIT 1"
        );
        $donorToRequester->execute([$viewerId, $targetId]);
        if ($donorToRequester->fetchColumn() !== false) {
            return 'matched_donor';
        }

        $requesterToDonor = Database::pdo()->prepare(
            "SELECT 1
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE br.requester_id = ? AND m.donor_id = ?
               AND br.status = 'OPEN'
               AND m.status IN ('RESPONDED', 'COMPLETED')
             LIMIT 1"
        );
        $requesterToDonor->execute([$viewerId, $targetId]);
        return $requesterToDonor->fetchColumn() !== false ? 'requester_of_responded_donor' : null;
    }

    /**
     * Determines whether two users share an active blood match, and returns the
     * match role of $currentUserId relative to $otherUserId ('donor' or 'requestor').
     */
    public function getMatchRole(int $currentUserId, int $otherUserId): ?string
    {
        if ($currentUserId <= 0 || $otherUserId <= 0 || $currentUserId === $otherUserId) {
            return null;
        }

        // Case A: $currentUserId is the DONOR, $otherUserId is the REQUESTOR
        $donorStmt = Database::pdo()->prepare(
            "SELECT m.id
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.donor_id = ? AND br.requester_id = ?
               AND m.status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED')
               AND br.status IN ('OPEN', 'FULFILLED')
             ORDER BY (CASE WHEN br.status = 'OPEN' THEN 0 ELSE 1 END), m.id DESC
             LIMIT 1"
        );
        $donorStmt->execute([$currentUserId, $otherUserId]);
        if ($donorStmt->fetchColumn() !== false) {
            return 'donor';
        }

        // Case B: $currentUserId is the REQUESTOR, $otherUserId is the DONOR
        $requesterStmt = Database::pdo()->prepare(
            "SELECT m.id
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE br.requester_id = ? AND m.donor_id = ?
               AND m.status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED')
               AND br.status IN ('OPEN', 'FULFILLED')
             ORDER BY (CASE WHEN br.status = 'OPEN' THEN 0 ELSE 1 END), m.id DESC
             LIMIT 1"
        );
        $requesterStmt->execute([$currentUserId, $otherUserId]);
        if ($requesterStmt->fetchColumn() !== false) {
            return 'requestor';
        }

        return null;
    }

    public static function countCompleted(int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM matches WHERE request_id = ? AND status = 'COMPLETED'"
        );
        $stmt->execute([$requestId]);
        return (int) $stmt->fetchColumn();
    }

    public static function closeUnfinishedForRequest(int $requestId): int
    {
        $close = Database::pdo()->prepare(
            "UPDATE matches SET status = 'CLOSED'
             WHERE request_id = ? AND status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED')"
        );
        $close->execute([$requestId]);
        return $close->rowCount();
    }

    public static function fulfillAndCloseUnresolved(int $requestId): int
    {
        if (!(new BloodRequestRepository())->setStatus($requestId, 'FULFILLED')) {
            throw new \RuntimeException('This request is no longer active.', 409);
        }
        return self::closeUnfinishedForRequest($requestId);
    }
}
