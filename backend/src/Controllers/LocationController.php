<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Services\LocationService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class LocationController
{
    public function municipalities(): void
    {
        Response::success(['municipalities' => LocationService::listMunicipalities()]);
    }

    public function barangays(): void
    {
        $code = Request::str('municipality_code');
        if ($code === null || $code === '') {
            Response::error('Municipality is required.', 400, [
                'municipality_code' => ['Select a municipality or city first.'],
            ]);
            return;
        }

        try {
            $result = LocationService::listBarangays($code);
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        }

        Response::success($result);
    }
}
