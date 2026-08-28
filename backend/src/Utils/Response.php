<?php

declare(strict_types=1);

namespace BloodMatch\Utils;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function success(mixed $data = null, int $status = 200): void
    {
        self::json(['success' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, array $details = []): void
    {
        $error = ['message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::json(['success' => false, 'error' => $error], $status);
    }
}
