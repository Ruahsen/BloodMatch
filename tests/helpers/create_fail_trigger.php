<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;

Env::load(BASE_PATH . '/.env');
$pdo = Database::pdo();

$pdo->exec('DROP TRIGGER IF EXISTS trg_force_fail');
$pdo->exec(
    "CREATE TRIGGER trg_force_fail BEFORE UPDATE ON donation_reports
     FOR EACH ROW
     BEGIN
         IF NEW.report_note LIKE '%FORCE_FAIL%' THEN
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced test failure';
         END IF;
     END"
);

echo "trigger ready" . PHP_EOL;
