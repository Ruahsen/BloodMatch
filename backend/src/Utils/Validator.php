<?php

declare(strict_types=1);

namespace BloodMatch\Utils;

final class Validator
{
    private array $errors = [];

    public function required(string $field, mixed $value, string $label): self
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->errors[$field][] = $label . ' is required.';
        }
        return $this;
    }

    public function email(string $field, mixed $value): self
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field][] = 'Must be a valid email address.';
        }
        return $this;
    }

    public function length(string $field, mixed $value, int $min, int $max, string $label): self
    {
        if (is_string($value) && $value !== '') {
            $len = mb_strlen($value);
            if ($len < $min || $len > $max) {
                $this->errors[$field][] = sprintf('%s must be between %d and %d characters.', $label, $min, $max);
            }
        }
        return $this;
    }

    public function in(string $field, mixed $value, array $allowed, string $label): self
    {
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->errors[$field][] = $label . ' is invalid.';
        }
        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field][] = $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
