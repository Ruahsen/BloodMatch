<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Services\BloodCompatibilityService;
use BloodMatch\Utils\Response;

final class CompatibilityController
{
    public function show(): void
    {
        AuthMiddleware::requireRoles(['officer', 'admin'], 'compatibility.matrix');

        $matrix = BloodCompatibilityService::fullMatrix();
        ksort($matrix);

        Response::success(['matrix' => $matrix]);
    }
}
