<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class DonationReportRepository
{
    private static ?bool $hasRejectionReasonColumn = null;

    private static function supportsRejectionReason(): bool
    {
        if (self::$hasRejectionReasonColumn === null) {
            $stmt = Database::pdo()->prepare(
                "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donation_reports'
                   AND COLUMN_NAME = 'rejection_reason' LIMIT 1"
            );
            $stmt->execute();
            $column = $stmt->fetch(PDO::FETCH_ASSOC);
            self::$hasRejectionReasonColumn = $column !== false
                && (string) $column['DATA_TYPE'] === 'varchar'
                && (int) $column['CHARACTER_MAXIMUM_LENGTH'] === 500
                && (string) $column['IS_NULLABLE'] === 'YES';
        }
        return self::$hasRejectionReasonColumn;
    }

    public function insert(int $matchId, int $donorId, ?string $note, string $reportedAtUtc): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO donation_reports (match_id, donor_id, report_note, status, reported_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$matchId, $donorId, $note, 'PENDING', $reportedAtUtc]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT dr.*, m.request_id, br.request_chapter_id, br.status AS request_status, br.quantity_units
             FROM donation_reports dr
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE dr.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        return $this->findByIdLocked($id);
    }

    private function findByIdLocked(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM donation_reports WHERE id = ? LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function pendingExistsForMatch(int $matchId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT 1 FROM donation_reports WHERE match_id = ? AND status = 'PENDING' LIMIT 1"
        );
        $stmt->execute([$matchId]);
        return $stmt->fetchColumn() !== false;
    }

    public function markConfirmed(int $id, int $officerId, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE donation_reports SET status = 'CONFIRMED', confirmed_by = ?, confirmed_at = ? WHERE id = ?"
        );
        $stmt->execute([$officerId, $nowUtc, $id]);
    }

    public function pendingExistsForMatchForUpdate(int $matchId): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM donation_reports WHERE match_id = ? AND status = 'PENDING' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$matchId]);
        return $stmt->fetchColumn() !== false;
    }

    public function markRejected(int $id, int $officerId, string $nowUtc, string $reason): void
    {
        if (!self::supportsRejectionReason()) {
            throw new \RuntimeException('Rejection reason storage is temporarily unavailable. Contact an administrator.', 503);
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE donation_reports SET status = 'REJECTED', confirmed_by = ?, confirmed_at = ?, rejection_reason = ? WHERE id = ?"
        );
        $stmt->execute([$officerId, $nowUtc, $reason, $id]);
    }

    public function listPendingByChapter(?int $chapterId, string $sort = 'oldest'): array
    {
        $chapterFilter = $chapterId === null ? '' : 'AND br.request_chapter_id = ?';
        $params = $chapterId === null ? [] : [$chapterId];
        $sortDir = strtolower($sort) === 'newest' ? 'DESC' : 'ASC';

        $stmt = Database::pdo()->prepare(
            "SELECT dr.id, dr.match_id, dr.donor_id, dr.report_note, dr.reported_at,
                    u.full_name AS donor_name, br.required_blood_type, br.facility_name,
                    br.request_chapter_id, br.status AS request_status, br.needed_datetime, m.status AS match_status
             FROM donation_reports dr
             JOIN users u ON u.id = dr.donor_id
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE dr.status = 'PENDING' $chapterFilter
             ORDER BY dr.reported_at {$sortDir}, dr.id {$sortDir}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listByDonor(int $donorId): array
    {
        $reasonColumn = self::supportsRejectionReason() ? 'dr.rejection_reason' : 'NULL AS rejection_reason';
        $stmt = Database::pdo()->prepare(
            'SELECT dr.id, dr.match_id, dr.report_note, dr.status, dr.reported_at, dr.confirmed_at, ' . $reasonColumn . ',
                    br.required_blood_type, br.facility_name
             FROM donation_reports dr
             JOIN matches m ON m.id = dr.match_id
             JOIN blood_requests br ON br.id = m.request_id
             WHERE dr.donor_id = ? ORDER BY dr.id DESC LIMIT 50'
        );
        $stmt->execute([$donorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
