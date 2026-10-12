<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\UserRepository;
use Throwable;

final class EmailVerificationService
{
    public const CODE_TTL_SECONDS = 600; // 10 minutes

    public static function sendVerificationCode(int $userId, string $email, bool $isResend = false): void
    {
        $code = sprintf('%06d', random_int(0, 999999));
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS);

        (new UserRepository())->setEmailCode($userId, $codeHash, $expiresAt);

        if (\BloodMatch\Config\AppConfig::debug()) {
            error_log(sprintf('[otp] Verification code for user %d (%s): %s', $userId, $email, $code));
        }

        if (Mailer::isConfigured()) {
            try {
                $subject = $isResend
                    ? '[BloodMatch] Your new verification code'
                    : '[BloodMatch] Verify your email address';
                $intro = $isResend
                    ? 'Here is your new verification code for BloodMatch:'
                    : 'Welcome to BloodMatch! Your verification code is:';

                $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #111;">'
                    . '<h2 style="margin-top: 0;">BloodMatch Verification</h2>'
                    . '<p>' . $intro . '</p>'
                    . '<div style="background: #f4f4f5; padding: 16px; border-radius: 8px; text-align: center; margin: 24px 0;">'
                    . '<span style="font-size: 32px; font-weight: bold; letter-spacing: 6px; font-family: monospace; color: #000;">'
                    . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
                    . '</span>'
                    . '</div>'
                    . '<p style="color: #666; font-size: 14px;">This code will expire in 10 minutes. If you did not request this, please ignore this email.</p>'
                    . '</div>';

                Mailer::send($email, $subject, $body);
            } catch (Throwable $e) {
                error_log(sprintf('[mailer] verification email failed for user %d: %s', $userId, $e->getMessage()));
            }
        }
    }
}
