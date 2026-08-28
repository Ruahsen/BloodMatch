<?php

declare(strict_types=1);

namespace BloodMatch\Controllers\Officer;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Utils\Request;
use BloodMatch\Utils\Response;

final class ChapterUsersController
{
    private const ENDPOINT = 'officer.users';

    public function index(): void
    {
        $actor = AuthMiddleware::requireRoles(['officer'], self::ENDPOINT . '.list');

        $ownChapter = $actor['chapter_id'] === null ? null : (int) $actor['chapter_id'];
        if ($ownChapter === null) {
            AuthMiddleware::requireChapterScope($actor, -1, self::ENDPOINT . '.list');
            return;
        }

        $requested = isset($_GET['chapter_id']) && is_string($_GET['chapter_id']) ? trim($_GET['chapter_id']) : null;
        if ($requested !== null && $requested !== '' && preg_match('/^\d+$/', $requested) && (int) $requested !== $ownChapter) {
            AuthMiddleware::requireChapterScope($actor, (int) $requested, self::ENDPOINT . '.list');
            return;
        }

        Response::success([
            'chapter_id' => $ownChapter,
            'users' => (new UserRepository())->listByChapter($ownChapter),
        ]);
    }
}
