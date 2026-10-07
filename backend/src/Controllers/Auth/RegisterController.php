<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Services\AuthService;
use BloodMatch\Repositories\Exceptions\DuplicateEntryException;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Utils\Response;

final class RegisterController
{
    public function register(): void
    {
        $service = new AuthService();

        try {
            $result = $service->register(\BloodMatch\Utils\Request::json());
        } catch (DuplicateEntryException $e) {
            Response::error($e->getMessage(), 409, ['email' => [$e->getMessage()]]);
            return;
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        }

        // The 201 payload carries the created user plus the automatic
        // registration-time email-OTP block (masked address, expiry, and
        // the single-purpose claim token). No session is created here.
        Response::success($result, 201);
    }
}
