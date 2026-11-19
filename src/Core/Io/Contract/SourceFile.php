<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * The uploaded file as a plugin sees it (M3 spec section 3.1, contract API
 * 1). The path is application-named inside the job folder; the plugin
 * reads it and must not write it. Temporary files go only to $tempFolder.
 */
final class SourceFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly int $size,
        public readonly string $tempFolder,
    ) {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || $tempFolder === '' || $tempFolder[0] !== '/' || str_contains($tempFolder, "\0")) {
            throw new \InvalidArgumentException('Source file paths are absolute.');
        }
        if (preg_match('/^[a-z0-9]{0,10}\z/', $extension) !== 1) {
            throw new \InvalidArgumentException('The extension is lower-case letters and digits.');
        }
        if ($size < 0) {
            throw new \InvalidArgumentException('The size is 0 or more.');
        }
    }
}
