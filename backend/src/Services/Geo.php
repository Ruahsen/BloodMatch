<?php

declare(strict_types=1);

namespace BloodMatch\Services;

final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function distanceKm($lat1, $lng1, $lat2, $lng2): ?float
    {
        foreach ([$lat1, $lng1, $lat2, $lng2] as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return null;
            }
        }

        $lat1 = deg2rad((float) $lat1);
        $lng1 = deg2rad((float) $lng1);
        $lat2 = deg2rad((float) $lat2);
        $lng2 = deg2rad((float) $lng2);

        $dLat = $lat2 - $lat1;
        $dLng = $lng2 - $lng1;

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }
}
