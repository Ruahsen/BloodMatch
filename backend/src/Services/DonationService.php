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
        if (!in_array((string) $match['status'], ['RESPONDED', 'ACCEPTED'], true)) {
            throw new RuntimeException('Only responded or accepted matches can receive a donation report.', 409);
        }
        if ((string) $match['request_status'] !== 'OPEN') {
            throw new RuntimeException('This request is no longer active.', 409);
        }

        // Donor must still hold a live account to file a report; deeper
        // safety prerequisites are enforced at officer confirmation time
        // (the donation itself already occurred at the facility).
        $donor = (new UserRepository())->findById($donorId);
        if ($donor === null || (string) $donor['account_status'] !== 'active') {
            throw new RuntimeException('Your account is no longer active.', 403);
        }

        // Atomic insert-if-no-pending: concurrent duplicate submits for the
        // same match yield exactly one active PENDING report.
        $reportId = (new DonationReportRepository())->insertIfNoPending($matchId, $donorId, $note, AuthService::nowUtc());
        if ($reportId === null) {
            throw new RuntimeException('A donation report is already pending for this match.', 409);
        }
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
            // Consistent lock order everywhere: request row, then match row,
            // then donation report row. Counts are read only after all locks
            // are held so concurrent accept/confirm paths (which take the
            // same request lock) cannot overshoot capacity.
            $request = (new BloodRequestRepository())->findByIdForUpdate((int) $pre['request_id']);
            if ($request === null) {
                throw new RuntimeException('Donation report not found.', 404);
            }
            if ((string) $request['status'] !== 'OPEN') {
                throw new RuntimeException('The related request is no longer active.', 409);
            }

            $match = (new MatchRepository())->findByIdForUpdate((int) $pre['match_id']);
            if ($match === null || (int) $match['request_id'] !== (int) $pre['request_id']) {
                throw new RuntimeException('Donation report not found.', 404);
            }

            $row = (new DonationReportRepository())->findByIdForUpdate($reportId);
            if ($row === null) {
                throw new RuntimeException('Donation report not found.', 404);
            }

            if ((string) $row['status'] !== 'PENDING') {
                throw new RuntimeException("Report already decided (current: {$row['status']}).", 409);
            }

            $displacedAccepted = [];
            if ($confirm) {
                // A confirmation must never resurrect an invalid match: only
                // live RESPONDED/ACCEPTED relationships may complete. CLOSED,
                // WITHDRAWN, POTENTIAL, and NOTIFIED rows are rejected here
                // with no donation side effects (no timestamps, no standby,
                // no fulfillment counting, no completion notification).
                $matchStatus = (string) $match['status'];
                if (!in_array($matchStatus, ['RESPONDED', 'ACCEPTED'], true)) {
                    throw new RuntimeException(
                        "Only responded or accepted matches can complete a donation (current: {$matchStatus}).",
                        409
                    );
                }

                // Safety/identity re-validation: an invalidated donor
                // relationship (deactivated, unverified, unenrolled,
                // incompatible, age-ineligible) must not complete. Scheduling
                // rules (availability, standby, cooldown) govern future
                // candidacy only and are intentionally not applied to a
                // donation that already occurred at the facility.
                $donor = (new UserRepository())->findByIdForUpdate((int) $row['donor_id']);
                DonorEligibilityService::assertSafetyEligible(
                    $donor,
                    (string) $request['required_blood_type'],
                    $endpoint
                );

                // Capacity: ACCEPTED -> COMPLETED keeps the committed sum
                // unchanged; RESPONDED -> COMPLETED adds one unit, so the
                // invariant COUNT(ACCEPTED) + COUNT(COMPLETED) <= quantity
                // is enforced here under the request lock.
                if ($matchStatus === 'RESPONDED') {
                    $accepted = MatchRepository::countAccepted((int) $row['request_id']);
                    $completed = MatchRepository::countCompleted((int) $row['request_id']);
                    if ($accepted + $completed + 1 > (int) $request['quantity_units']) {
                        AuditLogger::log((int) $actor['id'], 'match.capacity_full', 'blood_request', (string) $row['request_id'], [
                            'endpoint' => $endpoint,
                            'match_id' => (int) $row['match_id'],
                            'accepted' => $accepted,
                            'completed' => $completed,
                        ]);
                        throw new RuntimeException('This request already has enough committed donors for its required units.', 409);
                    }
                }

                // Snapshot the other live ACCEPTED donors before fulfillment
                // closes them, so each receives a clear closure notice.
                $displacedAccepted = array_values(array_filter(
                    (new MatchRepository())->listAcceptedDonors((int) $row['request_id']),
                    static fn (array $a): bool => (int) $a['match_id'] !== (int) $row['match_id']
                ));

                (new DonationReportRepository())->markConfirmed($reportId, (int) $actor['id'], $nowUtc);

                $users = new UserRepository();
                $users->setLastVerifiedDonation((int) $row['donor_id'], $nowUtc);
                $users->setAvailability((int) $row['donor_id'], 'standby');

                (new MatchRepository())->setStatus((int) $row['match_id'], 'COMPLETED');

                $completed = MatchRepository::countCompleted((int) $row['request_id']);
                $fulfilledNow = false;
                if ($completed >= (int) $request['quantity_units']) {
                    $closedCount = MatchRepository::fulfillAndCloseUnresolved((int) $row['request_id']);
                    $fulfilledNow = true;
                    $fulfillAuditId = AuditLogger::log((int) $actor['id'], 'request.fulfilled', 'blood_request', (string) $row['request_id'], [
                        'completed_units' => $completed,
                        'required_units' => (int) $request['quantity_units'],
                        'closed_matches' => $closedCount,
                    ]);
                }
            } else {
                (new DonationReportRepository())->markRejected($reportId, (int) $actor['id'], $nowUtc);
            }

            // Fail-closed: the report decision must not exist without its
            // audit trail; a failed audit write rolls the decision back.
            AuditLogger::logCritical((int) $actor['id'], $confirm ? 'donation.confirmed' : 'donation.rejected', 'donation_report', (string) $reportId, [
                'donor_id' => (int) $pre['donor_id'],
                'match_id' => (int) $pre['match_id'],
            ]);

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

        \BloodMatch\Services\NotificationService::notify(
            (int) $pre['donor_id'],
            'donation.' . ($confirm ? 'confirmed' : 'rejected'),
            $confirm ? 'Donation confirmed - thank you!' : 'Donation report rejected',
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

        // Fulfillment outreach: the requester learns the request is complete,
        // and every other ACCEPTED donor whose live contact relationship was
        // closed by fulfillment gets a clear notice (contact revoked).
        if ($confirm && ($fulfilledNow ?? false)) {
            $requesterId = (int) $request['requester_id'];
            \BloodMatch\Services\NotificationService::notify(
                $requesterId,
                'request.fulfilled',
                'Blood request fulfilled',
                sprintf('Your blood request #%d has received all required units and is now fulfilled.', (int) $pre['request_id']),
                [
                    'related_type' => 'blood_request',
                    'related_id' => (int) $pre['request_id'],
                    'dedup_key' => \BloodMatch\Services\NotificationService::dedupRequestStatus((int) $pre['request_id'], 'fulfilled'),
                    'email' => \BloodMatch\Services\NotificationService::EMAIL_NORMAL,
                ]
            );
            foreach ($displacedAccepted ?? [] as $displaced) {
                \BloodMatch\Services\NotificationService::notify(
                    (int) $displaced['donor_id'],
                    'match.closed',
                    'A blood request you were accepted for was fulfilled',
                    sprintf('Blood request #%d received all required units and was fulfilled. Contact details are no longer available. Thank you for your willingness to donate.', (int) $pre['request_id']),
                    [
                        'related_type' => 'blood_request',
                        'related_id' => (int) $pre['request_id'],
                        'dedup_key' => \BloodMatch\Services\NotificationService::dedupMatchOccurrence(
                            (int) $displaced['match_id'],
                            'closed',
                            $fulfillAuditId ?? null
                        ),
                        'email' => \BloodMatch\Services\NotificationService::EMAIL_NORMAL,
                    ]
                );
            }
        }

        return [
            'decision' => $confirm ? 'CONFIRMED' : 'REJECTED',
            'request_fulfilled_now' => $fulfilledNow ?? false,
        ];
    }
}
