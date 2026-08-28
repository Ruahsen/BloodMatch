<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class BloodRequestRepository
{
    private const SAFE_COLUMNS =
        'br.id, br.requester_id, br.request_chapter_id, br.required_blood_type, br.quantity_units,
         br.facility_name, br.latitude, br.longitude, br.urgency, br.needed_datetime,
         br.status, br.review_status, br.created_at, br.updated_at, br.expired_at';

    public function create(array $r): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO blood_requests
                (requester_id, request_chapter_id, required_blood_type, quantity_units,
                 facility_name, latitude, longitude, urgency, needed_datetime, status, review_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $r['requester_id'],
            $r['request_chapter_id'],
            $r['required_blood_type'],
            $r['quantity_units'],
            $r['facility_name'],
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
        $stmt = Database::pdo()->prepare('SELECT ' . self::SAFE_COLUMNS . ' FROM blood_requests br WHERE br.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function listByRequester(int $requesterId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::SAFE_COLUMNS . ' FROM blood_requests br
             WHERE br.requester_id = ? ORDER BY br.id DESC LIMIT 100'
        );
        $stmt->execute([$requesterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateFields(int $id, array $fields): void
    {
        static $map = [
            'required_blood_type' => 'required_blood_type',
            'quantity_units' => 'quantity_units',
            'facility_name' => 'facility_name',
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

    public function expireDueBatch(string $nowUtc, int $limit = 500): array
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE blood_requests
             SET status = 'EXPIRED', expired_at = ?
             WHERE status = 'OPEN' AND needed_datetime < ?
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
