<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\MatchDecisionService;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;
use RuntimeException;

final class MatchesController
{
    private function fail(RuntimeException $e): void
    {
        $code = $e->getCode();
        Response::error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
    }

    public function respond(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.respond');
        $matchId = (int) $params['matchId'];
        $consent = AuthService::isPrivacyAcknowledged(Request::json()['donor_share_consent'] ?? null);

        try {
            $result = (new MatchDecisionService())->respondByMatch($matchId, (int) $actor['id'], $consent);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success([
            'message' => 'Response recorded.',
            'status' => $result['status'],
            'match_id' => $result['match_id'],
        ]);
    }

    public function accept(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.accept');
        $matchId = (int) $params['matchId'];
        $consent = AuthService::isPrivacyAcknowledged(Request::json()['requester_share_consent'] ?? null);

        try {
            $result = (new MatchDecisionService())->accept($matchId, (int) $actor['id'], $consent);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success([
            'message' => 'Donor accepted.',
            'status' => $result['status'],
            'match_id' => $result['match_id'],
        ]);
    }

    public function unaccept(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.unaccept');
        $matchId = (int) $params['matchId'];

        try {
            $result = (new MatchDecisionService())->unaccept($matchId, (int) $actor['id']);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success([
            'message' => 'Acceptance withdrawn. The donor response remains recorded.',
            'status' => $result['status'],
            'match_id' => $result['match_id'],
        ]);
    }

    public function withdraw(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.withdraw');
        $matchId = (int) $params['matchId'];

        try {
            $result = (new MatchDecisionService())->withdraw($matchId, (int) $actor['id']);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success([
            'message' => 'Response withdrawn.',
            'status' => $result['status'],
            'match_id' => $result['match_id'],
        ]);
    }

    public function consent(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.consent');
        $matchId = (int) $params['matchId'];
        $body = Request::json();
        if (!array_key_exists('share', $body)) {
            Response::error('The share field is required.', 400, [
                'share' => ['Specify whether to share your email address.'],
            ]);
            return;
        }
        $share = AuthService::isPrivacyAcknowledged($body['share']);

        try {
            $result = (new MatchDecisionService())->setConsent($matchId, (int) $actor['id'], $share);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success([
            'message' => $share ? 'Email sharing enabled.' : 'Email sharing revoked.',
            'match_id' => $result['match_id'],
            'side' => $result['side'],
            'share' => $result['share'],
        ]);
    }

    public function contact(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.contact');
        $matchId = (int) $params['matchId'];

        try {
            $result = (new MatchDecisionService())->contact($matchId, (int) $actor['id']);
        } catch (RuntimeException $e) {
            $this->fail($e);
            return;
        }

        Response::success(['contact' => $result]);
    }
}
