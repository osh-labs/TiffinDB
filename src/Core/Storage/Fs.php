<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * File-system helpers: the only place in the core, besides SQLite, that
 * touches the file system (M1 spec section 4.3). Directories are made with
 * mode 0750, files with 0640 (ADR 0011 decision 1). Calls that can warn run
 * under ErrorTrap, never with `@`.
 */
final class Fs
{
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;

    private function __construct()
    {
    }

    /**
     * Makes one directory (not recursive) with mode 0750. An existing
     * directory is left as it is. Throws \RuntimeException on failure or
     * when the path exists as something other than a directory.
     */
    public static function makeDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Cannot make a directory: the path exists and is not a directory.');
        }
        $made = ErrorTrap::run(static fn (): bool => mkdir($path, self::DIR_MODE, false), 'Cannot make a directory.');
        if (!$made) {
            throw new \RuntimeException('Cannot make a directory.');
        }
        $changed = ErrorTrap::run(static fn (): bool => chmod($path, self::DIR_MODE), 'Cannot set the directory mode.');
        if (!$changed) {
            throw new \RuntimeException('Cannot set the directory mode.');
        }
    }

    /**
     * Creates a new file (fails when anything exists at the path), writes
     * $contents, closes it, and sets mode 0640. A partly written file is
     * removed again.
     */
    public static function writeNew(string $path, string $contents): void
    {
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'x'), 'Cannot create the file.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot create the file.');
        }
        try {
            $written = ErrorTrap::run(static fn (): int|false => fwrite($handle, $contents), 'Cannot write the file.');
            if ($written !== strlen($contents)) {
                throw new \RuntimeException('Cannot write the file.');
            }
            $closed = ErrorTrap::run(static fn (): bool => fclose($handle), 'Cannot close the file.');
            if (!$closed) {
                throw new \RuntimeException('Cannot close the file.');
            }
            $changed = ErrorTrap::run(static fn (): bool => chmod($path, self::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            self::remove($path);

            throw $error;
        }
    }

    /** Deletes a file (or a symbolic link); no error when nothing is there. */
    public static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $removed = ErrorTrap::run(static fn (): bool => unlink($path), 'Cannot remove the file.');
        if (!$removed) {
            throw new \RuntimeException('Cannot remove the file.');
        }
    }

    public static function isWritableDir(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    /** The real path of $path, or of its closest existing ancestor; null when none can be resolved. */
    public static function nearestExisting(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $current = $path;
        while (true) {
            if (file_exists($current)) {
                $real = realpath($current);

                return $real === false ? null : $real;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * Whether $path is $root or below it, after resolving both with
     * nearestExisting() (so symbolic links and `..` are followed).
     */
    public static function isInside(string $path, string $root): bool
    {
        $resolvedPath = self::nearestExisting($path);
        $resolvedRoot = self::nearestExisting($root);
        if ($resolvedPath === null || $resolvedRoot === null) {
            return false;
        }
        if ($resolvedPath === $resolvedRoot) {
            return true;
        }

        return str_starts_with($resolvedPath, rtrim($resolvedRoot, '/') . '/');
    }
}
