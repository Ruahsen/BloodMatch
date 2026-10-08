<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Services\Exceptions\WindowBlockedException;

final class DonorEligibilityService
{
    /**
     * Safety/identity prerequisites for COMMITTING a donor relationship
     * (acceptance, donation confirmation). Unlike the candidate pool
     * predicate (MatchService::poolWhereClause), this intentionally omits
     * scheduling rules - availability toggles, post-donation standby, and
     * inter-donation cooldown govern FUTURE candidacy, not whether an
     * already-responded donor relationship may be committed or a physically
     * completed donation may be confirmed. Account activity, verification,
     * enrollment, blood compatibility, and age are safety prerequisites and
     * are always enforced here.
     */
    public static function assertSafetyEligible(?array $donor, string $requiredBloodType, string $endpoint): void
    {
        $donorId = $donor !== null ? (int) $donor['id'] : 0;
        $deny = static function (string $reason, string $message) use ($donorId, $endpoint): never {
            AuditLogger::log($donorId !== 0 ? $donorId : null, 'authz.denied', 'blood_request', null, [
                'endpoint' => $endpoint,
                'reason' => $reason,
            ]);
            throw new RuntimeException($message, 409);
        };

        if ($donor === null || (string) $donor['account_status'] !== 'active') {
            $deny('donor_not_active', 'The donor account is no longer active.');
        }
        if ((string) $donor['verification_status'] !== 'verified') {
            $deny('donor_not_verified', 'The donor is no longer verified.');
        }
        if ($donor['donor_enrolled_at'] === null) {
            $deny('donor_not_enrolled', 'The donor is no longer enrolled as a donor.');
        }

        $compatible = BloodCompatibilityService::getCompatibleDonorTypes($requiredBloodType);
        if (!in_array((string) $donor['blood_type'], $compatible, true)) {
            $deny('donor_incompatible', 'The donor blood type is no longer compatible with this request.');
        }

        $age = AgeEligibilityService::evaluate(
            $donor['date_of_birth'] !== null ? (string) $donor['date_of_birth'] : null,
            (new DocumentRepository())->hasType((int) $donor['id'], 'parental_consent')
        );
        if (!$age['donor_path_allowed']) {
            $deny('age_ineligible', (string) $age['reason']);
        }
    }

    public static function nowUtc(): string
    {
        return AuthService::nowUtc();
    }

    public static function cutoffsUtc(?int $standbyHours, ?int $cooldownDays, string $nowUtc): array
    {
        $ts = strtotime($nowUtc . ' UTC');
        return [
            'standby' => gmdate('Y-m-d H:i:s', $ts - ($standbyHours ?? 0) * 3600),
            'cooldown' => gmdate('Y-m-d H:i:s', $ts - ($cooldownDays ?? 0) * 86400),
        ];
    }

    public static function evaluateWindows(?string $lastVerifiedDonationAt, string $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? self::nowUtc();

        if ($lastVerifiedDonationAt === null || $lastVerifiedDonationAt === '') {
            return [
                'blocked' => false,
                'which' => null,
                'ends_at_utc' => null,
                'remaining_seconds' => 0,
            ];
        }

        $settings = SystemSettingsService::get();
        $anchorTs = strtotime($lastVerifiedDonationAt . ' UTC');
        if ($anchorTs === false) {
            throw new RuntimeException('Invalid last_verified_donation_at value.');
        }
        $nowTs = strtotime($nowUtc . ' UTC');

        $standbyEndTs = $anchorTs + $settings['standby_hours'] * 3600;
        $cooldownEndTs = $anchorTs + $settings['cooldown_days'] * 86400;

        if ($nowTs < $standbyEndTs) {
            return [
                'blocked' => true,
                'which' => 'standby',
                'ends_at_utc' => gmdate('Y-m-d H:i:s', $standbyEndTs),
                'remaining_seconds' => max(0, $standbyEndTs - $nowTs),
            ];
        }

        if ($nowTs < $cooldownEndTs) {
            return [
                'blocked' => true,
                'which' => 'cooldown',
                'ends_at_utc' => gmdate('Y-m-d H:i:s', $cooldownEndTs),
                'remaining_seconds' => max(0, $cooldownEndTs - $nowTs),
            ];
        }

        return [
            'blocked' => false,
            'which' => null,
            'ends_at_utc' => null,
            'remaining_seconds' => 0,
        ];
    }

    public static function assertAvailabilityChangeAllowed(array $user, ?string $nowUtc = null): void
    {
        if ($user['donor_enrolled_at'] === null) {
            throw new RuntimeException('Enroll as a donor before changing availability.', 409);
        }
        if ((string) $user['account_status'] !== 'active') {
            throw new RuntimeException('Deactivated accounts cannot change availability.', 403);
        }

        $window = self::evaluateWindows(
            $user['last_verified_donation_at'] !== null ? (string) $user['last_verified_donation_at'] : null,
            $nowUtc
        );

        if ($window['blocked']) {
            throw new WindowBlockedException(
                sprintf(
                    'Post-donation %s window is active until %s UTC. This is a BloodMatch administrative interval; actual donation eligibility is determined by the authorized blood-donation facility.',
                    $window['which'],
                    $window['ends_at_utc']
                ),
                $window
            );
        }
    }
}
