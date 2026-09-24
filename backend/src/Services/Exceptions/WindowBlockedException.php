<?php

declare(strict_types=1);

namespace BloodMatch\Services\Exceptions;

use RuntimeException;

final class WindowBlockedException extends RuntimeException
{
    public function __construct(string $message, private array $window)
    {
        parent::__construct($message, 409);
    }

    public function window(): array
    {
        return $this->window;
    }
}
