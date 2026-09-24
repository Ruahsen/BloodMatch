<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class NotificationRepository
{
    public function insert(array $n): ?int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO notifications
                (user_id, type, title, body, related_type, related_id, dedup_key, generation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $n['user_id'],
            $n['type'],
            $n['title'],
            $n['body'],
            $n['related_type'] ?? null,
            $n['related_id'] ?? null,
            $n['dedup_key'] ?? null,
            $n['generation'] ?? 0,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }
        return (int) Database::pdo()->lastInsertId();
    }

    public function markEmailed(int $id, string $nowUtc): void
    {
        $stmt = Database::pdo()->prepare('UPDATE notifications SET emailed_at = ? WHERE id = ?');
        $stmt->execute([$nowUtc, $id]);
    }

    public function emailCountInLastHour(int $userId, string $nowUtc): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime($nowUtc . ' UTC') - 3600);
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND emailed_at IS NOT NULL AND created_at > ?'
        );
        $stmt->execute([$userId, $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    public function listForUser(int $userId, int $page, int $pageSize, ?string $typeFilter = null, ?string $readFilter = null): array
    {
        $offset = max(0, ($page - 1) * $pageSize);

        $where = 'WHERE user_id = ?';
        $params = [$userId];

        if ($typeFilter !== null && $typeFilter !== '') {
            $where .= ' AND type = ?';
            $params[] = $typeFilter;
        }
        if ($readFilter === 'read') {
            $where .= ' AND read_at IS NOT NULL';
        } elseif ($readFilter === 'unread') {
            $where .= ' AND read_at IS NULL';
        }

        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM notifications $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            "SELECT id, type, title, body, related_type, related_id, emailed_at, read_at, created_at
             FROM notifications $where
             ORDER BY created_at DESC, id DESC
             LIMIT " . (int) $pageSize . " OFFSET $offset"
        );
        $stmt->execute($params);

        return ['total' => $total, 'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function unreadCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([\BloodMatch\Services\AuthService::nowUtc(), $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([\BloodMatch\Services\AuthService::nowUtc(), $userId]);
        return $stmt->rowCount();
    }

    public static function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
