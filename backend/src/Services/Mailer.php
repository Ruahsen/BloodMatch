<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\AppConfig;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function isConfigured(): bool
    {
        return \BloodMatch\Config\Env::get('MAIL_HOST', '') !== '';
    }

    public static function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        if (!self::isConfigured()) {
            error_log('[mailer] skipped: SMTP not configured');
            return false;
        }

        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/Exception.php';
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/SMTP.php';
            require_once \BloodMatch\Config\AppConfig::basePath() . '/backend/lib/phpmailer/PHPMailer.php';
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) \BloodMatch\Config\Env::get('MAIL_HOST');
            $mail->Port = (int) (\BloodMatch\Config\Env::get('MAIL_PORT', '25'));
            $user = \BloodMatch\Config\Env::get('MAIL_USER', '');
            $pass = \BloodMatch\Config\Env::get('MAIL_PASS', '');
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            } else {
                $mail->SMTPAuth = false;
            }
            $mail->CharSet = 'UTF-8';
            $from = \BloodMatch\Config\Env::get('MAIL_FROM', 'bloodmatch@localhost');
            $mail->setFrom($from, 'BloodMatch');
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }
}
