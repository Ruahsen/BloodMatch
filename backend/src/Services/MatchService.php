<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Config\Database;
use PDO;
use RuntimeException;

final class MatchService
{
    /**
     * Shared candidate-pool predicate (bulk matching AND single-donor
     * respond reconciliation use this exact fragment — one source of truth).
     * Takes the 4 standby/cooldown cutoff placeholders in order:
     * standby, cooldown, standby, cooldown. Callers append their own
     * blood-type condition.
     */
    public static function poolWhereClause(): string
    {
        return "role = 'member'
               AND account_status = 'active'
               AND verification_status = 'verified'
               AND donor_enrolled_at IS NOT NULL
               AND (
                     donor_availability = 'available'
                     OR (donor_availability = 'standby'
                         AND last_verified_donation_at IS NOT NULL
                         AND last_verified_donation_at <= ?
                         AND last_verified_donation_at <= ?)
                   )
               AND (last_verified_donation_at IS NULL
                    OR (last_verified_donation_at <= ? AND last_verified_donation_at <= ?))";
    }

    /**
     * Single-donor pool check sharing the pool predicate above: is this user
     * currently an eligible candidate for a request needing $requiredBloodType?
     * Used by request-scoped Respond reconciliation (feed) so it can never
     * drift from bulk matching semantics.
     */
    public static function isDonorInPool(int $donorId, string $requiredBloodType): bool
    {
        $compatibleTypes = BloodCompatibilityService::getCompatibleDonorTypes($requiredBloodType);
        $settings = SystemSettingsService::get();
        $cutoffs = DonorEligibilityService::cutoffsUtc(
            $settings['standby_hours'],
            $settings['cooldown_days'],
            AuthService::nowUtc()
        );
        $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM users WHERE id = ? AND ' . self::poolWhereClause() . " AND blood_type IN ($placeholders) LIMIT 1"
        );
        $stmt->execute(array_merge(
            [$donorId],
            [$cutoffs['standby'], $cutoffs['cooldown']],
            [$cutoffs['standby'], $cutoffs['cooldown']],
            $compatibleTypes
        ));
        return $stmt->fetchColumn() !== false;
    }

    public function generateForRequest(int $requestId, bool $bumpGeneration, string $trigger, ?int $actorId = null): array
    {
        $repo = new BloodRequestRepository();
        $request = $repo->findById($requestId);

        if ($request === null) {
            throw new RuntimeException('Request not found.', 404);
        }
        if ((string) $request['status'] !== 'OPEN') {
            throw new RuntimeException("Matching runs only against OPEN requests (current: {$request['status']}).", 409);
        }

        $compatibleTypes = BloodCompatibilityService::getCompatibleDonorTypes(
            (string) $request['required_blood_type']
        );

        $settings = SystemSettingsService::get();
        $nowUtc = AuthService::nowUtc();
        $cutoffs = DonorEligibilityService::cutoffsUtc(
            $settings['standby_hours'],
            $settings['cooldown_days'],
            $nowUtc
        );

        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));

        // Persisted availability 'standby' may pass ONLY for genuine post-donation
        // donors (lvd set) whose windows have expired — the read-model path.
        // A stored 'standby' without donation history is never matchable.
        $stmt = $pdo->prepare(
            'SELECT id, full_name, chapter_id, donor_availability, latitude, longitude
             FROM users
             WHERE ' . self::poolWhereClause() . " AND blood_type IN ($placeholders)"
        );
        $stmt->execute(array_merge(
            [$cutoffs['standby'], $cutoffs['cooldown']],
            [$cutoffs['standby'], $cutoffs['cooldown']],
            $compatibleTypes
        ));
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reqChapter = $request['request_chapter_id'] !== null ? (int) $request['request_chapter_id'] : null;

        $scored = [];
        foreach ($pool as $donor) {
            $distance = Geo::distanceKm(
                $request['latitude'],
                $request['longitude'],
                $donor['latitude'],
                $donor['longitude']
            );

            $scored[(int) $donor['id']] = [
                'row' => $donor,
                'distance' => $distance,
                'rank_score' => self::rankScore(
                    $distance,
                    $reqChapter,
                    $donor['chapter_id'] !== null ? (int) $donor['chapter_id'] : null
                ),
            ];
        }
        uasort($scored, static fn (array $a, array $b): int => $b['rank_score'] <=> $a['rank_score']);

        $maxGenStmt = $pdo->prepare('SELECT COALESCE(MAX(generation), 0) FROM matches WHERE request_id = ?');
        $maxGenStmt->execute([$requestId]);
        $currentGen = (int) $maxGenStmt->fetchColumn();

        $generation = $bumpGeneration || $currentGen === 0 ? $currentGen + 1 : $currentGen;

        $existingStmt = $pdo->prepare(
            'SELECT id, donor_id, status FROM matches WHERE request_id = ?'
        );
        $existingStmt->execute([$requestId]);
        $existingByDonor = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $existingByDonor[(int) $m['donor_id']] = $m;
        }

        $inserted = 0;
        $updated = 0;
        $nowUtc = AuthService::nowUtc();

        foreach ($scored as $donorId => $entry) {
            if (isset($existingByDonor[$donorId])) {
                $existing = $existingByDonor[$donorId];
                $status = (string) $existing['status'];
                if ($status === 'WITHDRAWN' || $status === 'COMPLETED') {
                    // Terminal history: never resurrected, never rewritten.
                    // COMPLETED keeps its donation-time generation/distance
                    // values as the historical record of the donation.
                    continue;
                }
                if ($status === 'CLOSED') {
                    // New matching episode: stale contact consent must not leak
                    // into it — both flags reset, fresh consent required.
                    $status = 'POTENTIAL';
                    $pdo->prepare(
                        'UPDATE matches SET donor_share_consent = 0, requester_share_consent = 0 WHERE id = ?'
                    )->execute([$existing['id']]);
                }
                $upd = $pdo->prepare(
                    'UPDATE matches SET generation = ?, distance_km = ?, rank_score = ?, status = ? WHERE id = ?'
                );
                $upd->execute([$generation, $entry['distance'], $entry['rank_score'], $status, $existing['id']]);
                $updated++;
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

        $notifiedDonorIds = NotificationService::notifyMatchGeneration(
            $request,
            $generation,
            array_keys($scored)
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
                'bumped' => $bumpGeneration || $currentGen === 0,
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
     * Reconcile persisted matches after a donor's location changed.
     *
     * Strictly donor-scoped: only THIS donor's rows on OPEN requests are
     * touched (distance AND the donor's own rank recomputed from the fresh
     * coordinates with the same scoring formula generation uses, so list
     * ordering stays truthful after a move). No candidate generation runs,
     * so unrelated donors are never regenerated, resurrected, re-ranked,
     * or notified, and no new match.new notifications are emitted.
     * Generation, status, and consents are preserved;
     * COMPLETED/CLOSED/WITHDRAWN history is never rewritten. ACCEPTED rows
     * keep their committed status (location is a display input, never a
     * reason to revoke a commitment).
     *
     * @return int[] request IDs that were refreshed
     */
    public function refreshMatchesForDonor(int $donorId, ?int $actorId = null): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT u.latitude AS donor_lat, u.longitude AS donor_lng, u.chapter_id AS donor_chapter
              FROM users u WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$donorId]);
        $donorLoc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($donorLoc === false) {
            return [];
        }

        $rowsStmt = $pdo->prepare(
            "SELECT m.id AS match_id, m.request_id, br.latitude AS req_lat, br.longitude AS req_lng,
                    br.request_chapter_id AS req_chapter
              FROM matches m
              JOIN blood_requests br ON br.id = m.request_id
              WHERE m.donor_id = ?
                AND m.status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED')
                AND br.status = 'OPEN'"
        );
        $rowsStmt->execute([$donorId]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $refreshed = [];
        $upd = $pdo->prepare('UPDATE matches SET distance_km = ?, rank_score = ? WHERE id = ?');
        foreach ($rows as $row) {
            try {
                $distance = Geo::distanceKm(
                    $row['req_lat'],
                    $row['req_lng'],
                    $donorLoc['donor_lat'],
                    $donorLoc['donor_lng']
                );
                $rank = self::rankScore(
                    $distance,
                    $row['req_chapter'] !== null ? (int) $row['req_chapter'] : null,
                    $donorLoc['donor_chapter'] !== null ? (int) $donorLoc['donor_chapter'] : null
                );
                $upd->execute([$distance, $rank, (int) $row['match_id']]);
                $refreshed[] = (int) $row['request_id'];
            } catch (\Throwable $e) {
                error_log('[matches] donor-location refresh failed for match ' . $row['match_id'] . ': ' . $e->getMessage());
            }
        }

        if ($refreshed !== []) {
            AuditLogger::log(
                $actorId ?? $donorId,
                'match.distance_refresh',
                'user',
                (string) $donorId,
                ['requests' => array_values(array_unique($refreshed))]
            );
        }

        return array_values(array_unique($refreshed));
    }

    /**
     * Single ranking formula shared by generation and donor-scoped refresh
     * (Compatibility → Availability → Verification are pool gates upstream;
     * this scores Proximity + chapter preference among eligible donors).
     */
    public static function rankScore(?float $distanceKm, ?int $requestChapterId, ?int $donorChapterId): int
    {
        $sameChapter = $requestChapterId !== null
            && $donorChapterId !== null
            && $donorChapterId === $requestChapterId;

        $locatedBonus = $distanceKm !== null ? 10000 : 0;
        $chapterBonus = $sameChapter ? 100 : 0;
        $proximityBonus = $distanceKm !== null ? max(0, 5000 - (int) ceil($distanceKm)) : 0;

        return $locatedBonus + $chapterBonus + $proximityBonus;
    }

    public function countActiveMatches(int $requestId, ?int $onlyDonorId = null): int
    {
        $sql = "SELECT COUNT(*) FROM matches m WHERE m.request_id = ? AND m.status NOT IN ('CLOSED', 'WITHDRAWN')";
        $params = [$requestId];
        if ($onlyDonorId !== null) {
            $sql .= ' AND m.donor_id = ?';
            $params[] = $onlyDonorId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function privacySafeMatches(int $requestId, ?int $onlyDonorId = null, int $page = 1, int $pageSize = 50): array
    {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT m.id AS match_id, m.donor_id, m.generation, m.status, m.distance_km, m.rank_score,
                    u.full_name, u.chapter_id, c.name AS chapter_name,
                    u.verification_status, u.donor_availability
              FROM matches m
              JOIN users u ON u.id = m.donor_id
              LEFT JOIN chapters c ON c.id = u.chapter_id
              WHERE m.request_id = ? AND m.status NOT IN ('CLOSED', 'WITHDRAWN')";
        $params = [$requestId];
        if ($onlyDonorId !== null) {
            $sql .= ' AND m.donor_id = ?';
            $params[] = $onlyDonorId;
        }
        $sql .= ' ORDER BY m.generation DESC, m.rank_score DESC';
        $sql .= ' LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'match_id' => (int) $row['match_id'],
            'donor_reference' => 'donor-' . (int) $row['donor_id'],
            'display_name' => (string) $row['full_name'],
            'chapter_id' => $row['chapter_id'] !== null ? (int) $row['chapter_id'] : null,
            'chapter_name' => $row['chapter_name'] !== null ? (string) $row['chapter_name'] : null,
            'verification_status' => (string) $row['verification_status'],
            'availability' => $row['donor_availability'] !== null ? (string) $row['donor_availability'] : null,
            'approximate_distance_km' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 1) : null,
            'generation' => (int) $row['generation'],
            'status' => (string) $row['status'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Sanitized WITHDRAWN history for a request (requester/officer view).
     * Shows that a previously engaged donor withdrew — status and reference
     * only, no contact, no actions, no re-engagement.
     *
     * @return array<int, array{match_id:int, donor_reference:string, status:string, updated_at:string}>
     */
    public function terminalHistoryForRequest(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id AS match_id, donor_id, status, updated_at FROM matches
              WHERE request_id = ? AND status = 'WITHDRAWN'
              ORDER BY updated_at DESC"
        );
        $stmt->execute([$requestId]);
        return array_map(static fn (array $row): array => [
            'match_id' => (int) $row['match_id'],
            'donor_reference' => 'donor-' . (int) $row['donor_id'],
            'status' => (string) $row['status'],
            'updated_at' => (string) $row['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Sanitized terminal history for an involved principal (donor's own row).
     * WITHDRAWN/CLOSED are never active matching data, but the donor and the
     * requester may still see the terminal status of their own relationship.
     * No contact, no actions, no private information — status only.
     *
     * @return array<int, array{match_id:int, status:string, updated_at:string}>
     */
    public function principalTerminalHistory(int $requestId, int $donorId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id AS match_id, status, updated_at FROM matches
              WHERE request_id = ? AND donor_id = ? AND status IN ('CLOSED', 'WITHDRAWN')
              ORDER BY updated_at DESC"
        );
        $stmt->execute([$requestId, $donorId]);
        return array_map(static fn (array $row): array => [
            'match_id' => (int) $row['match_id'],
            'status' => (string) $row['status'],
            'updated_at' => (string) $row['updated_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
