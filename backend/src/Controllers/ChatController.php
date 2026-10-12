<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AuthThrottleRepository;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\ChatService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use Throwable;

final class ChatController
{
    private const ENDPOINT = 'chat';
    private ChatService $chatService;

    public function __construct(?ChatService $chatService = null)
    {
        $this->chatService = $chatService ?? new ChatService();
    }

    public function unreadCount(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.unread');
        $count = $this->chatService->getUnreadCount((int) $actor['id']);
        Response::success(['unread_count' => $count]);
    }

    public function conversations(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.conversations');
        $list = $this->chatService->getConversations((int) $actor['id']);
        Response::success(['conversations' => $list]);
    }

    public function startConversation(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.start');
        $recipientId = Request::int('recipient_id');

        if ($recipientId === null || $recipientId <= 0) {
            Response::error('Invalid or missing recipient_id.', 422);
            return;
        }

        try {
            $conversation = $this->chatService->getOrCreateDirectConversation($actor, $recipientId);
            Response::success(['conversation' => $conversation]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function conversation(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.detail');
        $conversationId = (int) ($params['id'] ?? 0);

        if ($conversationId <= 0) {
            Response::error('Invalid conversation ID.', 404);
            return;
        }

        try {
            $conv = $this->chatService->getConversationDetails($actor, $conversationId);
            Response::success(['conversation' => $conv]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 403);
        }
    }

    public function messages(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.messages');
        $conversationId = (int) ($params['id'] ?? 0);

        if ($conversationId <= 0) {
            Response::error('Invalid conversation ID.', 404);
            return;
        }

        $afterId = Request::int('after_id');
        $limit = Request::int('limit') ?? 50;

        try {
            $messages = $this->chatService->getMessages($actor, $conversationId, $afterId, $limit);
            $meta = $this->chatService->getConversationMetadata($actor, $conversationId);
            Response::success([
                'messages' => $messages,
                'has_messages' => $meta['has_messages'],
                'match_role' => $meta['match_role'],
                'suggested_chats' => $meta['suggested_chats'],
            ]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 403);
        }
    }

    public function sendMessage(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.send');
        $conversationId = (int) ($params['id'] ?? 0);

        if ($conversationId <= 0) {
            Response::error('Invalid conversation ID.', 404);
            return;
        }

        // Rate limit: max 15 messages per 30 seconds per user
        $throttleKey = 'chat_send:' . (int) $actor['id'];
        $throttle = new AuthThrottleRepository();
        $nowUtc = AuthService::nowUtc();

        if (!$throttle->hitAndCheckRateLimitSeconds($throttleKey, 15, 30, $nowUtc)) {
            Response::error('Too many messages sent. Please slow down and try again shortly.', 429);
            return;
        }

        $body = Request::str('body');
        if ($body === null || trim($body) === '') {
            Response::error('Message body is required.', 422);
            return;
        }

        try {
            $message = $this->chatService->sendMessage($actor, $conversationId, $body);
            Response::success(['message' => $message]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function markRead(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.read');
        $conversationId = (int) ($params['id'] ?? 0);

        if ($conversationId <= 0) {
            Response::error('Invalid conversation ID.', 404);
            return;
        }

        try {
            $this->chatService->markRead($actor, $conversationId);
            Response::success(['status' => 'ok']);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
