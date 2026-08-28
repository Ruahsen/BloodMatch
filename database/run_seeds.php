<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;

Env::load(BASE_PATH . '/.env');

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, '[seeds] cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$seedsDir = __DIR__ . '/seeds';
$files = glob($seedsDir . '/*.sql') ?: [];
sort($files);
$ran = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        continue;
    }

    $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
    try {
        foreach ($statements as $statement) {
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
        echo "[seeds] applied {$name}" . PHP_EOL;
        $ran++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[seeds] FAILED {$name}: " . $e->getMessage() . ' (seed files must be idempotent)' . PHP_EOL);
        exit(1);
    }
}

echo "[seeds] done ({$ran} executed)" . PHP_EOL;
