<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Http\Session;
use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuthService;
use BloodMatch\Utils\Response;

final class MeController
{
    public function me(): void
    {
        $actor = AuthMiddleware::requireActiveUser('auth.me');
        $userId = (int) $actor['id'];

        $user = (new UserRepository())->findById($userId);
        if ($user === null) {
            Response::error('Authentication required.', 401);
            return;
        }

        Response::success(['user' => (new AuthService())->publicUser($user)]);
    }
}
