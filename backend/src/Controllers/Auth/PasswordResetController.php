<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Services\AuthService;
use BloodMatch\Services\Exceptions\AuthException;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class PasswordResetController
{
    public function request(): void
    {
        // Body-only: reset identifiers must never arrive via query string
        // (Request::str falls back to $_GET when no source is given).
        $email = (string) (Request::str('email', Request::json()) ?? '');
        if ($email === '') {
            Response::error('Email is required.', 400);
            return;
        }

        try {
            (new AuthService())->requestPasswordReset($email);
        } catch (AuthException $e) {
            Response::error($e->getMessage(), 429);
            return;
        }

        Response::success([
            'message' => 'If that email exists in our records, a reset link has been issued.',
        ]);
    }

    public function confirm(): void
    {
        // Body-only: reset tokens are secrets and must never arrive via
        // query string (where they leak into access/proxy logs).
        $body = Request::json();
        $token = (string) (Request::str('token', $body) ?? '');
        $password = isset($body['password']) && is_string($body['password'])
            ? $body['password']
            : '';

        if ($token === '' || $password === '') {
            Response::error('Token and new password are required.', 400);
            return;
        }

        try {
            (new AuthService())->confirmPasswordReset($token, $password);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        } catch (AuthException $e) {
            // Preserve conflict/forbidden semantics (e.g. deactivated
            // account 403) instead of flattening everything to 400.
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 400);
            return;
        }

        Response::success(['message' => 'Password has been reset. You can now log in.']);
    }
}
