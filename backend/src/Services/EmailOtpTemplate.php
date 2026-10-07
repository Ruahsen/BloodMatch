<?php

declare(strict_types=1);

namespace BloodMatch\Services;

/**
 * Standalone template for the email-OTP verification channel.
 *
 * Unlike NotificationEmailTemplate (which mirrors an in-app notification),
 * the OTP email carries only the short-lived verification code plus
 * branding and footer. It never includes passwords, session/CSRF/reset
 * tokens, database internals, coordinates, or other personal information.
 */
final class EmailOtpTemplate
{
    /**
     * @return array{subject:string, html:string, text:string}
     */
    public static function render(string $recipientName, string $otpCode, int $ttlMinutes): array
    {
        $subject = 'BloodMatch Email Verification';

        $escName = htmlspecialchars($recipientName !== '' ? $recipientName : 'there', ENT_QUOTES, 'UTF-8');
        $escCode = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#111;">'
            . '<div style="background:#111;color:#fff;padding:16px 20px;font-size:18px;font-weight:bold;">BloodMatch</div>'
            . '<div style="padding:20px;border:1px solid #ddd;border-top:none;">'
            . '<p style="margin:0 0 12px 0;font-size:14px;">Hello ' . $escName . ',</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;">Your BloodMatch verification code is:</p>'
            . '<p style="margin:0 0 12px 0;font-size:28px;font-weight:bold;letter-spacing:8px;">' . $escCode . '</p>'
            . '<p style="margin:0 0 12px 0;font-size:14px;">This code expires in ' . $ttlMinutes . ' minutes and can be used only once.</p>'
            . '<p style="margin:0;font-size:14px;">If you did not request this code, you can ignore this email.</p>'
            . '</div>'
            . '<div style="padding:12px 20px;font-size:11px;color:#777;">'
            . 'BloodMatch will never ask for your password by email. Please do not reply to this email.'
            . '</div>'
            . '</div>';

        $text = "Hello " . ($recipientName !== '' ? $recipientName : 'there') . ",\n\n"
            . "Your BloodMatch verification code is:\n\n"
            . $otpCode . "\n\n"
            . "This code expires in {$ttlMinutes} minutes and can be used only once.\n\n"
            . "If you did not request this code, you can ignore this email.\n\n"
            . "BloodMatch";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }
}
