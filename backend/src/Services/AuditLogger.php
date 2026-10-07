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
            return self::insert($actorId, $action, $targetType, $targetId, $context);
        } catch (Throwable $e) {
            // Referential race: the actor row may have vanished between the
            // session check and this insert (e.g. account deleted
            // mid-session). Preserve the event with a null actor rather
            // than losing a security-relevant row.
            if (self::isForeignKeyViolation($e) && $actorId !== null) {
                try {
                    $context['_actor_unresolved'] = $actorId;
                    return self::insert(null, $action, $targetType, $targetId, $context);
                } catch (Throwable $retry) {
                    error_log(sprintf('[audit] failed to record %s (retry): %s', $action, $retry->getMessage()));
                    return null;
                }
            }
            error_log(sprintf('[audit] failed to record %s: %s', $action, $e->getMessage()));
            return null;
        }
    }

    /**
     * Fail-closed audit for security-critical mutations: when the audit row
     * cannot be recorded, the operation must abort instead of proceeding
     * without a trail. Callers should invoke this INSIDE their transaction
     * so the mutation rolls back together with the failed audit write.
     *
     * @throws \RuntimeException when the audit row cannot be recorded.
     */
    public static function logCritical(
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = []
    ): int {
        try {
            return self::insert($actorId, $action, $targetType, $targetId, $context);
        } catch (Throwable $e) {
            if (self::isForeignKeyViolation($e) && $actorId !== null) {
                $context['_actor_unresolved'] = $actorId;
                try {
                    return self::insert(null, $action, $targetType, $targetId, $context);
                } catch (Throwable $retry) {
                    error_log(sprintf('[audit] failed to record critical %s (retry): %s', $action, $retry->getMessage()));
                    throw new \RuntimeException('Could not record the security audit trail.', 500, $retry);
                }
            }
            error_log(sprintf('[audit] failed to record critical %s: %s', $action, $e->getMessage()));
            throw new \RuntimeException('Could not record the security audit trail.', 500, $e);
        }
    }

    private static function insert(
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $context
    ): int {
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
    }

    private static function isForeignKeyViolation(Throwable $e): bool
    {
        return $e instanceof \PDOException && (string) $e->getCode() === '23000';
    }
}
