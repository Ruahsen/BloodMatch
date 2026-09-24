<?php

declare(strict_types=1);

namespace BloodMatch\Services;

final class CapabilityMatrix
{
    public static function evaluate(array $user): array
    {
        $active = (string) $user['account_status'] === 'active';
        $vs = (string) $user['verification_status'];

        return [
            'browse_requests' => $active && $vs !== 'rejected'
                ? ($vs === 'unverified' ? 'limited' : 'full')
                : false,
            'create_request' => $active && in_array($vs, ['pending', 'verified'], true),
            'appear_as_donor' => $active && $vs === 'verified',
            'resubmit_verification' => $active && $vs === 'rejected',
            'account_locked' => !$active,
        ];
    }

    public const BLOOD_TYPE_NOTICE_UNVERIFIED =
        'Self-reported blood type. Not verified and never presented as medically confirmed.';
    public const BLOOD_TYPE_NOTICE_ADMIN_VERIFIED =
        'Blood type information is administratively verified from submitted documentation and is not a substitute for medical confirmation.';
}
