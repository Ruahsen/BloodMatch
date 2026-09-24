<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Config\Database;
use PDO;
use RuntimeException;

final class MatchService
{
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
            "SELECT id, full_name, chapter_id, donor_availability, latitude, longitude
             FROM users
             WHERE role = 'member'
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
                    OR (last_verified_donation_at <= ? AND last_verified_donation_at <= ?))
               AND blood_type IN ($placeholders)"
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
                if ($status === 'CLOSED') {
                    $status = 'POTENTIAL';
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

    public function privacySafeMatches(int $requestId, ?int $onlyDonorId = null): array
    {
        $sql = 'SELECT m.id AS match_id, m.donor_id, m.generation, m.status, m.distance_km, m.rank_score,
                    u.full_name, u.chapter_id, c.name AS chapter_name,
                    u.verification_status, u.donor_availability
             FROM matches m
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
}
