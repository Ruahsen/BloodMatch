<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class DocumentRepository
{
    public function create(int $userId, string $docType, array $stored, string $nowUtc): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO member_documents (user_id, doc_type, stored_name, mime_type, original_ext, size_bytes, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $docType,
            $stored['stored_name'],
            $stored['mime_type'],
            $stored['original_ext'],
            $stored['size_bytes'],
            $nowUtc,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function listByUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, doc_type, mime_type, original_ext, size_bytes, uploaded_at
             FROM member_documents WHERE user_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM member_documents WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function hasType(int $userId, string $docType): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM member_documents WHERE user_id = ? AND doc_type = ? LIMIT 1'
        );
        $stmt->execute([$userId, $docType]);
        return $stmt->fetchColumn() !== false;
    }
}
