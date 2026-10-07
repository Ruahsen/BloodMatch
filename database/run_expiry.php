<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;
use BloodMatch\Repositories\BloodRequestRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\NotificationService;

Env::load(BASE_PATH . '/.env');

try {
    Database::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, '[expiry] cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$nowUtc = AuthService::nowUtc();
$expiredRows = (new BloodRequestRepository())->expireDueBatch($nowUtc);
$expiredCount = count($expiredRows);

foreach ($expiredRows as $row) {
    $requestId = (int) $row['id'];
    // Per-request atomic sweep: re-claim the stamped row under its lock so
    // a concurrent accept either commits first (and is snapshotted + closed
    // + notified below) or fails its own OPEN check — never silently
    // closed without notice, never left live on a terminal request.
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $locked = (new BloodRequestRepository())->findByIdForUpdate($requestId);
        if ($locked === null || (string) $locked['status'] !== 'EXPIRED' || (string) $locked['expired_at'] !== $nowUtc) {
            $pdo->rollBack();
            continue;
        }
        $acceptedDonors = (new \BloodMatch\Repositories\MatchRepository())->listAcceptedDonors($requestId);
        \BloodMatch\Repositories\MatchRepository::closeUnresolvedForRequest($requestId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '[expiry] sweep failed for request ' . $requestId . ': ' . $e->getMessage() . PHP_EOL);
        continue;
    }
    NotificationService::notify(
        (int) $row['requester_id'],
        'request.expired',
        'Blood request expired',
        sprintf('Your blood request #%d passed its needed date and has expired.', $requestId),
        [
            'related_type' => 'blood_request',
            'related_id' => $requestId,
            'dedup_key' => 'request:' . $requestId . ':expired',
            'email' => NotificationService::EMAIL_NORMAL,
        ]
    );
    foreach ($acceptedDonors as $accepted) {
        NotificationService::notify(
            (int) $accepted['donor_id'],
            'request.expired',
            'A blood request you were accepted for has expired',
            sprintf('Blood request #%d has expired. Contact details are no longer available.', $requestId),
            [
                'related_type' => 'blood_request',
                'related_id' => $requestId,
                'dedup_key' => 'request:' . $requestId . ':expired:donor:' . (int) $accepted['donor_id'],
                'email' => NotificationService::EMAIL_NORMAL,
            ]
        );
    }
}

if ($expiredCount > 0) {
    AuditLogger::log(null, 'request.expired_batch', 'blood_request', null, [
        'count' => $expiredCount,
        'cutoff_utc' => $nowUtc,
    ]);
}

echo "[expiry] expired {$expiredCount} request(s) as of {$nowUtc} UTC" . PHP_EOL;
