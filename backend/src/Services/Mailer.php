<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\AppConfig;
use BloodMatch\Config\Env;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function isConfigured(): bool
    {
        return self::smtpHost() !== '' || self::captureDir() !== '';
    }

    /**
     * Best-effort send. Returns true only when the message was accepted for
     * delivery (SMTP) or captured to disk (local testing). Any failure is
     * logged and returns false — callers must never let an email failure
     * break the business transaction.
     */
    public static function send(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if (!self::isConfigured()) {
            error_log('[mailer] skipped: SMTP not configured');
            return false;
        }

        $captureDir = self::captureDir();
        if ($captureDir !== '') {
            return self::capture($captureDir, $toEmail, $subject, $htmlBody, $textBody);
        }

        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/Exception.php';
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/SMTP.php';
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/PHPMailer.php';
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = self::smtpHost();
            $mail->Port = (int) (Env::get('MAIL_PORT', '587') ?: '587');
            $mail->Timeout = (int) (Env::get('MAIL_TIMEOUT', '10') ?: '10');
            $user = self::smtpUser();
            $pass = self::smtpPass();
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            } else {
                $mail->SMTPAuth = false;
            }
            $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', ''));
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->CharSet = 'UTF-8';
            $from = self::fromAddress();
            $fromName = (string) Env::get('MAIL_FROM_NAME', 'BloodMatch');
            $mail->setFrom($from, $fromName !== '' ? $fromName : 'BloodMatch');
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== null && $textBody !== ''
                ? $textBody
                : trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)));
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function smtpHost(): string
    {
        return (string) Env::get('MAIL_HOST', '');
    }

    private static function smtpUser(): string
    {
        // MAIL_USERNAME is the canonical name; MAIL_USER kept for existing
        // deployments.
        $canonical = (string) Env::get('MAIL_USERNAME', '');
        return $canonical !== '' ? $canonical : (string) Env::get('MAIL_USER', '');
    }

    private static function smtpPass(): string
    {
        $canonical = (string) Env::get('MAIL_PASSWORD', '');
        return $canonical !== '' ? $canonical : (string) Env::get('MAIL_PASS', '');
    }

    private static function fromAddress(): string
    {
        $canonical = (string) Env::get('MAIL_FROM_ADDRESS', '');
        $address = $canonical !== '' ? $canonical : (string) Env::get('MAIL_FROM', 'bloodmatch@localhost');
        return $address !== '' ? $address : 'bloodmatch@localhost';
    }

    private static function captureDir(): string
    {
        return (string) Env::get('MAIL_CAPTURE_DIR', '');
    }

    /**
     * Safe local-testing transport: writes the rendered message to disk
     * instead of delivering it, so emails can be verified without ever
     * sending real mail. Each file is one notification email; the in-app
     * flow treats a captured message as sent (emailed_at is recorded).
     */
    private static function capture(string $dir, string $toEmail, string $subject, string $htmlBody, ?string $textBody): bool
    {
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                error_log('[mailer] capture failed: cannot create dir ' . $dir);
                return false;
            }
            if (!is_writable($dir)) {
                error_log('[mailer] capture failed: dir not writable ' . $dir);
                return false;
            }
            $safeTo = preg_replace('/[^A-Za-z0-9@._-]+/', '_', $toEmail);
            $file = rtrim($dir, "/\\")
                . DIRECTORY_SEPARATOR
                . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safeTo . '.eml';
            $text = $textBody !== null && $textBody !== ''
                ? $textBody
                : trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)));
            $payload = 'To: ' . $toEmail . "\n"
                . 'Subject: ' . $subject . "\n"
                . 'X-BloodMatch-Capture: 1' . "\n"
                . 'X-BloodMatch-Captured-At: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
                . "\n--- TEXT ---\n" . $text . "\n"
                . "\n--- HTML ---\n" . $htmlBody . "\n";
            if (@file_put_contents($file, $payload) === false) {
                error_log('[mailer] capture failed: cannot write ' . $file);
                return false;
            }
            error_log('[mailer] captured email to ' . $file);
            return true;
        } catch (\Throwable $e) {
            error_log('[mailer] capture failed: ' . $e->getMessage());
            return false;
        }
    }
}
