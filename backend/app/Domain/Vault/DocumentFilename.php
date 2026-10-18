<?php

namespace App\Domain\Vault;

/**
 * Filename concerns for delivery — port of pre-laravel-final-state
 * `app/api/routes.py::_safe_filename` and its extension→media-type map.
 */
final class DocumentFilename
{
    /** @var array<string, string> */
    private const array MIME_TYPES = [
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'txt' => 'text/plain',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * A download-safe name: no directory components, no quote/CR/LF characters.
     */
    public static function safe(string $filename): string
    {
        $basename = substr($filename, (int) strrpos('/'.$filename, '/'));
        $basename = str_replace(['\\', '"', "\r", "\n"], '', $basename);
        $basename = trim($basename);

        return $basename === '' ? 'patent' : $basename;
    }

    public static function mimeType(string $filename): string
    {
        return self::MIME_TYPES[DocumentRules::extensionOf($filename)] ?? 'application/octet-stream';
    }
}
