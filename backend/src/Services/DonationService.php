<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\DonationReportRepository;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\Exceptions\ValidationException;
use RuntimeException;
use Throwable;

final class DonationService
{
    public function submit(int $donorId, int $matchId, ?string $note): array
    {
        $repo = new MatchRepository();
        $match = $repo->findByIdDetailed($matchId);

        if ($match === null) {
            throw new RuntimeException('Match not found.', 404);
        }
        if ((int) $match['donor_id'] !== $donorId) {
            AuditLogger::log($donorId, 'authz.denied', null, null, [
                'endpoint' => 'donation_reports.submit',
                'reason' => 'not_match_owner',
            ]);
            throw new RuntimeException('You can only report donations for your own matches.', 403);
        }
        if ((string) $match['status'] !== 'RESPONDED') {
            throw new RuntimeException('Only responded matches can receive a donation report.', 409);
        }
        if ((string) $match['request_status'] !== 'OPEN') {
            throw new RuntimeException('This request is no longer active.', 409);
        }

        $result = WorkflowLockService::transaction(function () use ($donorId, $matchId, $note, $match, $repo): array {
            $request = WorkflowLockService::request((int) $match['request_id']);
            $users = WorkflowLockService::users([$donorId, (int) $request['requester_id']]);
            WorkflowLockService::assertActiveActor($users[$donorId]);
            $currentMatch = $repo->findByIdForUpdate($matchId);
            if ($currentMatch === null || (int) $currentMatch['donor_id'] !== $donorId
                || (int) $currentMatch['request_id'] !== (int) $request['id']) {
                throw new RuntimeException('Match not found.', 404);
            }
            WorkflowLockService::assertOpen($request);
            if ((string) $currentMatch['status'] !== 'RESPONDED') {
                throw new RuntimeException('Only responded matches can receive a donation report.', 409);
            }
            $reports = new DonationReportRepository();
            if ($reports->pendingExistsForMatchForUpdate($matchId)) {
                throw new RuntimeException('A donation report is already pending for this match.', 409);
            }
            $nowUtc = AuthService::nowUtc();
            WorkflowLockService::assertOpenBeforeDeadline($request, $nowUtc);
            $reportId = $reports->insert($matchId, $donorId, $note, $nowUtc);
            AuditLogger::log($donorId, 'donation.reported', 'donation_report', (string) $reportId, ['match_id' => $matchId]);
            return ['id' => $reportId, 'status' => 'PENDING'];
        });

        $reportId = (int) $result['id'];
        $requesterId = (int) $match['requester_id'];
        $requestChapterId = (int) ($match['request_chapter_id'] ?? 0);
        $requestId = (int) $match['request_id'];

        // 1. To the blood request owner (requester_id): notifying them that a donor has submitted a donation report for their request.
        NotificationService::notify(
            $requesterId,
            'donation.report_submitted',
            'Donation report submitted',
            sprintf('A donor has submitted a donation report for your blood request (#%d).', $requestId),
            [
                'related_type' => 'donation_report',
                'related_id' => $reportId,
                'dedup_key' => NotificationService::dedupDonationReportSubmitted($reportId),
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        // 2. To active chapter officers in request_chapter_id: notifying them that a donation report is awaiting confirmation in the chapter queue.
        if ($requestChapterId > 0) {
            $officerIds = (new UserRepository())->activeOfficersByChapter($requestChapterId);
            foreach ($officerIds as $officerId) {
                NotificationService::notify(
                    $officerId,
                    'donation.report_submitted',
                    'New donation report awaiting confirmation',
                    sprintf('A donation report (#%d) for request #%d is awaiting confirmation in the chapter queue.', $reportId, $requestId),
                    [
                        'related_type' => 'donation_report',
                        'related_id' => $reportId,
                        'dedup_key' => NotificationService::dedupDonationReportSubmitted($reportId, $officerId),
                        'email' => NotificationService::EMAIL_NORMAL,
                    ]
                );
            }
        }

        return $result;
    }

    public static function normalizeRejectionReason(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new ValidationException(['rejection_reason' => ['Enter a rejection reason as text.']]);
        }
        $reason = preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $value) ?? '';
        if (!preg_match('/[^\s\p{Z}\p{Cf}]/u', $reason)) {
            throw new ValidationException(['rejection_reason' => ['Enter a rejection reason.']]);
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ValidationException(['rejection_reason' => ['Rejection reason must be at most 500 characters.']]);
        }
        return $reason;
    }

    public function decide(array $actor, int $reportId, bool $confirm, mixed $rejectionReason = null): array
    {
        $endpoint = $confirm ? 'officer.reports.confirm' : 'officer.reports.reject';
        AuthMiddleware::requireRoles(['officer', 'admin'], $endpoint);

        // Pre-transaction authorization reads (scope + self rules).
        $pre = (new DonationReportRepository())->findById($reportId);
        if ($pre === null) {
            throw new RuntimeException('Donation report not found.', 404);
        }
        if ((int) $pre['donor_id'] === (int) $actor['id']) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'donation_report', (string) $reportId, [
                'endpoint' => $endpoint,
                'reason' => 'self_confirmation_forbidden',
            ]);
            throw new RuntimeException('You cannot decide on your own donation report.', 403);
        }
        if ((string) $actor['role'] === 'officer') {
            AuthBridge::assertChapter($actor, (int) $pre['request_chapter_id'], $endpoint);
        }

        $reason = $confirm ? null : self::normalizeRejectionReason($rejectionReason);

        try {
            $fulfilledNow = WorkflowLockService::transaction(function () use ($actor, $pre, $reportId, $confirm, $reason): bool {
                $request = WorkflowLockService::request((int) $pre['request_id']);
                $users = WorkflowLockService::users([(int) $actor['id'], (int) $pre['donor_id'], (int) $request['requester_id']]);
                $reviewer = $users[(int) $actor['id']];
                WorkflowLockService::assertReportReviewer($reviewer, $request, (int) $pre['donor_id']);
                $match = (new MatchRepository())->findByIdForUpdate((int) $pre['match_id']);
                $row = (new DonationReportRepository())->findByIdForUpdate($reportId);
                if ($row === null || $match === null || (int) $row['match_id'] !== (int) $match['id']
                    || (int) $match['request_id'] !== (int) $request['id'] || (int) $row['donor_id'] !== (int) $match['donor_id']) {
                    throw new RuntimeException('Donation report not found.', 404);
                }
                if ((string) $row['status'] !== 'PENDING') {
                    throw new RuntimeException("Report already decided (current: {$row['status']}).", 409);
                }
                $nowUtc = AuthService::nowUtc();
                $fulfilledNow = false;
                if ($confirm) {
                    WorkflowLockService::assertOpenBeforeDeadline($request, $nowUtc);
                    if ((string) $match['status'] !== 'RESPONDED') {
                        throw new RuntimeException('Only a responded match can be confirmed as a donation.', 409);
                    }
                    (new DonationReportRepository())->markConfirmed($reportId, (int) $reviewer['id'], $nowUtc);
                    $userRepo = new UserRepository();
                    $userRepo->setLastVerifiedDonation((int) $row['donor_id'], $nowUtc);
                    $userRepo->setAvailability((int) $row['donor_id'], 'standby');
                    (new MatchRepository())->setStatus((int) $row['match_id'], 'COMPLETED');
                    $completed = MatchRepository::countCompleted((int) $request['id']);
                    if ($completed >= (int) $request['quantity_units']) {
                        $closedCount = MatchRepository::fulfillAndCloseUnresolved((int) $request['id']);
                        $fulfilledNow = true;
                        AuditLogger::log((int) $reviewer['id'], 'request.fulfilled', 'blood_request', (string) $request['id'], [
                            'completed_units' => $completed, 'required_units' => (int) $request['quantity_units'],
                            'closed_matches' => $closedCount,
                        ]);
                    }
                } else {
                    (new DonationReportRepository())->markRejected($reportId, (int) $reviewer['id'], $nowUtc, $reason);
                }
                AuditLogger::log((int) $reviewer['id'], $confirm ? 'donation.confirmed' : 'donation.rejected', 'donation_report', (string) $reportId, [
                    'donor_id' => (int) $row['donor_id'], 'match_id' => (int) $row['match_id'],
                    'rejection_reason' => $reason,
                ]);
                return $fulfilledNow;
            });
        } catch (Throwable $e) {
            $code = $e->getCode();
            if ((int) $code === 403) {
                AuditLogger::log((int) $actor['id'], 'authz.denied', 'donation_report', (string) $reportId, [
                    'endpoint' => $endpoint, 'reason' => 'locked_review_authorization_denied',
                ]);
            }
            if ($e instanceof RuntimeException && (($code >= 400 && $code <= 499) || (int) $code === 503)) {
                throw $e;
            }
            error_log('[donations] decision failed: ' . $e->getMessage());
            throw new RuntimeException('Could not process the donation report.', 500);
        }

        \BloodMatch\Services\NotificationService::notify(
            (int) $pre['donor_id'],
            'donation.' . ($confirm ? 'confirmed' : 'rejected'),
            $confirm ? 'Donation confirmed — thank you!' : 'Donation report rejected',
            $confirm
                ? 'Your donation was confirmed. Thank you for saving a life!'
                : 'Your donation report could not be confirmed.',
            [
                'related_type' => 'donation_report',
                'related_id' => $reportId,
                'dedup_key' => \BloodMatch\Services\NotificationService::dedupDonation($reportId, $confirm ? 'confirmed' : 'rejected'),
                'email' => \BloodMatch\Services\NotificationService::EMAIL_NORMAL,
            ]
        );

        return [
            'decision' => $confirm ? 'CONFIRMED' : 'REJECTED',
            'request_fulfilled_now' => $fulfilledNow ?? false,
        ];
    }
}
