<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Config\Database;
use BloodMatch\Utils\Response;

final class ChaptersController
{
    public function index(): void
    {
        $stmt = Database::pdo()->query(
            'SELECT id, code, name, municipality FROM chapters ORDER BY name ASC'
        );
        Response::success(['chapters' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }
}
