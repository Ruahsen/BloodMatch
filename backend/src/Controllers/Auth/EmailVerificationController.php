<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Auth;

use BloodMatch\Http\Session;
use BloodMatch\Repositories\AuthThrottleRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\EmailVerificationService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class EmailVerificationController
{
    private const MAX_VERIFY_ATTEMPTS = 5;
    private const VERIFY_LOCK_MINUTES = 15;
    private const MAX_RESEND_ATTEMPTS = 3;
    private const RESEND_LOCK_MINUTES = 15;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const DUMMY_HASH = '$2y$10$EvHKGIw69p65IMXgiJWB0O7HSMwAQWpRENdGC3dzsyydzJ1UF4qIK';

    private UserRepository $users;
    private AuthThrottleRepository $throttle;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->throttle = new AuthThrottleRepository();
    }

    public function verify(): void
    {
        $input = Request::json();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $now = AuthService::nowUtc();

        $keyAcct = 'verify:acct:' . $email;
        $keyIp = 'verify:ip:' . $ip;

        if ($this->throttle->isLocked($keyAcct, $now) || $this->throttle->isLocked($keyIp, $now)) {
            Response::error('Too many verification attempts. Please try again in 15 minutes.', 429);
            return;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->throttle->recordFailure($keyIp, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            Response::error('Invalid or expired verification code.', 400);
            return;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Constant timing against user enumeration
            password_verify($code !== '' ? $code : '000000', self::DUMMY_HASH);
            $this->throttle->recordFailure($keyIp, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            AuditLogger::log(null, 'auth.email_verify.failed', 'user', null, [
                'reason' => 'user_not_found',
            ]);
            Response::error('Invalid or expired verification code.', 400);
            return;
        }

        if (!empty($user['email_verified_at'])) {
            Response::success(['message' => 'Email is already verified.', 'email_verified' => true]);
            return;
        }

        $hash = $user['email_code_hash'] ?? null;
        $expiresAt = $user['email_code_expires_at'] ?? null;

        if (
            empty($hash)
            || empty($expiresAt)
            || !is_string($expiresAt)
            || $now > $expiresAt
        ) {
            $this->throttle->recordFailure($keyAcct, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            $this->throttle->recordFailure($keyIp, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            AuditLogger::log((int) $user['id'], 'auth.email_verify.failed', 'user', (string) $user['id'], [
                'reason' => 'code_expired_or_missing',
            ]);
            Response::error('Invalid or expired verification code.', 400);
            return;
        }

        if (!password_verify($code, (string) $hash)) {
            $this->throttle->recordFailure($keyAcct, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            $this->throttle->recordFailure($keyIp, self::MAX_VERIFY_ATTEMPTS, self::VERIFY_LOCK_MINUTES, $now);
            AuditLogger::log((int) $user['id'], 'auth.email_verify.failed', 'user', (string) $user['id'], [
                'reason' => 'invalid_code',
            ]);
            Response::error('Invalid or expired verification code.', 400);
            return;
        }

        // Verification successful: invalidate code and clear lock
        $this->users->markEmailVerified((int) $user['id'], $now);
        $this->throttle->clear($keyAcct);

        AuditLogger::log((int) $user['id'], 'auth.email_verify.success', 'user', (string) $user['id']);

        $userRecord = $this->users->findById((int) $user['id']);
        $authService = new AuthService();
        $publicUser = null;

        if ($userRecord !== null && (string) ($userRecord['account_status'] ?? '') !== 'deactivated') {
            Session::regenerate();
            $_SESSION['user_id'] = (int) $userRecord['id'];
            $_SESSION['role'] = (string) $userRecord['role'];
            $publicUser = $authService->publicUser($userRecord);
        }

        Response::success([
            'message' => 'Email verified successfully.',
            'email_verified' => true,
            'user' => $publicUser,
        ]);
    }

    public function resend(): void
    {
        $input = Request::json();
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('A valid email address is required.', 400);
            return;
        }

        $now = AuthService::nowUtc();
        $keyResend = 'resend:acct:' . $email;
        $keyCooldown = 'resend:cd:' . $email;

        if ($this->throttle->isLocked($keyResend, $now)) {
            Response::error('Too many resend attempts. Please wait 15 minutes before trying again.', 429);
            return;
        }

        if ($this->throttle->isLocked($keyCooldown, $now)) {
            Response::error('Please wait 60 seconds before requesting another code.', 429);
            return;
        }

        // Record attempt against limit and lock cooldown
        $this->throttle->recordFailure($keyResend, self::MAX_RESEND_ATTEMPTS, self::RESEND_LOCK_MINUTES, $now);
        $this->throttle->setLock($keyCooldown, self::RESEND_COOLDOWN_SECONDS, $now);

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Constant timing: simulate password hash computation
            password_hash('000000', PASSWORD_BCRYPT);
            AuditLogger::log(null, 'auth.email_verify.resend_requested', 'user', null, [
                'email' => $email,
            ]);
            Response::success(['message' => 'If an account exists with this email, a verification code has been sent.']);
            return;
        }

        if (!empty($user['email_verified_at'])) {
            AuditLogger::log((int) $user['id'], 'auth.email_verify.resend_requested', 'user', (string) $user['id'], [
                'status' => 'already_verified',
            ]);
            Response::success(['message' => 'If an account exists with this email, a verification code has been sent.']);
            return;
        }

        // Generate and send new code (which invalidates any previous code)
        EmailVerificationService::sendVerificationCode((int) $user['id'], $email, isResend: true);
        AuditLogger::log((int) $user['id'], 'auth.email_verify.resent', 'user', (string) $user['id']);

        Response::success(['message' => 'If an account exists with this email, a verification code has been sent.']);
    }
}
