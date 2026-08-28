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
    fwrite(STDERR, '[migrations] cannot connect: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);
$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$ran = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        continue;
    }

    echo "[migrations] applying {$name}" . PHP_EOL;

    $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
    try {
        foreach ($statements as $statement) {
            $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        echo "[migrations] applied {$name}" . PHP_EOL;
        $ran++;
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "[migrations] FAILED {$name}: " . $e->getMessage() . PHP_EOL .
            '[migrations] note: MySQL DDL auto-commits; a failed migration may leave partial objects. ' .
            'Drop and recreate the database before retrying.' . PHP_EOL
        );
        exit(1);
    }
}

echo "[migrations] done ({$ran} applied)" . PHP_EOL;
