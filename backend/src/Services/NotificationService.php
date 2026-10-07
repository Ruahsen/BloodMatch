<?php

declare(strict_types=1);

namespace BloodMatch\Services;

final class NotificationService
{
    public const EMAIL_NONE = 'none';
    public const EMAIL_NORMAL = 'normal';
    public const EMAIL_CRITICAL = 'critical';

    private const MAX_EMAILS_PER_USER_PER_HOUR = 5;
    private const MAX_EMAIL_RECIPIENTS_PER_BATCH = 500;
    /**
     * Critical-specific throttle: a donor receives at most this many
     * critical match emails per request per hour. Critical keeps priority
     * (higher than the normal 5/hour budget), but repeated material edits
     * on one critical request cannot flood a donor. In-app notifications
     * are always still created; only the email leg is throttled.
     */
    private const MAX_CRITICAL_EMAILS_PER_REQUEST_PER_HOUR = 3;

    public static function dedupMatch(int $requestId, int $donorId): string
    {
        return "match:{$requestId}:{$donorId}";
    }

    public static function dedupVerification(int $userId, string $decision, ?int $auditId): string
    {
        $suffix = $auditId !== null ? (string) $auditId : uniqid('', true);
        return "verification:{$userId}:{$decision}:{$suffix}";
    }

    public static function dedupAccount(int $userId, string $transition, ?int $auditId): string
    {
        $suffix = $auditId !== null ? (string) $auditId : uniqid('', true);
        return "account:{$userId}:{$transition}:{$suffix}";
    }

    public static function dedupRequestStatus(int $requestId, string $status): string
    {
        return "request:{$requestId}:{$status}";
    }

    public static function dedupDonation(int $reportId, string $decision): string
    {
        return "donation:{$reportId}:{$decision}";
    }

    /**
     * One-time match lifecycle events (responded/accepted/withdrawn): stable
     * key per match so duplicate submissions stay idempotent (INSERT IGNORE).
     */
    public static function dedupMatchEvent(int $matchId, string $event): string
    {
        return "match-event:{$matchId}:{$event}";
    }

    /**
     * Repeatable match lifecycle events (unaccepted/consent_revoked): the
     * audit id suffix keeps each occurrence notifiable, mirroring the
     * verification/account dedup pattern.
     */
    public static function dedupMatchOccurrence(int $matchId, string $event, ?int $auditId): string
    {
        $suffix = $auditId !== null ? (string) $auditId : uniqid('', true);
        return "match-event:{$matchId}:{$event}:{$suffix}";
    }

    public static function notify(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $options = []
    ): ?int {
        $repo = new \BloodMatch\Repositories\NotificationRepository();
        $id = $repo->insert([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => mb_substr($body, 0, 500),
            'related_type' => $options['related_type'] ?? null,
            'related_id' => $options['related_id'] ?? null,
            'dedup_key' => $options['dedup_key'] ?? null,
            'generation' => $options['generation'] ?? 0,
        ]);

        if ($id === null) {
            return null;
        }

        $emailPriority = $options['email'] ?? self::EMAIL_NONE;
        if ($emailPriority !== self::EMAIL_NONE) {
            self::attemptEmail(
                $userId,
                $id,
                $type,
                $title,
                $body,
                $emailPriority,
                $options['related_type'] ?? null,
                isset($options['related_id']) ? (int) $options['related_id'] : null
            );
        }

        return $id;
    }

    public static function notifyMany(array $userIds, string $type, string $title, string $body, array $options = []): array
    {
        $notified = [];
        foreach (array_slice(array_unique($userIds), 0, self::MAX_EMAIL_RECIPIENTS_PER_BATCH * 10) as $uid) {
            $id = self::notify($uid, $type, $title, $body, $options);
            if ($id !== null) {
                $notified[$id] = $uid;
            }
        }
        return $notified;
    }

    public static function notifyMatchGeneration(
        array $request,
        int $generation,
        array $eligibleDonorIds
    ): array {
        $requestId = (int) $request['id'];
        $critical = (string) $request['urgency'] === 'critical';
        $emailPriority = $critical ? self::EMAIL_CRITICAL : self::EMAIL_NORMAL;

        $title = sprintf('New compatible donation opportunity (#%d)', $requestId);
        $body = sprintf(
            'An %s request for blood type %s near %s needs donors like you. Open BloodMatch for details.',
            (string) $request['urgency'],
            (string) $request['required_blood_type'],
            (string) $request['facility_name']
        );

        // Intentional outreach bound: at most 500 donors are notified per
        // generation as an anti-flood measure. Donors beyond the bound are
        // still persisted as POTENTIAL candidates by MatchService (visible
        // to the requester, counted in pool_size) and become notifiable on
        // later generations; nothing is silently dropped from matching.
        $notifiedDonorIds = [];
        foreach (array_slice($eligibleDonorIds, 0, self::MAX_EMAIL_RECIPIENTS_PER_BATCH) as $donorId) {
            $id = self::notify(
                (int) $donorId,
                'match.new',
                $title,
                $body,
                [
                    'related_type' => 'blood_request',
                    'related_id' => $requestId,
                    'dedup_key' => self::dedupMatch($requestId, (int) $donorId),
                    'generation' => $generation,
                    'email' => $emailPriority,
                ]
            );
            if ($id !== null) {
                $notifiedDonorIds[] = (int) $donorId;
            }
        }

        return $notifiedDonorIds;
    }

    private static function relatedRequestOf(int $notificationId): ?int
    {
        $stmt = \BloodMatch\Config\Database::pdo()->prepare(
            "SELECT related_id FROM notifications
              WHERE id = ? AND related_type = 'blood_request' LIMIT 1"
        );
        $stmt->execute([$notificationId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int) $id;
    }

    private static function attemptEmail(
        int $userId,
        int $notificationId,
        string $type,
        string $title,
        string $body,
        string $priority,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        if (!Mailer::isConfigured()) {
            return;
        }

        $repo = new \BloodMatch\Repositories\NotificationRepository();

        if ($priority !== self::EMAIL_CRITICAL) {
            if ($repo->emailCountInLastHour($userId, AuthService::nowUtc()) >= self::MAX_EMAILS_PER_USER_PER_HOUR) {
                return;
            }
        } else {
            $related = self::relatedRequestOf($notificationId);
            if ($related !== null
                && $repo->criticalEmailCountForRequest($userId, $related, AuthService::nowUtc()) >= self::MAX_CRITICAL_EMAILS_PER_REQUEST_PER_HOUR) {
                return;
            }
        }

        $user = (new \BloodMatch\Repositories\UserRepository())->findById($userId);
        if ($user === null || !is_string($user['email']) || filter_var($user['email'], FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // The email mirrors the in-app notification: same title/body the
        // recipient already received, plus branding, timestamp, deep link,
        // and footer from the shared template. No other data is pulled in.
        $now = AuthService::nowUtc();
        $email = NotificationEmailTemplate::render($type, $title, $body, $relatedType, $relatedId, $now);

        $sent = Mailer::send($user['email'], $email['subject'], $email['html'], $email['text']);

        if ($sent) {
            $repo->markEmailed($notificationId, AuthService::nowUtc());
        }
    }
}
