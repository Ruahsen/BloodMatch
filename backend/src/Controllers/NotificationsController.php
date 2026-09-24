<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\NotificationRepository;
use BloodMatch\Services\AuthService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use RuntimeException;

final class NotificationsController
{
    private const ENDPOINT = 'notifications';

    public function index(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.list');

        $typeFilter = Request::str('type');
        $readFilter = Request::str('read');

        $result = (new NotificationRepository())->listForUser(
            (int) $actor['id'],
            max(1, Request::int('page') ?? 1),
            min(50, max(1, Request::int('page_size') ?? 25)),
            $typeFilter,
            $readFilter
        );

        Response::success([
            'total' => $result['total'],
            'notifications' => array_map(static fn (array $n): array => [
                'id' => (int) $n['id'],
                'type' => (string) $n['type'],
                'title' => (string) $n['title'],
                'body' => (string) $n['body'],
                'related_type' => $n['related_type'],
                'related_id' => $n['related_id'] !== null ? (int) $n['related_id'] : null,
                'emailed_at' => $n['emailed_at'],
                'read_at' => $n['read_at'],
                'created_at' => (string) $n['created_at'],
            ], $result['notifications']),
        ]);
    }

    public function unreadCount(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.unread');
        Response::success(['unread_count' => (new NotificationRepository())->unreadCount((int) $actor['id'])]);
    }

    public function markRead(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.read');

        $repo = new NotificationRepository();
        if ($repo->findOwned((int) $params['id'], (int) $actor['id']) === null) {
            Response::error('Notification not found.', 404);
            return;
        }

        $repo->markRead((int) $params['id'], (int) $actor['id']);
        Response::success(['unread_count' => $repo->unreadCount((int) $actor['id'])]);
    }

    public function markAllRead(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.read_all');
        $count = (new NotificationRepository())->markAllRead((int) $actor['id']);
        Response::success(['marked_read' => $count]);
    }
}
