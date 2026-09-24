<?php

declare(strict_types=1);

namespace BloodMatch\Routing;

use BloodMatch\Utils\Response;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[$pattern][strtoupper($method)] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        foreach ($this->routes as $pattern => $handlers) {
            $params = $this->match($pattern, $path);
            if ($params === null) {
                continue;
            }
            if (!isset($handlers[$method])) {
                Response::error('Method not allowed.', 405);
                return;
            }
            $handlers[$method]($params);
            return;
        }

        Response::error('Not found.', 404);
    }

    private function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $m) === 1) {
                if ($pathParts[$i] === '') {
                    return null;
                }
                $params[$m[1]] = urldecode($pathParts[$i]);
                continue;
            }
            if ($part !== $pathParts[$i]) {
                return null;
            }
        }

        return $params;
    }
}
