<?php

declare(strict_types=1);

namespace BloodMatch\Repositories;

use BloodMatch\Config\Database;
use PDO;

final class MatchRepository
{
    public const STATUSES = ['POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED', 'COMPLETED', 'CLOSED', 'WITHDRAWN'];

    /**
     * Match states that represent an unresolved donor relationship. ACCEPTED is
     * included because it carries a live contact relationship that terminal
     * request states (fulfillment/cancellation/expiry) must close.
     * COMPLETED and WITHDRAWN are terminal history and are never swept.
     */
    public const UNRESOLVED_STATUSES = ['POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED'];

    /**
     * Match states that qualify a request for the Home feed "Match" tab:
     * the viewer's own ACTION-BACKED relationships on OPEN requests.
     * RESPONDED = donor explicitly willing (consent given); ACCEPTED =
     * requester-selected bilateral commitment; COMPLETED = confirmed
     * donation on a still-OPEN (multi-unit) request.
     * Excluded: POTENTIAL (engine-inserted candidate the donor never acted
     * on — may never even have been notified) and NOTIFIED (an "opportunity"
     * notification, still no donor action) — surfacing those as "Match"
     * would duplicate compatibility ranking under a misleading label with an
     * inflated count. Also excluded: CLOSED (system-closed/ineligible) and
     * WITHDRAWN (explicit donor withdrawal — re-surfacing it would
     * contradict the withdrawal).
     */
    public const MATCH_TAB_STATUSES = ['RESPONDED', 'ACCEPTED', 'COMPLETED'];

    /**
     * Distinct OPEN requests (requester still active) carrying at least one
     * of the viewer's own qualifying match relationships. Single query —
     * no per-row lookups.
     */
    public function countMatchedOpenRequests(int $donorId): int
    {
        $ph = implode(',', array_fill(0, count(self::MATCH_TAB_STATUSES), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(DISTINCT m.request_id) FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             JOIN users req ON req.id = br.requester_id
             WHERE m.donor_id = ? AND m.status IN ($ph)
               AND br.status = 'OPEN' AND req.account_status = 'active'"
        );
        $stmt->execute(array_merge([$donorId], self::MATCH_TAB_STATUSES));
        return (int) $stmt->fetchColumn();
    }

    public function findByIdDetailed(int $matchId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, br.status AS request_status, br.request_chapter_id, br.quantity_units
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByIdForUpdate(int $matchId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, br.status AS request_status, br.request_chapter_id, br.quantity_units
             FROM matches m
             JOIN blood_requests br ON br.id = m.request_id
             WHERE m.id = ? LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function setStatus(int $matchId, string $status): void
    {
        $allowed = self::STATUSES;
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid match status.');
        }
        $stmt = Database::pdo()->prepare('UPDATE matches SET status = ? WHERE id = ?');
        $stmt->execute([$status, $matchId]);
    }

    public function findByRequestAndDonor(int $requestId, int $donorId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, generation, status, donor_share_consent, requester_share_consent FROM matches WHERE request_id = ? AND donor_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId, $donorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function countCompleted(int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM matches WHERE request_id = ? AND status = 'COMPLETED'"
        );
        $stmt->execute([$requestId]);
        return (int) $stmt->fetchColumn();
    }

    public static function countAccepted(int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM matches WHERE request_id = ? AND status = 'ACCEPTED'"
        );
        $stmt->execute([$requestId]);
        return (int) $stmt->fetchColumn();
    }

    public static function fulfillAndCloseUnresolved(int $requestId): int
    {
        $close = Database::pdo()->prepare(
            "UPDATE matches SET status = 'CLOSED'
             WHERE request_id = ? AND status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED')"
        );
        $close->execute([$requestId]);
        $closedCount = $close->rowCount();

        $upd = Database::pdo()->prepare(
            "UPDATE blood_requests SET status = 'FULFILLED' WHERE id = ?"
        );
        $upd->execute([$requestId]);

        return $closedCount;
    }

    /**
     * Close unresolved relationships for a request reaching a terminal state
     * (cancellation/expiry). COMPLETED and WITHDRAWN history is preserved.
     */
    public static function closeUnresolvedForRequest(int $requestId): int
    {
        $close = Database::pdo()->prepare(
            "UPDATE matches SET status = 'CLOSED'
             WHERE request_id = ? AND status IN ('POTENTIAL', 'NOTIFIED', 'RESPONDED', 'ACCEPTED')"
        );
        $close->execute([$requestId]);
        return $close->rowCount();
    }

    /**
     * Close a principal's ACCEPTED relationships on OPEN requests (account
     * deactivation / verification invalidation). Returns affected rows with
     * counterpart references for notification. Targeted cleanup only — no
     * matching fan-out; COMPLETED/WITHDRAWN history is preserved.
     *
     * @return array<int, array{match_id:int, request_id:int, donor_id:int, requester_id:int, reason:string}>
     */
    public static function closeAcceptedForUser(int $userId, string $reason): array
    {
        $pdo = Database::pdo();
        $sel = $pdo->prepare(
            "SELECT m.id AS match_id, m.request_id, m.donor_id, br.requester_id
              FROM matches m
              JOIN blood_requests br ON br.id = m.request_id
              WHERE m.status = 'ACCEPTED' AND br.status = 'OPEN'
                AND (m.donor_id = ? OR br.requester_id = ?)"
        );
        $sel->execute([$userId, $userId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['match_id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE matches SET status = 'CLOSED' WHERE id IN ($ph)")->execute($ids);

        return array_map(static fn (array $r): array => [
            'match_id' => (int) $r['match_id'],
            'request_id' => (int) $r['request_id'],
            'donor_id' => (int) $r['donor_id'],
            'requester_id' => (int) $r['requester_id'],
            'reason' => $reason,
        ], $rows);
    }

    /**
     * OPEN ACCEPTED relationships for a donor, with the request's required
     * blood type (for blood-type-change safety invalidation).
     *
     * @return array<int, array{match_id:int, request_id:int, required_blood_type:string, requester_id:int}>
     */
    public function listAcceptedForDonor(int $donorId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT m.id AS match_id, m.request_id, br.required_blood_type, br.requester_id
              FROM matches m
              JOIN blood_requests br ON br.id = m.request_id
              WHERE m.donor_id = ? AND m.status = 'ACCEPTED' AND br.status = 'OPEN'"
        );
        $stmt->execute([$donorId]);
        return array_map(static fn (array $r): array => [
            'match_id' => (int) $r['match_id'],
            'request_id' => (int) $r['request_id'],
            'required_blood_type' => (string) $r['required_blood_type'],
            'requester_id' => (int) $r['requester_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A donor's own match rows keyed by request (single query for feed action
     * state — no per-row lookups). Includes every status; callers decide what
     * each state means for actions.
     *
     * @return array<int, array{match_id:int, status:string}>
     */
    public function listMatchesForDonor(int $donorId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT request_id, id AS match_id, status FROM matches WHERE donor_id = ?'
        );
        $stmt->execute([$donorId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['request_id']] = [
                'match_id' => (int) $r['match_id'],
                'status' => (string) $r['status'],
            ];
        }
        return $map;
    }

    /**
     * @return array<int, array{match_id:int, donor_id:int}>
     */
    public function listAcceptedDonors(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT id AS match_id, donor_id FROM matches
              WHERE request_id = ? AND status = 'ACCEPTED'"
        );
        $stmt->execute([$requestId]);
        return array_map(static fn (array $r): array => [
            'match_id' => (int) $r['match_id'],
            'donor_id' => (int) $r['donor_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByRequestAndDonorForUpdate(int $requestId, int $donorId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*
              FROM matches m
              WHERE m.request_id = ? AND m.donor_id = ? LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$requestId, $donorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateDistance(int $matchId, ?float $distanceKm): void
    {
        $stmt = Database::pdo()->prepare('UPDATE matches SET distance_km = ? WHERE id = ?');
        $stmt->execute([$distanceKm, $matchId]);
    }

    public function setConsent(int $matchId, string $side, bool $value): void
    {
        $col = $side === 'donor' ? 'donor_share_consent' : 'requester_share_consent';
        $stmt = Database::pdo()->prepare("UPDATE matches SET {$col} = ? WHERE id = ?");
        $stmt->execute([$value ? 1 : 0, $matchId]);
    }

    public function resetConsents(int $matchId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE matches SET donor_share_consent = 0, requester_share_consent = 0 WHERE id = ?'
        );
        $stmt->execute([$matchId]);
    }
}
