<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use DateTimeImmutable;

final class AgeEligibilityService
{
    public static function evaluate(?string $dobUtcDate, bool $hasParentalConsent): array
    {
        if ($dobUtcDate === null) {
            return [
                'age' => null,
                'category' => 'unknown',
                'donor_path_allowed' => false,
                'requires_parental_consent' => false,
                'consent_satisfied' => false,
                'reason' => 'Date of birth is required before donor eligibility can be assessed.',
            ];
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $dobUtcDate);
        if ($birth === false || $birth->format('Y-m-d') !== $dobUtcDate) {
            return [
                'age' => null,
                'category' => 'unknown',
                'donor_path_allowed' => false,
                'requires_parental_consent' => false,
                'consent_satisfied' => false,
                'reason' => 'Date of birth is invalid.',
            ];
        }

        $today = new DateTimeImmutable('today');
        $age = (int) $today->diff($birth)->y;
        $hadBirthday = ($today->format('m-d') >= $birth->format('m-d'));
        if (!$hadBirthday && $age > 0) {
            $boundaryCheck = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                ($birth->format('Y') + $age) . '-' . $birth->format('m-d')
            );
            if ($boundaryCheck !== null && $boundaryCheck > $today) {
                $age--;
            }
        }

        if ($age < 16) {
            return [
                'age' => $age,
                'category' => 'under_16',
                'donor_path_allowed' => false,
                'requires_parental_consent' => false,
                'consent_satisfied' => false,
                'reason' => 'Under 16: cannot participate as an eligible donor.',
            ];
        }

        if ($age < 18) {
            return [
                'age' => $age,
                'category' => 'minor_16_17',
                'donor_path_allowed' => $hasParentalConsent,
                'requires_parental_consent' => true,
                'consent_satisfied' => $hasParentalConsent,
                'reason' => $hasParentalConsent
                    ? 'Ages 16-17: parental/guardian consent on file; medical screening still governs donation.'
                    : 'Ages 16-17 require documented parental/guardian consent before donor eligibility.',
            ];
        }

        return [
            'age' => $age,
            'category' => 'adult',
            'donor_path_allowed' => true,
            'requires_parental_consent' => false,
            'consent_satisfied' => false,
            'reason' => 'Adult: standard platform prerequisites apply; facility screening governs donation.',
        ];
    }
}
