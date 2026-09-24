<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Config\Database;
use BloodMatch\Utils\Response;
use Throwable;

final class HealthController
{
    public function check(): void
    {
        $summary = Database::configSummary();

        try {
            Database::pdo()->query('SELECT 1');
            $db = ['connected' => true, 'port' => $summary['port']];
        } catch (Throwable) {
            $db = ['connected' => false, 'port' => $summary['port']];
        }

        Response::success([
            'status' => 'ok',
            'time' => gmdate('c'),
            'db' => $db,
        ]);
    }
}
