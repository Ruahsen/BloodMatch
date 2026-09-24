<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\RequestService;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use RuntimeException;

final class RequestsController
{
    public function create(): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.create');

        $throttleKey = 'mutation:req_create:' . (int) $actor['id'];
        if (!(new \BloodMatch\Repositories\AuthThrottleRepository())->hitAndCheckRateLimit($throttleKey, 10, 10, \BloodMatch\Services\AuthService::nowUtc())) {
            Response::error('Too many requests created. Please wait before creating more blood requests.', 429);
            return;
        }
        try {
            RequestService::assertCanCreate($actor);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 403);
            return;
        }

        try {
            $fields = RequestService::validatePayload(Request::json(), partial: false);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        }

        $repo = new BloodRequestRepository();
        $id = $repo->create([
            'requester_id' => (int) $actor['id'],
            'request_chapter_id' => RequestService::chapterIdOf($actor),
            'required_blood_type' => $fields['required_blood_type'],
            'quantity_units' => $fields['quantity_units'],
            'facility_name' => $fields['facility_name'],
            'latitude' => $fields['latitude'],
            'longitude' => $fields['longitude'],
            'urgency' => $fields['urgency'],
            'needed_datetime' => $fields['needed_datetime'],
            'review_status' => RequestService::reviewStatusFor($actor),
        ]);

        AuditLogger::log((int) $actor['id'], 'request.created', 'blood_request', (string) $id, [
            'review_status' => RequestService::reviewStatusFor($actor),
            'urgency' => $fields['urgency'],
        ]);

        $matchSummary = null;
        try {
            $matchSummary = (new \BloodMatch\Services\MatchService())
                ->generateForRequest($id, true, 'request_created', (int) $actor['id']);
        } catch (\Throwable $e) {
            error_log('[matches] auto-generation failed for request ' . $id . ': ' . $e->getMessage());
        }

        $fresh = $repo->findById($id);
        $requester = (new UserRepository())->findById((int) $actor['id']);
        Response::success([
            'request' => RequestService::publicView($fresh, $requester),
            'matching' => $matchSummary,
        ], 201);
    }

    public function mine(): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.mine');
        $rows = (new BloodRequestRepository())->listByRequester((int) $actor['id']);
        Response::success(['requests' => array_map(
            static fn (array $row): array => RequestService::publicView($row, null),
            $rows
        )]);
    }

    public function show(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.show');
        $row = $this->loadForAccess($actor, (int) $params['id'], 'requests.show');
        if ($row === null) {
            return;
        }
        $requester = (new UserRepository())->findById((int) $row['requester_id']);
        Response::success(['request' => RequestService::publicView($row, $requester)]);
    }

    public function update(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.update');
        $repo = new BloodRequestRepository();
        $id = (int) $params['id'];

        $row = $repo->findById($id);
        if ($row === null || (int) $row['requester_id'] !== (int) $actor['id']) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', (string) $id, [
                'endpoint' => 'requests.update',
                'reason' => $row === null ? 'not_found' : 'not_owner',
            ]);
            Response::error('Request not found.', $row === null ? 404 : 403);
            return;
        }

        if ((string) $row['status'] !== 'OPEN') {
            Response::error('Only OPEN requests can be edited.', 409);
            return;
        }

        try {
            $fields = RequestService::validatePayload(Request::json(), partial: true);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        }

        if ($fields === []) {
            Response::error('No editable fields supplied.', 400);
            return;
        }

        $materialGroups = RequestService::materialChangedFields($row, $fields);
        $repo->updateFields($id, $fields);

        if ($materialGroups !== []) {
            AuditLogger::log((int) $actor['id'], 'request.material_change', 'blood_request', (string) $id, [
                'groups' => $materialGroups,
            ]);
            try {
                (new \BloodMatch\Services\MatchService())
                    ->generateForRequest($id, true, 'material_change', (int) $actor['id']);
            } catch (\Throwable $e) {
                error_log('[matches] regeneration failed for request ' . $id . ': ' . $e->getMessage());
            }
        } else {
            AuditLogger::log((int) $actor['id'], 'request.updated', 'blood_request', (string) $id, [
                'material' => false,
            ]);
        }

        $fresh = $repo->findById($id);
        Response::success(['request' => RequestService::publicView($fresh, $actor)]);
    }

    public function matches(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.matches');
        $requestId = (int) $params['id'];
        $repo = new BloodRequestRepository();
        $row = $repo->findById($requestId);

        if ($row === null) {
            Response::error('Request not found.', 404);
            return;
        }

        $isOwner = (int) $row['requester_id'] === (int) $actor['id'];
        $isAdmin = (string) $actor['role'] === 'admin';
        $isSameChapterOfficer = (string) $actor['role'] === 'officer'
            && $actor['chapter_id'] !== null
            && (int) $actor['chapter_id'] === (int) $row['request_chapter_id'];

        if ($isOwner || $isAdmin || $isSameChapterOfficer) {
            Response::success(['matches' => (new \BloodMatch\Services\MatchService())->privacySafeMatches($requestId)]);
            return;
        }

        // Matched donors may view ONLY their own entry for this request.
        $ownMatch = (new \BloodMatch\Repositories\MatchRepository())
            ->findByRequestAndDonor($requestId, (int) $actor['id']);

        if ($ownMatch !== null) {
            Response::success([
                'matches' => (new \BloodMatch\Services\MatchService())
                    ->privacySafeMatches($requestId, (int) $actor['id']),
            ]);
            return;
        }

        AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', (string) $requestId, [
            'endpoint' => 'requests.matches',
            'reason' => 'not_owner_officer_or_matched_donor',
        ]);
        Response::error('Forbidden.', 403);
    }

    public function rematch(array $params): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], 'requests.rematch');
        $repo = new BloodRequestRepository();
        $row = $repo->findById((int) $params['id']);

        if ($row === null) {
            Response::error('Request not found.', 404);
            return;
        }

        AuthMiddleware::requireChapterScope(
            $actor,
            $row['request_chapter_id'] !== null ? (int) $row['request_chapter_id'] : -1,
            'requests.rematch'
        );

        try {
            $summary = (new \BloodMatch\Services\MatchService())
                ->generateForRequest((int) $row['id'], false, 'manual_rematch', (int) $actor['id']);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
            return;
        }

        Response::success(['matching' => $summary]);
    }

    public function cancel(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('requests.cancel');
        $repo = new BloodRequestRepository();
        $id = (int) $params['id'];

        $row = $repo->findById($id);
        if ($row === null) {
            Response::error('Request not found.', 404);
            return;
        }

        $isOwner = (int) $row['requester_id'] === (int) $actor['id'];
        $isAdmin = (string) $actor['role'] === 'admin';
        $isChapterOfficer = (string) $actor['role'] === 'officer'
            && $actor['chapter_id'] !== null
            && (int) $actor['chapter_id'] === (int) $row['request_chapter_id'];

        if (!$isOwner && !$isAdmin && !$isChapterOfficer) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', (string) $id, [
                'endpoint' => 'requests.cancel',
                'reason' => 'not_owner_or_out_of_chapter',
                'actor_role' => (string) $actor['role'],
            ]);
            Response::error('Forbidden.', 403);
            return;
        }

        if ((string) $row['status'] !== 'OPEN') {
            Response::error("Only OPEN requests can be cancelled (current: {$row['status']}).", 409);
            return;
        }

        $repo->setStatus($id, 'CANCELLED');
        AuditLogger::log((int) $actor['id'], 'request.cancelled', 'blood_request', (string) $id, [
            'via' => $isOwner ? 'owner' : ($isAdmin ? 'admin' : 'officer'),
        ]);

        \BloodMatch\Services\NotificationService::notify(
            (int) $row['requester_id'],
            'request.cancelled',
            'Blood request cancelled',
            sprintf('Your blood request #%d has been cancelled.', $id),
            [
                'related_type' => 'blood_request',
                'related_id' => $id,
                'dedup_key' => "request:{$id}:cancelled",
            ]
        );

        Response::success(['request' => RequestService::publicView($repo->findById($id), null)]);
    }

    private function loadForAccess(array $actor, int $id, string $endpoint): ?array
    {
        $repo = new BloodRequestRepository();
        $row = $repo->findById($id);

        if ($row === null) {
            Response::error('Request not found.', 404);
            return null;
        }

        $isOwner = (int) $row['requester_id'] === (int) $actor['id'];
        $isAdmin = (string) $actor['role'] === 'admin';
        $isSameChapterOfficer = (string) $actor['role'] === 'officer'
            && $actor['chapter_id'] !== null
            && (int) $actor['chapter_id'] === (int) $row['request_chapter_id'];

        if (!$isOwner && !$isAdmin && !$isSameChapterOfficer) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', (string) $id, [
                'endpoint' => $endpoint,
                'reason' => 'not_owner_or_out_of_chapter',
                'actor_role' => (string) $actor['role'],
            ]);
            Response::error('Forbidden.', 403);
            return null;
        }

        return $row;
    }
}
