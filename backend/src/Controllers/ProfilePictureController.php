<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\AuthThrottleRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\ProfilePictureStorageService;
use BloodMatch\Utils\Response;

final class ProfilePictureController
{
    private const ENDPOINT = 'profile.picture';

    public function upload(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.upload');

        $throttleKey = 'mutation:profile_picture:' . (int) $actor['id'];
        if (!(new AuthThrottleRepository())->hitAndCheckRateLimit($throttleKey, 10, 5, AuthService::nowUtc())) {
            Response::error('Too many profile picture uploads. Please wait a few minutes before trying again.', 429);
            return;
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('No file was uploaded.', 400);
            return;
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the maximum allowed upload size.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please retry.',
                default => 'Upload failed.',
            };
            Response::error($message, (int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE ? 413 : 400);
            return;
        }

        if (($file['size'] ?? 0) > ProfilePictureStorageService::maxBytes()) {
            Response::error('File exceeds the maximum size of 5 MB.', 413);
            return;
        }

        try {
            $stored = ProfilePictureStorageService::store((string) $file['tmp_name']);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        $users = new UserRepository();
        $previous = isset($actor['profile_picture']) && is_string($actor['profile_picture'])
            ? $actor['profile_picture']
            : null;

        $users->setProfilePicture((int) $actor['id'], (string) $stored['stored_name']);

        if ($previous !== null && $previous !== '') {
            ProfilePictureStorageService::delete($previous);
        }

        AuditLogger::log((int) $actor['id'], 'profile.picture_updated', 'user', (string) $actor['id'], [
            'mime_type' => $stored['mime_type'],
        ]);

        $fresh = $users->findById((int) $actor['id']);
        Response::success([
            'user' => (new AuthService())->publicUser($fresh),
        ], 200);
    }

    public function show(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.file');

        $storedName = isset($actor['profile_picture']) && is_string($actor['profile_picture'])
            ? $actor['profile_picture']
            : null;
        if ($storedName === null || $storedName === '') {
            Response::error('Profile picture not found.', 404);
            return;
        }

        try {
            $path = ProfilePictureStorageService::pathFor($storedName);
        } catch (\RuntimeException) {
            Response::error('Profile picture reference is invalid.', 404);
            return;
        }

        if (!is_file($path)) {
            Response::error('Profile picture file missing.', 404);
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || !in_array($mime, ProfilePictureStorageService::allowedMimeTypes(), true)) {
            Response::error('Profile picture file missing.', 404);
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="profile-picture"');
        header_remove('Cache-Control');
        readfile($path);
        exit;
    }
}
