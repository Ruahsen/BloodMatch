<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Repositories\DonationReportRepository;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Repositories\UserRepository;
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

        $reports = new DonationReportRepository();
        if ($reports->pendingExistsForMatch($matchId)) {
            throw new RuntimeException('A donation report is already pending for this match.', 409);
        }

        $reportId = $reports->insert($matchId, $donorId, $note, AuthService::nowUtc());
        AuditLogger::log($donorId, 'donation.reported', 'donation_report', (string) $reportId, [
            'match_id' => $matchId,
        ]);

        return ['id' => $reportId, 'status' => 'PENDING'];
    }

    public function decide(array $actor, int $reportId, bool $confirm): array
    {
        $endpoint = $confirm ? 'officer.reports.confirm' : 'officer.reports.reject';
        AuthMiddleware::requireRoles(['officer', 'admin'], $endpoint);

        $pdo = \BloodMatch\Config\Database::pdo();
        $nowUtc = AuthService::nowUtc();
        $action = $confirm ? 'confirm' : 'reject';

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

        $pdo->beginTransaction();

        try {
            $row = (new DonationReportRepository())->findByIdForUpdate($reportId);
            $match = (new MatchRepository())->findByIdForUpdate((int) $pre['match_id']);

            if ($row === null || $match === null) {
                throw new RuntimeException('Donation report not found.', 404);
            }

            if ((string) $row['status'] !== 'PENDING') {
                throw new RuntimeException("Report already decided (current: {$row['status']}).", 409);
            }

            if ($confirm) {
                if ((string) $row['request_status'] !== 'OPEN') {
                    throw new RuntimeException('The related request is no longer active.', 409);
                }

                (new DonationReportRepository())->markConfirmed($reportId, (int) $actor['id'], $nowUtc);

                $users = new UserRepository();
                $users->setLastVerifiedDonation((int) $row['donor_id'], $nowUtc);
                $users->setAvailability((int) $row['donor_id'], 'standby');

                (new MatchRepository())->setStatus((int) $row['match_id'], 'COMPLETED');

                $completed = MatchRepository::countCompleted((int) $row['request_id']);
                $fulfilledNow = false;
                if ($completed >= (int) $row['quantity_units']) {
                    $closedCount = MatchRepository::fulfillAndCloseUnresolved((int) $row['request_id']);
                    $fulfilledNow = true;
                    AuditLogger::log((int) $actor['id'], 'request.fulfilled', 'blood_request', (string) $row['request_id'], [
                        'completed_units' => $completed,
                        'required_units' => (int) $row['quantity_units'],
                        'closed_matches' => $closedCount,
                    ]);
                }
            } else {
                (new DonationReportRepository())->markRejected($reportId, (int) $actor['id'], $nowUtc);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getCode();
            if ($e instanceof RuntimeException && $code >= 400 && $code <= 499) {
                throw $e;
            }
            error_log('[donations] decision failed: ' . $e->getMessage());
            throw new RuntimeException('Could not process the donation report.', 500);
        }

        $auditAction = $confirm ? 'donation.confirmed' : 'donation.rejected';
        AuditLogger::log((int) $actor['id'], $auditAction, 'donation_report', (string) $reportId, [
            'donor_id' => (int) $pre['donor_id'],
            'match_id' => (int) $pre['match_id'],
        ]);

        \BloodMatch\Services\NotificationService::notify(
            (int) $pre['donor_id'],
            'donation.' . ($confirm ? 'confirmed' : 'rejected'),
            $confirm ? 'Donation confirmed — thank you!' : 'Donation report rejected',
            $confirm
                ? 'Your donation was confirmed. Thank you for saving a life!'
                : ('Your donation report could not be confirmed.' . ($pre['report_note'] !== null ? '' : '')),
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
