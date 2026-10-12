<?php

declare(strict_types=1);

namespace BloodMatch\Services;

final class SuggestedChatService
{
    public const DONOR_SUGGESTIONS = [
        'When do you need the donation?',
        'Do you have any questions?',
        'What date and time would work best for the donation?',
    ];

    public const REQUESTOR_SUGGESTIONS = [
        'Thank you for being willing to help!',
        'Can I ask you something?',
        'When would you be available to donate?',
    ];

    /**
     * @return string[]|null
     */
    public static function getSuggestionsForRole(?string $matchRole): ?array
    {
        return match ($matchRole) {
            'donor' => self::DONOR_SUGGESTIONS,
            'requestor' => self::REQUESTOR_SUGGESTIONS,
            default => null,
        };
    }
}
