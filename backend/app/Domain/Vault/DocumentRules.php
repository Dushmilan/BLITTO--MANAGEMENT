<?php

namespace App\Domain\Vault;

/**
 * Upload rules — port of pre-laravel-final-state
 * `app/api/routes.py::_validate_extension` plus its 10 MiB cap.
 */
final class DocumentRules
{
    /** @var list<string> */
    public const array ALLOWED_EXTENSIONS = ['csv', 'doc', 'docx', 'jpeg', 'jpg', 'pdf', 'png', 'txt', 'xls', 'xlsx'];

    /** The `documents.filename` column width. */
    public const int MAX_FILENAME_LENGTH = 255;

    public const int MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Lowercased extension after the last dot; '' when there is none.
     */
    public static function extensionOf(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return mb_strtolower($extension);
    }

    public static function isAllowed(string $filename): bool
    {
        return in_array(self::extensionOf($filename), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Human-readable allow-list for error messages, e.g. ".csv, .doc, ...".
     */
    public static function acceptedList(): string
    {
        $extensions = array_map(static fn (string $extension): string => ".{$extension}", self::ALLOWED_EXTENSIONS);

        return implode(', ', $extensions);
    }
}
