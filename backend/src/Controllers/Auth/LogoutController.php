<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Http\Session;
use BloodMatch\Services\AuthService;
use BloodMatch\Utils\Response;

final class LogoutController
{
    public function logout(): void
    {
        Session::start();
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        (new AuthService())->logout($userId);
        Response::success(['message' => 'Logged out.']);
    }
}
