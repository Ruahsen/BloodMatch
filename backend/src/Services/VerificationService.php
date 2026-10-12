<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Repositories\VerificationRepository;
use RuntimeException;

final class VerificationService
{
    public function decide(
        array $actor,
        int $targetId,
        string $decision,
        ?string $reason,
        bool $acceptDonorCard
    ): array {
        $users = new UserRepository();
        $docs = new DocumentRepository();
        $ver = new VerificationRepository();
        $nowUtc = AuthService::nowUtc();

        $target = $users->findById($targetId);
        if ($target === null) {
            throw new RuntimeException('User not found.', 404);
        }

        if ((string) $actor['id'] === (string) $target['id']) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'user', (string) $targetId, [
                'endpoint' => 'officer.verifications.decide',
                'reason' => 'self_verification_forbidden',
            ]);
            throw new RuntimeException('You cannot verify your own account.', 403);
        }

        if ((string) $target['role'] !== 'member') {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'user', (string) $targetId, [
                'endpoint' => 'officer.verifications.decide',
                'reason' => 'privileged_target_forbidden',
                'target_role' => (string) $target['role'],
            ]);
            throw new RuntimeException('Only member accounts can go through verification.', 403);
        }

        if ((string) $actor['role'] === 'officer') {
            AuthBridge::assertChapter($actor, (int) $target['chapter_id'], 'officer.verifications.decide');
        }

        $currentVs = (string) $target['verification_status'];

        if ($decision === 'verified' || $decision === 'rejected') {
            if ($currentVs !== 'pending') {
                throw new RuntimeException("Only pending members can receive a decision (current: {$currentVs}).", 409);
            }
            if ($decision === 'rejected' && ($reason === null || trim($reason) === '')) {
                throw new RuntimeException('A rejection reason is required so the member knows what to correct.', 400);
            }
            if ($decision === 'verified' && !$docs->hasType($targetId, 'national_id')) {
                throw new RuntimeException('A National ID is required before this account can be approved.', 409);
            }
            $ver->setVerificationStatus($targetId, $decision);
            $ver->addDecision($targetId, (int) $actor['id'], $decision, $reason, $nowUtc);
            $auditId = AuditLogger::log(
                (int) $actor['id'],
                $decision === 'verified' ? 'verification.approved' : 'verification.rejected',
                'user',
                (string) $targetId,
                ['reason' => $reason]
            );

            \BloodMatch\Services\NotificationService::notify(
                $targetId,
                'verification.decision',
                $decision === 'verified' ? 'Your membership has been verified' : 'Account verification rejected',
                $decision === 'verified'
                    ? 'An officer verified your membership. You can now participate as a donor.'
                    : 'Your National ID could not be approved: ' . trim((string) $reason)
                        . '. Upload a clearer or valid National ID to request another review.',
                [
                    'related_type' => 'verification',
                    'related_id' => $targetId,
                    'dedup_key' => \BloodMatch\Services\NotificationService::dedupVerification($targetId, $decision, $auditId),
                    'email' => \BloodMatch\Services\NotificationService::EMAIL_NORMAL,
                ]
            );

            $provenanceChanged = false;
            if ($decision === 'verified' && $acceptDonorCard && $docs->hasType($targetId, 'donor_card')) {
                if ((string) $target['blood_type'] !== '') {
                    $users->setBloodProvenance($targetId, 'donor_card', true);
                    $provenanceChanged = true;
                    AuditLogger::log((int) $actor['id'], 'verification.provenance_accepted', 'user', (string) $targetId, [
                        'source' => 'donor_card',
                        'note' => 'administrative provenance; not medical confirmation',
                    ]);
                }
            }

            if ($decision === 'verified') {
                if (empty($target['email_verified_at'])) {
                    $users->markEmailVerified($targetId, $nowUtc);
                }
                (new MatchService())->refreshMatchesForDonor(
                    $targetId,
                    'donor_verified',
                    (int) $actor['id']
                );
            }

            return [
                'decision' => $decision,
                'verification_status' => $decision,
                'blood_type_provenance_updated' => $provenanceChanged,
                'notice' => CapabilityMatrix::BLOOD_TYPE_NOTICE_ADMIN_VERIFIED,
            ];
        }

        throw new RuntimeException('Invalid decision value.', 400);
    }

    public function resubmit(array $actor): array
    {
        $docs = new DocumentRepository();
        $ver = new VerificationRepository();
        $nowUtc = AuthService::nowUtc();

        $userId = (int) $actor['id'];
        if ((string) $actor['role'] !== 'member') {
            throw new RuntimeException('Only members resubmit for verification.', 403);
        }
        if ((string) $actor['verification_status'] !== 'rejected') {
            throw new RuntimeException('Resubmission is only available after rejection.', 409);
        }
        if (!$docs->hasType($userId, 'national_id')) {
            throw new RuntimeException('Upload at least one national ID document before resubmitting.', 400);
        }

        $ver->setVerificationStatus($userId, 'pending');
        $ver->addDecision($userId, $userId, 'resubmitted', null, $nowUtc);
        $auditId = AuditLogger::log($userId, 'verification.resubmitted', 'user', (string) $userId);

        NotificationService::notify(
            $userId,
            'verification.resubmitted',
            'Verification is being processed',
            'We received your new National ID. Your account is being reviewed. Please wait—you will be notified when a decision is made.',
            [
                'related_type' => 'verification',
                'related_id' => $userId,
                'dedup_key' => "verification:{$userId}:resubmitted:{$auditId}",
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );

        return [
            'verification_status' => 'pending',
            'message' => 'Your new National ID is being reviewed. You will be notified when a decision is made.',
        ];
    }
}
