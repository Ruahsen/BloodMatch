<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Admin;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AuditLogRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class AuditLogAdminController
{
    private const ENDPOINT = 'admin.audit_logs';

    public function index(): void
    {
        AuthMiddleware::requireRoles(['admin'], self::ENDPOINT . '.list');

        $filters = [
            'action' => Request::str('action'),
            'actor_id' => Request::int('actor_id'),
            'target_type' => Request::str('target_type'),
            'target_id' => Request::str('target_id'),
            'chapter_id' => Request::int('chapter_id'),
            'date_from' => Request::str('date_from'),
            'date_to' => Request::str('date_to'),
        ];

        $page = max(1, Request::int('page') ?? 1);
        $pageSize = min(100, max(1, Request::int('page_size') ?? 25));

        $result = (new AuditLogRepository())->listAll($filters, $page, $pageSize);

        Response::success($result);
    }
}
