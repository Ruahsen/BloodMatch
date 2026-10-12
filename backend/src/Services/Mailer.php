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
        $domain = strtolower((string) substr(strrchr($toEmail, '@') ?: '', 1));
        if (preg_match('/(^|\.)(local|test|invalid)$/', $domain) === 1) {
            error_log('[mailer] skipped: reserved domain (' . $domain . ')');
            return false;
        }

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
            $host = (string) \BloodMatch\Config\Env::get('MAIL_HOST');
            if ($host === '' || $host === 'mock') {
                return true;
            }
            $mail->isSMTP();
            $mail->Host = $host;
            $port = (int) (\BloodMatch\Config\Env::get('MAIL_PORT', '25'));
            $mail->Port = $port;
            if ($port === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($port === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
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
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
            $mail->send();
            error_log('[mailer] successfully sent email to ' . $toEmail . ' (subject: ' . $subject . ')');
            return true;
        } catch (\Throwable $e) {
            error_log('[mailer] send failed to ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }
}
