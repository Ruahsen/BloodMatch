<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Repositories\UserRepository;
use PDO;

/**
 * Authenticated Home feed: requests ranked FOR a viewer.
 *
 * This is a separate ranking model from MatchService (which ranks DONORS for
 * a REQUEST). Both share the same authorities — BloodCompatibilityService,
 * DonorEligibilityService, Geo — and this service introduces no second
 * matrix, no second Haversine, and no second eligibility implementation.
 * SQL filters candidates only; ranking and slicing happen here so the global
 * order is computed before pagination.
 */
final class RequestFeedService
{
    public const PAGE_SIZE_DEFAULT = 15;
    public const PAGE_SIZE_MAX = 50;

    private const URGENCY_WEIGHT = ['critical' => 0, 'urgent' => 1, 'routine' => 2];

    /**
     * Top-level feed scopes only narrow the candidate dataset; ranking below
     * is untouched. 'match' restricts to the viewer's own qualifying match
     * relationships; 'critical' forces urgency=critical (ANDed with the
     * right-side filters).
     *
     * @return array{requests:array, total:int, page:int, page_size:int, match_count:int, viewer:array{has_location:bool}}
     */
    public function getFeed(array $viewer, array $filters, int $page, int $pageSize): array
    {
        $viewerId = (int) $viewer['id'];
        $ctx = $this->viewerContext($viewer);
        $scope = $filters['feed_scope'] ?? 'all';

        $repo = new BloodRequestRepository();
        $candidates = $repo->listOpenFeedCandidates(
            $filters['blood_type'] ?? null,
            $scope === 'critical' ? 'critical' : ($filters['urgency'] ?? null),
            $filters['chapter_id'] ?? null,
            $scope === 'match' ? $viewerId : null,
            $scope === 'match' ? MatchRepository::MATCH_TAB_STATUSES : []
        );

        $nearMe = !empty($filters['near_me']);
        $ownMatches = (new MatchRepository())->listMatchesForDonor($viewerId);

        $ranked = [];
        foreach ($candidates as $row) {
            $distance = Geo::distanceKm(
                $viewer['latitude'],
                $viewer['longitude'],
                $row['latitude'],
                $row['longitude']
            );
            if ($nearMe && $distance === null) {
                continue;
            }

            $tier = $this->tierFor($ctx, (string) $row['required_blood_type']);

            $ranked[] = [
                'row' => $row,
                'tier' => $tier,
                'distance' => $distance,
                'urgency' => self::URGENCY_WEIGHT[(string) $row['urgency']] ?? 9,
                'needed' => (string) $row['needed_datetime'],
                'created' => (string) $row['created_at'],
                'id' => (int) $row['id'],
            ];
        }

        usort($ranked, static function (array $a, array $b) use ($nearMe): int {
            if ($a['tier'] !== $b['tier']) {
                return $a['tier'] <=> $b['tier'];
            }
            if ($nearMe) {
                // Compatibility first; distance is the first secondary key.
                $cmp = self::cmpNullableFloat($a['distance'], $b['distance']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                if ($a['urgency'] !== $b['urgency']) {
                    return $a['urgency'] <=> $b['urgency'];
                }
            } else {
                if ($a['urgency'] !== $b['urgency']) {
                    return $a['urgency'] <=> $b['urgency'];
                }
            }
            if ($a['needed'] !== $b['needed']) {
                return $a['needed'] <=> $b['needed'];
            }
            if (!$nearMe) {
                $cmp = self::cmpNullableFloat($a['distance'], $b['distance']);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            if ($a['created'] !== $b['created']) {
                return $b['created'] <=> $a['created'];
            }
            return $b['id'] <=> $a['id'];
        });

        $total = count($ranked);
        $pageItems = array_slice($ranked, max(0, ($page - 1) * $pageSize), $pageSize);

        $requests = array_map(
            fn (array $entry): array => $this->serialize($entry, $ctx, $ownMatches, $viewerId),
            $pageItems
        );

        // Global match count: distinct OPEN requests carrying the viewer's
        // own qualifying relationships, independent of right-side filters,
        // so the Match tab label stays stable while the list composes.
        $matchCount = (new MatchRepository())->countMatchedOpenRequests($viewerId);

        return [
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'match_count' => $matchCount,
            'viewer' => ['has_location' => $ctx['has_location']],
        ];
    }

    private static function cmpNullableFloat(?float $a, ?float $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        return $a <=> $b;
    }

    /**
     * Viewer donor context, computed once per feed request from the LIVE user
     * row (never from persisted match rows, which may be stale).
     *
     * @return array{blood:?string, verified_blood:bool, actionable:bool, block_reason:?string, has_location:bool}
     */
    private function viewerContext(array $viewer): array
    {
        $blood = $viewer['blood_type'] !== null ? (string) $viewer['blood_type'] : null;
        $verifiedBlood = (int) ($viewer['blood_type_verified'] ?? 0) === 1;
        $hasLocation = $viewer['latitude'] !== null && $viewer['longitude'] !== null;

        $blockReason = null;
        if ((string) $viewer['role'] !== 'member' || (string) $viewer['verification_status'] !== 'verified') {
            $blockReason = 'unverified_account';
        } elseif ($viewer['donor_enrolled_at'] === null) {
            $blockReason = 'unenrolled_donor';
        } else {
            $availability = $viewer['donor_availability'] !== null ? (string) $viewer['donor_availability'] : null;
            $window = DonorEligibilityService::evaluateWindows(
                $viewer['last_verified_donation_at'] !== null ? (string) $viewer['last_verified_donation_at'] : null
            );
            if ($availability === 'available' || ($availability === 'standby' && !$window['blocked'] && $viewer['last_verified_donation_at'] !== null)) {
                $docs = new DocumentRepository();
                $age = AgeEligibilityService::evaluate(
                    $viewer['date_of_birth'] !== null ? (string) $viewer['date_of_birth'] : null,
                    $docs->hasType((int) $viewer['id'], 'parental_consent')
                );
                if (!$age['donor_path_allowed']) {
                    $blockReason = 'age_ineligible';
                } elseif (!$verifiedBlood) {
                    // Self-reported blood participates in ranking but is never
                    // presented as medically confirmed (see label + notice).
                    $blockReason = null;
                }
            } elseif ($window['blocked'] && $window['which'] === 'standby') {
                $blockReason = 'standby_active';
            } elseif ($window['blocked'] && $window['which'] === 'cooldown') {
                $blockReason = 'cooldown_active';
            } else {
                $blockReason = 'donor_unavailable';
            }
        }

        return [
            'blood' => $blood,
            'verified_blood' => $verifiedBlood,
            'actionable' => $blockReason === null,
            'block_reason' => $blockReason,
            'has_location' => $hasLocation,
        ];
    }

    /**
     * Tier 0 = compatible + actionable; 1 = compatible + blocked;
     * 2 = viewer blood unknown; 3 = incompatible. Incompatible rows stay
     * visible — they rank last instead of being hidden.
     */
    private function tierFor(array $ctx, string $requiredBloodType): int
    {
        if ($ctx['blood'] === null) {
            return 2;
        }
        $allowed = BloodCompatibilityService::getCompatibleDonorTypes($requiredBloodType);
        if (!in_array($ctx['blood'], $allowed, true)) {
            return 3;
        }
        return $ctx['actionable'] ? 0 : 1;
    }

    /**
     * Member-safe feed serializer. Deliberately NOT RequestService::publicView
     * (which exposes raw latitude/longitude): the feed carries location
     * labels plus approximate distance only.
     */
    private function serialize(array $entry, array $ctx, array $ownMatches, int $viewerId): array
    {
        $row = $entry['row'];
        $requestId = (int) $row['id'];
        $isOwn = (int) $row['requester_id'] === $viewerId;
        $own = $ownMatches[$requestId] ?? null;
        $ownStatus = $own !== null ? (string) $own['status'] : null;
        // match_id is the viewer's OWN match only, otherwise null.
        $ownMatchId = $own !== null ? (int) $own['match_id'] : null;

        [$action, $reason] = $this->actionFor($entry['tier'], $ctx, $isOwn, $ownStatus);

        return [
            'id' => $requestId,
            'required_blood_type' => (string) $row['required_blood_type'],
            'quantity_units' => (int) $row['quantity_units'],
            'urgency' => (string) $row['urgency'],
            'needed_datetime' => (string) $row['needed_datetime'],
            'facility_name' => (string) $row['facility_name'],
            'location' => RequestService::locationView($row),
            'approximate_distance_km' => $entry['distance'] !== null ? round((float) $entry['distance'], 1) : null,
            'request_chapter_id' => $row['request_chapter_id'] !== null ? (int) $row['request_chapter_id'] : null,
            'chapter_name' => $row['chapter_name'] !== null ? (string) $row['chapter_name'] : null,
            'status' => (string) $row['status'],
            'review_status' => (string) $row['review_status'],
            'created_at' => (string) $row['created_at'],
            'compatibility_tier' => $entry['tier'],
            'compatibility_label' => $this->tierLabel($entry['tier'], $ctx),
            'blood_type_notice' => $ctx['blood'] !== null && !$ctx['verified_blood']
                ? CapabilityMatrix::BLOOD_TYPE_NOTICE_UNVERIFIED
                : null,
            'is_own' => $isOwn,
            'my_match_status' => $ownStatus,
            'match_id' => $ownMatchId,
            'primary_action' => $action,
            'action_reason' => $reason,
        ];
    }

    private function tierLabel(int $tier, array $ctx): string
    {
        if ($tier === 0) {
            return 'Compatible with your blood type';
        }
        if ($tier === 2) {
            return 'Add your blood type to personalize';
        }
        if ($tier === 3) {
            return 'Not compatible with your blood type';
        }
        return 'Compatible · ' . match ($ctx['block_reason']) {
            'unenrolled_donor' => 'donor enrollment needed',
            'donor_unavailable' => 'currently unavailable',
            'standby_active' => 'in post-donation standby',
            'cooldown_active' => 'in donation cooldown',
            'unverified_account' => 'account verification needed',
            'age_ineligible' => 'age requirement not met',
            default => 'not currently actionable',
        };
    }

    /**
     * Compact server-derived action contract: exactly one primary action plus
     * a stable reason. The frontend renders; the backend authorizes.
     *
     * @return array{0:string, 1:string}
     */
    private function actionFor(int $tier, array $ctx, bool $isOwn, ?string $ownStatus): array
    {
        if ($isOwn) {
            return ['view_matches', 'own_request'];
        }
        if ($ownStatus === 'WITHDRAWN') {
            return ['none', 'withdrawn_terminal'];
        }
        if ($ownStatus === 'ACCEPTED') {
            return ['view_match', 'accepted_connected'];
        }
        if ($ownStatus === 'RESPONDED') {
            return ['view_match', 'already_responded'];
        }
        if ($ownStatus === 'COMPLETED') {
            return ['view_match', 'donation_completed'];
        }
        if ($tier !== 0) {
            return ['none', match ($tier) {
                2 => 'no_blood_type',
                3 => 'incompatible',
                default => $ctx['block_reason'] ?? 'not_actionable',
            }];
        }
        return ['respond', 'ready'];
    }
}
