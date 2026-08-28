<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use Throwable;

final class AuditLogger
{
    public static function log(
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = []
    ): ?int {
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log (actor_id, action, target_type, target_id, context)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ctx = $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE);
            $stmt->execute([
                $actorId,
                $action,
                $targetType,
                $targetId,
                $ctx,
            ]);
            return (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            error_log(sprintf('[audit] failed to record %s: %s', $action, $e->getMessage()));
            return null;
        }
    }
}
