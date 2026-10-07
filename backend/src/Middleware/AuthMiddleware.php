<?php

declare(strict_types=1);

namespace BloodMatch\Middleware;

use BloodMatch\Http\Session;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Utils\Response;

final class AuthMiddleware
{
    /**
     * Idle session lifetime (seconds). Browser-session cookies carry no
     * server-side expiry, so activity is tracked per request; conservative
     * operational default, documented in docs/api.md security notes.
     */
    public const IDLE_TIMEOUT_SECONDS = 43200;

    public static function requireAuth(string $endpoint = ''): void
    {
        Session::start();
        if (empty($_SESSION['user_id'])) {
            AuditLogger::log(null, 'authz.denied', null, null, [
                'endpoint' => $endpoint,
                'reason' => 'unauthenticated',
            ]);
            Response::error('Authentication required.', 401);
            exit;
        }

        // Idle timeout: a stolen cookie is only useful inside the window.
        $lastActivity = $_SESSION['last_activity'] ?? null;
        if (!is_int($lastActivity) || (time() - $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
            AuditLogger::log((int) $_SESSION['user_id'], 'auth.session_expired', 'user', (string) (int) $_SESSION['user_id'], [
                'endpoint' => $endpoint,
                'reason' => 'idle_timeout',
            ]);
            Session::destroy();
            Response::error('Session expired. Please log in again.', 401);
            exit;
        }
        $_SESSION['last_activity'] = time();
    }

    public static function requireActiveUser(string $endpoint = ''): array
    {
        self::requireAuth($endpoint);

        $userId = (int) $_SESSION['user_id'];
        $user = (new UserRepository())->findById($userId);

        if ($user === null || (string) $user['account_status'] !== 'active') {
            AuditLogger::log(
                $user !== null ? (int) $user['id'] : $userId,
                'authz.denied',
                'user',
                (string) $userId,
                ['endpoint' => $endpoint, 'reason' => 'inactive_or_missing_account']
            );
            Response::error('Forbidden.', 403);
            exit;
        }

        // Server-side revocation: password resets, deactivations, and
        // role/chapter changes bump users.session_version; cookies carrying
        // an older epoch stop here even though PHP file sessions cannot
        // delete a peer session directly.
        $sessionVersion = (int) ($_SESSION['session_version'] ?? 1);
        $currentVersion = (int) ($user['session_version'] ?? 1);
        if ($sessionVersion !== $currentVersion) {
            AuditLogger::log((int) $user['id'], 'authz.denied', 'user', (string) $userId, [
                'endpoint' => $endpoint,
                'reason' => 'session_revoked',
            ]);
            Response::error('Forbidden.', 403);
            exit;
        }

        return $user;
    }

    public static function requireRoles(array $roles, string $endpoint = ''): array
    {
        $user = self::requireActiveUser($endpoint);
        $role = (string) $user['role'];

        if (!in_array($role, $roles, true)) {
            AuditLogger::log((int) $user['id'], 'authz.denied', 'user', (string) $user['id'], [
                'endpoint' => $endpoint,
                'reason' => 'role_not_permitted',
                'actor_role' => $role,
                'required_roles' => array_values($roles),
            ]);
            Response::error('Forbidden.', 403);
            exit;
        }

        return $user;
    }

    public static function requireChapterScope(array $actor, int $targetChapterId, string $endpoint = ''): void
    {
        if ((string) $actor['role'] === 'admin') {
            return;
        }

        $ownChapter = $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];

        if ((string) $actor['role'] !== 'officer' || $ownChapter === null || $ownChapter !== $targetChapterId) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'chapter', (string) $targetChapterId, [
                'endpoint' => $endpoint,
                'reason' => 'outside_chapter_scope',
                'actor_role' => (string) $actor['role'],
                'actor_chapter_id' => $ownChapter,
            ]);
            Response::error('Forbidden.', 403);
            exit;
        }
    }
}
