<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\MatchRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Utils\Response;

final class MatchesController
{
    public function respond(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser('matches.respond');
        $matchId = (int) $params['matchId'];

        $repo = new MatchRepository();
        $match = $repo->findByIdDetailed($matchId);

        if ($match === null) {
            Response::error('Match not found.', 404);
            return;
        }

        if ((int) $match['donor_id'] !== (int) $actor['id']) {
            AuditLogger::log((int) $actor['id'], 'authz.denied', null, null, [
                'endpoint' => 'matches.respond',
                'reason' => 'not_match_owner',
            ]);
            Response::error('Forbidden.', 403);
            return;
        }

        if ((string) $match['request_status'] !== 'OPEN') {
            Response::error('This request is no longer active.', 409);
            return;
        }

        $status = (string) $match['status'];
        if ($status === 'RESPONDED') {
            Response::success(['message' => 'Already responded.', 'status' => 'RESPONDED']);
            return;
        }
        if (!in_array($status, ['POTENTIAL', 'NOTIFIED'], true)) {
            Response::error("Cannot respond to a match in state {$status}.", 409);
            return;
        }

        $repo->setStatus($matchId, 'RESPONDED');
        AuditLogger::log((int) $actor['id'], 'match.responded', 'blood_request', (string) $match['request_id'], [
            'match_id' => $matchId,
        ]);

        Response::success(['message' => 'Response recorded.', 'status' => 'RESPONDED']);
    }
}
