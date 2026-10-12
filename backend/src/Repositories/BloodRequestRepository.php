<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class BloodRequestRepository
{
    private const SAFE_COLUMNS =
        'br.id, br.requester_id, br.request_chapter_id, br.required_blood_type, br.quantity_units,
         br.facility_name, br.hospital_id, br.location_id, br.latitude, br.longitude, br.urgency, br.needed_datetime,
         br.status, br.review_status, br.created_at, br.updated_at, br.expired_at,
         loc.psgc_code AS loc_psgc, loc.name AS loc_name, loc.level AS loc_level,
         loc.municipality_code AS loc_municipality_code, loc.municipality_name AS loc_municipality_name';

    private const LOCATION_JOIN = ' LEFT JOIN bataan_locations loc ON loc.id = br.location_id';

    public function create(array $r): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO blood_requests
                (requester_id, request_chapter_id, required_blood_type, quantity_units,
                 facility_name, hospital_id, location_id, latitude, longitude, urgency, needed_datetime, status, review_status)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $r['requester_id'],
            $r['request_chapter_id'],
            $r['required_blood_type'],
            $r['quantity_units'],
            $r['facility_name'],
            $r['hospital_id'] ?? null,
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
        $stmt = Database::pdo()->prepare(
            'SELECT *
               FROM blood_requests
              WHERE id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function listByRequester(int $requesterId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ',
                    (SELECT COUNT(*) FROM matches mx WHERE mx.request_id = br.id AND mx.status <> \'CLOSED\') AS match_count,
                    (SELECT COUNT(*) FROM matches rx WHERE rx.request_id = br.id AND rx.status IN (\'RESPONDED\', \'COMPLETED\')) AS response_count
             FROM blood_requests br' . self::LOCATION_JOIN . '
             WHERE br.requester_id = ? ORDER BY br.id DESC LIMIT 100'
        );
        $stmt->execute([$requesterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listByRespondedDonor(int $donorId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ',
                    m.id AS match_id,
                    m.status AS match_status,
                    requester.full_name AS requester_name,
                    requester.verification_status AS requester_verification_status,
                    requester.profile_picture AS requester_profile_picture,
                    requester_chapter.name AS requester_chapter_name,
                    (SELECT COUNT(*) FROM matches mx WHERE mx.request_id = br.id AND mx.status <> \'CLOSED\') AS match_count,
                    (SELECT COUNT(*) FROM matches rx WHERE rx.request_id = br.id AND rx.status IN (\'RESPONDED\', \'COMPLETED\')) AS response_count
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             JOIN users requester ON requester.id = br.requester_id
             LEFT JOIN chapters requester_chapter ON requester_chapter.id = requester.chapter_id' . self::LOCATION_JOIN . '
             WHERE m.donor_id = ?
               AND m.status IN (\'RESPONDED\', \'COMPLETED\')
             ORDER BY m.id DESC LIMIT 100'
        );
        $stmt->execute([$donorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listCompatibleOpenForDonor(int $donorId, array $recipientTypes, int $limit = 50): array
    {
        $result = $this->pageCompatibleOpenForViewer(
            $donorId,
            $recipientTypes,
            ['q' => null, 'municipality_code' => null, 'blood' => [], 'urgency' => []],
            1,
            max(1, min($limit, 100)),
            \BloodMatch\Services\AuthService::nowUtc()
        );
        return $result['rows'];
    }

    /**
     * @return array{rows: array, total_matching: int, total_unfiltered: int, has_more: bool}
     */
    public function pageCompatibleOpenForViewer(
        int $viewerId,
        array $recipientTypes,
        array $filters,
        int $page,
        int $pageSize,
        string $nowUtc
    ): array {
        if ($recipientTypes === []) {
            return ['rows' => [], 'total_matching' => 0, 'total_unfiltered' => 0, 'has_more' => false];
        }

        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, 100));
        $offset = ($page - 1) * $pageSize;
        $typePlaceholders = implode(',', array_fill(0, count($recipientTypes), '?'));
        $joins = '
             FROM blood_requests br
             JOIN users requester ON requester.id = br.requester_id
             LEFT JOIN chapters requester_chapter ON requester_chapter.id = requester.chapter_id' .
             self::LOCATION_JOIN;
        $baseWhere = [
            'br.requester_id <> ?',
            "br.status = 'OPEN'",
            'br.needed_datetime >= ?',
            "br.required_blood_type IN ($typePlaceholders)",
            "requester.role = 'member'",
            "requester.account_status = 'active'",
        ];
        $baseParams = array_merge([$viewerId, $nowUtc], array_values($recipientTypes));

        $totalUnfiltered = $this->countFeedRows($joins, $baseWhere, $baseParams);
        $where = $baseWhere;
        $params = $baseParams;

        if (($filters['q'] ?? null) !== null) {
            $query = (string) $filters['q'];
            $query = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
            $needle = '%' . self::escapeLike($query) . '%';
            $where[] = "(LOWER(br.facility_name) LIKE ? ESCAPE '='
                         OR LOWER(COALESCE(loc.municipality_name, '')) LIKE ? ESCAPE '=')";
            $params[] = $needle;
            $params[] = $needle;
        }
        if (($filters['municipality_code'] ?? null) !== null) {
            $where[] = 'loc.municipality_code = ?';
            $params[] = (string) $filters['municipality_code'];
        }
        if (($filters['blood'] ?? []) !== []) {
            $selectedBlood = array_values($filters['blood']);
            $where[] = 'br.required_blood_type IN (' . implode(',', array_fill(0, count($selectedBlood), '?')) . ')';
            array_push($params, ...$selectedBlood);
        }
        if (($filters['urgency'] ?? []) !== []) {
            $selectedUrgencies = array_values($filters['urgency']);
            $where[] = 'br.urgency IN (' . implode(',', array_fill(0, count($selectedUrgencies), '?')) . ')';
            array_push($params, ...$selectedUrgencies);
        }
        if (isset($filters['request_id'])) {
            $where[] = 'br.id = ?';
            $params[] = (int) $filters['request_id'];
        }

        $totalMatching = $this->countFeedRows($joins, $where, $params);
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ',
                    m.id AS match_id, m.status AS match_status,
                    requester.full_name AS requester_name,
                    requester.verification_status AS requester_verification_status,
                    requester.profile_picture AS requester_profile_picture,
                    requester_chapter.name AS requester_chapter_name' .
             $joins . '
             LEFT JOIN matches m ON m.request_id = br.id AND m.donor_id = ?
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY FIELD(br.urgency, 'emergency', 'urgent', 'routine'), br.created_at DESC, br.id DESC
             LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute(array_merge([$viewerId], $params));

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total_matching' => $totalMatching,
            'total_unfiltered' => $totalUnfiltered,
            'has_more' => $offset + $pageSize < $totalMatching,
        ];
    }

    public function findCompatibleOpenForViewer(
        int $requestId,
        int $viewerId,
        array $recipientTypes,
        string $nowUtc
    ): ?array {
        $result = $this->pageCompatibleOpenForViewer(
            $viewerId,
            $recipientTypes,
            [
                'q' => null,
                'municipality_code' => null,
                'blood' => [],
                'urgency' => [],
                'request_id' => $requestId,
            ],
            1,
            1,
            $nowUtc
        );
        return $result['rows'][0] ?? null;
    }

    public function findRequestContext(int $requestId, int $viewerId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ',
                    m.id AS match_id, m.status AS match_status,
                    requester.full_name AS requester_name,
                    requester.verification_status AS requester_verification_status,
                    requester.profile_picture AS requester_profile_picture,
                    requester_chapter.name AS requester_chapter_name
             FROM blood_requests br
             JOIN users requester ON requester.id = br.requester_id
             LEFT JOIN chapters requester_chapter ON requester_chapter.id = requester.chapter_id
             LEFT JOIN matches m ON m.request_id = br.id AND m.donor_id = ?' .
             self::LOCATION_JOIN . '
             WHERE br.id = ? LIMIT 1'
        );
        $stmt->execute([$viewerId, $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function countFeedRows(string $joins, array $where, array $params): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)' . $joins . ' WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, [
            '=' => '==',
            '%' => '=%',
            '_' => '=_',
            '\\' => '=\\',
        ]);
    }

    public function listMatchedOpenForDonor(int $donorId, int $limit = 20): array
    {
        $safeLimit = max(1, min($limit, 100));
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ',
                    m.status AS match_status,
                    requester.full_name AS requester_name,
                    requester.verification_status AS requester_verification_status,
                    requester.profile_picture AS requester_profile_picture,
                    requester_chapter.name AS requester_chapter_name
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             JOIN users requester ON requester.id = br.requester_id
             LEFT JOIN chapters requester_chapter ON requester_chapter.id = requester.chapter_id' .
             self::LOCATION_JOIN . "
             WHERE m.donor_id = ?
               AND br.requester_id <> ?
               AND br.status = 'OPEN'
               AND requester.role = 'member'
               AND requester.account_status = 'active'
               AND m.status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'COMPLETED')
             ORDER BY FIELD(br.urgency, 'emergency', 'urgent', 'routine'), br.created_at DESC
             LIMIT {$safeLimit}"
        );
        $stmt->execute([$donorId, $donorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateFields(int $id, array $fields): void
    {
        static $map = [
            'required_blood_type' => 'required_blood_type',
            'quantity_units' => 'quantity_units',
            'facility_name' => 'facility_name',
            'hospital_id' => 'hospital_id',
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

        $stmt = Database::pdo()->prepare('UPDATE blood_requests SET ' . implode(', ', $sets) . " WHERE id = ? AND status = 'OPEN'");
        $stmt->execute($params);
    }

    public function setStatus(int $id, string $status, ?string $expiredAtUtc = null): bool
    {
        $allowed = ['FULFILLED', 'CANCELLED', 'EXPIRED'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid request status.');
        }
        $extra = $status === 'EXPIRED' ? ', expired_at = COALESCE(expired_at, ?)' : '';
        $params = [$status];
        if ($status === 'EXPIRED') {
            $params[] = $expiredAtUtc ?? \BloodMatch\Services\AuthService::nowUtc();
        }
        $params[] = $id;

        $stmt = Database::pdo()->prepare("UPDATE blood_requests SET status = ?{$extra} WHERE id = ? AND status = 'OPEN'");
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function dueRequestIds(string $nowUtc, int $limit = 500, int $afterId = 0): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM blood_requests
             WHERE status = 'OPEN' AND needed_datetime < ? AND id > ?
             ORDER BY id ASC LIMIT " . max(1, min($limit, 500))
        );
        $stmt->execute([$nowUtc, max(0, $afterId)]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
