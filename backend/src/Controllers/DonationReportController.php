<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\DonationReportRepository;
use BloodMatch\Services\AuthBridge;
use BloodMatch\Services\DonationService;
use BloodMatch\Services\Exceptions\ValidationException;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use RuntimeException;

final class DonationReportController
{
    public function submit(): void
    {
        $actor = AuthMiddleware::requireActiveUser('donation_reports.submit');

        $body = Request::json();
        $matchId = Request::int('match_id', $body);
        $note = Request::str('note', $body);

        if ($matchId === null) {
            Response::error('match_id is required.', 400);
            return;
        }
        if ($note !== null && mb_strlen($note) > 500) {
            Response::error('Note must be at most 500 characters.', 400, [
                'note' => ['Note must be at most 500 characters.'],
            ]);
            return;
        }

        try {
            $result = (new \BloodMatch\Services\DonationService())->submit((int) $actor['id'], $matchId, $note);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
            return;
        }

        Response::success(['report' => $result], 201);
    }

    public function myReports(): void
    {
        $actor = AuthMiddleware::requireActiveUser('donation_reports.mine');
        $rows = (new DonationReportRepository())->listByDonor((int) $actor['id']);

        Response::success(['reports' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'match_id' => (int) $r['match_id'],
            'status' => (string) $r['status'],
            'note' => $r['report_note'],
            'rejection_reason' => $r['rejection_reason'],
            'reported_at' => (string) $r['reported_at'],
            'confirmed_at' => $r['confirmed_at'],
            'required_blood_type' => (string) $r['required_blood_type'],
            'facility_name' => (string) $r['facility_name'],
        ], $rows)]);
    }

    public function officerQueue(): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], 'officer.reports.queue');

        $chapterId = (string) $actor['role'] === 'admin'
            ? null
            : ($actor['chapter_id'] !== null ? (int) $actor['chapter_id'] : -1);

        if ($chapterId === -1) {
            AuthBridge::assertChapter($actor, -1, 'officer.reports.queue');
            return;
        }

        $sort = Request::str('sort') ?? 'oldest';
        $rows = (new DonationReportRepository())->listPendingByChapter($chapterId, $sort);
        Response::success(['sort' => $sort, 'pending_reports' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'match_id' => (int) $r['match_id'],
            'donor_name' => (string) $r['donor_name'],
            'required_blood_type' => (string) $r['required_blood_type'],
            'facility_name' => (string) $r['facility_name'],
            'report_note' => $r['report_note'],
            'reported_at' => (string) $r['reported_at'],
            'request_status' => (string) $r['request_status'],
            'match_status' => (string) $r['match_status'],
            'can_reject' => (int) $r['donor_id'] !== (int) $actor['id'],
            'can_confirm' => (int) $r['donor_id'] !== (int) $actor['id']
                && (string) $r['request_status'] === 'OPEN' && (string) $r['match_status'] === 'RESPONDED'
                && !\BloodMatch\Services\RequestService::hasDeadlinePassed($r),
        ], $rows)]);
    }

    public function confirm(array $params): void
    {
        $this->decide($params, true);
    }

    public function reject(array $params): void
    {
        $this->decide($params, false);
    }

    private function decide(array $params, bool $confirm): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], 'officer.reports.decide');

        try {
            $result = (new \BloodMatch\Services\DonationService())->decide(
                $actor,
                (int) $params['id'],
                $confirm,
                Request::json()['rejection_reason'] ?? null
            );
        } catch (ValidationException $e) {
            Response::error($e->getMessage(), 400, $e->errors());
            return;
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            Response::error($e->getMessage(), $code >= 400 && $code <= 599 ? $code : 500);
            return;
        }

        Response::success(['result' => $result]);
    }
}
