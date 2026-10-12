<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\ChatRepository;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Repositories\UserRepository;
use RuntimeException;

final class ChatService
{
    private ChatRepository $chatRepo;
    private UserRepository $userRepo;
    private ChatPolicy $policy;
    private MatchRepository $matchRepo;

    public function __construct(
        ?ChatRepository $chatRepo = null,
        ?UserRepository $userRepo = null,
        ?ChatPolicy $policy = null,
        ?MatchRepository $matchRepo = null
    ) {
        $this->chatRepo = $chatRepo ?? new ChatRepository();
        $this->userRepo = $userRepo ?? new UserRepository();
        $this->policy = $policy ?? new ChatPolicy($this->chatRepo);
        $this->matchRepo = $matchRepo ?? new MatchRepository();
    }

    public function getOrCreateDirectConversation(array $currentUser, int $recipientId): array
    {
        $currentUserId = (int) $currentUser['id'];
        if ($currentUserId === $recipientId) {
            throw new RuntimeException('You cannot message yourself.');
        }

        $recipient = $this->userRepo->findProfileDisplayById($recipientId);
        if ($recipient === null) {
            throw new RuntimeException('Recipient not found.');
        }

        if (!$this->policy->canMessage($currentUser, $recipient)) {
            throw new RuntimeException('You are not authorized to message this user.');
        }

        $existingId = $this->chatRepo->findDirectConversationId($currentUserId, $recipientId);
        $convId = $existingId ?? $this->chatRepo->createDirectConversation($currentUserId, $recipientId);

        $other = $this->chatRepo->getOtherParticipant($convId, $currentUserId);
        $hasMessages = $this->chatRepo->hasMessages($convId);
        $matchRole = $this->matchRepo->getMatchRole($currentUserId, $recipientId);
        $suggestedChats = (!$hasMessages && $matchRole !== null)
            ? SuggestedChatService::getSuggestionsForRole($matchRole)
            : null;

        return [
            'id' => $convId,
            'type' => 'direct',
            'recipient' => $other,
            'match_role' => $matchRole,
            'has_messages' => $hasMessages,
            'suggested_chats' => $suggestedChats,
        ];
    }

    public function getConversations(int $userId): array
    {
        return $this->chatRepo->listConversationsForUser($userId);
    }

    public function getMessages(array $currentUser, int $conversationId, ?int $afterId = null, int $limit = 50): array
    {
        if (!$this->policy->canAccessConversation($currentUser, $conversationId)) {
            throw new RuntimeException('You are not authorized to view this conversation.');
        }

        return $this->chatRepo->listMessages($conversationId, $afterId, $limit);
    }

    public function sendMessage(array $currentUser, int $conversationId, string $body): array
    {
        if (!$this->policy->canAccessConversation($currentUser, $conversationId)) {
            throw new RuntimeException('You are not authorized to send messages in this conversation.');
        }

        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Message body cannot be empty.');
        }

        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new RuntimeException('Message contains invalid characters.');
        }

        // Strip non-printable control characters while preserving standard whitespace
        $cleanBody = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
        $cleanBody = trim($cleanBody ?? '');

        if ($cleanBody === '') {
            throw new RuntimeException('Message body cannot be empty.');
        }

        if (mb_strlen($cleanBody) > 2000) {
            throw new RuntimeException('Message body exceeds maximum length of 2000 characters.');
        }

        return $this->chatRepo->insertMessage($conversationId, (int) $currentUser['id'], $cleanBody);
    }

    public function markRead(array $currentUser, int $conversationId): void
    {
        if (!$this->policy->canAccessConversation($currentUser, $conversationId)) {
            throw new RuntimeException('You are not authorized to update this conversation.');
        }

        $this->chatRepo->markRead($conversationId, (int) $currentUser['id']);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->chatRepo->totalUnreadCount($userId);
    }

    public function getConversationDetails(array $currentUser, int $conversationId): array
    {
        if (!$this->policy->canAccessConversation($currentUser, $conversationId)) {
            throw new RuntimeException('You are not authorized to view this conversation.');
        }

        $currentUserId = (int) $currentUser['id'];
        $other = $this->chatRepo->getOtherParticipant($conversationId, $currentUserId);
        $recipientId = (int) ($other['id'] ?? 0);
        $matchRole = ($recipientId > 0) ? $this->matchRepo->getMatchRole($currentUserId, $recipientId) : null;
        $hasMessages = $this->chatRepo->hasMessages($conversationId);
        $suggestedChats = (!$hasMessages && $matchRole !== null)
            ? SuggestedChatService::getSuggestionsForRole($matchRole)
            : null;

        return [
            'id' => $conversationId,
            'type' => 'direct',
            'recipient' => $other,
            'match_role' => $matchRole,
            'has_messages' => $hasMessages,
            'suggested_chats' => $suggestedChats,
        ];
    }

    public function getConversationMetadata(array $currentUser, int $conversationId): array
    {
        if (!$this->policy->canAccessConversation($currentUser, $conversationId)) {
            throw new RuntimeException('You are not authorized to view this conversation.');
        }

        $currentUserId = (int) $currentUser['id'];
        $other = $this->chatRepo->getOtherParticipant($conversationId, $currentUserId);
        $recipientId = (int) ($other['id'] ?? 0);
        $matchRole = ($recipientId > 0) ? $this->matchRepo->getMatchRole($currentUserId, $recipientId) : null;
        $hasMessages = $this->chatRepo->hasMessages($conversationId);
        $suggestedChats = (!$hasMessages && $matchRole !== null)
            ? SuggestedChatService::getSuggestionsForRole($matchRole)
            : null;

        return [
            'has_messages' => $hasMessages,
            'match_role' => $matchRole,
            'suggested_chats' => $suggestedChats,
        ];
    }
}
