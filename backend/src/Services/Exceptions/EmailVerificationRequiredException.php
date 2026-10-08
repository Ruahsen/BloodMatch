<?php

declare(strict_types=1);

namespace BloodMatch\Services\Exceptions;

use RuntimeException;

// Sibling of (not a child of) AuthException, which is final. Carries the
// login 403 status plus machine-readable guidance for the login UI.
final class EmailVerificationRequiredException extends RuntimeException
{
    public function __construct(string $message, private array $details)
    {
        parent::__construct($message, 403);
    }

    /**
     * Machine-readable guidance for the login UI. Carries only a stable
     * code, the masked address, and a fresh single-purpose claim token -
     * never passwords, OTPs, hashes, or other secrets.
     */
    public function details(): array
    {
        return $this->details;
    }
}
