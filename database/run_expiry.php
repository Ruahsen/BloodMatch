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
    NotificationService::notify(
        (int) $row['requester_id'],
        'request.expired',
        'Blood request expired',
        sprintf('Your blood request #%d passed its needed date and has expired.', (int) $row['id']),
        [
            'related_type' => 'blood_request',
            'related_id' => (int) $row['id'],
            'dedup_key' => 'request:' . ((int) $row['id']) . ':expired',
        ]
    );
}

if ($expiredCount > 0) {
    AuditLogger::log(null, 'request.expired_batch', 'blood_request', null, [
        'count' => $expiredCount,
        'cutoff_utc' => $nowUtc,
    ]);
}

echo "[expiry] expired {$expiredCount} request(s) as of {$nowUtc} UTC" . PHP_EOL;
