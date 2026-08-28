<?php

declare(strict_types=1);

namespace BloodMatch\Middleware;

use BloodMatch\Http\Session;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Utils\Response;

final class AuthMiddleware
{
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
