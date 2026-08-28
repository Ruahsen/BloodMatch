<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use BloodMatch\Middleware\AuthMiddleware;

final class AuthBridge
{
    public static function assertChapter(array $actor, int $targetChapterId, string $endpoint): void
    {
        AuthMiddleware::requireChapterScope($actor, $targetChapterId, $endpoint);
    }
}
