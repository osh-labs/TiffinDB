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
 * under ErrorTrap, never with `@`. No method accepts a path containing a
 * NUL byte.
 */
final class Fs
{
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;

    private function __construct()
    {
    }

    /**
     * Makes one directory (not recursive) with mode 0750. Throws
     * \RuntimeException on failure, and when anything already exists at the
     * path: a directory, a file, or a symbolic link (dangling or not).
     */
    public static function makeDir(string $path): void
    {
        self::refuseNul($path);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Cannot make a directory: the path already exists.');
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
     * Creates a new file, sets mode 0640, writes $contents, and closes it.
     * Fails when anything exists at the path, including a dangling symbolic
     * link. A partly written file is removed again.
     */
    public static function writeNew(string $path, string $contents): void
    {
        self::refuseNul($path);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Cannot create the file: the path already exists.');
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'x'), 'Cannot create the file.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot create the file.');
        }
        try {
            $changed = ErrorTrap::run(static fn (): bool => chmod($path, self::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
            $written = ErrorTrap::run(static fn (): int|false => fwrite($handle, $contents), 'Cannot write the file.');
            if ($written !== strlen($contents)) {
                throw new \RuntimeException('Cannot write the file.');
            }
            $closed = ErrorTrap::run(static fn (): bool => fclose($handle), 'Cannot close the file.');
            if (!$closed) {
                throw new \RuntimeException('Cannot close the file.');
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
        self::refuseNul($path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $removed = ErrorTrap::run(static fn (): bool => unlink($path), 'Cannot remove the file.');
        if (!$removed) {
            throw new \RuntimeException('Cannot remove the file.');
        }
    }

    /**
     * Removes an empty directory (P64). Throws \RuntimeException when the
     * path is missing, is a symbolic link (never followed, whatever it
     * points to), is not a directory, or is not empty. There is no
     * recursive removal: callers delete the files they know of first.
     */
    public static function removeDir(string $path): void
    {
        self::refuseNul($path);
        if (is_link($path)) {
            throw new \RuntimeException('Cannot remove the directory: the path is a symbolic link.');
        }
        if (!file_exists($path)) {
            throw new \RuntimeException('Cannot remove the directory: nothing is there.');
        }
        if (!is_dir($path)) {
            throw new \RuntimeException('Cannot remove the directory: the path is not a directory.');
        }
        $entries = ErrorTrap::run(static fn (): array|false => scandir($path), 'Cannot read the directory.');
        if ($entries === false) {
            throw new \RuntimeException('Cannot read the directory.');
        }
        if (array_diff($entries, ['.', '..']) !== []) {
            throw new \RuntimeException('Cannot remove the directory: it is not empty.');
        }
        $removed = ErrorTrap::run(static fn (): bool => rmdir($path), 'Cannot remove the directory.');
        if (!$removed) {
            throw new \RuntimeException('Cannot remove the directory.');
        }
    }

    public static function isWritableDir(string $path): bool
    {
        return !str_contains($path, "\0") && is_dir($path) && is_writable($path);
    }

    /**
     * The real path of $path, or of its closest existing ancestor.
     *
     * Null when the path is empty or holds a NUL byte, when nothing can be
     * resolved, or when the part beyond the closest existing ancestor holds
     * a `.` or `..` segment or a dangling symbolic link: that part cannot be
     * resolved without guessing, so it is refused instead of dropped (P62).
     */
    public static function nearestExisting(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $current = $path;
        while (!file_exists($current)) {
            if (is_link($current)) {
                return null;
            }
            $name = basename($current);
            if ($name === '.' || $name === '..') {
                return null;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
        $real = realpath($current);

        return $real === false ? null : $real;
    }

    /**
     * Whether $path is $root or below it, after resolving both with
     * nearestExisting() (so symbolic links and `..` in existing parts are
     * followed). False when either cannot be resolved, including any NUL
     * byte or a `.`/`..` segment in a part that does not exist yet.
     */
    public static function isInside(string $path, string $root): bool
    {
        if (str_contains($path, "\0") || str_contains($root, "\0")) {
            return false;
        }
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

    private static function refuseNul(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('A path must not be empty or contain a NUL byte.');
        }
    }
}
