<?php

declare(strict_types=1);

namespace BloodMatch\Controllers;

use BloodMatch\Http\Session;
use BloodMatch\Middleware\AuthMiddleware;
use BloodMatch\Repositories\DocumentRepository;
use BloodMatch\Repositories\UserRepository;
use BloodMatch\Services\AuditLogger;
use BloodMatch\Services\AuthService;
use BloodMatch\Services\DocumentStorageService;
use BloodMatch\Utils\Response;

final class DocumentController
{
    private const ENDPOINT = 'profile.documents';

    private const DOC_TYPES = ['national_id', 'donor_card', 'parental_consent'];

    public function upload(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.upload');

        $throttleKey = 'mutation:doc_upload:' . (int) $actor['id'];
        if (!(new \BloodMatch\Repositories\AuthThrottleRepository())->hitAndCheckRateLimit($throttleKey, 10, 5, AuthService::nowUtc())) {
            Response::error('Too many document uploads. Please wait a few minutes before trying again.', 429);
            return;
        }

        $docType = $_POST['doc_type'] ?? null;
        if (!is_string($docType) || !in_array($docType, self::DOC_TYPES, true)) {
            Response::error('Invalid document type.', 400, [
                'doc_type' => ['Accepted types: national_id, donor_card, parental_consent.'],
            ]);
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

        if (($file['size'] ?? 0) > DocumentStorageService::maxBytes()) {
            Response::error('File exceeds the maximum size of 5 MB.', 413);
            return;
        }

        try {
            $stored = DocumentStorageService::store((string) $file['tmp_name']);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
            return;
        }

        $docId = (new DocumentRepository())->create(
            (int) $actor['id'],
            $docType,
            $stored,
            AuthService::nowUtc()
        );

        AuditLogger::log((int) $actor['id'], 'document.uploaded', 'member_document', (string) $docId, [
            'doc_type' => $docType,
            'mime_type' => $stored['mime_type'],
        ]);

        Response::success([
            'document' => [
                'id' => $docId,
                'doc_type' => $docType,
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
            ],
        ], 201);
    }

    public function listOwn(): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.list');
        $docs = (new DocumentRepository())->listByUser((int) $actor['id']);
        Response::success(['documents' => array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'doc_type' => (string) $d['doc_type'],
            'mime_type' => (string) $d['mime_type'],
            'size_bytes' => (int) $d['size_bytes'],
            'uploaded_at' => (string) $d['uploaded_at'],
        ], $docs)]);
    }

    public function fileOwn(array $params): void
    {
        $actor = AuthMiddleware::requireActiveUser(self::ENDPOINT . '.file');
        $this->streamForOwner((new DocumentRepository())->findById((int) $params['id']), (int) $actor['id'], $actor);
    }

    public function fileOfficer(array $params): void
    {
        $actor = AuthMiddleware::requireRoles(['officer', 'admin'], 'officer.documents.file');

        $doc = (new DocumentRepository())->findById((int) $params['documentId']);
        if ($doc === null) {
            Response::error('Document not found.', 404);
            return;
        }

        $owner = (new UserRepository())->findById((int) $doc['user_id']);
        if ($owner === null || (string) $owner['role'] !== 'member') {
            AuditLogger::log((int) $actor['id'], 'authz.denied', 'member_document', (string) $doc['id'], [
                'endpoint' => 'officer.documents.file',
                'reason' => 'owner_not_member',
            ]);
            Response::error('Forbidden.', 403);
            return;
        }

        AuthMiddleware::requireChapterScope($actor, (int) $owner['chapter_id'], 'officer.documents.file');

        AuditLogger::log((int) $actor['id'], 'document.accessed', 'member_document', (string) $doc['id'], [
            'via' => 'officer_review',
            'owner_user_id' => (int) $owner['id'],
        ]);

        $this->stream($doc);
    }

    private function streamForOwner(?array $doc, int $ownerId, array $actor): void
    {
        if ($doc === null || (int) $doc['user_id'] !== $ownerId) {
            Response::error('Document not found.', 404);
            return;
        }

        AuditLogger::log($ownerId, 'document.accessed', 'member_document', (string) $doc['id'], ['via' => 'owner']);

        $this->stream($doc);
    }

    private function stream(array $doc): void
    {
        try {
            $path = DocumentStorageService::pathFor((string) $doc['stored_name']);
        } catch (\RuntimeException) {
            Response::error('Document reference is invalid.', 404);
            return;
        }

        if (!is_file($path)) {
            Response::error('Document file missing.', 404);
            return;
        }

        header('Content-Type: ' . (string) $doc['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="document-' . (int) $doc['id'] . '.' . (string) $doc['original_ext'] . '"');
        header_remove('Cache-Control');
        readfile($path);
        exit;
    }
}
