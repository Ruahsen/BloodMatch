<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use BloodMatch\Services\ProfilePictureStorageService;
use PDO;

final class ChatRepository
{
    public function findDirectConversationId(int $userA, int $userB): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.id 
             FROM conversations c
             INNER JOIN conversation_participants p1 ON p1.conversation_id = c.id AND p1.user_id = ?
             INNER JOIN conversation_participants p2 ON p2.conversation_id = c.id AND p2.user_id = ?
             WHERE c.type = "direct"
             LIMIT 1'
        );
        $stmt->execute([$userA, $userB]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function createDirectConversation(int $userA, int $userB): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $existing = $this->findDirectConversationId($userA, $userB);
            if ($existing !== null) {
                $pdo->rollBack();
                return $existing;
            }

            $stmt = $pdo->prepare('INSERT INTO conversations (type) VALUES ("direct")');
            $stmt->execute();
            $convId = (int) $pdo->lastInsertId();

            $partStmt = $pdo->prepare(
                'INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, CURRENT_TIMESTAMP(3)), (?, ?, NULL)'
            );
            $partStmt->execute([$convId, $userA, $convId, $userB]);

            $pdo->commit();
            return $convId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // If duplicate caught, try to find existing
            $existing = $this->findDirectConversationId($userA, $userB);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    public function isParticipant(int $conversationId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getOtherParticipant(int $conversationId, int $currentUserId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.full_name, u.profile_picture, u.role, u.verification_status, u.account_status,
                    cp.last_read_at
             FROM conversation_participants cp
             INNER JOIN users u ON u.id = cp.user_id
             WHERE cp.conversation_id = ? AND cp.user_id != ?
             LIMIT 1'
        );
        $stmt->execute([$conversationId, $currentUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'profile_picture_url' => ProfilePictureStorageService::urlFor($row['profile_picture'], (int) $row['id']),
            'role' => (string) $row['role'],
            'verification_status' => (string) $row['verification_status'],
            'account_status' => (string) $row['account_status'],
            'last_read_at' => $row['last_read_at'],
        ];
    }

    public function listConversationsForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.id AS conversation_id, c.type, c.updated_at,
                    cp.last_read_at AS my_last_read_at,
                    u.id AS other_user_id, u.full_name AS other_user_name,
                    u.profile_picture AS other_user_picture, u.role AS other_user_role,
                    u.verification_status AS other_user_verification,
                    last_m.id AS last_message_id, last_m.body AS last_message_body,
                    last_m.sender_id AS last_message_sender_id, last_m.created_at AS last_message_created_at,
                    (
                        SELECT COUNT(*)
                        FROM messages unread_m
                        WHERE unread_m.conversation_id = c.id
                          AND unread_m.sender_id != ?
                          AND (cp.last_read_at IS NULL OR unread_m.created_at > cp.last_read_at)
                    ) AS unread_count
             FROM conversation_participants cp
             INNER JOIN conversations c ON c.id = cp.conversation_id
             INNER JOIN conversation_participants cp_other ON cp_other.conversation_id = c.id AND cp_other.user_id != ?
             INNER JOIN users u ON u.id = cp_other.user_id
             LEFT JOIN messages last_m ON last_m.id = (
                 SELECT MAX(sub_m.id)
                 FROM messages sub_m
                 WHERE sub_m.conversation_id = c.id
             )
             WHERE cp.user_id = ?
             ORDER BY COALESCE(last_m.created_at, c.updated_at) DESC'
        );
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $otherId = (int) $row['other_user_id'];
            $results[] = [
                'id' => (int) $row['conversation_id'],
                'type' => (string) $row['type'],
                'updated_at' => (string) $row['updated_at'],
                'unread_count' => (int) $row['unread_count'],
                'recipient' => [
                    'id' => $otherId,
                    'full_name' => (string) $row['other_user_name'],
                    'profile_picture_url' => ProfilePictureStorageService::urlFor($row['other_user_picture'], $otherId),
                    'role' => (string) $row['other_user_role'],
                    'verification_status' => (string) $row['other_user_verification'],
                ],
                'last_message' => $row['last_message_id'] !== null ? [
                    'id' => (int) $row['last_message_id'],
                    'body' => (string) $row['last_message_body'],
                    'sender_id' => (int) $row['last_message_sender_id'],
                    'created_at' => (string) $row['last_message_created_at'],
                ] : null,
            ];
        }

        return $results;
    }

    public function listMessages(int $conversationId, ?int $afterId = null, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $params = [$conversationId];
        $whereAfter = '';

        if ($afterId !== null && $afterId > 0) {
            $whereAfter = 'AND m.id > ?';
            $params[] = $afterId;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT m.id, m.conversation_id, m.sender_id, m.body, m.created_at,
                    u.full_name AS sender_name, u.profile_picture AS sender_picture
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             WHERE m.conversation_id = ? {$whereAfter}
             ORDER BY m.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $messages = [];
        foreach ($rows as $row) {
            $senderId = (int) $row['sender_id'];
            $messages[] = [
                'id' => (int) $row['id'],
                'conversation_id' => (int) $row['conversation_id'],
                'sender_id' => $senderId,
                'sender_name' => (string) $row['sender_name'],
                'sender_picture_url' => ProfilePictureStorageService::urlFor($row['sender_picture'], $senderId),
                'body' => (string) $row['body'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $messages;
    }

    public function insertMessage(int $conversationId, int $senderId, string $body): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)'
        );
        $stmt->execute([$conversationId, $senderId, $body]);
        $msgId = (int) $pdo->lastInsertId();

        // Update conversation timestamp
        $upd = $pdo->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?');
        $upd->execute([$conversationId]);

        // Update sender's last_read_at
        $this->markRead($conversationId, $senderId);

        // Fetch inserted row with sender details
        $fetchStmt = $pdo->prepare(
            'SELECT m.id, m.conversation_id, m.sender_id, m.body, m.created_at,
                    u.full_name AS sender_name, u.profile_picture AS sender_picture
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             WHERE m.id = ?'
        );
        $fetchStmt->execute([$msgId]);
        $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'sender_id' => (int) $row['sender_id'],
            'sender_name' => (string) $row['sender_name'],
            'sender_picture_url' => ProfilePictureStorageService::urlFor($row['sender_picture'], (int) $row['sender_id']),
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE conversation_participants 
             SET last_read_at = CURRENT_TIMESTAMP(3) 
             WHERE conversation_id = ? AND user_id = ?'
        );
        $stmt->execute([$conversationId, $userId]);
    }

    public function totalUnreadCount(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(m.id)
             FROM conversation_participants cp
             INNER JOIN messages m ON m.conversation_id = cp.conversation_id
             WHERE cp.user_id = ?
               AND m.sender_id != ?
               AND (cp.last_read_at IS NULL OR m.created_at > cp.last_read_at)'
        );
        $stmt->execute([$userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function hasMessages(int $conversationId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM messages WHERE conversation_id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchColumn() !== false;
    }

    public function getMessageCount(int $conversationId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM messages WHERE conversation_id = ?'
        );
        $stmt->execute([$conversationId]);
        return (int) $stmt->fetchColumn();
    }
}
