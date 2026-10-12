<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\ChatRepository;
use BloodMatch\Repositories\MatchRepository;

final class ProfileViewPolicy
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

    public function relation(array $viewer, array $target): ?string
    {
        $viewerId = (int) ($viewer['id'] ?? 0);
        $targetId = (int) ($target['id'] ?? 0);

        if ($viewerId <= 0 || $targetId <= 0) {
            return null;
        }

        if ($viewerId === $targetId) {
            return 'self';
        }

        if (($target['account_status'] ?? '') !== 'active') {
            return null;
        }

        $targetRole = (string) ($target['role'] ?? '');
        if (!in_array($targetRole, ['member', 'officer', 'admin'], true)) {
            return null;
        }

        $viewerRole = (string) ($viewer['role'] ?? '');

        if ($viewerRole === 'admin') {
            return 'admin';
        }

        // Live chat participants may view each other's limited public profile & avatar
        if ($this->chatRepo->findDirectConversationId($viewerId, $targetId) !== null) {
            return 'chat_participant';
        }

        // Active members may view the limited public account details of any
        // other active member. Sensitive fields are excluded by
        // MemberProfileController's response model.
        if ($viewerRole === 'member' && $targetRole === 'member') {
            return 'member';
        }

        if ($viewerRole === 'officer'
            && !empty($viewer['chapter_id'])
            && !empty($target['chapter_id'])
            && (int) $viewer['chapter_id'] === (int) $target['chapter_id']) {
            return 'chapter_officer';
        }

        return $this->matchRepo->profileViewRelation($viewerId, $targetId);
    }
}
