<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Admin;

use BloodMatch\Http\Session;
use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\Exceptions\DuplicateEntryException;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AuthService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class UserAdminController
{
    private const ENDPOINT = 'admin.users';

    public function index(): void
    {
        AuthMiddleware::requireRoles(['admin'], self::ENDPOINT . '.list');

        $users = (new UserRepository())->listAll(
            [
                'role' => \BloodMatch\Utils\Request::str('role'),
                'verification_status' => \BloodMatch\Utils\Request::str('verification_status'),
                'account_status' => \BloodMatch\Utils\Request::str('account_status'),
                'chapter_id' => \BloodMatch\Utils\Request::str('chapter_id'),
                'q' => \BloodMatch\Utils\Request::str('q'),
            ],
            max(1, Request::int('page') ?? 1),
            min(100, max(1, Request::int('page_size') ?? 25))
        );

        Response::success($users);
    }

    public function setRole(array $params): void
    {
        AuthMiddleware::requireRoles(['admin'], self::ENDPOINT . '.set_role');

        $actorId = (int) $_SESSION['user_id'];
        $targetId = (int) $params['id'];
        $repo = new UserRepository();

        if ($targetId === $actorId) {
            AuditLogger::log($actorId, 'authz.denied', 'user', (string) $targetId, [
                'endpoint' => self::ENDPOINT . '.set_role',
                'reason' => 'self_role_change_forbidden',
            ]);
            Response::error('You cannot change your own role.', 403);
            return;
        }

        $target = $repo->findById($targetId);
        if ($target === null) {
            Response::error('User not found.', 404);
            return;
        }

        $body = Request::json();
        $role = Request::str('role', $body);

        if (!in_array($role, ['member', 'officer', 'admin'], true)) {
            Response::error('Invalid role value.', 400, [
                'role' => ['Role must be one of: member, officer, admin.'],
            ]);
            return;
        }

        $chapterIdRaw = Request::str('chapter_id', $body);
        $chapterId = $chapterIdRaw !== null && preg_match('/^\d+$/', $chapterIdRaw) ? (int) $chapterIdRaw : null;

        if ($chapterId !== null && !$repo->chapterExists($chapterId)) {
            Response::error('Chapter does not exist.', 400, [
                'chapter_id' => ['Chapter does not exist.'],
            ]);
            return;
        }

        if ($role === 'officer') {
            $effectiveChapter = $chapterId ?? ($target['chapter_id'] !== null ? (int) $target['chapter_id'] : null);
            if ($effectiveChapter === null) {
                Response::error('An Officer must be assigned exactly one chapter.', 400, [
                    'chapter_id' => ['An Officer requires a chapter assignment.'],
                ]);
                return;
            }
        }

        try {
            $repo->setRoleAndChapter($targetId, $role, $role === 'officer'
                ? ($chapterId ?? (int) $target['chapter_id'])
                : ($chapterId ?? ($target['chapter_id'] !== null ? (int) $target['chapter_id'] : null)));
        } catch (\Throwable $e) {
            Response::error('Could not update role.', 500);
            return;
        }

        AuditLogger::log($actorId, 'admin.user.role_changed', 'user', (string) $targetId, [
            'from_role' => (string) $target['role'],
            'to_role' => $role,
            'chapter_id' => $chapterId,
        ]);

        Response::success(['user' => (new AuthService())->publicUser($repo->findById($targetId))]);
    }

    public function setChapter(array $params): void
    {
        AuthMiddleware::requireRoles(['admin'], self::ENDPOINT . '.set_chapter');

        $actorId = (int) $_SESSION['user_id'];
        $targetId = (int) $params['id'];
        $repo = new UserRepository();

        if ($targetId === $actorId) {
            AuditLogger::log($actorId, 'authz.denied', 'user', (string) $targetId, [
                'endpoint' => self::ENDPOINT . '.set_chapter',
                'reason' => 'self_chapter_change_forbidden',
            ]);
            Response::error('You cannot change your own chapter assignment.', 403);
            return;
        }

        $target = $repo->findById($targetId);
        if ($target === null) {
            Response::error('User not found.', 404);
            return;
        }

        $body = Request::json();
        $raw = Request::str('chapter_id', $body);
        $clear = array_key_exists('chapter_id', $body) && ($body['chapter_id'] === null || $body['chapter_id'] === '');

        if (!$clear && ($raw === null || !preg_match('/^\d+$/', $raw))) {
            Response::error('chapter_id must be a positive integer or null to clear.', 400);
            return;
        }
        $chapterId = $clear ? null : (int) $raw;

        if ($chapterId !== null && !$repo->chapterExists($chapterId)) {
            Response::error('Chapter does not exist.', 400, [
                'chapter_id' => ['Chapter does not exist.'],
            ]);
            return;
        }

        if ((string) $target['role'] === 'officer' && $chapterId === null) {
            Response::error('An Officer cannot have a null chapter assignment.', 422, [
                'chapter_id' => ['Officers require exactly one chapter.'],
            ]);
            return;
        }

        $repo->setChapter($targetId, $chapterId);

        AuditLogger::log($actorId, 'admin.user.chapter_assigned', 'user', (string) $targetId, [
            'from_chapter_id' => $target['chapter_id'],
            'to_chapter_id' => $chapterId,
        ]);

        Response::success(['user' => (new AuthService())->publicUser($repo->findById($targetId))]);
    }

    public function deactivate(array $params): void
    {
        $this->setStatus((int) $params['id'], 'deactivated');
    }

    public function reactivate(array $params): void
    {
        $this->setStatus((int) $params['id'], 'active');
    }

    private function setStatus(int $targetId, string $status): void
    {
        $endpoint = self::ENDPOINT . '.' . $status;
        AuthMiddleware::requireRoles(['admin'], $endpoint);

        $actorId = (int) $_SESSION['user_id'];

        if ($targetId === $actorId) {
            AuditLogger::log($actorId, 'authz.denied', 'user', (string) $targetId, [
                'endpoint' => $endpoint,
                'reason' => 'self_status_change_forbidden',
            ]);
            Response::error('You cannot change your own account status.', 403);
            return;
        }

        $repo = new UserRepository();
        $target = $repo->findById($targetId);
        if ($target === null) {
            Response::error('User not found.', 404);
            return;
        }

        $nowUtc = AuthService::nowUtc();
        $deactivatedAt = $status === 'deactivated' ? $nowUtc : null;

        $alreadyInState =
            ($status === 'deactivated' && (string) $target['account_status'] === 'deactivated')
            || ($status === 'active' && (string) $target['account_status'] === 'active');
        if (!$alreadyInState) {
            $repo->setStatus($targetId, $status, $deactivatedAt);
            $auditId = AuditLogger::log(
                $actorId,
                $status === 'deactivated' ? 'admin.user.deactivated' : 'admin.user.reactivated',
                'user',
                (string) $targetId,
                ['previous_status' => (string) $target['account_status']]
            );

            \BloodMatch\Services\NotificationService::notify(
                $targetId,
                'account.status_changed',
                $status === 'deactivated' ? 'Your account has been deactivated' : 'Your account has been reactivated',
                $status === 'deactivated'
                    ? 'A BloodMatch officer deactivated your account. Contact an officer for details.'
                    : 'Your account was reactivated. Welcome back!',
                [
                    'related_type' => 'account',
                    'related_id' => $targetId,
                    'dedup_key' => \BloodMatch\Services\NotificationService::dedupAccount($targetId, $status, $auditId),
                    'email' => \BloodMatch\Services\NotificationService::EMAIL_NORMAL,
                ]
            );
        }

        $fresh = $repo->findById($targetId);
        Response::success([
            'user' => [
                'id' => (int) $fresh['id'],
                'email' => (string) $fresh['email'],
                'account_status' => (string) $fresh['account_status'],
                'verification_status' => (string) $fresh['verification_status'],
                'role' => (string) $fresh['role'],
            ],
        ]);
    }
}
