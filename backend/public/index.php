<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

require dirname(__DIR__) . '/src/autoload.php';

use BloodMatch\Config\AppConfig;
use BloodMatch\Config\Env;
use BloodMatch\Middleware\CorsMiddleware;
use BloodMatch\Middleware\CsrfMiddleware;
use BloodMatch\Middleware\SecurityHeaders;
use BloodMatch\Routing\Router;

Env::load(BASE_PATH . '/.env');

ini_set('display_errors', AppConfig::debug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', AppConfig::logPath());
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[uncaught] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $message = AppConfig::debug() ? $e->getMessage() : 'Internal server error.';
    echo json_encode(['success' => false, 'error' => ['message' => $message]]);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

SecurityHeaders::apply();
CorsMiddleware::handle();
CsrfMiddleware::handle();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($scriptDir !== '' && str_starts_with($uriPath, $scriptDir)) {
    $uriPath = substr($uriPath, strlen($scriptDir));
}
$path = '/' . ltrim($uriPath, '/');

$register = require dirname(__DIR__) . '/routes/api.php';
$router = new Router();
$register($router);
$router->dispatch($method, $path);
