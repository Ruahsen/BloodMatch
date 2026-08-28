<?php

declare(strict_types=1);

namespace BloodMatch\Services;

use RuntimeException;

final class DocumentStorageService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public static function baseDir(): string
    {
        return \BloodMatch\Config\AppConfig::basePath() . '/backend/storage/documents';
    }

    public static function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function store(string $tmpPath): array
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        $size = filesize($tmpPath);
        if ($size === false || $size === 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('File exceeds the maximum size of 5 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('File type is not allowed. Accepted: JPG, PNG, WEBP, PDF.');
        }

        $dir = self::baseDir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage directory unavailable.');
        }

        $storedName = bin2hex(random_bytes(32));
        $target = $dir . '/' . $storedName;

        if (!move_uploaded_file($tmpPath, $target) && !rename($tmpPath, $target)) {
            throw new RuntimeException('Could not persist uploaded file.');
        }
        chmod($target, 0640);

        return [
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'original_ext' => self::ALLOWED[$mime],
            'size_bytes' => $size,
        ];
    }

    public static function pathFor(string $storedName): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $storedName)) {
            throw new RuntimeException('Invalid document reference.');
        }
        return self::baseDir() . '/' . $storedName;
    }
}
