<?php

declare(strict_types=1);

namespace BloodMatch\Middleware;

use BloodMatch\Utils\Csrf;
use BloodMatch\Utils\Response;

final class CsrfMiddleware
{
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, self::PROTECTED_METHODS, true)) {
            return;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!Csrf::verify(is_string($token) ? $token : null)) {
            Response::error('CSRF token missing or invalid.', 403);
            exit;
        }
    }
}
