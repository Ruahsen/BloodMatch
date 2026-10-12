<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\UserRepository;
use DateTimeImmutable;
use RuntimeException;

final class RequestService
{
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    public const URGENCIES = ['routine', 'urgent', 'emergency'];

    public static function nowUtc(): string
    {
        return AuthService::nowUtc();
    }

    public static function hasDeadlinePassed(array $request, ?string $nowUtc = null): bool
    {
        $needed = trim((string) ($request['needed_datetime'] ?? ''));
        $clock = trim($nowUtc ?? self::nowUtc());
        if ($needed === '' || $clock === '') { return true; }
        $neededAt = strtotime($needed . ' UTC');
        $now = strtotime($clock . ' UTC');
        return $neededAt === false || $now === false || $neededAt < $now;
    }

    /**
     * Validate and normalize the Home feed query. All list values are exact
     * allow-list matches; q remains data and is escaped by the repository.
     */
    public static function validateHomeFeedQuery(array $query): array
    {
        $errors = [];
        $scalar = static function (string $key) use ($query, &$errors): ?string {
            if (!array_key_exists($key, $query) || $query[$key] === null) {
                return null;
            }
            if (!is_scalar($query[$key])) {
                $errors[$key][] = 'Value must be a single string.';
                return null;
            }
            return trim((string) $query[$key]);
        };

        $pageRaw = $scalar('page');
        $page = 1;
        if ($pageRaw !== null && ($pageRaw === '' || !preg_match('/^[1-9]\d*$/', $pageRaw))) {
            $errors['page'][] = 'Page must be a positive integer.';
        } elseif ($pageRaw !== null && (int) $pageRaw > 2147483647) {
            $errors['page'][] = 'Page is too large.';
        } elseif ($pageRaw !== null) {
            $page = (int) $pageRaw;
        }

        $q = $scalar('q');
        if ($q === '') {
            $q = null;
        }
        $qLength = $q === null ? 0 : (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q));
        if ($qLength > 80) {
            $errors['q'][] = 'Search must be at most 80 characters.';
        }

        $municipalityCode = $scalar('municipality_code');
        if ($municipalityCode === '') {
            $municipalityCode = null;
        }
        if ($municipalityCode !== null
            && (!preg_match('/^\d{9}$/', $municipalityCode)
                || !LocationService::municipalityExists($municipalityCode))) {
            $errors['municipality_code'][] = 'Selected municipality is invalid or unavailable.';
        }

        $parseList = static function (string $key, array $allowed) use ($scalar, &$errors): array {
            $raw = $scalar($key);
            if ($raw === null || $raw === '') {
                return [];
            }
            $values = array_map('trim', explode(',', $raw));
            if (in_array('', $values, true)) {
                $errors[$key][] = 'List values cannot be empty.';
                return [];
            }
            foreach ($values as $value) {
                if (!in_array($value, $allowed, true)) {
                    $errors[$key][] = 'Invalid value: ' . $value . '.';
                }
            }
            return array_values(array_unique($values));
        };

        $blood = $parseList('blood', self::BLOOD_TYPES);
        $urgency = $parseList('urgency', self::URGENCIES);

        if ($errors !== []) {
            throw new Exceptions\ValidationException($errors);
        }

        return [
            'page' => $page,
            'page_size' => 20,
            'q' => $q,
            'municipality_code' => $municipalityCode,
            'blood' => $blood,
            'urgency' => $urgency,
        ];
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
                $v->addError('urgency', 'Urgency must be routine, urgent, or emergency.');
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

        if ($has('hospital_id')) {
            $hIdRaw = $body['hospital_id'] ?? null;
            $fields['hospital_id'] = $hIdRaw !== null && $hIdRaw !== '' ? (int) $hIdRaw : null;
        } elseif (!$partial) {
            $fields['hospital_id'] = null;
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
            'requester_name' => $requester !== null ? (string) $requester['full_name'] : ($row['requester_name'] ?? null),
            'requester_verification_status' => $requester !== null ? (string) $requester['verification_status'] : ($row['requester_verification_status'] ?? null),
            'requester_chapter_name' => $row['requester_chapter_name'] ?? null,
            'requester_profile_picture_url' => ProfilePictureStorageService::urlFor(
                $requester !== null ? ($requester['profile_picture'] ?? null) : ($row['requester_profile_picture'] ?? null),
                (int) $row['requester_id']
            ),
            'request_chapter_id' => $row['request_chapter_id'] !== null ? (int) $row['request_chapter_id'] : null,
            'required_blood_type' => (string) $row['required_blood_type'],
            'quantity_units' => (int) $row['quantity_units'],
            'facility_name' => (string) $row['facility_name'],
            'hospital_id' => isset($row['hospital_id']) && $row['hospital_id'] !== null ? (int) $row['hospital_id'] : null,
            'location' => self::presentLocation($row),
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'urgency' => (string) $row['urgency'],
            'needed_datetime' => (string) $row['needed_datetime'],
            'status' => (string) $row['status'],
            'review_status' => (string) $row['review_status'],
            'created_at' => (string) $row['created_at'],
            'match_count' => isset($row['match_count']) ? (int) $row['match_count'] : null,
            'response_count' => isset($row['response_count']) ? (int) $row['response_count'] : null,
            'match_id' => isset($row['match_id']) ? (int) $row['match_id'] : null,
            'match_status' => isset($row['match_status']) ? (string) $row['match_status'] : null,
        ];
    }

    public static function homeFeedView(array $row, array $eligibility): array
    {
        $matchStatus = isset($row['match_status']) && $row['match_status'] !== null
            ? (string) $row['match_status']
            : null;

        return [
            'id' => (int) $row['id'],
            'requester_id' => (int) $row['requester_id'],
            'requester_name' => (string) $row['requester_name'],
            'requester_verification_status' => (string) $row['requester_verification_status'],
            'requester_chapter_name' => $row['requester_chapter_name'] !== null
                ? (string) $row['requester_chapter_name']
                : null,
            'requester_profile_picture_url' => ProfilePictureStorageService::urlFor(
                $row['requester_profile_picture'],
                (int) $row['requester_id']
            ),
            'required_blood_type' => (string) $row['required_blood_type'],
            'quantity_units' => (int) $row['quantity_units'],
            'facility_name' => (string) $row['facility_name'],
            'location' => [
                'municipality_name' => $row['loc_municipality_name'] !== null
                    ? (string) $row['loc_municipality_name']
                    : null,
            ],
            'urgency' => (string) $row['urgency'],
            'needed_datetime' => (string) $row['needed_datetime'],
            'created_at' => (string) $row['created_at'],
            'status' => (string) $row['status'],
            'match_id' => isset($row['match_id']) && $row['match_id'] !== null ? (int) $row['match_id'] : null,
            'match_status' => $matchStatus,
            'can_respond' => (bool) $eligibility['eligible'],
            'reason_code' => $eligibility['code'],
            'reason_text' => $eligibility['reason'],
            'eligible_again_at' => $eligibility['eligible_again_at'],
            'responded' => $matchStatus === 'RESPONDED',
            'can_view_requester_profile' => true,
        ];
    }
}
