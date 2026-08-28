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
        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float) $input['longitude'] : null;

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

        if (($latitude === null) !== ($longitude === null)) {
            $v->addError('location', 'Latitude and longitude must be provided together.');
        }

        if ($chapterId !== null && !$this->users->chapterExists($chapterId)) {
            $v->addError('chapter_id', 'Chapter does not exist.');
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

        AuditLogger::log($userId, 'user.registered', 'user', (string) $userId, [
            'verification_status' => 'pending',
        ]);

        return $this->publicUser($this->users->findById($userId));
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
            AuditLogger::log(null, 'auth.login.failed', 'user', null, ['email' => $email]);
            throw new Exceptions\AuthException('Invalid email or password.', 401);
        }

        if ((string) $user['account_status'] === 'deactivated') {
            AuditLogger::log((int) $user['id'], 'auth.login.blocked_deactivated', 'user', (string) $user['id']);
            throw new Exceptions\AuthException('This account has been deactivated.', 403);
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        }

        $this->throttle->clear($key);
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = (string) $user['role'];

        AuditLogger::log((int) $user['id'], 'auth.login.success', 'user', (string) $user['id']);

        return $this->publicUser($user);
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
            throw new Exceptions\AuthException('Too many attempts. Try again later.', 429);
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || (string) $user['account_status'] === 'deactivated') {
            $this->throttle->recordFailure($key, self::MAX_FAILED_ATTEMPTS, self::LOCK_MINUTES, $now);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL_SECONDS);
        $this->resets->create((int) $user['id'], hash('sha256', $token), $expiresAt);

        $this->throttle->clear($key);
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

        $reset = $this->resets->findValidByHash($tokenHash, $now);
        if ($reset === null) {
            throw new Exceptions\AuthException('Reset link is invalid or has expired.', 400);
        }

        $pdo = \BloodMatch\Config\Database::pdo();
        try {
            $pdo->beginTransaction();
            $this->resets->markUsed((int) $reset['id'], $now);
            $this->users->updatePasswordHash((int) $reset['user_id'], password_hash($newPassword, PASSWORD_BCRYPT));
            $this->resets->deleteOtherUnused((int) $reset['user_id'], (int) $reset['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Could not complete password reset.', 0, $e);
        }

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
        ];
    }
}
