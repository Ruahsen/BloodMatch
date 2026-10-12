<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\ChatRepository;
use BloodMatch\Repositories\MatchRepository;

final class ChatPolicy
{
    private ChatRepository $chatRepo;
    private MatchRepository $matchRepo;

    public function __construct(
        ?ChatRepository $chatRepo = null,
        ?MatchRepository $matchRepo = null
    ) {
        $this->chatRepo = $chatRepo ?? new ChatRepository();
        $this->matchRepo = $matchRepo ?? new MatchRepository();
    }

    public function canMessage(array $sender, array $recipient): bool
    {
        $senderId = (int) ($sender['id'] ?? 0);
        $recipientId = (int) ($recipient['id'] ?? 0);

        // Cannot message oneself
        if ($senderId <= 0 || $recipientId <= 0 || $senderId === $recipientId) {
            return false;
        }

        // Both accounts must be active
        if (($sender['account_status'] ?? '') !== 'active' || ($recipient['account_status'] ?? '') !== 'active') {
            return false;
        }

        $senderRole = (string) ($sender['role'] ?? '');
        $recipientRole = (string) ($recipient['role'] ?? '');

        if (!in_array($senderRole, ['member', 'officer', 'admin'], true)) {
            return false;
        }

        if (!in_array($recipientRole, ['member', 'officer', 'admin'], true)) {
            return false;
        }

        // Officers and admins can message any active user
        if (in_array($senderRole, ['officer', 'admin'], true)) {
            return true;
        }

        // Senders who are regular members:
        // 1. Messaging an officer or admin is always permitted
        if (in_array($recipientRole, ['officer', 'admin'], true)) {
            return true;
        }

        // 2. Verified members can message other regular members
        $senderVerified = ($sender['verification_status'] ?? '') === 'verified';
        if ($senderVerified) {
            return true;
        }

        // 3. Unverified members cannot initiate cold chats with other regular members
        // unless they share an active match or request relationship
        $hasActiveMatchOrRequest = $this->matchRepo->profileViewRelation($senderId, $recipientId) !== null;
        if ($hasActiveMatchOrRequest) {
            return true;
        }

        return false;
    }

    public function canAccessConversation(array $user, int $conversationId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || ($user['account_status'] ?? '') !== 'active') {
            return false;
        }

        return $this->chatRepo->isParticipant($conversationId, $userId);
    }
}
