<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Config\Database;
use PDO;
use RuntimeException;

final class MatchService
{
    private const DONOR_RECONCILE_LIMIT = 100;

    public function generateForRequest(
        int $requestId,
        bool $bumpGeneration,
        string $trigger,
        ?int $actorId = null,
        ?int $onlyDonorId = null
    ): array
    {
        return WorkflowLockService::transaction(fn (): array => $this->generateLockedForRequest(
            $requestId, $bumpGeneration, $trigger, $actorId, $onlyDonorId
        ));
    }

    private function generateLockedForRequest(
        int $requestId,
        bool $bumpGeneration,
        string $trigger,
        ?int $actorId,
        ?int $onlyDonorId
    ): array {
        $repo = new BloodRequestRepository();
        $request = $repo->findByIdForUpdate($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.', 404);
        }
        if ((string) $request['status'] !== 'OPEN') {
            throw new RuntimeException("Matching runs only against OPEN requests (current: {$request['status']}).", 409);
        }

        $compatibleTypes = BloodCompatibilityService::getCompatibleDonorTypes(
            (string) $request['required_blood_type']
        );

        $nowUtc = AuthService::nowUtc();

        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));

        // Persisted availability 'standby' may pass ONLY for genuine post-donation
        // donors (lvd set) whose windows have expired — the read-model path.
        // A stored 'standby' without donation history is never matchable.
        $donorScopeSql = $onlyDonorId !== null ? ' AND id = ?' : '';
        $stmt = $pdo->prepare(
            "SELECT id, full_name, role, chapter_id, account_status, verification_status,
                    email_verified_at, donor_enrolled_at, donor_availability,
                    last_verified_donation_at, blood_type, latitude, longitude, date_of_birth,
                    EXISTS (SELECT 1 FROM member_documents consent
                            WHERE consent.user_id = u.id AND consent.doc_type = 'parental_consent') AS has_parental_consent
             FROM users u
             WHERE blood_type IN ($placeholders){$donorScopeSql}"
        );
        $poolParams = $compatibleTypes;
        if ($onlyDonorId !== null) {
            $poolParams[] = $onlyDonorId;
        }
        $stmt->execute($poolParams);
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reqChapter = $request['request_chapter_id'] !== null ? (int) $request['request_chapter_id'] : null;

        $scored = [];
        foreach ($pool as $donor) {
            $eligibility = DonorEligibilityService::evaluateForRequest($donor, $request, $nowUtc);
            if (!$eligibility['eligible']) {
                continue;
            }

            $distance = Geo::distanceKm(
                $request['latitude'],
                $request['longitude'],
                $donor['latitude'],
                $donor['longitude']
            );

            $sameChapter = $reqChapter !== null
                && $donor['chapter_id'] !== null
                && (int) $donor['chapter_id'] === $reqChapter;

            $locatedBonus = $distance !== null ? 10000 : 0;
            $chapterBonus = $sameChapter ? 100 : 0;
            $proximityBonus = $distance !== null ? max(0, 5000 - (int) ceil($distance)) : 0;

            $scored[(int) $donor['id']] = [
                'row' => $donor,
                'distance' => $distance,
                'rank_score' => $locatedBonus + $chapterBonus + $proximityBonus,
            ];
        }
        uasort($scored, static fn (array $a, array $b): int => $b['rank_score'] <=> $a['rank_score']);

        $maxGenStmt = $pdo->prepare('SELECT COALESCE(MAX(generation), 0) FROM matches WHERE request_id = ?');
        $maxGenStmt->execute([$requestId]);
        $currentGen = (int) $maxGenStmt->fetchColumn();

        $generation = $bumpGeneration ? $currentGen + 1 : max(1, $currentGen);

        $existingSql = 'SELECT id, donor_id, status FROM matches WHERE request_id = ?';
        $existingParams = [$requestId];
        if ($onlyDonorId !== null) {
            $existingSql .= ' AND donor_id = ?';
            $existingParams[] = $onlyDonorId;
        }
        $existingStmt = $pdo->prepare($existingSql);
        $existingStmt->execute($existingParams);
        $existingByDonor = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $existingByDonor[(int) $m['donor_id']] = $m;
        }

        $inserted = 0;
        $updated = 0;
        $notificationCandidateIds = [];

        foreach ($scored as $donorId => $entry) {
            if (isset($existingByDonor[$donorId])) {
                $existing = $existingByDonor[$donorId];
                $status = (string) $existing['status'];
                if ($onlyDonorId !== null && in_array($status, ['CLOSED', 'RESPONDED', 'COMPLETED'], true)) {
                    continue;
                }
                if ($onlyDonorId !== null) {
                    $upd = $pdo->prepare(
                        'UPDATE matches SET distance_km = ?, rank_score = ? WHERE id = ?'
                    );
                    $upd->execute([$entry['distance'], $entry['rank_score'], $existing['id']]);
                } else {
                    if ($status === 'CLOSED') {
                        $status = 'POTENTIAL';
                    }
                    $upd = $pdo->prepare(
                        'UPDATE matches SET generation = ?, distance_km = ?, rank_score = ?, status = ? WHERE id = ?'
                    );
                    $upd->execute([$generation, $entry['distance'], $entry['rank_score'], $status, $existing['id']]);
                }
                $updated++;
                if ($onlyDonorId === null) {
                    $notificationCandidateIds[] = $donorId;
                }
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO matches (request_id, donor_id, generation, status, distance_km, rank_score)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $requestId,
                    $donorId,
                    $generation,
                    'POTENTIAL',
                    $entry['distance'],
                    $entry['rank_score'],
                ]);
                $inserted++;
                $notificationCandidateIds[] = $donorId;
            }
        }

        $closed = 0;
        foreach ($existingByDonor as $donorId => $existing) {
            $status = (string) $existing['status'];
            if (!isset($scored[$donorId]) && in_array($status, ['POTENTIAL', 'NOTIFIED'], true)) {
                $cls = $pdo->prepare("UPDATE matches SET status = 'CLOSED' WHERE id = ?");
                $cls->execute([$existing['id']]);
                $closed++;
            }
        }

        $sendEmergencyEmail = $onlyDonorId === null && $trigger === 'request_created';
        $sendEmailOnMatch = $trigger === 'donor_verified';
        $notifiedDonorIds = NotificationService::notifyMatchGeneration(
            $request,
            $generation,
            $notificationCandidateIds,
            $sendEmergencyEmail,
            $sendEmailOnMatch
        );

        if ($notifiedDonorIds !== []) {
            $ph = implode(',', array_fill(0, count($notifiedDonorIds), '?'));
            $pdo->prepare(
                "UPDATE matches SET status = 'NOTIFIED'
                 WHERE request_id = ? AND donor_id IN ($ph) AND status = 'POTENTIAL'"
            )->execute(array_merge([$requestId], $notifiedDonorIds));
        }

        AuditLogger::log(
            $actorId,
            $trigger === 'manual_rematch' ? 'match.manual_rematch' : 'match.generation',
            'blood_request',
            (string) $requestId,
            [
                'trigger' => $trigger,
                'generation' => $generation,
                'bumped' => $bumpGeneration,
                'donor_scope' => $onlyDonorId,
                'pool_size' => count($scored),
                'inserted' => $inserted,
                'updated' => $updated,
                'closed' => $closed,
            ]
        );

        return [
            'generation' => $generation,
            'pool_size' => count($scored),
            'inserted' => $inserted,
            'updated' => $updated,
            'closed' => $closed,
            'notified' => count($notifiedDonorIds),
        ];
    }

    /**
     * Reconcile one donor against at most 100 current OPEN requests.
     * Each request is isolated in its own transaction. Generation is never
     * bumped, notification email is disabled, and only this donor's unanswered
     * matches may change. CLOSED, RESPONDED and COMPLETED rows are preserved.
     *
     * @return int[] request IDs that were refreshed
     */
    public function refreshMatchesForDonor(
        int $donorId,
        string $trigger,
        ?int $actorId = null
    ): array
    {
        $pdo = Database::pdo();
        $nowUtc = AuthService::nowUtc();
        $stmt = $pdo->prepare(
            "SELECT id
               FROM blood_requests
              WHERE status = 'OPEN'
                AND needed_datetime >= ?
              ORDER BY id DESC
              LIMIT " . self::DONOR_RECONCILE_LIMIT
        );
        $stmt->execute([$nowUtc]);
        $requestIds = array_map(
            static fn ($r): int => (int) $r['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $refreshed = [];
        foreach ($requestIds as $requestId) {
            try {
                $pdo->beginTransaction();
                $this->generateForRequest(
                    $requestId,
                    false,
                    $trigger,
                    $actorId ?? $donorId,
                    $donorId
                );
                $pdo->commit();
                $refreshed[] = $requestId;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[matches] donor reconciliation failed for request ' . $requestId . ': ' . $e->getMessage());
            }
        }

        return $refreshed;
    }

    public function privacySafeMatches(int $requestId, ?int $onlyDonorId = null, ?array $viewer = null): array
    {
        $sql = 'SELECT m.id AS match_id, m.donor_id, m.generation, m.status, m.distance_km, m.rank_score,
                    br.requester_id, br.status AS request_status, u.full_name, u.chapter_id, c.name AS chapter_name,
                    u.verification_status, u.donor_availability
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             JOIN users u ON u.id = m.donor_id
             LEFT JOIN chapters c ON c.id = u.chapter_id
             WHERE m.request_id = ? AND m.status <> ?';
        $params = [$requestId, 'CLOSED'];
        if ($onlyDonorId !== null) {
            $sql .= ' AND m.donor_id = ?';
            $params[] = $onlyDonorId;
        }
        $sql .= ' ORDER BY m.generation DESC, m.rank_score DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $row) use ($viewer): array {
            $canViewProfile = $viewer !== null && (
                (int) $viewer['id'] === (int) $row['donor_id']
                || (string) $viewer['role'] === 'admin'
                || (string) $viewer['role'] === 'member'
                || ((string) $viewer['role'] === 'officer'
                    && $viewer['chapter_id'] !== null
                    && $row['chapter_id'] !== null
                    && (int) $viewer['chapter_id'] === (int) $row['chapter_id'])
                || ((int) $viewer['id'] === (int) $row['requester_id']
                    && (string) $row['request_status'] === 'OPEN'
                    && in_array((string) $row['status'], ['RESPONDED', 'COMPLETED'], true))
            );

            return [
                'match_id' => (int) $row['match_id'],
                'donor_reference' => 'donor-' . (int) $row['donor_id'],
                'is_current_user' => $viewer !== null && (int) $viewer['id'] === (int) $row['donor_id'],
                'display_name' => (string) $row['full_name'],
                'chapter_id' => $row['chapter_id'] !== null ? (int) $row['chapter_id'] : null,
                'chapter_name' => $row['chapter_name'] !== null ? (string) $row['chapter_name'] : null,
                'verification_status' => (string) $row['verification_status'],
                'availability' => $row['donor_availability'] !== null ? (string) $row['donor_availability'] : null,
                'approximate_distance_km' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 1) : null,
                'generation' => (int) $row['generation'],
                'status' => (string) $row['status'],
                'profile_user_id' => $canViewProfile ? (int) $row['donor_id'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
