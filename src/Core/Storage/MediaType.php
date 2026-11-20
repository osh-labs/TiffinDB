<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * The type of an uploaded file, decided from its leading bytes only (ADR
 * 0018 decision 2, M4 spec section 3.1): never from the browser-supplied
 * type, the file name, or the fileinfo extension. Bytes are compared
 * exactly; nothing beyond the first 16 bytes is read.
 */
final class MediaType
{
    public const PNG = 'image/png';
    public const JPEG = 'image/jpeg';
    public const GIF = 'image/gif';
    public const WEBP = 'image/webp';
    public const PDF = 'application/pdf';
    public const OTHER = 'application/octet-stream';
    /** The four types ever shown in a page (ADR 0018 decision 1). */
    public const INLINE = [self::PNG, self::JPEG, self::GIF, self::WEBP];
    /** How many leading bytes ofFile() reads. */
    public const HEAD_BYTES = 16;

    private const SIGNATURES = [
        "\x89PNG\r\n\x1A\n" => self::PNG,
        "\xFF\xD8\xFF" => self::JPEG,
        'GIF87a' => self::GIF,
        'GIF89a' => self::GIF,
        '%PDF-' => self::PDF,
    ];

    private function __construct()
    {
    }

    /** The type of a file from its first 16 bytes (fewer when the file is shorter), per ADR 0018 decision 2. */
    public static function sniff(string $head): string
    {
        foreach (self::SIGNATURES as $signature => $type) {
            if (str_starts_with($head, $signature)) {
                return $type;
            }
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return self::WEBP;
        }

        return self::OTHER;
    }

    /**
     * The type of the regular file at $path: reads at most 16 bytes with
     * fopen/fread under ErrorTrap. \RuntimeException when the path is not
     * a regular file (a symbolic link included) or cannot be read.
     */
    public static function ofFile(string $path): string
    {
        if (!Fs::isFile($path)) {
            throw new \RuntimeException('Cannot read the file type: the path is not a regular file.');
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'rb'), 'Cannot open the file.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the file.');
        }
        try {
            $head = '';
            while (strlen($head) < self::HEAD_BYTES) {
                $chunk = ErrorTrap::run(static fn (): string|false => fread($handle, self::HEAD_BYTES - strlen($head)), 'Cannot read the file.');
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot read the file.');
                }
                if ($chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        return self::sniff($head);
    }

    /** True only for the four inline constants, compared exactly (case and parameters included). */
    public static function isInline(string $mediaType): bool
    {
        return in_array($mediaType, self::INLINE, true);
    }
}
