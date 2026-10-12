<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;
use Throwable;

final class UserRepository
{
    public function findProfileDisplayById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.email, u.full_name, u.role, u.chapter_id,
                    u.verification_status, u.account_status, u.blood_type,
                    u.profile_picture, u.created_at, c.name AS chapter_name
             FROM users u
             LEFT JOIN chapters c ON c.id = u.chapter_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.email, u.full_name, u.first_name, u.middle_name, u.last_name, u.created_at,
                    u.phone, u.role, u.chapter_id, c.name AS chapter_name,
                    u.verification_status, u.account_status, u.blood_type, u.blood_type_source,
                    u.blood_type_verified, u.date_of_birth, u.password_hash, u.donor_enrolled_at,
                    u.donor_availability, u.last_verified_donation_at, u.profile_picture,
                    u.location_id, u.latitude, u.longitude, u.email_verified_at,
                    u.email_code_hash, u.email_code_expires_at,
                    EXISTS (SELECT 1 FROM member_documents consent
                            WHERE consent.user_id = u.id AND consent.doc_type = \'parental_consent\') AS has_parental_consent
             FROM users u
             LEFT JOIN chapters c ON c.id = u.chapter_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.*,
                    EXISTS (SELECT 1 FROM member_documents consent
                            WHERE consent.user_id = u.id AND consent.doc_type = \'parental_consent\') AS has_parental_consent
             FROM users u WHERE u.id = ? LIMIT 1 FOR UPDATE'
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
                (email, password_hash, first_name, middle_name, last_name, phone, role, chapter_id, verification_status,
                 account_status, date_of_birth, blood_type, blood_type_source, blood_type_verified,
                 latitude, longitude, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $user['email'],
                $user['password_hash'],
                $user['first_name'],
                $user['middle_name'],
                $user['last_name'],
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
                $user['email_verified_at'] ?? null,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new Exceptions\DuplicateEntryException('Email is already registered.');
            }
            throw $e;
        }

        return (int) Database::pdo()->lastInsertId();
    }

    public function setEmailCode(int $userId, string $codeHash, string $expiresAtUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET email_code_hash = ?, email_code_expires_at = ? WHERE id = ?'
        );
        $stmt->execute([$codeHash, $expiresAtUtc, $userId]);
    }

    public function markEmailVerified(int $userId, string $verifiedAtUtc): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET email_verified_at = ?, email_code_hash = NULL, email_code_expires_at = NULL WHERE id = ?'
        );
        $stmt->execute([$verifiedAtUtc, $userId]);
    }

    public function clearEmailCode(int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET email_code_hash = NULL, email_code_expires_at = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);
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

    public function pendingVerificationMembers(array $filters, int $page, int $pageSize): array
    {
        $where = [
            "u.role = 'member'",
            "u.verification_status = 'pending'",
            "u.account_status = 'active'",
            "EXISTS (
                SELECT 1 FROM member_documents required_id
                WHERE required_id.user_id = u.id AND required_id.doc_type = 'national_id'
            )",
        ];
        $params = [];

        if (!empty($filters['chapter_id']) && preg_match('/^\d+$/', (string) $filters['chapter_id'])) {
            $where[] = 'u.chapter_id = ?';
            $params[] = (int) $filters['chapter_id'];
        }
        if (!empty($filters['q']) && is_string($filters['q'])) {
            $where[] = '(u.email LIKE ? OR u.full_name LIKE ?)';
            $like = '%' . trim($filters['q']) . '%';
            array_push($params, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $pageSize);

        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sortOrder = strtolower((string) ($filters['sort'] ?? 'oldest')) === 'newest' ? 'DESC' : 'ASC';

        $stmt = Database::pdo()->prepare(
            "SELECT u.id, u.email, u.full_name, u.chapter_id, u.date_of_birth,
                    u.blood_type, u.blood_type_source, u.blood_type_verified,
                    u.verification_status, u.created_at, c.name AS chapter_name,
                    COUNT(md.id) AS national_id_count,
                    MAX(md.uploaded_at) AS latest_national_id_uploaded_at
             FROM users u
             LEFT JOIN chapters c ON c.id = u.chapter_id
             INNER JOIN member_documents md ON md.user_id = u.id AND md.doc_type = 'national_id'
             WHERE $whereSql
             GROUP BY u.id, u.email, u.full_name, u.chapter_id, u.date_of_birth,
                      u.blood_type, u.blood_type_source, u.blood_type_verified,
                      u.verification_status, u.created_at, c.name
             ORDER BY COALESCE(MAX(md.uploaded_at), u.created_at) {$sortOrder}, u.id {$sortOrder}
             LIMIT " . (int) $pageSize . " OFFSET " . (int) $offset
        );
        $stmt->execute($params);

        return ['total' => $total, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function activeIdsByRole(string $role): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM users WHERE role = ? AND account_status = 'active' ORDER BY id ASC"
        );
        $stmt->execute([$role]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function activeOfficersByChapter(int $chapterId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id FROM users WHERE role = 'officer' AND chapter_id = ? AND account_status = 'active' ORDER BY id ASC"
        );
        $stmt->execute([$chapterId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
            'first_name' => 'first_name',
            'middle_name' => 'middle_name',
            'last_name' => 'last_name',
            'phone' => 'phone',
            'date_of_birth' => 'date_of_birth',
            'blood_type' => 'blood_type',
            'blood_type_source' => 'blood_type_source',
            'blood_type_verified' => 'blood_type_verified',
            'location_id' => 'location_id',
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

    public function setProfilePicture(int $userId, string $storedName): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
        $stmt->execute([$storedName, $userId]);
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
