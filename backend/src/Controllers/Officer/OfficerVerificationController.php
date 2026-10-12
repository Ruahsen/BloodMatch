<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Officer;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Repositories\VerificationRepository;
use BloodMatch\Services\AgeEligibilityService;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\CapabilityMatrix;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Services\VerificationService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class OfficerVerificationController
{
    private const ENDPOINT = 'officer.verifications';

    public function adminQueue(): void
    {
        AuthMiddleware::requireRoles(['admin'], 'admin.verifications.queue');
        $this->queue();
    }

    public function adminDetail(array $params): void
    {
        AuthMiddleware::requireRoles(['admin'], 'admin.verifications.detail');
        $this->detail($params);
    }

    public function adminDecide(array $params): void
    {
        AuthMiddleware::requireRoles(['admin'], 'admin.verifications.decide');
        $this->decide($params);
    }

    public function queue(): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], self::ENDPOINT . '.queue');
        $isAdmin = (string) $actor['role'] === 'admin';
        $ownChapter = $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];
        if (!$isAdmin && $ownChapter === null) {
            AuthMiddleware::requireChapterScope($actor, -1, self::ENDPOINT . '.queue');
            return;
        }

        $page = max(1, Request::int('page') ?? 1);
        $pageSize = min(100, max(1, Request::int('page_size') ?? 25));
        $requestedChapter = Request::str('chapter_id');
        $sort = Request::str('sort') ?? 'oldest';
        $filters = [
            'chapter_id' => $isAdmin ? $requestedChapter : (string) $ownChapter,
            'q' => Request::str('q'),
            'sort' => $sort,
        ];
        $result = (new UserRepository())->pendingVerificationMembers($filters, $page, $pageSize);
        Response::success([
            'sort' => $sort,
            'chapter_id' => $isAdmin
                ? ($requestedChapter !== null && preg_match('/^\d+$/', $requestedChapter) ? (int) $requestedChapter : null)
                : $ownChapter,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $result['total'],
            'queue' => array_map(static fn (array $m): array => [
                'id' => (int) $m['id'],
                'full_name' => (string) $m['full_name'],
                'email' => (string) $m['email'],
                'chapter_id' => $m['chapter_id'] !== null ? (int) $m['chapter_id'] : null,
                'chapter_name' => $m['chapter_name'],
                'date_of_birth' => $m['date_of_birth'],
                'blood_type' => $m['blood_type'],
                'blood_type_verified' => (int) $m['blood_type_verified'] === 1,
                'created_at' => (string) $m['created_at'],
                'national_id_count' => (int) $m['national_id_count'],
                'latest_national_id_uploaded_at' => (string) $m['latest_national_id_uploaded_at'],
            ], $result['users']),
        ]);
    }

    public function detail(array $params): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], self::ENDPOINT . '.detail');
        $targetId = (int) $params['userId'];

        $repo = new UserRepository();
        $target = $repo->findById($targetId);
        $display = $repo->findProfileDisplayById($targetId);

        if ($target === null || (string) $target['role'] !== 'member') {
            Response::error('Verification target not found.', 404);
            return;
        }

        AuthMiddleware::requireChapterScope($actor, (int) $target['chapter_id'], self::ENDPOINT . '.detail');

        $docs = new DocumentRepository();
        $documents = $docs->listByUser($targetId);
        $hasConsent = false;
        foreach ($documents as $d) {
            if ((string) $d['doc_type'] === 'parental_consent') {
                $hasConsent = true;
                break;
            }
        }

        Response::success(['verification' => [
            'user' => [
                'id' => (int) $target['id'],
                'full_name' => (string) $target['full_name'],
                'email' => (string) $target['email'],
                'phone' => $target['phone'],
                'date_of_birth' => $target['date_of_birth'],
                'verification_status' => (string) $target['verification_status'],
                'chapter_id' => $target['chapter_id'] !== null ? (int) $target['chapter_id'] : null,
                'chapter_name' => $display['chapter_name'] ?? null,
                'blood_type' => $target['blood_type'],
                'blood_type_source' => $target['blood_type_source'],
                'blood_type_verified' => (int) $target['blood_type_verified'] === 1,
            ],
            'age_eligibility' => AgeEligibilityService::evaluate(
                $target['date_of_birth'] !== null ? (string) $target['date_of_birth'] : null,
                $hasConsent
            ),
            'documents' => array_map(static fn (array $d): array => [
                'id' => (int) $d['id'],
                'doc_type' => (string) $d['doc_type'],
                'mime_type' => (string) $d['mime_type'],
                'size_bytes' => (int) $d['size_bytes'],
                'uploaded_at' => (string) $d['uploaded_at'],
            ], $documents),
            'history' => (new VerificationRepository())->historyFor($targetId),
            'blood_type_notice' => CapabilityMatrix::BLOOD_TYPE_NOTICE_ADMIN_VERIFIED,
        ]]);
    }

    public function decide(array $params): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], self::ENDPOINT . '.decide');

        $body = Request::json();
        $decision = Request::str('decision', $body);
        $reason = Request::str('reason', $body);
        $acceptDonorCard = !empty($body['accept_donor_card']);

        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new ValidationException([
                'decision' => ['Decision must be "verified" or "rejected".'],
            ]);
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new ValidationException([
                'reason' => ['Reason must be at most 500 characters.'],
            ]);
        }

        try {
            $result = (new VerificationService())->decide(
                $actor,
                (int) $params['userId'],
                $decision,
                $reason,
                $acceptDonorCard
            );
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
            return;
        }

        $fresh = (new UserRepository())->findById((int) $params['userId']);
        Response::success([
            'result' => $result,
            'verification_status' => $fresh !== null ? (string) $fresh['verification_status'] : null,
        ]);
    }
}
