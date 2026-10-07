<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Services\EmailOtpService;
use BloodMatch\Services\Exceptions\AuthException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class EmailOtpController
{
    /**
     * Identity resolution: an explicit claim token (registration journey,
     * logged-out registrant) always wins and must validate — fail closed.
     * Without one, the session account is used (authenticated journey).
     *
     * @return array{mode:string, user?:array, token?:string}
     */
    private static function resolveIdentity(?string $claimToken, string $endpoint): array
    {
        if ($claimToken !== null && $claimToken !== '') {
            return ['mode' => 'claim', 'token' => $claimToken];
        }
        return ['mode' => 'session', 'user' => AuthMiddleware::requireActiveUser($endpoint)];
    }

    private static function mapStatus(int $code): int
    {
        return in_array($code, [400, 401, 403, 422, 429, 503], true) ? $code : 400;
    }

    public function send(): void
    {
        // Body-only read: tokens are secrets and must never arrive via
        // query string (where they leak into access/proxy logs).
        $claimToken = Request::str('verification_token', Request::json());
        $identity = self::resolveIdentity($claimToken, 'auth.email_otp.send');

        try {
            $service = new EmailOtpService();
            $result = $identity['mode'] === 'claim'
                ? $service->sendWithToken((string) $identity['token'])
                : $service->requestOtp($identity['user']);
        } catch (AuthException $e) {
            Response::error($e->getMessage(), self::mapStatus($e->getCode()));
            return;
        }

        if (($result['already_verified'] ?? false) === true) {
            Response::success([
                'already_verified' => true,
                'message' => 'Email is already verified.',
            ]);
            return;
        }

        Response::success([
            'already_verified' => false,
            'message' => 'Verification code sent.',
            'expires_in_seconds' => (int) $result['expires_in_seconds'],
            'resend_available_in_seconds' => (int) $result['resend_available_in_seconds'],
        ]);
    }

    public function verify(): void
    {
        // Body-only: OTP codes and claim tokens are secrets and must never
        // arrive via query string (where they leak into access/proxy logs)
        // or URLs.
        $body = Request::json();
        $code = Request::str('code', $body);
        if ($code === null || $code === '') {
            Response::error('Verification code is required.', 400, [
                'code' => ['Verification code is required.'],
            ]);
            return;
        }
        $claimToken = Request::str('verification_token', $body);
        $identity = self::resolveIdentity($claimToken, 'auth.email_otp.verify');

        try {
            $service = new EmailOtpService();
            $result = $identity['mode'] === 'claim'
                ? $service->verifyWithToken((string) $identity['token'], $code)
                : $service->verifyOtp($identity['user'], $code);
        } catch (AuthException $e) {
            $code0 = $e->getCode();
            Response::error($e->getMessage(), $code0 >= 400 && $code0 <= 499 ? $code0 : 400);
            return;
        }

        Response::success([
            'verified' => true,
            'email_verified_at' => $result['email_verified_at'],
        ]);
    }

    public function status(): void
    {
        $actor = AuthMiddleware::requireActiveUser('auth.email_otp.status');

        Response::success((new EmailOtpService())->statusFor($actor));
    }

    /**
     * Claim-mode status for the logged-out registration journey. POST (not
     * GET) so the claim token travels in the JSON body and never appears
     * in URLs or access logs. With a session and no token, behaves like
     * the GET status for convenience.
     */
    public function statusViaPost(): void
    {
        $claimToken = Request::str('verification_token', Request::json());
        $identity = self::resolveIdentity($claimToken, 'auth.email_otp.status');

        try {
            $service = new EmailOtpService();
            $result = $identity['mode'] === 'claim'
                ? $service->statusWithToken((string) $identity['token'])
                : $service->statusFor($identity['user']);
        } catch (AuthException $e) {
            $code0 = $e->getCode();
            Response::error($e->getMessage(), $code0 >= 400 && $code0 <= 499 ? $code0 : 400);
            return;
        }

        Response::success($result);
    }
}
