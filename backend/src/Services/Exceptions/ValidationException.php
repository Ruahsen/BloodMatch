<?php

declare(strict_types=1);

namespace BloodMatch\Services\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed.', 400);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
