<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\CapabilityMatrix;
use BloodMatch\Services\RequestFeedService;
use BloodMatch\Services\RequestService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class FeedController
{
    public function feed(): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.feed');

        $caps = CapabilityMatrix::evaluate($actor);
        if ($caps['browse_requests'] === false) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', null, [
                'endpoint' => 'requests.feed',
                'reason' => 'capability_browse_denied',
                'verification_status' => (string) $actor['verification_status'],
            ]);
            Response::error('Forbidden.', 403);
            return;
        }

        if (array_key_exists('latitude', $_GET) || array_key_exists('longitude', $_GET)) {
            Response::error('Location is resolved server-side from your profile.', 400, [
                'location_id' => ['Raw coordinates are never accepted.'],
            ]);
            return;
        }

        $bloodType = Request::str('blood_type');
        if ($bloodType !== null && !in_array($bloodType, RequestService::BLOOD_TYPES, true)) {
            Response::error('Invalid blood type filter.', 400, [
                'blood_type' => ['Blood type must be one of: ' . implode(', ', RequestService::BLOOD_TYPES) . '.'],
            ]);
            return;
        }

        $urgency = Request::str('urgency');
        if ($urgency !== null && !in_array($urgency, RequestService::URGENCIES, true)) {
            Response::error('Invalid urgency filter.', 400, [
                'urgency' => ['Urgency must be one of: ' . implode(', ', RequestService::URGENCIES) . '.'],
            ]);
            return;
        }

        $chapterRaw = Request::str('chapter_id');
        $chapterId = null;
        if ($chapterRaw !== null) {
            if (!preg_match('/^\d+$/', $chapterRaw)) {
                Response::error('Invalid chapter filter.', 400, [
                    'chapter_id' => ['Chapter must be a valid chapter id.'],
                ]);
                return;
            }
            $chapterId = (int) $chapterRaw;
            if (!(new UserRepository())->chapterExists($chapterId)) {
                Response::error('Invalid chapter filter.', 400, [
                    'chapter_id' => ['Chapter does not exist.'],
                ]);
                return;
            }
        }

        $nearMe = Request::str('near_me');
        $nearMeOn = $nearMe !== null && in_array(strtolower($nearMe), ['1', 'true', 'on', 'yes'], true);
        if ($nearMeOn && ($actor['latitude'] === null || $actor['longitude'] === null)) {
            Response::error('Set your location in Profile to use Near You.', 400, [
                'near_me' => ['Your profile has no location yet.'],
            ]);
            return;
        }

        $scope = Request::str('feed_scope') ?? 'all';
        if (!in_array($scope, ['all', 'match', 'critical'], true)) {
            Response::error('Invalid feed scope.', 400, [
                'feed_scope' => ['Scope must be one of: all, match, critical.'],
            ]);
            return;
        }

        $page = max(1, Request::int('page') ?? 1);
        $pageSize = min(
            RequestFeedService::PAGE_SIZE_MAX,
            max(1, Request::int('page_size') ?? RequestFeedService::PAGE_SIZE_DEFAULT)
        );

        $result = (new RequestFeedService())->getFeed(
            $actor,
            [
                'blood_type' => $bloodType,
                'urgency' => $urgency,
                'chapter_id' => $chapterId,
                'near_me' => $nearMeOn,
                'feed_scope' => $scope,
            ],
            $page,
            $pageSize
        );

        Response::success($result);
    }
}
