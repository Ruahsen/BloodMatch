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
        $email = (string) (Request::str('email') ?? '');
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
        $token = (string) (Request::str('token') ?? '');
        $password = isset(Request::json()['password']) && is_string(Request::json()['password'])
            ? Request::json()['password']
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
            Response::error($e->getMessage(), 400);
            return;
        }

        Response::success(['message' => 'Password has been reset. You can now log in.']);
    }
}
