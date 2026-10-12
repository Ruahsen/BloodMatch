<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Repositories\MatchRepository;
use RuntimeException;

final class RequestLifecycleService
{
    public static function assertCanEdit(array $actor, array $request): void
    {
        WorkflowLockService::assertActiveActor($actor);
        if ((int) $request['requester_id'] !== (int) $actor['id']) {
            throw new RuntimeException('Request not found.', 403);
        }
    }

    public static function cancellationMode(array $actor, array $request): string
    {
        WorkflowLockService::assertActiveActor($actor);
        if ((int) $actor['id'] === (int) $request['requester_id']) { return 'owner'; }
        if ((string) $actor['role'] === 'admin') { return 'admin'; }
        if ((string) $actor['role'] === 'officer' && $actor['chapter_id'] !== null
            && $request['request_chapter_id'] !== null
            && (int) $actor['chapter_id'] === (int) $request['request_chapter_id']) { return 'officer'; }
        throw new RuntimeException('Forbidden.', 403);
    }

    public function update(array $actor, int $requestId, array $body): array
    {
        try {
            $groups = WorkflowLockService::transaction(function () use ($actor, $requestId, $body): array {
                $request = WorkflowLockService::request($requestId);
                $users = WorkflowLockService::users([(int) $actor['id'], (int) $request['requester_id']]);
                self::assertCanEdit($users[(int) $actor['id']], $request);
                WorkflowLockService::assertOpenBeforeDeadline($request);
                $fields = RequestService::validatePayload($body, partial: true);
                if ($fields === []) { throw new RuntimeException('No editable fields supplied.', 400); }
                // Recheck time after reference-data validation, just before writing.
                $nowUtc = AuthService::nowUtc();
                WorkflowLockService::assertOpenBeforeDeadline($request, $nowUtc);
                WorkflowLockService::assertOpenBeforeDeadline(array_replace($request, $fields), $nowUtc);
                $groups = RequestService::materialChangedFields($request, $fields);
                (new BloodRequestRepository())->updateFields($requestId, $fields);
                AuditLogger::log((int) $actor['id'], $groups === [] ? 'request.updated' : 'request.material_change',
                    'blood_request', (string) $requestId, $groups === [] ? ['material' => false] : ['groups' => $groups]);
                return $groups;
            });
        } catch (RuntimeException $e) {
            $this->logDenied($actor, $requestId, 'requests.update', $e);
            throw $e;
        }
        // Generation has its own request lock and refuses a terminal state if closure won meanwhile.
        if ($groups !== []) {
            try { (new MatchService())->generateForRequest($requestId, true, 'material_change', (int) $actor['id']); }
            catch (\Throwable $e) { error_log('[matches] regeneration failed for request ' . $requestId . ': ' . $e->getMessage()); }
        }
        return (new BloodRequestRepository())->findById($requestId);
    }

    public function cancel(array $actor, int $requestId): array
    {
        try {
            WorkflowLockService::transaction(function () use ($actor, $requestId): void {
                $request = WorkflowLockService::request($requestId);
                $users = WorkflowLockService::users([(int) $actor['id'], (int) $request['requester_id']]);
                $via = self::cancellationMode($users[(int) $actor['id']], $request);
                WorkflowLockService::assertOpen($request);
                $closed = $this->closeLocked($requestId, 'CANCELLED');
                AuditLogger::log((int) $actor['id'], 'request.cancelled', 'blood_request', (string) $requestId,
                    ['via' => $via, 'closed_matches' => $closed]);
                NotificationService::notify((int) $request['requester_id'], 'request.cancelled', 'Blood request cancelled',
                    sprintf('Your blood request #%d has been cancelled.', $requestId), [
                        'related_type' => 'blood_request', 'related_id' => $requestId,
                        'dedup_key' => "request:{$requestId}:cancelled",
                        'email' => NotificationService::EMAIL_NORMAL,
                    ]);
            });
        } catch (RuntimeException $e) {
            $this->logDenied($actor, $requestId, 'requests.cancel', $e);
            throw $e;
        }
        return (new BloodRequestRepository())->findById($requestId);
    }

    public function expireOne(int $requestId, string $cutoffUtc): ?array
    {
        return WorkflowLockService::transaction(function () use ($requestId, $cutoffUtc): ?array {
            $request = (new BloodRequestRepository())->findByIdForUpdate($requestId);
            if ($request === null || (string) $request['status'] !== 'OPEN'
                || !RequestService::hasDeadlinePassed($request, $cutoffUtc)) { return null; }
            // Use the same request -> users -> matches order as interactive workflows.
            WorkflowLockService::users([(int) $request['requester_id']]);
            $closed = $this->closeLocked($requestId, 'EXPIRED', $cutoffUtc);
            NotificationService::notify((int) $request['requester_id'], 'request.expired', 'Blood request expired',
                sprintf('Your blood request #%d passed its needed date and has expired.', $requestId), [
                    'related_type' => 'blood_request', 'related_id' => $requestId,
                    'dedup_key' => 'request:' . $requestId . ':expired',
                    'email' => NotificationService::EMAIL_NORMAL,
                ]);
            return ['id' => $requestId, 'requester_id' => (int) $request['requester_id'], 'closed_matches' => $closed];
        });
    }

    /** Each yielded row has already committed; failures cannot hide earlier committed counts. */
    public function expireDueRequests(string $cutoffUtc, int $limit = 500): \Generator
    {
        $cursor = 0;
        do {
            $ids = (new BloodRequestRepository())->dueRequestIds($cutoffUtc, $limit, $cursor);
            foreach ($ids as $id) {
                $row = $this->expireOne($id, $cutoffUtc);
                $cursor = $id;
                if ($row !== null) { yield $row; }
            }
        } while ($ids !== []);
    }

    private function closeLocked(int $requestId, string $status, ?string $expiredAt = null): int
    {
        if (!(new BloodRequestRepository())->setStatus($requestId, $status, $expiredAt)) {
            throw new RuntimeException('This request is no longer active.', 409);
        }
        // Only offers change here. COMPLETED matches, all reports and donor windows remain intact.
        return MatchRepository::closeUnfinishedForRequest($requestId);
    }

    private function logDenied(array $actor, int $requestId, string $endpoint, RuntimeException $e): void
    {
        if ((int) $e->getCode() === 403) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', (string) $requestId,
                ['endpoint' => $endpoint, 'reason' => 'locked_request_authorization_denied']);
        }
    }
}
