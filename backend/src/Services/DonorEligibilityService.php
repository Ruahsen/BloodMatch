<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use BloodMatch\Services\Exceptions\WindowBlockedException;

final class DonorEligibilityService
{
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
