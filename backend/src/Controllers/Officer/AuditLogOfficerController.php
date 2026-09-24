<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Officer;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AuditLogRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class AuditLogOfficerController
{
    private const ENDPOINT = 'officer.audit_logs';

    public function index(): void
    {
        $actor = AuthMiddleware::requireRoles(['officer'], self::ENDPOINT . '.list');

        $ownChapter = $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];
        if ($ownChapter === null) {
            AuthMiddleware::requireChapterScope($actor, -1, self::ENDPOINT . '.list');
            return;
        }

        $requestedChapter = Request::int('chapter_id');
        if ($requestedChapter !== null && $requestedChapter !== $ownChapter) {
            AuthMiddleware::requireChapterScope($actor, $requestedChapter, self::ENDPOINT . '.list');
            return;
        }

        $filters = [
            'action' => Request::str('action'),
            'actor_id' => Request::int('actor_id'),
            'target_type' => Request::str('target_type'),
            'target_id' => Request::str('target_id'),
            'date_from' => Request::str('date_from'),
            'date_to' => Request::str('date_to'),
        ];

        $page = max(1, Request::int('page') ?? 1);
        $pageSize = min(100, max(1, Request::int('page_size') ?? 25));

        $result = (new AuditLogRepository())->listForChapter($ownChapter, $filters, $page, $pageSize);

        Response::success($result);
    }
}
