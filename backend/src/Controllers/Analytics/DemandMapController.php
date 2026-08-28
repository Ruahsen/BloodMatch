<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Analytics;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AnalyticsRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class DemandMapController
{
    private const ENDPOINT = 'analytics.demand_map';

    public function index(): void
    {
        $actor = AuthMiddleware::requireRoles(['admin', 'officer'], self::ENDPOINT);

        $scopedChapterId = null;
        if ($actor['role'] === 'officer') {
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

            $scopedChapterId = $ownChapter;
        }

        $filters = [
            'chapter_id' => $scopedChapterId ?? Request::int('chapter_id'),
            'blood_type' => Request::str('blood_type'),
            'urgency' => Request::str('urgency'),
            'days' => Request::int('days'),
        ];

        $data = (new AnalyticsRepository())->getDemandMap($filters, $scopedChapterId);

        Response::success(['chapters' => $data]);
    }
}
