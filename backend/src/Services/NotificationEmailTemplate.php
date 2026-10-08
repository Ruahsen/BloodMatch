<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Config\AppConfig;
use BloodMatch\Config\Env;

/**
 * Reusable email templates for the notification email channel.
 *
 * The in-app notification remains the source event: every template renders
 * ONLY the notification's own title/body (already intended for this
 * recipient) plus BloodMatch branding, a timestamp, a deep link, and a
 * footer. Templates never pull contact details, coordinates, tokens, or any
 * other user's personal information - bilateral contact rules are untouched
 * (see MatchDecisionService::contact).
 *
 * Add a new notification type by extending actionPathFor(); unknown types
 * safely fall back to the notification center.
 */
final class NotificationEmailTemplate
{
    /**
     * @return array{subject:string, html:string, text:string, actionUrl:string}
     */
    public static function render(
        string $type,
        string $title,
        string $body,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $sentAtUtc = null
    ): array {
        $subject = '[BloodMatch] ' . $title;
        $actionPath = self::actionPathFor($type, $relatedType, $relatedId);
        $base = self::frontendBaseUrl();
        $actionUrl = $base !== '' ? rtrim($base, '/') . $actionPath : $actionPath;
        $centerUrl = $base !== '' ? rtrim($base, '/') . '/notifications' : '/notifications';
        $when = $sentAtUtc !== null && $sentAtUtc !== '' ? $sentAtUtc . ' UTC' : 'just now';

        $escTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $escBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $escWhen = htmlspecialchars($when, ENT_QUOTES, 'UTF-8');
        $escAction = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');
        $escCenter = htmlspecialchars($centerUrl, ENT_QUOTES, 'UTF-8');
        $ctaLabel = htmlspecialchars(self::ctaLabelFor($type), ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#111;">'
            . '<div style="background:#111;color:#fff;padding:16px 20px;font-size:18px;font-weight:bold;">BloodMatch</div>'
            . '<div style="padding:20px;border:1px solid #ddd;border-top:none;">'
            . '<h2 style="margin:0 0 8px 0;font-size:18px;">' . $escTitle . '</h2>'
            . '<p style="margin:0 0 12px 0;font-size:14px;">' . $escBody . '</p>'
            . '<p style="margin:0 0 16px 0;font-size:12px;color:#555;">When: ' . $escWhen . '</p>'
            . '<p style="margin:0 0 16px 0;"><a href="' . $escAction . '" style="display:inline-block;background:#111;color:#fff;padding:10px 18px;text-decoration:none;font-size:14px;">' . $ctaLabel . '</a></p>'
            . '<p style="margin:0;font-size:12px;color:#555;">Or open your notification center: <a href="' . $escCenter . '">' . $escCenter . '</a></p>'
            . '</div>'
            . '<div style="padding:12px 20px;font-size:11px;color:#777;">'
            . 'This email mirrors an in-app BloodMatch notification sent to you. Sign in to BloodMatch to view it and take action. '
            . 'Please do not reply to this email. BloodMatch will never ask for your password by email.'
            . '</div>'
            . '</div>';

        $text = "BloodMatch\n\n"
            . $title . "\n\n"
            . $body . "\n\n"
            . 'When: ' . $when . "\n\n"
            . self::ctaLabelFor($type) . ': ' . $actionUrl . "\n"
            . 'All notifications: ' . $centerUrl . "\n\n"
            . "This email mirrors an in-app BloodMatch notification sent to you. Sign in to BloodMatch to view it and take action. "
            . 'Please do not reply to this email. BloodMatch will never ask for your password by email.';

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'actionUrl' => $actionUrl];
    }

    /**
     * Role-safe deep link per notification type. Links point at pages the
     * recipient is already authorized to open; anything else falls back to
     * the notification center. Donor-side copies intentionally avoid
     * requester-only pages such as /requests/:id/matches.
     */
    public static function actionPathFor(string $type, ?string $relatedType = null, ?int $relatedId = null): string
    {
        $requestId = ($relatedType === 'blood_request' && $relatedId !== null && $relatedId > 0)
            ? (int) $relatedId
            : null;

        return match ($type) {
            // Donor opportunity: browse compatible requests.
            'match.new' => '/feed',
            // Requester-side lifecycle on their own request.
            'match.responded', 'match.withdrawn' => $requestId !== null
                ? '/requests/' . $requestId . '/matches'
                : '/notifications',
            'request.fulfilled' => '/requests/mine',
            // Member account pages.
            'verification.decision', 'account.status_changed' => '/profile',
            // Everything else (match.accepted, match.unaccepted,
            // match.consent_revoked, match.closed, donation.*, request.cancelled,
            // request.expired, future types): notification center.
            default => '/notifications',
        };
    }

    public static function ctaLabelFor(string $type): string
    {
        return match ($type) {
            'match.new' => 'View compatible requests',
            'match.responded', 'match.withdrawn' => 'Review your matches',
            'match.accepted' => 'View your notifications',
            'request.fulfilled', 'request.cancelled', 'request.expired' => 'View your requests',
            'verification.decision', 'account.status_changed' => 'Open your profile',
            'donation.confirmed', 'donation.rejected' => 'View your notifications',
            default => 'Open BloodMatch',
        };
    }

    /**
     * Absolute frontend base URL for email links. Explicit FRONTEND_URL wins;
     * otherwise the first APP_CORS_ORIGINS entry is reused so no new setting
     * is required in existing deployments.
     */
    public static function frontendBaseUrl(): string
    {
        $explicit = (string) Env::get('FRONTEND_URL', '');
        if ($explicit !== '') {
            return $explicit;
        }
        $origins = AppConfig::corsOrigins();
        return $origins !== [] ? (string) $origins[0] : '';
    }
}
