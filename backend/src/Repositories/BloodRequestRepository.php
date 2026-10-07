<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class BloodRequestRepository
{
    private const SAFE_COLUMNS =
        'br.id, br.requester_id, br.request_chapter_id, br.required_blood_type, br.quantity_units,
         br.facility_name, br.location_id, br.latitude, br.longitude, br.urgency, br.needed_datetime,
         br.status, br.review_status, br.created_at, br.updated_at, br.expired_at,
         loc.psgc_code AS loc_psgc, loc.name AS loc_name, loc.level AS loc_level,
         loc.municipality_code AS loc_municipality_code, loc.municipality_name AS loc_municipality_name';

    private const LOCATION_JOIN = ' LEFT JOIN bataan_locations loc ON loc.id = br.location_id';

    public function create(array $r): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO blood_requests
                (requester_id, request_chapter_id, required_blood_type, quantity_units,
                 facility_name, location_id, latitude, longitude, urgency, needed_datetime, status, review_status)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $r['requester_id'],
            $r['request_chapter_id'],
            $r['required_blood_type'],
            $r['quantity_units'],
            $r['facility_name'],
            $r['location_id'],
            $r['latitude'],
            $r['longitude'],
            $r['urgency'],
            $r['needed_datetime'],
            'OPEN',
            $r['review_status'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ' . self::SAFE_COLUMNS . ' FROM blood_requests br' . self::LOCATION_JOIN . ' WHERE br.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT ' . self::SAFE_COLUMNS . ' FROM blood_requests br' . self::LOCATION_JOIN . ' WHERE br.id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Feed candidates: OPEN requests whose requester account is still active.
     * SQL does FILTERING ONLY (status, requester activity, optional
     * blood/urgency/chapter predicates). Ranking happens in PHP
     * (RequestFeedService) via the existing compatibility/geo/eligibility
     * services so no business rule is duplicated in SQL.
     *
     * @return array<int, array>
     */
    public function listOpenFeedCandidates(?string $bloodType, ?string $urgency, ?int $chapterId, ?int $matchedDonorId = null, array $matchStatuses = []): array
    {
        $where = "br.status = 'OPEN' AND req.account_status = 'active'";
        $params = [];

        if ($matchedDonorId !== null && $matchStatuses !== []) {
            // Match-tab scope: only requests carrying one of the viewer's own
            // qualifying match relationships. Single subquery — no N+1.
            $ph = implode(',', array_fill(0, count($matchStatuses), '?'));
            $where .= " AND br.id IN (SELECT m.request_id FROM matches m WHERE m.donor_id = ? AND m.status IN ($ph))";
            $params[] = $matchedDonorId;
            foreach ($matchStatuses as $st) {
                $params[] = $st;
            }
        }
        if ($bloodType !== null) {
            $where .= ' AND br.required_blood_type = ?';
            $params[] = $bloodType;
        }
        if ($urgency !== null) {
            $where .= ' AND br.urgency = ?';
            $params[] = $urgency;
        }
        if ($chapterId !== null) {
            $where .= ' AND br.request_chapter_id = ?';
            $params[] = $chapterId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ', ch.name AS chapter_name' .
            ' FROM blood_requests br' . self::LOCATION_JOIN .
            ' JOIN users req ON req.id = br.requester_id' .
            ' LEFT JOIN chapters ch ON ch.id = br.request_chapter_id' .
            " WHERE {$where} ORDER BY br.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Paginated requester listing (replaces the old unexplained hard cap):
     * total metadata lets the UI page through the full history.
     *
     * @return array{requests:array, total:int}
     */
    public function listByRequester(int $requesterId, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM blood_requests br WHERE br.requester_id = ?'
        );
        $countStmt->execute([$requesterId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ' FROM blood_requests br' . self::LOCATION_JOIN . '
             WHERE br.requester_id = ? ORDER BY br.id DESC
             LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$requesterId]);
        return ['requests' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function updateFields(int $id, array $fields): void
    {
        static $map = [
            'required_blood_type' => 'required_blood_type',
            'quantity_units' => 'quantity_units',
            'facility_name' => 'facility_name',
            'location_id' => 'location_id',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'urgency' => 'urgency',
            'needed_datetime' => 'needed_datetime',
        ];

        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!isset($map[$key])) {
                continue;
            }
            $sets[] = $map[$key] . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;

        $stmt = Database::pdo()->prepare('UPDATE blood_requests SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function setStatus(int $id, string $status, ?string $expiredAtUtc = null): void
    {
        $allowed = ['OPEN', 'FULFILLED', 'CANCELLED', 'EXPIRED'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid request status.');
        }
        $extra = $status === 'EXPIRED' ? ', expired_at = COALESCE(expired_at, ?)' : '';
        $params = [$status];
        if ($status === 'EXPIRED') {
            $params[] = $expiredAtUtc ?? \BloodMatch\Services\AuthService::nowUtc();
        }
        $params[] = $id;

        $stmt = Database::pdo()->prepare("UPDATE blood_requests SET status = ?{$extra} WHERE id = ?");
        $stmt->execute($params);
    }

    /**
     * Claim overdue OPEN requests for expiry. Deterministic order
     * (needed_datetime, id) so batches are stable; inclusive deadline
     * (needed_datetime <= now) so exact-deadline requests expire on time.
     * Returns only rows stamped by THIS call, so concurrent workers never
     * process each other's claims (notification dedup additionally guards
     * the same-second edge).
     */
    public function expireDueBatch(string $nowUtc, int $limit = 500): array
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE blood_requests
             SET status = 'EXPIRED', expired_at = ?
             WHERE status = 'OPEN' AND needed_datetime <= ?
             ORDER BY needed_datetime ASC, id ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$nowUtc, $nowUtc]);

        if ($stmt->rowCount() === 0) {
            return [];
        }

        $idStmt = Database::pdo()->prepare(
            'SELECT id, requester_id FROM blood_requests WHERE status = ? AND expired_at = ?'
        );
        $idStmt->execute(['EXPIRED', $nowUtc]);
        return $idStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
