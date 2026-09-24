<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class AuditLogRepository
{
    private const SENSITIVE_CONTEXT_KEYS = [
        'password',
        'password_hash',
        'token',
        'token_hash',
        'stored_name',
        'secret',
        'authorization',
        'cookie',
    ];

    public function listAll(array $filters, int $page = 1, int $pageSize = 25): array
    {
        return $this->queryLogs($filters, null, $page, $pageSize);
    }

    public function listForChapter(int $chapterId, array $filters, int $page = 1, int $pageSize = 25): array
    {
        return $this->queryLogs($filters, $chapterId, $page, $pageSize);
    }

    private function queryLogs(array $filters, ?int $scopedChapterId, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $where = [];
        $params = [];

        $targetChapterId = $scopedChapterId ?? (isset($filters['chapter_id']) && is_numeric($filters['chapter_id']) ? (int) $filters['chapter_id'] : null);

        if ($targetChapterId !== null) {
            $where[] = '(
                (a.target_type = \'user\' AND a.target_id REGEXP \'^[0-9]+$\' AND CAST(a.target_id AS UNSIGNED) IN (SELECT id FROM users WHERE chapter_id = ?))
                OR (a.target_type = \'blood_request\' AND a.target_id REGEXP \'^[0-9]+$\' AND CAST(a.target_id AS UNSIGNED) IN (SELECT id FROM blood_requests WHERE request_chapter_id = ?))
                OR (a.target_type = \'member_document\' AND a.target_id REGEXP \'^[0-9]+$\' AND CAST(a.target_id AS UNSIGNED) IN (SELECT md.id FROM member_documents md JOIN users u_d ON u_d.id = md.user_id WHERE u_d.chapter_id = ?))
                OR (a.target_type = \'donation_report\' AND a.target_id REGEXP \'^[0-9]+$\' AND CAST(a.target_id AS UNSIGNED) IN (SELECT dr.id FROM donation_reports dr JOIN matches m ON m.id = dr.match_id JOIN blood_requests br ON br.id = m.request_id WHERE br.request_chapter_id = ?))
                OR (a.target_type = \'chapter\' AND a.target_id REGEXP \'^[0-9]+$\' AND CAST(a.target_id AS UNSIGNED) = ?)
                OR (
                    (a.target_type IS NULL OR a.target_type NOT IN (\'user\', \'blood_request\', \'member_document\', \'donation_report\', \'chapter\'))
                    AND a.actor_id IN (SELECT id FROM users WHERE chapter_id = ?)
                )
            )';
            $params[] = $targetChapterId;
            $params[] = $targetChapterId;
            $params[] = $targetChapterId;
            $params[] = $targetChapterId;
            $params[] = $targetChapterId;
            $params[] = $targetChapterId;
        }

        if (isset($filters['action']) && is_string($filters['action']) && trim($filters['action']) !== '') {
            $act = trim($filters['action']);
            if (str_contains($act, '*')) {
                $where[] = 'a.action LIKE ?';
                $params[] = str_replace('*', '%', $act);
            } else {
                $where[] = 'a.action = ?';
                $params[] = $act;
            }
        }

        if (isset($filters['actor_id']) && is_numeric($filters['actor_id'])) {
            $where[] = 'a.actor_id = ?';
            $params[] = (int) $filters['actor_id'];
        }

        if (isset($filters['target_type']) && is_string($filters['target_type']) && trim($filters['target_type']) !== '') {
            $where[] = 'a.target_type = ?';
            $params[] = trim($filters['target_type']);
        }

        if (isset($filters['target_id']) && is_string($filters['target_id']) && trim($filters['target_id']) !== '') {
            $where[] = 'a.target_id = ?';
            $params[] = trim($filters['target_id']);
        }

        if (isset($filters['date_from']) && is_string($filters['date_from']) && trim($filters['date_from']) !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = trim($filters['date_from']);
        }

        if (isset($filters['date_to']) && is_string($filters['date_to']) && trim($filters['date_to']) !== '') {
            $where[] = 'a.created_at <= ?';
            $params[] = trim($filters['date_to']);
        }

        $whereClause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $pdo = Database::pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $whereClause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $pageSize));

        $sql = "SELECT a.id, a.actor_id, u.full_name AS actor_name, u.role AS actor_role,
                       u.chapter_id AS actor_chapter_id, c.name AS actor_chapter_name,
                       a.action, a.target_type, a.target_id, a.context, a.created_at
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.actor_id
                LEFT JOIN chapters c ON c.id = u.chapter_id
                $whereClause
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT $pageSize OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sanitizedLogs = array_map(function (array $row): array {
            $context = null;
            if ($row['context'] !== null && is_string($row['context'])) {
                $decoded = json_decode($row['context'], true);
                if (is_array($decoded)) {
                    $context = $this->sanitizeContext($decoded);
                }
            }

            return [
                'id' => (int) $row['id'],
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'actor_name' => $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
                'actor_role' => $row['actor_role'] !== null ? (string) $row['actor_role'] : null,
                'actor_chapter_id' => $row['actor_chapter_id'] !== null ? (int) $row['actor_chapter_id'] : null,
                'actor_chapter_name' => $row['actor_chapter_name'] !== null ? (string) $row['actor_chapter_name'] : null,
                'action' => (string) $row['action'],
                'target_type' => $row['target_type'] !== null ? (string) $row['target_type'] : null,
                'target_id' => $row['target_id'] !== null ? (string) $row['target_id'] : null,
                'context' => $context,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => $totalPages,
            'logs' => $sanitizedLogs,
        ];
    }

    private function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $k => $v) {
            $lowerKey = strtolower((string) $k);
            if (in_array($lowerKey, self::SENSITIVE_CONTEXT_KEYS, true)) {
                continue;
            }
            if (is_array($v)) {
                $clean[$k] = $this->sanitizeContext($v);
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }
}
