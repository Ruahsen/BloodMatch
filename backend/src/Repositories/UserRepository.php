<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;
use Throwable;

final class UserRepository
{
    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, full_name, phone, role, chapter_id, verification_status,
                    account_status, blood_type, blood_type_source, blood_type_verified,
                    date_of_birth, password_hash, donor_enrolled_at, donor_availability,
                    last_verified_donation_at, latitude, longitude
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetchColumn() !== false;
    }

    public function chapterExists(int $chapterId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM chapters WHERE id = ? LIMIT 1');
        $stmt->execute([$chapterId]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(array $user): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, full_name, phone, role, chapter_id, verification_status,
                 account_status, date_of_birth, blood_type, blood_type_source, blood_type_verified,
                 latitude, longitude)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $user['email'],
                $user['password_hash'],
                $user['full_name'],
                $user['phone'],
                $user['role'],
                $user['chapter_id'],
                $user['verification_status'],
                $user['account_status'],
                $user['date_of_birth'],
                $user['blood_type'],
                $user['blood_type_source'],
                $user['blood_type_verified'],
                $user['latitude'],
                $user['longitude'],
            ]);
        } catch (Throwable $e) {
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new Exceptions\DuplicateEntryException('Email is already registered.');
            }
            throw $e;
        }

        return (int) Database::pdo()->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);
    }

    public function setRoleAndChapter(int $userId, string $role, ?int $chapterId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET role = ?, chapter_id = ? WHERE id = ?'
        );
        $stmt->execute([$role, $chapterId, $userId]);
    }

    public function setChapter(int $userId, ?int $chapterId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET chapter_id = ? WHERE id = ?');
        $stmt->execute([$chapterId, $userId]);
    }

    public function setStatus(int $userId, string $status, ?string $deactivatedAtUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET account_status = ?, deactivated_at = ? WHERE id = ?'
        );
        $stmt->execute([$status, $deactivatedAtUtc, $userId]);
    }

    public function listAll(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $params = [];

        static $allowed = ['role', 'verification_status', 'account_status'];
        foreach ($allowed as $col) {
            if (!empty($filters[$col]) && is_string($filters[$col])) {
                $where[] = "$col = ?";
                $params[] = $filters[$col];
            }
        }
        if (!empty($filters['chapter_id']) && preg_match('/^\d+$/', (string) $filters['chapter_id'])) {
            $where[] = 'chapter_id = ?';
            $params[] = (int) $filters['chapter_id'];
        }
        if (!empty($filters['q']) && is_string($filters['q'])) {
            $where[] = '(email LIKE ? OR full_name LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like);
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $pageSize);

        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM users $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT id, email, full_name, role, chapter_id, verification_status,
                       account_status, blood_type, blood_type_verified, created_at
                FROM users $whereSql
                ORDER BY id ASC
                LIMIT " . (int) $pageSize . " OFFSET $offset";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return ['total' => $total, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function listByChapter(int $chapterId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, full_name, role, chapter_id, verification_status, account_status
             FROM users WHERE chapter_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$chapterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateProfile(int $userId, array $fields): void
    {
        static $map = [
            'full_name' => 'full_name',
            'phone' => 'phone',
            'date_of_birth' => 'date_of_birth',
            'blood_type' => 'blood_type',
            'blood_type_source' => 'blood_type_source',
            'blood_type_verified' => 'blood_type_verified',
            'latitude' => 'latitude',
            'longitude' => 'longitude',
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
        $params[] = $userId;

        $stmt = Database::pdo()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function setBloodProvenance(int $userId, string $source, bool $verified): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET blood_type_source = ?, blood_type_verified = ? WHERE id = ?'
        );
        $stmt->execute([$source, $verified ? 1 : 0, $userId]);
    }

    public function setDonorEnrollment(int $userId, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE users SET donor_enrolled_at = ?, donor_availability = 'available' WHERE id = ?"
        );
        $stmt->execute([$nowUtc, $userId]);
    }

    public function setAvailability(int $userId, string $availability): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET donor_availability = ? WHERE id = ?');
        $stmt->execute([$availability, $userId]);
    }

    public function setLastVerifiedDonation(int $userId, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET last_verified_donation_at = ? WHERE id = ?'
        );
        $stmt->execute([$nowUtc, $userId]);
    }

    public function pendingMembersByChapter(int $chapterId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id, email, full_name, date_of_birth, blood_type, blood_type_source,
                    blood_type_verified, phone, created_at
             FROM users
             WHERE role = 'member' AND verification_status = 'pending' AND account_status = 'active'
               AND chapter_id = ?
             ORDER BY created_at ASC"
        );
        $stmt->execute([$chapterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
