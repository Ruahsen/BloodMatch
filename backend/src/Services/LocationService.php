<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use BloodMatch\Services\Exceptions\ValidationException;
use PDO;

final class LocationService
{
    private const COLUMNS =
        'id, psgc_code, name, level, municipality_code, municipality_name, latitude, longitude, is_active';

    public static function listMunicipalities(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, psgc_code, name FROM bataan_locations
              WHERE level = \'municipality\' AND is_active = 1 ORDER BY name ASC'
        );
        $stmt->execute();
        return array_map(static fn (array $r): array => [
            'location_id' => (int) $r['id'],
            'psgc_code' => (string) $r['psgc_code'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function listBarangays(string $municipalityCode): array
    {
        $municipality = self::findMunicipality($municipalityCode);
        if ($municipality === null) {
            throw new ValidationException([
                'municipality_code' => ['Selected municipality is invalid or unavailable.'],
            ]);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, psgc_code, name FROM bataan_locations
              WHERE level = \'barangay\' AND municipality_code = ? AND is_active = 1 ORDER BY name ASC'
        );
        $stmt->execute([$municipalityCode]);
        $barangays = array_map(static fn (array $r): array => [
            'location_id' => (int) $r['id'],
            'psgc_code' => (string) $r['psgc_code'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'municipality' => [
                'location_id' => (int) $municipality['id'],
                'psgc_code' => (string) $municipality['psgc_code'],
                'name' => (string) $municipality['name'],
            ],
            'barangays' => $barangays,
        ];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM bataan_locations WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::present($row);
    }

    /**
     * Validate a location_id payload and return the canonical reference row.
     * Coordinates always come from this row — never from client input.
     */
    public static function resolveLocationId(mixed $raw): array
    {
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw))) {
            throw new ValidationException([
                'location_id' => ['Selected location is invalid.'],
            ]);
        }

        $row = self::findById((int) $raw);
        if ($row === null || !$row['is_active']) {
            throw new ValidationException([
                'location_id' => ['Selected location is invalid or unavailable.'],
            ]);
        }

        return $row;
    }

    public static function present(array $row): array
    {
        $isBarangay = (string) $row['level'] === 'barangay';
        return [
            'location_id' => (int) $row['id'],
            'psgc_code' => (string) $row['psgc_code'],
            'name' => (string) $row['name'],
            'level' => (string) $row['level'],
            'municipality_code' => (string) $row['municipality_code'],
            'municipality_name' => (string) $row['municipality_name'],
            'barangay_code' => $isBarangay ? (string) $row['psgc_code'] : null,
            'barangay_name' => $isBarangay ? (string) $row['name'] : null,
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'is_active' => (int) $row['is_active'] === 1,
        ];
    }

    private static function findMunicipality(string $code): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM bataan_locations
              WHERE psgc_code = ? AND level = \'municipality\' LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['is_active'] !== 1) {
            return null;
        }
        return $row;
    }
}
