<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\UserRepository;
use DateTimeImmutable;
use RuntimeException;

final class RequestService
{
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    public const URGENCIES = ['routine', 'urgent', 'critical'];

    public static function nowUtc(): string
    {
        return AuthService::nowUtc();
    }

    public static function validatePayload(array $body, bool $partial = false): array
    {
        $v = new \BloodMatch\Utils\Validator();
        $fields = [];
        $has = fn (string $k): bool => $partial ? array_key_exists($k, $body) : true;

        if ($has('required_blood_type')) {
            $bt = \BloodMatch\Utils\Request::str('required_blood_type', $body);
            if (!in_array($bt, self::BLOOD_TYPES, true)) {
                $v->addError('required_blood_type', 'Blood type must be one of: ' . implode(', ', self::BLOOD_TYPES) . '.');
            }
            $fields['required_blood_type'] = $bt;
        }

        if ($has('quantity_units')) {
            $q = $body['quantity_units'] ?? 1;
            if (!preg_match('/^\d+$/', (string) $q) || (int) $q < 1 || (int) $q > 10) {
                $v->addError('quantity_units', 'Quantity must be between 1 and 10 units.');
            }
            $fields['quantity_units'] = (int) $q;
        }

        if ($has('facility_name')) {
            $fn = \BloodMatch\Utils\Request::str('facility_name', $body);
            $v->required('facility_name', $fn, 'Facility name')->length('facility_name', $fn, 2, 150, 'Facility name');
            $fields['facility_name'] = $fn;
        }

        if ($has('urgency')) {
            $u = \BloodMatch\Utils\Request::str('urgency', $body) ?? 'routine';
            if (!in_array($u, self::URGENCIES, true)) {
                $v->addError('urgency', 'Urgency must be routine, urgent, or critical.');
            }
            $fields['urgency'] = $u;
        }

        if ($has('needed_datetime')) {
            $nd = \BloodMatch\Utils\Request::str('needed_datetime', $body);
            $dt = null;
            foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!Y-m-d H:i'] as $fmt) {
                $dt = DateTimeImmutable::createFromFormat($fmt, (string) $nd, new \DateTimeZone('UTC'));
                if ($dt instanceof DateTimeImmutable) {
                    break;
                }
            }
            if (!($dt instanceof DateTimeImmutable)) {
                $v->addError('needed_datetime', 'Needed date/time must be a valid datetime.');
            } elseif ($dt->getTimestamp() <= time()) {
                $v->addError('needed_datetime', 'Needed date/time must be in the future.');
            } else {
                $fields['needed_datetime'] = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        if (array_key_exists('latitude', $body) || array_key_exists('longitude', $body)) {
            throw new Exceptions\ValidationException([
                'location_id' => ['Set the facility location using the Bataan municipality/barangay selector instead of coordinates.'],
            ]);
        }

        if ($has('location_id')) {
            $locRaw = $body['location_id'] ?? null;
            if ($locRaw === null || $locRaw === '') {
                $fields['location_id'] = null;
                $fields['latitude'] = null;
                $fields['longitude'] = null;
            } else {
                $location = LocationService::resolveLocationId($locRaw);
                $fields['location_id'] = $location['location_id'];
                $fields['latitude'] = $location['latitude'];
                $fields['longitude'] = $location['longitude'];
            }
        } elseif (!$partial) {
            $fields['location_id'] = null;
            $fields['latitude'] = null;
            $fields['longitude'] = null;
        }

        if ($v->fails()) {
            throw new \BloodMatch\Services\Exceptions\ValidationException($v->errors());
        }

        return $fields;
    }

    public static function materialChangedFields(array $before, array $after): array
    {
        $materialGroups = [
            'required_blood_type' => ['required_blood_type'],
            'location' => ['facility_name', 'location_id', 'latitude', 'longitude'],
            'urgency' => ['urgency'],
            'needed_datetime' => ['needed_datetime'],
            'quantity_units' => ['quantity_units'],
        ];

        $same = static function ($old, $new): bool {
            if ($old === null && $new === null) {
                return true;
            }
            if (is_numeric($old) && is_numeric($new)) {
                return (float) $old === (float) $new;
            }
            return (string) ($old ?? '') === (string) ($new ?? '');
        };

        $changedGroups = [];
        foreach ($materialGroups as $group => $cols) {
            foreach ($cols as $col) {
                if (!array_key_exists($col, $after)) {
                    continue;
                }
                if (!$same($before[$col] ?? null, $after[$col])) {
                    $changedGroups[$group] = true;
                    break;
                }
            }
        }

        return array_keys($changedGroups);
    }

    public static function assertCanCreate(array $actor): void
    {
        $caps = CapabilityMatrix::evaluate($actor);
        if ($caps['create_request'] !== true) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'blood_request', null, [
                'endpoint' => 'requests.create',
                'reason' => 'capability_create_request_denied',
                'verification_status' => (string) $actor['verification_status'],
            ]);
            throw new RuntimeException(
                'Only pending or verified members can create blood requests.', 403
            );
        }
    }

    public static function reviewStatusFor(array $actor): string
    {
        return (string) $actor['verification_status'] === 'pending' ? 'pending_review' : 'not_required';
    }

    public static function chapterIdOf(array $actor): ?int
    {
        return $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];
    }

    private static function presentLocation(array $row): ?array
    {
        if (!isset($row['location_id']) || $row['location_id'] === null) {
            return null;
        }
        $isBarangay = isset($row['loc_level']) && (string) $row['loc_level'] === 'barangay';
        return [
            'location_id' => (int) $row['location_id'],
            'psgc_code' => isset($row['loc_psgc']) && $row['loc_psgc'] !== null ? (string) $row['loc_psgc'] : null,
            'name' => isset($row['loc_name']) && $row['loc_name'] !== null ? (string) $row['loc_name'] : null,
            'level' => isset($row['loc_level']) && $row['loc_level'] !== null ? (string) $row['loc_level'] : null,
            'municipality_code' => isset($row['loc_municipality_code']) ? (string) $row['loc_municipality_code'] : null,
            'municipality_name' => isset($row['loc_municipality_name']) ? (string) $row['loc_municipality_name'] : null,
            'barangay_code' => $isBarangay && isset($row['loc_psgc']) ? (string) $row['loc_psgc'] : null,
            'barangay_name' => $isBarangay && isset($row['loc_name']) ? (string) $row['loc_name'] : null,
        ];
    }

    public static function publicView(array $row, ?array $requester): array
    {
        return [
            'id' => (int) $row['id'],
            'requester_id' => (int) $row['requester_id'],
            'requester_name' => $requester !== null ? (string) $requester['full_name'] : null,
            'requester_verification_status' => $requester !== null ? (string) $requester['verification_status'] : null,
            'request_chapter_id' => $row['request_chapter_id'] !== null ? (int) $row['request_chapter_id'] : null,
            'required_blood_type' => (string) $row['required_blood_type'],
            'quantity_units' => (int) $row['quantity_units'],
            'facility_name' => (string) $row['facility_name'],
            'location' => self::presentLocation($row),
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'urgency' => (string) $row['urgency'],
            'needed_datetime' => (string) $row['needed_datetime'],
            'status' => (string) $row['status'],
            'review_status' => (string) $row['review_status'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
