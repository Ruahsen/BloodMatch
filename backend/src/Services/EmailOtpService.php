<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\Database;
use BloodMatch\Repositories\AuthThrottleRepository;
use BloodMatch\Repositories\EmailOtpClaimRepository;
use BloodMatch\Repositories\EmailOtpRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\Exceptions\AuthException;
use Throwable;

/**
 * Email-ownership proof via short-lived one-time codes.
 *
 * Separate from (but modeled on) the password-reset flow: its own table,
 * its own audit events, its own throttle keys. A reset token is never
 * accepted here (6-digit format gate) and an OTP can never reset a
 * password (no password write path exists in this service).
 *
 * Rules: 6 numeric digits from random_int(), SHA-256 hash storage only,
 * 10-minute TTL, single-use, max 5 failed attempts per code, 60s resend
 * cooldown, max 5 sends per user per hour, newest code supersedes older
 * ones. Verification state (`users.email_verified_at`) is orthogonal to
 * `verification_status` (officer identity workflow) and `account_status`;
 * it gates nothing by itself.
 *
 * Two journeys share one core: (1) the registration journey, where the
 * account has no session yet and authorizes via a single-purpose claim
 * token minted at registration; (2) the authenticated journey for
 * signed-in users. Limits, hashing, expiry, and consumption are identical
 * in both — only identity resolution differs.
 */
final class EmailOtpService
{
    public const CODE_LENGTH = 6;
    public const TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_SENDS_PER_HOUR = 5;
    /**
     * Claim-token lifetime (seconds). Parity with password-reset tokens:
     * long enough to cover the 10-minute OTP plus resends, short enough
     * to bound the fallback credential. Lapsed tokens simply route the
     * user to the normal login + authenticated OTP flow.
     */
    public const CLAIM_TOKEN_TTL_SECONDS = 1800;

    private EmailOtpRepository $otps;
    private EmailOtpClaimRepository $claims;
    private UserRepository $users;
    private AuthThrottleRepository $throttle;

    public function __construct()
    {
        $this->otps = new EmailOtpRepository();
        $this->claims = new EmailOtpClaimRepository();
        $this->users = new UserRepository();
        $this->throttle = new AuthThrottleRepository();
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @return array{already_verified:bool, expires_in_seconds:int, resend_available_in_seconds:int}
     */
    public function requestOtp(array $user): array
    {
        if (($user['email_verified_at'] ?? null) !== null) {
            return [
                'already_verified' => true,
                'expires_in_seconds' => 0,
                'resend_available_in_seconds' => 0,
            ];
        }

        $challenge = $this->createChallenge($user, self::nowUtc());
        $this->sendChallenge($user, $challenge);

        AuditLogger::log((int) $user['id'], 'auth.email_otp.requested', 'user', (string) (int) $user['id']);

        return [
            'already_verified' => false,
            'expires_in_seconds' => self::TTL_SECONDS,
            'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS,
        ];
    }

    /**
     * Registration-time issuance: same core as requestOtp, but delivery
     * failure is reported (never thrown) so account creation — the
     * authoritative step — can never fail because of the OTP subsystem.
     *
     * @return array{delivered:bool, expires_in_seconds:int, resend_available_in_seconds:int}
     */
    public function issueForRegistration(array $user): array
    {
        try {
            $challenge = $this->createChallenge($user, self::nowUtc());
        } catch (AuthException $e) {
            error_log('[email-otp] registration issuance skipped: ' . $e->getMessage());
            return [
                'delivered' => false,
                'expires_in_seconds' => 0,
                'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS,
            ];
        }

        try {
            $this->sendChallenge($user, $challenge);
        } catch (AuthException $e) {
            return [
                'delivered' => false,
                'expires_in_seconds' => 0,
                'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS,
            ];
        }

        AuditLogger::log((int) $user['id'], 'auth.email_otp.requested', 'user', (string) (int) $user['id']);

        return [
            'delivered' => true,
            'expires_in_seconds' => self::TTL_SECONDS,
            'resend_available_in_seconds' => self::RESEND_COOLDOWN_SECONDS,
        ];
    }

    /**
     * Shared issuance core: pre-checks, single-active supersession,
     * hourly budget. Returns the plaintext code for immediate delivery
     * only; callers must unset it after sending.
     *
     * @return array{otpId:int, code:string}
     */
    private function createChallenge(array $user, string $now): array
    {
        $userId = (int) $user['id'];

        $email = (string) ($user['email'] ?? '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AuthException('No deliverable email address is on file for this account.', 422);
        }

        $latest = $this->otps->latestForUser($userId);
        if ($latest !== null && ($latest['used_at'] ?? null) === null) {
            $createdAt = strtotime((string) $latest['created_at'] . ' UTC');
            if ($createdAt !== false) {
                $elapsed = time() - $createdAt;
                $wait = self::RESEND_COOLDOWN_SECONDS - $elapsed;
                if ($wait > 0) {
                    $e = new AuthException('Please wait before requesting another code.', 429);
                    throw $e;
                }
            }
        }

        $sendKey = 'emailotp:send:' . $userId;
        if ($this->throttle->isLocked($sendKey, $now)) {
            AuditLogger::log($userId, 'auth.email_otp.locked', 'user', (string) $userId);
            throw new AuthException('Too many codes requested. Try again later.', 429);
        }

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            // Newest code wins: prior unused challenges die at issuance.
            $this->otps->deleteUnusedForUser($userId);
            $otpId = $this->otps->create($userId, hash('sha256', $code), $expiresAt);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Hourly send budget via the shared auth-throttle table. The +1
        // compensates AuthThrottleRepository's left-to-right SET evaluation
        // (the lock bit is written during the Nth record, enforced on the
        // next attempt), so exactly MAX_SENDS_PER_HOUR codes go out.
        $this->throttle->recordFailure($sendKey, self::MAX_SENDS_PER_HOUR + 1, 60, $now);

        return ['otpId' => $otpId, 'code' => $code];
    }

    /**
     * Delivers an issued challenge through the shared Mailer. On failure
     * the undelivered row is removed (no usable challenge the user never
     * received) and nothing is marked verified.
     */
    private function sendChallenge(array $user, array $challenge): void
    {
        $userId = (int) $user['id'];
        $otpId = (int) $challenge['otpId'];
        $mail = EmailOtpTemplate::render(
            (string) ($user['full_name'] ?? ''),
            (string) $challenge['code'],
            (int) (self::TTL_SECONDS / 60)
        );
        // Plaintext code lives only for this call; never logged, never stored.
        $sent = Mailer::send((string) $user['email'], $mail['subject'], $mail['html'], $mail['text']);
        unset($challenge);

        if (!$sent) {
            $this->otps->deleteById($otpId);
            AuditLogger::log($userId, 'auth.email_otp.delivery_failed', 'user', (string) $userId);
            error_log('[email-otp] delivery failed for user ' . $userId);
            throw new AuthException('Could not deliver the verification code. Try again later.', 503);
        }
    }

    /**
     * @return array{verified:bool, email_verified_at:string}
     */
    public function verifyOtp(array $user, string $code): array
    {
        $userId = (int) $user['id'];
        $now = self::nowUtc();
        $code = trim($code);

        if (!preg_match('/^\d{' . self::CODE_LENGTH . '}$/', $code)) {
            AuditLogger::log($userId, 'auth.email_otp.failed', 'user', (string) $userId, [
                'reason' => 'malformed',
            ]);
            throw new AuthException('Invalid or expired code.', 400);
        }

        $row = $this->otps->findActiveForUser($userId, $now);
        if ($row === null) {
            // Flat timing: always perform a comparison even with no row.
            hash_equals(hash('sha256', '000000'), hash('sha256', $code));
            AuditLogger::log($userId, 'auth.email_otp.failed', 'user', (string) $userId, [
                'reason' => 'no_active_challenge',
            ]);
            throw new AuthException('Invalid or expired code.', 400);
        }

        $otpId = (int) $row['id'];
        if (!hash_equals((string) $row['otp_hash'], hash('sha256', $code))) {
            $this->otps->incrementAttempts($otpId);
            $attempts = (int) $row['attempt_count'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->otps->markUsed($otpId, $now);
                AuditLogger::log($userId, 'auth.email_otp.exhausted', 'user', (string) $userId);
            }
            AuditLogger::log($userId, 'auth.email_otp.failed', 'user', (string) $userId, [
                'reason' => 'mismatch',
            ]);
            throw new AuthException('Invalid or expired code.', 400);
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $locked = $this->otps->lockRow($otpId);
            if ($locked === null
                || ($locked['used_at'] ?? null) !== null
                || strcmp((string) $locked['expires_at'], $now) <= 0
                || (int) $locked['attempt_count'] >= self::MAX_ATTEMPTS
                || !hash_equals((string) $locked['otp_hash'], hash('sha256', $code))
            ) {
                // Lost a race (concurrent consume/expiry/exhaustion): the
                // code is dead; report it exactly like any other dead code.
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                AuditLogger::log($userId, 'auth.email_otp.failed', 'user', (string) $userId, [
                    'reason' => 'race_consumed',
                ]);
                throw new AuthException('Invalid or expired code.', 400);
            }
            if (!$this->otps->consumeRow($otpId, self::MAX_ATTEMPTS, $now)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                AuditLogger::log($userId, 'auth.email_otp.failed', 'user', (string) $userId, [
                    'reason' => 'race_consumed',
                ]);
                throw new AuthException('Invalid or expired code.', 400);
            }
            $this->users->setEmailVerifiedAt($userId, $now);
            // Fail-closed audit: a verification without a trail aborts.
            AuditLogger::logCritical($userId, 'auth.email_otp.verified', 'user', (string) $userId);
            $pdo->commit();
        } catch (AuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->throttle->clear('emailotp:send:' . $userId);

        return ['verified' => true, 'email_verified_at' => $now];
    }

    /**
     * @return array{verified:bool, email_verified_at:?string, has_active_code:bool, expires_in_seconds:int, resend_available_in_seconds:int, attempts_remaining:int, masked_email:string}
     */
    public function statusFor(array $user): array
    {
        $userId = (int) $user['id'];
        $masked = self::maskEmail((string) ($user['email'] ?? ''));
        $verifiedAt = $user['email_verified_at'] ?? null;
        if ($verifiedAt !== null) {
            return [
                'verified' => true,
                'email_verified_at' => (string) $verifiedAt,
                'has_active_code' => false,
                'expires_in_seconds' => 0,
                'resend_available_in_seconds' => 0,
                'attempts_remaining' => 0,
                'masked_email' => $masked,
            ];
        }

        $now = self::nowUtc();
        $row = $this->otps->findActiveForUser($userId, $now);
        if ($row === null) {
            return [
                'verified' => false,
                'email_verified_at' => null,
                'has_active_code' => false,
                'expires_in_seconds' => 0,
                'resend_available_in_seconds' => 0,
                'attempts_remaining' => self::MAX_ATTEMPTS,
                'masked_email' => $masked,
            ];
        }

        $expiresIn = max(0, (int) (strtotime((string) $row['expires_at'] . ' UTC') - time()));
        $createdAgo = time() - (int) strtotime((string) $row['created_at'] . ' UTC');
        return [
            'verified' => false,
            'email_verified_at' => null,
            'has_active_code' => true,
            'expires_in_seconds' => $expiresIn,
            'resend_available_in_seconds' => max(0, self::RESEND_COOLDOWN_SECONDS - $createdAgo),
            'attempts_remaining' => max(0, self::MAX_ATTEMPTS - (int) $row['attempt_count']),
            'masked_email' => $masked,
        ];
    }

    /**
     * Mask an address for display (`j***@gmail.com`). The registrant typed
     * the address, but responses and logs must still avoid carrying it in
     * full where a masked form suffices.
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '***';
        }
        return mb_substr($parts[0], 0, 1) . '***@' . $parts[1];
    }

    /**
     * Mint the registration claim token. Returns the plaintext token once;
     * only its SHA-256 hash is stored. The token authorizes OTP
     * send/verify/status for this account only — never a session, never
     * any other endpoint.
     */
    public function mintClaimToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CLAIM_TOKEN_TTL_SECONDS);
        $this->claims->deleteUnusedForUser($userId);
        $this->claims->create($userId, hash('sha256', $token), $expiresAt);
        AuditLogger::log($userId, 'auth.email_otp.claim_issued', 'user', (string) $userId);
        return $token;
    }

    /**
     * Resolve a claim token to its live account row.
     *
     * @return array{user:array, claimId:int}
     */
    public function resolveClaimUser(string $token): array
    {
        $token = trim($token);
        $now = self::nowUtc();

        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            AuditLogger::log(null, 'auth.email_otp.failed', 'user', null, [
                'reason' => 'claim_malformed',
            ]);
            throw new AuthException('Verification session is invalid or has expired.', 401);
        }

        // Failed-claim throttle (per-token prefix): guessing a 256-bit
        // token is infeasible, but every failure is still rate-limited,
        // mirroring the password-reset confirm throttle.
        $claimKey = 'emailotp:claim:' . substr(hash('sha256', $token), 0, 16);
        if ($this->throttle->isLocked($claimKey, $now)) {
            AuditLogger::log(null, 'auth.email_otp.locked', null, null, ['endpoint' => 'email_otp.claim']);
            throw new AuthException('Too many attempts. Try again later.', 429);
        }

        $row = $this->claims->findValidByHash(hash('sha256', $token), $now);
        if ($row === null) {
            $this->throttle->recordFailure($claimKey, 5, 15, $now);
            AuditLogger::log(null, 'auth.email_otp.failed', 'user', null, [
                'reason' => 'claim_invalid',
            ]);
            throw new AuthException('Verification session is invalid or has expired.', 401);
        }

        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || (string) $user['account_status'] !== 'active') {
            AuditLogger::log((int) $row['user_id'], 'auth.email_otp.failed', 'user', (string) (int) $row['user_id'], [
                'reason' => 'claim_account_inactive',
            ]);
            throw new AuthException('This account can no longer verify its email.', 403);
        }

        $this->throttle->clear($claimKey);
        return ['user' => $user, 'claimId' => (int) $row['id']];
    }

    /**
     * @return array{verified:bool, email_verified_at:string}
     */
    public function verifyWithToken(string $token, string $code): array
    {
        $ctx = $this->resolveClaimUser($token);
        $result = $this->verifyOtp($ctx['user'], $code);
        // Single-use claim: the registration journey ends here. If the
        // consume races (double submit), verification already succeeded
        // exactly once via the atomic OTP consume, so still report success.
        $now = self::nowUtc();
        if (!$this->claims->consume((int) $ctx['claimId'], $now)) {
            error_log('[email-otp] claim already consumed for user ' . (int) $ctx['user']['id']);
        }
        return $result;
    }

    /**
     * @return array{already_verified:bool, expires_in_seconds:int, resend_available_in_seconds:int}
     */
    public function sendWithToken(string $token): array
    {
        $ctx = $this->resolveClaimUser($token);
        return $this->requestOtp($ctx['user']);
    }

    /**
     * @return array{verified:bool, email_verified_at:?string, has_active_code:bool, expires_in_seconds:int, resend_available_in_seconds:int, attempts_remaining:int, masked_email:string}
     */
    public function statusWithToken(string $token): array
    {
        $ctx = $this->resolveClaimUser($token);
        return $this->statusFor($ctx['user']);
    }
}
