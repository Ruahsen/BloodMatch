<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Services\AuthService;
use BloodMatch\Services\Exceptions\AuthException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class LoginController
{
    public function login(): void
    {
        $body = Request::json();
        $email = (string) (Request::str('email', $body) ?? '');
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if ($email === '' || $password === '') {
            Response::error('Email and password are required.', 400);
            return;
        }

        try {
            $user = (new AuthService())->login($email, $password);
        } catch (AuthException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 429 ? $e->getCode() : 401;
            Response::error($e->getMessage(), $status);
            return;
        }

        Response::success(['user' => $user]);
    }
}
