<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AgeEligibilityService;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\CapabilityMatrix;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use BloodMatch\Utils\Validator;
use DateTimeImmutable;

final class ProfileController
{
    private const ENDPOINT = 'profile';

    public function get(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.get');
        Response::success(['profile' => $this->compose($actor)]);
    }

    public function update(): void
    {
        try {
            $this->processUpdate();
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
        }
    }

    private function processUpdate(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.update');

        foreach (['role', 'verification_status', 'account_status', 'email', 'password', 'blood_type_verified'] as $forbidden) {
            if (array_key_exists($forbidden, Request::json())) {
                throw new ValidationException([
                    $forbidden => ["Field '{$forbidden}' cannot be modified through profile updates."],
                ]);
            }
        }

        $body = Request::json();
        $v = new Validator();
        $fields = [];

        if (array_key_exists('full_name', $body)) {
            $name = Request::str('full_name', $body);
            $v->required('full_name', $name, 'Full name')->length('full_name', $name, 2, 150, 'Full name');
            $fields['full_name'] = $name;
        }

        if (array_key_exists('phone', $body)) {
            $phone = Request::str('phone', $body);
            if ($phone !== null && !preg_match('/^[0-9+\-\s()]{5,30}$/', $phone)) {
                $v->addError('phone', 'Phone format is invalid.');
            }
            $fields['phone'] = $phone;
        }

        if (array_key_exists('date_of_birth', $body)) {
            $dob = Request::str('date_of_birth', $body);
            $dt = $dob === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if ($dob !== null && ($dt === false || $dt->format('Y-m-d') !== $dob || $dt->getTimestamp() > time())) {
                $v->addError('date_of_birth', 'Date of birth must be a valid past date (YYYY-MM-DD).');
            }
            $fields['date_of_birth'] = $dob;
        }

        if (array_key_exists('blood_type', $body)) {
            $bt = $body['blood_type'];
            if ($bt === null || $bt === '') {
                $fields['blood_type'] = null;
                $fields['blood_type_source'] = null;
                $fields['blood_type_verified'] = 0;
            } else {
                $allowed = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                if (!in_array($bt, $allowed, true)) {
                    $v->addError('blood_type', 'Blood type is invalid.');
                } elseif ($bt === (string) $actor['blood_type']) {
                    // unchanged value keeps existing provenance (e.g., officer-verified donor card)
                } else {
                    $fields['blood_type'] = $bt;
                    $fields['blood_type_source'] = 'self_reported';
                    $fields['blood_type_verified'] = 0;
                }
            }
        }

        $hasLat = array_key_exists('latitude', $body);
        $hasLng = array_key_exists('longitude', $body);
        if ($hasLat !== $hasLng) {
            $v->addError('location', 'Latitude and longitude must be updated together.');
        } elseif ($hasLat && $hasLng) {
            $latRaw = $body['latitude'];
            $lngRaw = $body['longitude'];
            if ($latRaw === null || $lngRaw === null || $latRaw === '' || $lngRaw === '') {
                $fields['latitude'] = null;
                $fields['longitude'] = null;
            } else {
                if (!is_numeric($latRaw) || (float) $latRaw < -90 || (float) $latRaw > 90) {
                    $v->addError('latitude', 'Latitude must be a number between -90 and 90.');
                }
                if (!is_numeric($lngRaw) || (float) $lngRaw < -180 || (float) $lngRaw > 180) {
                    $v->addError('longitude', 'Longitude must be a number between -180 and 180.');
                }
                if (!$v->fails()) {
                    $fields['latitude'] = (float) $latRaw;
                    $fields['longitude'] = (float) $lngRaw;
                }
            }
        }

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        if ($fields !== []) {
            (new UserRepository())->updateProfile((int) $actor['id'], $fields);
            AuditLogger::log((int) $actor['id'], 'profile.updated', 'user', (string) $actor['id'], [
                'fields' => array_keys($fields),
            ]);
        }

        $fresh = (new UserRepository())->findById((int) $actor['id']);
        Response::success(['profile' => $this->compose($fresh)]);
    }

    public function resubmit(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.resubmit');

        $throttleKey = 'mutation:resubmit:' . (int) $actor['id'];
        if (!(new \BloodMatch\Repositories\AuthThrottleRepository())->hitAndCheckRateLimit($throttleKey, 5, 15, AuthService::nowUtc())) {
            Response::error('Too many verification resubmission attempts. Please try again later.', 429);
            return;
        }
        try {
            $result = (new \BloodMatch\Services\VerificationService())->resubmit($actor);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 409 ? $e->getCode() : 500;
            Response::error($e->getMessage(), $code);
            return;
        }
        Response::success($result);
    }

    public function enrollDonor(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.enroll_donor');

        if ((string) $actor['role'] !== 'member') {
            Response::error('Only member accounts can enroll as donors.', 403);
            return;
        }
        if ((string) $actor['verification_status'] !== 'verified') {
            Response::error('Only verified members can enroll as donors.', 409);
            return;
        }

        $docs = new DocumentRepository();
        $eligibility = AgeEligibilityService::evaluate(
            $actor['date_of_birth'] !== null ? (string) $actor['date_of_birth'] : null,
            $docs->hasType((int) $actor['id'], 'parental_consent')
        );
        if (!$eligibility['donor_path_allowed']) {
            Response::error($eligibility['reason'], 409, ['age_eligibility' => [$eligibility['reason']]]);
            return;
        }

        if ($actor['donor_enrolled_at'] !== null) {
            Response::success(['message' => 'Already enrolled.', 'enrolled' => true]);
            return;
        }

        (new UserRepository())->setDonorEnrollment((int) $actor['id'], AuthService::nowUtc());
        AuditLogger::log((int) $actor['id'], 'donor.enrolled', 'user', (string) $actor['id']);

        Response::success(['message' => 'Enrolled as available donor.', 'enrolled' => true]);
    }

    public function setDonorAvailability(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.availability');

        $value = \BloodMatch\Utils\Request::str('availability');
        if (!in_array($value, ['available', 'unavailable'], true)) {
            Response::error('Invalid availability value.', 400, [
                'availability' => ['Value must be "available" or "unavailable".'],
            ]);
            return;
        }

        try {
            \BloodMatch\Services\DonorEligibilityService::assertAvailabilityChangeAllowed($actor);
        } catch (\BloodMatch\Services\Exceptions\WindowBlockedException $e) {
            Response::error($e->getMessage(), 409, [
                'availability_window' => $e->window(),
            ]);
            return;
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 409);
            return;
        }

        (new UserRepository())->setAvailability((int) $actor['id'], $value);
        AuditLogger::log((int) $actor['id'], 'donor.availability_changed', 'user', (string) $actor['id'], [
            'to' => $value,
        ]);

        Response::success(['availability' => $value]);
    }

    public static function compose(array $user): array
    {
        $docs = new DocumentRepository();
        $documents = $docs->listByUser((int) $user['id']);
        $hasConsent = false;
        foreach ($documents as $d) {
            if ((string) $d['doc_type'] === 'parental_consent') {
                $hasConsent = true;
                break;
            }
        }

        $eligibility = AgeEligibilityService::evaluate(
            $user['date_of_birth'] !== null ? (string) $user['date_of_birth'] : null,
            $hasConsent
        );

        $verifiedBlood = (int) $user['blood_type_verified'] === 1;

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'phone' => $user['phone'],
            'role' => (string) $user['role'],
            'chapter_id' => $user['chapter_id'] !== null ? (int) $user['chapter_id'] : null,
            'verification_status' => (string) $user['verification_status'],
            'account_status' => (string) $user['account_status'],
            'date_of_birth' => $user['date_of_birth'],
            'blood_type' => $user['blood_type'],
            'blood_type_source' => $user['blood_type_source'],
            'blood_type_verified' => $verifiedBlood,
            'blood_type_notice' => $user['blood_type'] === null
                ? null
                : ($verifiedBlood ? CapabilityMatrix::BLOOD_TYPE_NOTICE_ADMIN_VERIFIED : CapabilityMatrix::BLOOD_TYPE_NOTICE_UNVERIFIED),
            'latitude' => $user['latitude'] !== null ? (float) $user['latitude'] : null,
            'longitude' => $user['longitude'] !== null ? (float) $user['longitude'] : null,
            'donor_enrolled' => $user['donor_enrolled_at'] !== null,
            'availability' => $user['donor_availability'],
            'availability_window' => \BloodMatch\Services\DonorEligibilityService::evaluateWindows(
                $user['last_verified_donation_at'] !== null ? (string) $user['last_verified_donation_at'] : null
            ),
            'capabilities' => CapabilityMatrix::evaluate($user),
            'age_eligibility' => $eligibility,
            'documents' => array_map(static fn (array $d): array => [
                'id' => (int) $d['id'],
                'doc_type' => (string) $d['doc_type'],
                'mime_type' => (string) $d['mime_type'],
                'size_bytes' => (int) $d['size_bytes'],
                'uploaded_at' => (string) $d['uploaded_at'],
            ], $documents),
        ];
    }
}
