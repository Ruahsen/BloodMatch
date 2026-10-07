<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Http\Session;
use BloodMatch\Repositories\AuthThrottleRepository;
use BloodMatch\Repositories\Exceptions\DuplicateEntryException;
use BloodMatch\Repositories\PasswordResetRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Validator;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const RESET_TOKEN_TTL_SECONDS = 1800;

    private UserRepository $users;
    private PasswordResetRepository $resets;
    private AuthThrottleRepository $throttle;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->resets = new PasswordResetRepository();
        $this->throttle = new AuthThrottleRepository();
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function isPrivacyAcknowledged(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['true', 'on', 'yes'], true);
        }
        return false;
    }

    public function register(array $input): array
    {
        $v = new Validator();
        $email = strtolower((string) (Request::str('email', $input) ?? ''));
        $fullName = Request::str('full_name', $input);
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : null;
        $phone = Request::str('phone', $input);
        $chapterId = Request::int('chapter_id', $input);
        $dob = Request::str('date_of_birth', $input);
        $bloodType = Request::str('blood_type', $input);
        $latitude = null;
        $longitude = null;

        $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

        $v->required('full_name', $fullName, 'Full name')
            ->length('full_name', $fullName, 2, 150, 'Full name')
            ->required('email', $email, 'Email')
            ->email('email', $email)
            ->required('password', $password, 'Password')
            ->required('chapter_id', $chapterId, 'Chapter')
            ->required('date_of_birth', $dob, 'Date of birth')
            ->in('blood_type', $bloodType, $allowedBloodTypes, 'Blood type');

        if ($password !== null && strlen($password) < 8) {
            $v->addError('password', 'Password must be at least 8 characters.');
        } elseif ($password !== null && strlen($password) > 72) {
            $v->addError('password', 'Password must be at most 72 characters.');
        }
        if ($password !== null && strlen($password) >= 8 && !preg_match('/[A-Za-z]/', $password)) {
            $v->addError('password', 'Password must contain at least one letter.');
        }
        if ($password !== null && strlen($password) >= 8 && !preg_match('/\d/', $password)) {
            $v->addError('password', 'Password must contain at least one number.');
        }

        if ($dob !== null) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if ($dt === false || $dt->format('Y-m-d') !== $dob) {
                $v->addError('date_of_birth', 'Date of birth must be a valid date (YYYY-MM-DD).');
            } elseif ($dt->getTimestamp() > time()) {
                $v->addError('date_of_birth', 'Date of birth cannot be in the future.');
            } elseif ((int) $dt->format('Y') < 1900) {
                $v->addError('date_of_birth', 'Date of birth is not plausible.');
            }
        }

        if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
            $v->addError('location_id', 'Set your location after registration using the Bataan municipality/barangay selector.');
        }

        if ($phone !== null && !preg_match('/^[0-9+\-\s()]{5,30}$/', $phone)) {
            $v->addError('phone', 'Phone format is invalid.');
        }

        if ($chapterId !== null && !$this->users->chapterExists($chapterId)) {
            $v->addError('chapter_id', 'Chapter does not exist.');
        }

        if (!self::isPrivacyAcknowledged($input['privacy_acknowledged'] ?? null)) {
            $v->addError('privacy_acknowledged', 'You must read and acknowledge the Privacy Notice to create an account.');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->users->emailExists($email)) {
            throw new DuplicateEntryException('Email is already registered.');
        }

        if ($v->fails()) {
            throw new Exceptions\ValidationException($v->errors());
        }

        $userId = $this->users->create([
            'email' => $email,
            'password_hash' => password_hash((string) $password, PASSWORD_BCRYPT),
            'full_name' => (string) $fullName,
            'phone' => $phone,
            'role' => 'member',
            'chapter_id' => $chapterId,
            'verification_status' => 'pending',
            'account_status' => 'active',
            'date_of_birth' => $dob,
            'blood_type' => $bloodType,
            'blood_type_source' => $bloodType !== null ? 'self_reported' : null,
            'blood_type_verified' => 0,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        // Registration-time email OTP: the account is authoritative from
        // this point on; the OTP subsystem is auxiliary and must never be
        // able to fail registration. The minted claim token lets the
        // still-logged-out registrant complete verification; no session
        // is created here (the user signs in afterwards, as before).
        $emailOtp = [
            'required' => false,
            'delivered' => false,
            'masked_email' => null,
            'expires_in_seconds' => 0,
            'resend_available_in_seconds' => 0,
            'verification_token' => null,
        ];
        try {
            $otpService = new EmailOtpService();
            $issued = $otpService->issueForRegistration($this->users->findById($userId));
            $emailOtp = [
                'required' => true,
                'delivered' => (bool) $issued['delivered'],
                'masked_email' => EmailOtpService::maskEmail($email),
                'expires_in_seconds' => (int) $issued['expires_in_seconds'],
                'resend_available_in_seconds' => (int) $issued['resend_available_in_seconds'],
                'verification_token' => $otpService->mintClaimToken($userId),
            ];
        } catch (Throwable $e) {
            error_log('[register] email-otp issuance failed: ' . $e->getMessage());
        }

        AuditLogger::log($userId, 'user.registered', 'user', (string) $userId, [
            'verification_status' => 'pending',
            'email_otp_delivered' => $emailOtp['delivered'],
        ]);

        return [
            'user' => $this->publicUser($this->users->findById($userId)),
            'email_otp' => $emailOtp,
        ];
    }

    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $key = 'login:' . $email;
        $now = self::nowUtc();

        if ($this->throttle->isLocked($key, $now)) {
            throw new Exceptions\AuthException('Too many attempts. Try again later.', 429);
        }

        $user = $this->users->findByEmail($email);

        if (
            $user === null
            || !is_string($user['password_hash'])
            || $user['password_hash'] === ''
            || !password_verify($password, $user['password_hash'])
        ) {
            $this->throttle->recordFailure($key, self::MAX_FAILED_ATTEMPTS, self::LOCK_MINUTES, $now);
            // No email in audit context: login identifiers are private.
            AuditLogger::log(null, 'auth.login.failed', 'user', null, ['endpoint' => 'auth.login']);
            throw new Exceptions\AuthException('Invalid email or password.', 401);
        }

        if ((string) $user['account_status'] === 'deactivated') {
            AuditLogger::log((int) $user['id'], 'auth.login.blocked_deactivated', 'user', (string) $user['id']);
            throw new Exceptions\AuthException('This account has been deactivated.', 403);
        }

        // Mandatory email verification gate: accounts that never proved
        // ownership of their address cannot authenticate. Placement is
        // deliberate — AFTER the uniform 401 password check (wrong
        // passwords stay indistinguishable from unknown emails) and after
        // the deactivated check (existing precedence), but BEFORE any
        // session state is created, so a blocked login never yields even
        // a partial authenticated session. No throttle failure is
        // recorded here (the password was correct), so verifying later
        // is never punished with a lockout.
        if (($user['email_verified_at'] ?? null) === null) {
            // Fresh single-purpose claim token so the blocked user has an
            // immediate path back to verification (resend rules still
            // apply; no OTP email is sent here). Supersedes any older
            // token, exactly like registration issuance.
            $claimToken = (new EmailOtpService())->mintClaimToken((int) $user['id']);
            AuditLogger::log((int) $user['id'], 'auth.login.blocked_unverified', 'user', (string) $user['id']);
            throw new Exceptions\EmailVerificationRequiredException(
                'Please verify your email address before logging in.',
                [
                    'code' => 'email_verification_required',
                    'masked_email' => EmailOtpService::maskEmail((string) $user['email']),
                    'verification_token' => $claimToken,
                ]
            );
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        }

        $this->throttle->clear($key);
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = (string) $user['role'];
        // Revocation epoch + activity marker (see AuthMiddleware idle and
        // version checks). Previous-session CSRF is rotated at this
        // privilege boundary so a pre-login token cannot be reused; the
        // fresh token ships in the login response so clients do not need
        // an extra round-trip.
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf_token']);
        $freshCsrf = \BloodMatch\Utils\Csrf::token();

        AuditLogger::log((int) $user['id'], 'auth.login.success', 'user', (string) $user['id']);

        return ['user' => $this->publicUser($user), 'csrf_token' => $freshCsrf];
    }

    public function logout(?int $userId): void
    {
        if ($userId !== null) {
            AuditLogger::log($userId, 'auth.logout', 'user', (string) $userId);
        }
        Session::destroy();
    }

    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        $key = 'reset:' . $email;
        $now = self::nowUtc();

        if ($this->throttle->isLocked($key, $now)) {
            AuditLogger::log(null, 'auth.password_reset.locked', null, null, ['endpoint' => 'password_reset.request']);
            throw new Exceptions\AuthException('Too many attempts. Try again later.', 429);
        }

        $user = $this->users->findByEmail($email);

        // Identical externally observable behavior whether or not the email
        // belongs to an account: throttle advances in both cases and the
        // response stays generic, so repeated probes cannot distinguish
        // known from unknown addresses (no lockout oracle, no email bombing
        // of valid accounts, no token-table bloat).
        $this->throttle->recordFailure($key, self::MAX_FAILED_ATTEMPTS, self::LOCK_MINUTES, $now);

        if ($user === null || (string) $user['account_status'] === 'deactivated') {
            return;
        }

        // Cap concurrently valid tokens per account; extra requests reuse the
        // existing window instead of minting unlimited tokens.
        $this->resets->deleteExpired($now);
        if ($this->resets->countActiveForUser((int) $user['id'], $now) >= 3) {
            AuditLogger::log((int) $user['id'], 'auth.password_reset.requested', 'user', (string) $user['id'], ['reused_window' => true]);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL_SECONDS);
        $this->resets->create((int) $user['id'], hash('sha256', $token), $expiresAt);

        AuditLogger::log((int) $user['id'], 'auth.password_reset.requested', 'user', (string) $user['id']);

        if (\BloodMatch\Services\Mailer::isConfigured()) {
            \BloodMatch\Services\Mailer::send(
                (string) $user['email'],
                '[BloodMatch] Password reset',
                '<p>A password reset was requested for your BloodMatch account.</p>'
                . '<p>Your single-use reset token (valid ~30 minutes):</p>'
                . '<p><strong>' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</strong></p>'
                . '<p>If you did not request this, ignore this email.</p>'
            );
        }
    }

    public function confirmPasswordReset(string $token, string $newPassword): void
    {
        if (
            strlen($newPassword) < 8
            || strlen($newPassword) > 72
            || !preg_match('/[A-Za-z]/', $newPassword)
            || !preg_match('/\d/', $newPassword)
        ) {
            throw new Exceptions\ValidationException([
                'password' => ['Password must be 8-72 characters and contain a letter and a number.'],
            ]);
        }

        $tokenHash = hash('sha256', $token);
        $now = self::nowUtc();

        // Failed-confirmation throttle (per-token): guessing a 256-bit token
        // is infeasible, but every failure is still rate-limited and audited.
        $confirmKey = 'reset-confirm:' . substr($tokenHash, 0, 16);
        if ($this->throttle->isLocked($confirmKey, $now)) {
            AuditLogger::log(null, 'auth.password_reset.locked', null, null, ['endpoint' => 'password_reset.confirm']);
            throw new Exceptions\AuthException('Too many attempts. Try again later.', 429);
        }

        // Atomic consumption: of N concurrent confirms with the same token,
        // exactly one succeeds; the rest observe an invalid token.
        $reset = $this->resets->consumeValidToken($tokenHash, $now);
        if ($reset === null) {
            $this->throttle->recordFailure($confirmKey, self::MAX_FAILED_ATTEMPTS, self::LOCK_MINUTES, $now);
            AuditLogger::log(null, 'auth.password_reset.failed', null, null, ['endpoint' => 'password_reset.confirm']);
            throw new Exceptions\AuthException('Reset link is invalid or has expired.', 400);
        }

        $target = $this->users->findById((int) $reset['user_id']);
        if ($target === null || (string) $target['account_status'] !== 'active') {
            AuditLogger::log((int) $reset['user_id'], 'auth.password_reset.failed', 'user', (string) $reset['user_id'], [
                'endpoint' => 'password_reset.confirm',
                'reason' => 'account_inactive',
            ]);
            throw new Exceptions\AuthException('This account can no longer use password reset.', 403);
        }

        $pdo = \BloodMatch\Config\Database::pdo();
        try {
            $pdo->beginTransaction();
            $this->users->updatePasswordHash((int) $reset['user_id'], password_hash($newPassword, PASSWORD_BCRYPT));
            $this->users->bumpSessionVersion((int) $reset['user_id']);
            $this->resets->deleteOtherUnused((int) $reset['user_id'], (int) $reset['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Could not complete password reset.', 0, $e);
        }

        $this->throttle->clear($confirmKey);
        AuditLogger::log((int) $reset['user_id'], 'auth.password_reset.completed', 'user', (string) $reset['user_id']);
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'verification_status' => (string) $user['verification_status'],
            'account_status' => (string) $user['account_status'],
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'profile_picture_url' => ProfilePictureStorageService::urlFor($user['profile_picture'] ?? null),
        ];
    }
}
