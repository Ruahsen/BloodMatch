<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Admin;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AnalyticsRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class AdminDashboardController
{
    private const ENDPOINT = 'admin.dashboard';

    public function index(): void
    {
        AuthMiddleware::requireRoles(['admin'], self::ENDPOINT);

        $chapterId = Request::int('chapter_id');
        $data = (new AnalyticsRepository())->getAdminDashboard($chapterId);

        Response::success($data);
    }
}
