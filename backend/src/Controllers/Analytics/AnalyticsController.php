<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Analytics;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AnalyticsRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class AnalyticsController
{
    private const ENDPOINT = 'analytics.summary';

    public function summary(): void
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
            'date_from' => Request::str('date_from'),
            'date_to' => Request::str('date_to'),
        ];

        $data = (new AnalyticsRepository())->getAnalyticsSummary($filters, $scopedChapterId);

        Response::success($data);
    }
}
