<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Repositories\UserRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Single decision/reconciliation core for donor–requester match relationships.
 *
 * Both respond entry points (request-scoped feed action and match-scoped
 * legacy action) delegate here so eligibility, consent, and transition rules
 * exist exactly once. Feed rendering never calls this; it runs only on
 * explicit user action.
 */
final class MatchDecisionService
{
    public const DONOR_CONSENT_TEXT =
        'I agree to share my email address with this requester if my response is accepted.';
    public const REQUESTER_CONSENT_TEXT =
        'I agree to share my email address with this donor.';

    // ------------------------------------------------------------------
    // Respond (donor)
    // ------------------------------------------------------------------

    /**
     * @return array{match_id:int, request_id:int, status:string, reconciled:bool}
     */
    public function respondByMatch(int $matchId, int $donorId, bool $donorConsent): array
    {
        $match = (new MatchRepository())->findByIdDetailed($matchId);
        if ($match === null) {
            throw new RuntimeException('Match not found.', 404);
        }
        if ((int) $match['donor_id'] !== $donorId) {
            AuditLogger::log($donorId, 'authz.denied', null, null, [
                'endpoint' => 'matches.respond',
                'reason' => 'not_match_owner',
            ]);
            throw new RuntimeException('Forbidden.', 403);
        }
        return $this->respondDonor((int) $match['request_id'], $donorId, $donorConsent, 'matches.respond');
    }

    /**
     * Request-scoped respond: reconciles a possibly missing/stale persisted
     * relationship from LIVE donor state, then transitions to RESPONDED.
     * Never invokes candidate generation; never emits match.new.
     *
     * @return array{match_id:int, request_id:int, status:string, reconciled:bool}
     */
    public function respondDonor(int $requestId, int $donorId, bool $donorConsent, string $endpoint): array
    {
        $repo = new BloodRequestRepository();
        $request = $repo->findById($requestId);
        if ($request === null) {
            throw new RuntimeException('Request not found.', 404);
        }
        if ((string) $request['status'] !== 'OPEN') {
            throw new RuntimeException('This request is no longer active.', 409);
        }

        $donor = (new UserRepository())->findById($donorId);
        if ($donor === null || (string) $donor['account_status'] !== 'active') {
            throw new RuntimeException('Forbidden.', 403);
        }

        $this->assertLiveDonorEligible($donor, (string) $request['required_blood_type'], $endpoint);

        if (!$donorConsent) {
            AuditLogger::log($donorId, 'match.consent_required', 'blood_request', (string) $requestId, [
                'endpoint' => $endpoint,
                'reason' => 'donor_share_consent_missing',
            ]);
            throw new RuntimeException(
                'Sharing your email with the requester is required to respond. ' . self::DONOR_CONSENT_TEXT,
                422
            );
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Serialize against cancel/expiry/fulfill: re-check OPEN under
            // the request row lock before touching the relationship.
            $lockedRequest = (new BloodRequestRepository())->findByIdForUpdate($requestId);
            if ($lockedRequest === null || (string) $lockedRequest['status'] !== 'OPEN') {
                throw new RuntimeException('This request is no longer active.', 409);
            }
            $matches = new MatchRepository();
            $existing = $matches->findByRequestAndDonorForUpdate($requestId, $donorId);

            if ($existing === null) {
                // Newly eligible donor with no persisted row: ensure the
                // relationship at the current generation (no bump, no match.new).
                $genStmt = $pdo->prepare('SELECT COALESCE(MAX(generation), 0) FROM matches WHERE request_id = ?');
                $genStmt->execute([$requestId]);
                $generation = max(1, (int) $genStmt->fetchColumn());
                $distance = Geo::distanceKm(
                    $request['latitude'],
                    $request['longitude'],
                    $donor['latitude'],
                    $donor['longitude']
                );
                $ins = $pdo->prepare(
                    'INSERT INTO matches (request_id, donor_id, generation, status, distance_km, donor_share_consent)
                     VALUES (?, ?, ?, ?, ?, 1)'
                );
                $ins->execute([$requestId, $donorId, $generation, 'RESPONDED', $distance]);
                $matchId = (int) $pdo->lastInsertId();
                $reconciled = true;
                $changed = true;
            } else {
                $matchId = (int) $existing['id'];
                $status = (string) $existing['status'];
                if ($status === 'WITHDRAWN') {
                    throw new RuntimeException(
                        'This response was withdrawn and cannot be reopened for this request.', 409
                    );
                }
                if (in_array($status, ['RESPONDED', 'ACCEPTED', 'COMPLETED'], true)) {
                    // Idempotent replay (double-click/retries): state already
                    // reflects willingness - no transition, no new audit row,
                    // no new notification.
                    $reconciled = false;
                    $changed = false;
                } else {
                    if ($status === 'CLOSED') {
                        // New episode: stale consent must not leak into it.
                        $matches->resetConsents($matchId);
                    }
                    $pdo->prepare(
                        "UPDATE matches SET status = 'RESPONDED', donor_share_consent = 1 WHERE id = ?"
                    )->execute([$matchId]);
                    $reconciled = $status === 'CLOSED';
                    $changed = true;
                }
            }

            if ($changed ?? false) {
                AuditLogger::logCritical($donorId, 'match.responded', 'blood_request', (string) $requestId, [
                    'match_id' => $matchId,
                    'reconciled' => $reconciled ?? false,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            error_log('[matches] respond reconciliation failed: ' . $e->getMessage());
            throw new RuntimeException('Could not record the response.', 500);
        }

        $final = (new MatchRepository())->findByRequestAndDonor($requestId, $donorId);
        $finalStatus = $final !== null ? (string) $final['status'] : 'RESPONDED';

        if (!($changed ?? false)) {
            return [
                'match_id' => $matchId,
                'request_id' => $requestId,
                'status' => $finalStatus,
                'reconciled' => $reconciled ?? false,
            ];
        }

        NotificationService::notify(
            (int) $request['requester_id'],
            'match.responded',
            sprintf('A donor responded to your request (#%d)', $requestId),
            sprintf(
                'A compatible donor responded to your %s blood request at %s. Review your matches to accept a donor.',
                (string) $request['required_blood_type'],
                (string) $request['facility_name']
            ),
            [
                'related_type' => 'blood_request',
                'related_id' => $requestId,
                'dedup_key' => NotificationService::dedupMatchEvent($matchId, 'responded'),
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        return [
            'match_id' => $matchId,
            'request_id' => $requestId,
            'status' => $finalStatus,
            'reconciled' => $reconciled ?? false,
        ];
    }

    /**
     * LIVE donor eligibility for responding: shared pool predicate (single
     * source of truth with bulk matching) plus age path and consent-independent
     * prerequisites. Throws 409 with a specific reason when not eligible.
     */
    private function assertLiveDonorEligible(array $donor, string $requiredBloodType, string $endpoint): void
    {
        $donorId = (int) $donor['id'];
        if (!MatchService::isDonorInPool($donorId, $requiredBloodType)) {
            AuditLogger::log($donorId, 'authz.denied', 'blood_request', null, [
                'endpoint' => $endpoint,
                'reason' => 'donor_not_eligible',
            ]);
            throw new RuntimeException(
                'You are not currently eligible to respond to this request (verification, enrollment, availability, standby/cooldown, or blood compatibility).', 409
            );
        }

        $docs = new DocumentRepository();
        $age = AgeEligibilityService::evaluate(
            $donor['date_of_birth'] !== null ? (string) $donor['date_of_birth'] : null,
            $docs->hasType($donorId, 'parental_consent')
        );
        if (!$age['donor_path_allowed']) {
            AuditLogger::log($donorId, 'authz.denied', 'blood_request', null, [
                'endpoint' => $endpoint,
                'reason' => 'age_ineligible',
            ]);
            throw new RuntimeException((string) $age['reason'], 409);
        }
    }

    // ------------------------------------------------------------------
    // Accept / unaccept (requester)
    // ------------------------------------------------------------------

    /**
     * @return array{match_id:int, request_id:int, status:string}
     */
    public function accept(int $matchId, int $requesterId, bool $requesterConsent): array
    {
        $matches = new MatchRepository();
        $pre = $matches->findByIdDetailed($matchId);
        if ($pre === null) {
            throw new RuntimeException('Match not found.', 404);
        }
        $requestId = (int) $pre['request_id'];

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Consistent lock order everywhere: request row, then match row.
            $reqRepo = new BloodRequestRepository();
            $request = $reqRepo->findByIdForUpdate($requestId);
            if ($request === null) {
                throw new RuntimeException('Match not found.', 404);
            }
            if ((int) $request['requester_id'] !== $requesterId) {
                AuditLogger::log($requesterId, 'authz.denied', 'blood_request', (string) $requestId, [
                    'endpoint' => 'matches.accept',
                    'reason' => 'not_request_owner',
                ]);
                throw new RuntimeException('Forbidden.', 403);
            }
            if ((string) $request['status'] !== 'OPEN') {
                throw new RuntimeException('This request is no longer active.', 409);
            }

            $match = $matches->findByIdForUpdate($matchId);
            if ($match === null || (int) $match['request_id'] !== $requestId) {
                throw new RuntimeException('Match not found.', 404);
            }
            if ((string) $match['status'] !== 'RESPONDED') {
                // Idempotent replay of an existing acceptance: no state
                // change, no new audit row, no new notification.
                if ((string) $match['status'] === 'ACCEPTED') {
                    $pdo->commit();
                    return ['match_id' => $matchId, 'request_id' => (int) $requestId, 'status' => 'ACCEPTED'];
                }
                throw new RuntimeException(
                    "Only responded matches can be accepted (current: {$match['status']}).", 409
                );
            }

            // A RESPONDED row may have gone stale (availability toggled,
            // standby/cooldown onset, verification loss, incompatibility,
            // age change). A new ACCEPTED commitment requires the full live
            // candidate gate - unlike an already-ACCEPTED relationship,
            // which survives ordinary scheduling changes by design.
            $donor = (new UserRepository())->findByIdForUpdate((int) $match['donor_id']);
            if ($donor === null || (string) $donor['account_status'] !== 'active') {
                throw new RuntimeException('The donor account is no longer active.', 409);
            }
            $this->assertLiveDonorEligible($donor, (string) $request['required_blood_type'], 'matches.accept');
            if ((int) ($match['donor_share_consent'] ?? 0) !== 1) {
                AuditLogger::log($requesterId, 'match.consent_required', 'blood_request', (string) $requestId, [
                    'endpoint' => 'matches.accept',
                    'reason' => 'donor_share_consent_missing',
                    'match_id' => $matchId,
                ]);
                throw new RuntimeException('The donor has not agreed to share contact information.', 422);
            }
            if (!$requesterConsent) {
                AuditLogger::log($requesterId, 'match.consent_required', 'blood_request', (string) $requestId, [
                    'endpoint' => 'matches.accept',
                    'reason' => 'requester_share_consent_missing',
                    'match_id' => $matchId,
                ]);
                throw new RuntimeException(
                    'Sharing your email with the donor is required to accept. ' . self::REQUESTER_CONSENT_TEXT, 422
                );
            }

            $accepted = MatchRepository::countAccepted((int) $requestId);
            $completed = MatchRepository::countCompleted((int) $requestId);
            if ($accepted + $completed >= (int) $request['quantity_units']) {
                AuditLogger::log($requesterId, 'match.capacity_full', 'blood_request', (string) $requestId, [
                    'endpoint' => 'matches.accept',
                    'match_id' => $matchId,
                    'accepted' => $accepted,
                    'completed' => $completed,
                ]);
                throw new RuntimeException('This request already has enough accepted donors for its required units.', 409);
            }

            $pdo->prepare("UPDATE matches SET status = 'ACCEPTED', requester_share_consent = 1 WHERE id = ?")
                ->execute([$matchId]);
            // Fail-closed: the acceptance must not exist without its audit
            // trail; a failed audit write rolls the acceptance back.
            AuditLogger::logCritical($requesterId, 'match.accepted', 'blood_request', (string) $requestId, [
                'match_id' => $matchId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            error_log('[matches] accept failed: ' . $e->getMessage());
            throw new RuntimeException('Could not accept the donor.', 500);
        }

        NotificationService::notify(
            (int) $match['donor_id'],
            'match.accepted',
            sprintf('You were accepted for request (#%d)', (int) $requestId),
            'The requester accepted your response. You can now view contact details to coordinate directly.',
            [
                'related_type' => 'blood_request',
                'related_id' => (int) $requestId,
                'dedup_key' => NotificationService::dedupMatchEvent($matchId, 'accepted'),
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        return ['match_id' => $matchId, 'request_id' => (int) $requestId, 'status' => 'ACCEPTED'];
    }

    /**
     * @return array{match_id:int, request_id:int, status:string}
     */
    public function unaccept(int $matchId, int $requesterId): array
    {
        // Lock order: request row first (pre-read supplies its id), then
        // match row - the same order accept/confirm use.
        $pre = (new MatchRepository())->findByIdDetailed($matchId);
        if ($pre === null) {
            throw new RuntimeException('Match not found.', 404);
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $matches = new MatchRepository();
            $reqRepo = new BloodRequestRepository();
            $request = $reqRepo->findByIdForUpdate((int) $pre['request_id']);
            if ($request === null) {
                throw new RuntimeException('Match not found.', 404);
            }
            $match = $matches->findByIdForUpdate($matchId);
            if ($match === null || (int) $match['request_id'] !== (int) $pre['request_id']) {
                throw new RuntimeException('Match not found.', 404);
            }
            if ((int) $request['requester_id'] !== $requesterId) {
                AuditLogger::log($requesterId, 'authz.denied', 'blood_request', (string) $match['request_id'], [
                    'endpoint' => 'matches.unaccept',
                    'reason' => 'not_request_owner',
                ]);
                throw new RuntimeException('Forbidden.', 403);
            }
            if ((string) $request['status'] !== 'OPEN') {
                throw new RuntimeException('This request is no longer active.', 409);
            }
            if ((string) $match['status'] !== 'ACCEPTED') {
                throw new RuntimeException(
                    "Only accepted matches can be unaccepted (current: {$match['status']}).", 409
                );
            }

            // Willingness (RESPONDED) survives; the requester's acceptance
            // decision - and therefore their contact consent - is reversed.
            $pdo->prepare(
                "UPDATE matches SET status = 'RESPONDED', requester_share_consent = 0 WHERE id = ?"
            )->execute([$matchId]);
            $unacceptAuditId = AuditLogger::logCritical($requesterId, 'match.unaccepted', 'blood_request', (string) $match['request_id'], [
                'match_id' => $matchId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            error_log('[matches] unaccept failed: ' . $e->getMessage());
            throw new RuntimeException('Could not unaccept the donor.', 500);
        }

        // The authoritative audit row was written inside the transaction
        // above; its id suffixes the per-occurrence notification dedup key.
        $auditId = $unacceptAuditId ?? 0;

        NotificationService::notify(
            (int) $match['donor_id'],
            'match.unaccepted',
            'A requester withdrew an acceptance',
            'The requester is no longer holding your response as accepted. Your willingness to donate is still recorded.',
            [
                'related_type' => 'blood_request',
                'related_id' => (int) $match['request_id'],
                'dedup_key' => NotificationService::dedupMatchOccurrence($matchId, 'unaccepted', $auditId),
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        return ['match_id' => $matchId, 'request_id' => (int) $match['request_id'], 'status' => 'RESPONDED'];
    }

    // ------------------------------------------------------------------
    // Withdraw (donor)
    // ------------------------------------------------------------------

    /**
     * @return array{match_id:int, request_id:int, status:string}
     */
    public function withdraw(int $matchId, int $donorId): array
    {
        // Lock order: request row first (pre-read supplies its id), then
        // match row - the same order accept/confirm use.
        $pre = (new MatchRepository())->findByIdDetailed($matchId);
        if ($pre === null) {
            throw new RuntimeException('Match not found.', 404);
        }
        if ((int) $pre['donor_id'] !== $donorId) {
            AuditLogger::log($donorId, 'authz.denied', null, null, [
                'endpoint' => 'matches.withdraw',
                'reason' => 'not_match_owner',
            ]);
            throw new RuntimeException('Forbidden.', 403);
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $matches = new MatchRepository();
            $request = (new BloodRequestRepository())->findByIdForUpdate((int) $pre['request_id']);
            if ($request === null) {
                throw new RuntimeException('Match not found.', 404);
            }
            $match = $matches->findByIdForUpdate($matchId);
            if ($match === null || (int) $match['request_id'] !== (int) $pre['request_id']) {
                throw new RuntimeException('Match not found.', 404);
            }
            if ((int) $match['donor_id'] !== $donorId) {
                AuditLogger::log($donorId, 'authz.denied', null, null, [
                    'endpoint' => 'matches.withdraw',
                    'reason' => 'not_match_owner',
                ]);
                throw new RuntimeException('Forbidden.', 403);
            }
            if ((string) $request['status'] !== 'OPEN') {
                throw new RuntimeException('This request is no longer active.', 409);
            }
            if (!in_array((string) $match['status'], ['RESPONDED', 'ACCEPTED'], true)) {
                throw new RuntimeException(
                    "Only responded or accepted matches can be withdrawn (current: {$match['status']}).", 409
                );
            }

            $pdo->prepare("UPDATE matches SET status = 'WITHDRAWN' WHERE id = ?")->execute([$matchId]);
            AuditLogger::logCritical($donorId, 'match.withdrawn', 'blood_request', (string) $match['request_id'], [
                'match_id' => $matchId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            error_log('[matches] withdraw failed: ' . $e->getMessage());
            throw new RuntimeException('Could not withdraw the response.', 500);
        }

        NotificationService::notify(
            (int) (new BloodRequestRepository())->findById((int) $match['request_id'])['requester_id'],
            'match.withdrawn',
            'A donor withdrew a response',
            'A donor withdrew their response to your blood request. Your accepted capacity was released if they were accepted.',
            [
                'related_type' => 'blood_request',
                'related_id' => (int) $match['request_id'],
                'dedup_key' => NotificationService::dedupMatchEvent($matchId, 'withdrawn'),
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        return ['match_id' => $matchId, 'request_id' => (int) $match['request_id'], 'status' => 'WITHDRAWN'];
    }

    // ------------------------------------------------------------------
    // Consent (bilateral, own flag only)
    // ------------------------------------------------------------------

    /**
     * @return array{match_id:int, side:string, share:bool}
     */
    public function setConsent(int $matchId, int $actorId, bool $share): array
    {
        $matches = new MatchRepository();
        $match = $matches->findByIdDetailed($matchId);
        if ($match === null) {
            throw new RuntimeException('Match not found.', 404);
        }

        $reqRepo = new BloodRequestRepository();
        $request = $reqRepo->findById((int) $match['request_id']);
        if ($request === null) {
            throw new RuntimeException('Match not found.', 404);
        }

        $side = null;
        if ((int) $match['donor_id'] === $actorId) {
            $side = 'donor';
        } elseif ((int) $request['requester_id'] === $actorId) {
            $side = 'requester';
        }
        if ($side === null) {
            AuditLogger::log($actorId, 'authz.denied', 'blood_request', (string) $match['request_id'], [
                'endpoint' => 'matches.consent',
                'reason' => 'not_match_principal',
            ]);
            throw new RuntimeException('Forbidden.', 403);
        }

        $status = (string) $match['status'];
        $requestOpen = (string) $request['status'] === 'OPEN';
        if ($share) {
            // Grants are meaningful only while the relationship can use contact.
            if ($status !== 'ACCEPTED' || !$requestOpen) {
                throw new RuntimeException('Contact sharing can only be granted for an accepted match on an open request.', 409);
            }
            $other = $side === 'donor'
                ? (new UserRepository())->findById((int) $request['requester_id'])
                : (new UserRepository())->findById((int) $match['donor_id']);
            if ($other === null || (string) $other['account_status'] !== 'active') {
                throw new RuntimeException('The other party account is no longer active.', 409);
            }
        } elseif (!in_array($status, ['RESPONDED', 'ACCEPTED'], true)
            && !($status === 'COMPLETED' && $requestOpen)) {
            throw new RuntimeException("Consent cannot be changed in match state {$status}.", 409);
        }

        $matches->setConsent($matchId, $side, $share);
        $auditId = AuditLogger::log($actorId, $share ? 'match.consent_granted' : 'match.consent_revoked', 'blood_request', (string) $match['request_id'], [
            'match_id' => $matchId,
            'side' => $side,
        ]);

        if (!$share) {
            $otherId = $side === 'donor' ? (int) $request['requester_id'] : (int) $match['donor_id'];
            NotificationService::notify(
                $otherId,
                'match.consent_revoked',
                'Contact sharing was revoked',
                'The other party revoked email sharing for your match. Contact details are no longer available.',
                [
                    'related_type' => 'blood_request',
                    'related_id' => (int) $match['request_id'],
                    'dedup_key' => NotificationService::dedupMatchOccurrence($matchId, 'consent_revoked', $auditId),
                    'email' => NotificationService::EMAIL_NORMAL,
                ]
            );
        }

        return ['match_id' => $matchId, 'side' => $side, 'share' => $share];
    }

    // ------------------------------------------------------------------
    // Targeted ACCEPTED cleanup (deactivation / invalidation / incompat)
    // ------------------------------------------------------------------

    /**
     * Close a user's OPEN ACCEPTED relationships after an administrative or
     * safety event (deactivation, verification rejection, incompatible
     * blood-type change). Targeted cleanup only - no matching fan-out.
     * Notifies the unaffected principal of each relationship.
     */
    public function closeAcceptedForInvalidatedUser(int $userId, string $reason, ?int $actorId): int
    {
        $closures = MatchRepository::closeAcceptedForUser($userId, $reason);
        foreach ($closures as $c) {
            $auditId = AuditLogger::log($actorId, 'match.closed', 'blood_request', (string) $c['request_id'], [
                'match_id' => $c['match_id'],
                'reason' => $reason,
            ]);
            $otherId = (int) $c['donor_id'] === $userId ? (int) $c['requester_id'] : (int) $c['donor_id'];
            NotificationService::notify(
                $otherId,
                'match.closed',
                'An accepted match was closed',
                'An accepted donor relationship on your blood request was closed for an administrative or safety reason. Contact details are no longer available.',
                [
                    'related_type' => 'blood_request',
                    'related_id' => (int) $c['request_id'],
                    'dedup_key' => NotificationService::dedupMatchOccurrence((int) $c['match_id'], 'closed', $auditId),
                    'email' => NotificationService::EMAIL_NORMAL,
                ]
            );
        }
        return count($closures);
    }

    // ------------------------------------------------------------------
    // Contact (protected email exchange)
    // ------------------------------------------------------------------

    /**
     * @return array{donor_email:string, requester_email:string}
     */
    public function contact(int $matchId, int $actorId): array
    {
        $matches = new MatchRepository();
        $match = $matches->findByIdDetailed($matchId);
        if ($match === null) {
            // Uniform 404: never leak existence to strangers.
            throw new RuntimeException('Match not found.', 404);
        }

        $reqRepo = new BloodRequestRepository();
        $request = $reqRepo->findById((int) $match['request_id']);
        if ($request === null) {
            throw new RuntimeException('Match not found.', 404);
        }

        $isDonor = (int) $match['donor_id'] === $actorId;
        $isRequester = (int) $request['requester_id'] === $actorId;
        if (!$isDonor && !$isRequester) {
            AuditLogger::log($actorId, 'authz.denied', 'blood_request', (string) $match['request_id'], [
                'endpoint' => 'matches.contact',
                'reason' => 'not_match_principal',
            ]);
            throw new RuntimeException('Match not found.', 404);
        }

        $denied = static function (string $reason) use ($actorId, $match): never {
            AuditLogger::log($actorId, 'authz.denied', 'blood_request', (string) $match['request_id'], [
                'endpoint' => 'matches.contact',
                'reason' => $reason,
                'match_id' => (int) $match['id'],
            ]);
            throw new RuntimeException('Contact details are not available for this match.', 403);
        };

        if (!in_array((string) $match['status'], ['ACCEPTED', 'COMPLETED'], true)) {
            $denied('invalid_match_state');
        }
        if ((string) $request['status'] !== 'OPEN') {
            $denied('request_not_open');
        }
        if ((int) ($match['donor_share_consent'] ?? 0) !== 1 || (int) ($match['requester_share_consent'] ?? 0) !== 1) {
            $denied('consent_missing');
        }

        $users = new UserRepository();
        $donor = $users->findById((int) $match['donor_id']);
        $requester = $users->findById((int) $request['requester_id']);
        if ($donor === null || $requester === null
            || (string) $donor['account_status'] !== 'active'
            || (string) $requester['account_status'] !== 'active') {
            $denied('account_inactive');
        }

        AuditLogger::log($actorId, 'match.contact_viewed', 'blood_request', (string) $match['request_id'], [
            'match_id' => $matchId,
        ]);

        return [
            'donor_email' => (string) $donor['email'],
            'requester_email' => (string) $requester['email'],
        ];
    }
}
