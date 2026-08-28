<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Utils\Csrf;
use BloodMatch\Utils\Response;

final class CsrfController
{
    public function token(): void
    {
        Response::success(['csrf_token' => Csrf::token()]);
    }
}
