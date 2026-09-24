<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Officer;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AnalyticsRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class OfficerDashboardController
{
    private const ENDPOINT = 'officer.dashboard';

    public function index(): void
    {
        $actor = AuthMiddleware::requireRoles(['officer'], self::ENDPOINT);

        $ownChapter = $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];
        if ($ownChapter === null) {
            AuthMiddleware::requireChapterScope($actor, -1, self::ENDPOINT);
            return;
        }

        $requestedChapter = Request::int('chapter_id');
        if ($requestedChapter !== null && $requestedChapter !== $ownChapter) {
            AuthMiddleware::requireChapterScope($actor, $requestedChapter, self::ENDPOINT);
            return;
        }

        $data = (new AnalyticsRepository())->getOfficerDashboard($ownChapter);

        Response::success($data);
    }
}
