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
 * NUL byte. The listing and stat helpers (entries, exists, isFile, isDir,
 * size, sha256) never follow a symbolic link (T-CF-4). Recursive removal exists
 * only for the staging area (removeTree, P127).
 */
final class Fs
{
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;

    /** File-type bits of an lstat() mode (POSIX S_IFMT and its values). */
    private const TYPE_MASK = 0170000;
    private const TYPE_FILE = 0100000;
    private const TYPE_DIR = 0040000;
    private const TYPE_LINK = 0120000;

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

    /**
     * Removes a directory tree inside the staging area (M3 spec section 5.1,
     * P127; amends P64 for that folder only). $root must be an existing
     * directory whose path ends in `/staging`, and $path must be strictly
     * below it (its parent resolved with realpath; its own last segment not
     * `.` or `..`); otherwise \LogicException and nothing is removed. The
     * walk uses lstat and never follows a symbolic link: a link, wherever it
     * points and whatever its target is, is removed as a link. Files are
     * removed first, then directories bottom-up. No error when nothing is at
     * $path; \RuntimeException when an entry cannot be removed.
     */
    public static function removeTree(string $path, string $root): void
    {
        self::refuseNul($path);
        self::refuseNul($root);
        $trimmedRoot = rtrim($root, '/');
        if (!str_ends_with($trimmedRoot, '/staging')) {
            throw new \LogicException('Recursive removal is allowed only inside the staging area.');
        }
        $realRoot = realpath($trimmedRoot);
        if ($realRoot === false || !str_ends_with($realRoot, '/staging') || !self::isDir($realRoot)) {
            throw new \LogicException('The staging area is not a directory.');
        }
        $name = basename(rtrim($path, '/'));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \LogicException('The path to remove must name an entry.');
        }
        $realParent = realpath(dirname(rtrim($path, '/')));
        if ($realParent === false || ($realParent !== $realRoot && !str_starts_with($realParent, $realRoot . '/'))) {
            throw new \LogicException('The path to remove is not inside the staging area.');
        }
        self::removeEntry($realParent . '/' . $name);
    }

    /**
     * Moves a regular file or a directory to $to, which must not exist
     * (anything there, a dangling link included, is \RuntimeException).
     * `rename` first; when that fails (across devices) a file is copied to
     * a new file and the original removed (a directory is not copied). A
     * moved file gets mode 0640. $from must be a regular file or a
     * directory, never a symbolic link.
     */
    public static function moveIn(string $from, string $to): void
    {
        self::refuseNul($from);
        self::refuseNul($to);
        $isFile = self::isFile($from);
        if (!$isFile && !self::isDir($from)) {
            throw new \RuntimeException('Cannot move: the source is not a regular file or a directory.');
        }
        if (self::exists($to)) {
            throw new \RuntimeException('Cannot move: the target already exists.');
        }
        $renamed = ErrorTrap::run(static fn (): bool => rename($from, $to), 'Cannot rename.');
        if (!$renamed) {
            if (!$isFile) {
                throw new \RuntimeException('Cannot move the directory.');
            }
            self::copyNew($from, $to);
            self::remove($from);
        }
        if ($isFile) {
            $changed = ErrorTrap::run(static fn (): bool => chmod($to, self::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
        }
    }

    /**
     * Copies the regular file $from to a new file $to, which must not exist
     * (anything there, a dangling link included, is \RuntimeException), and
     * sets mode 0640. $from must be a regular file, never a symbolic link
     * (\RuntimeException otherwise). A partial copy is removed. Used to take
     * a working copy of a snapshot (M7 spec section 5.3 step 1).
     */
    public static function copyIn(string $from, string $to): void
    {
        self::refuseNul($from);
        self::refuseNul($to);
        if (!self::isFile($from)) {
            throw new \RuntimeException('Cannot copy: the source is not a regular file.');
        }
        if (self::exists($to)) {
            throw new \RuntimeException('Cannot copy: the target already exists.');
        }
        self::copyNew($from, $to);
        try {
            $changed = ErrorTrap::run(static fn (): bool => chmod($to, self::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
        } catch (\Throwable $error) {
            self::remove($to);

            throw $error;
        }
    }

    /**
     * Renames the regular file $from over the existing regular file $to in
     * the same folder (one `rename`, so $to is either the old file or the
     * new one; M7 spec section 5.3 step 6). Refused (\RuntimeException,
     * nothing changed) when either path is a symbolic link or anything but
     * a regular file, or the two are in different folders; \RuntimeException
     * when the rename fails, leaving $to as it was.
     */
    public static function replace(string $from, string $to): void
    {
        self::refuseNul($from);
        self::refuseNul($to);
        if (dirname($from) !== dirname($to) || basename($from) === basename($to)) {
            throw new \RuntimeException('Cannot replace: the two files must be different files in the same folder.');
        }
        if (!self::isFile($from) || !self::isFile($to)) {
            throw new \RuntimeException('Cannot replace: both paths must be regular files.');
        }
        $renamed = ErrorTrap::run(static fn (): bool => rename($from, $to), 'Cannot rename.');
        if (!$renamed) {
            throw new \RuntimeException('Cannot replace the file.');
        }
    }

    /**
     * The names in a directory, sorted by byte value, without `.` and `..`
     * (T-CF-4). Names of symbolic links inside are listed but not followed.
     * Throws \RuntimeException when nothing is at the path, when the path
     * is a symbolic link (never followed, whatever it points to), when it
     * is not a directory, or when it cannot be read.
     *
     * @return list<string>
     */
    public static function entries(string $path): array
    {
        self::refuseNul($path);
        $stat = self::lstat($path);
        if ($stat === null) {
            throw new \RuntimeException('Cannot list the directory: nothing is there.');
        }
        if (self::type($stat) === self::TYPE_LINK) {
            throw new \RuntimeException('Cannot list the directory: the path is a symbolic link.');
        }
        if (self::type($stat) !== self::TYPE_DIR) {
            throw new \RuntimeException('Cannot list the directory: the path is not a directory.');
        }
        $entries = ErrorTrap::run(static fn (): array|false => scandir($path, SCANDIR_SORT_NONE), 'Cannot read the directory.');
        if ($entries === false) {
            throw new \RuntimeException('Cannot read the directory.');
        }
        $names = array_values(array_diff($entries, ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }

    /** Whether anything is at the path, a dangling symbolic link included (never followed); false for a NUL byte. */
    public static function exists(string $path): bool
    {
        return self::lstatOrNull($path) !== null;
    }

    /** Whether a regular file is at the path; false for a symbolic link (never followed), a NUL byte, or nothing. */
    public static function isFile(string $path): bool
    {
        $stat = self::lstatOrNull($path);

        return $stat !== null && self::type($stat) === self::TYPE_FILE;
    }

    /** Whether a directory is at the path; false for a symbolic link (never followed), a NUL byte, or nothing. */
    public static function isDir(string $path): bool
    {
        $stat = self::lstatOrNull($path);

        return $stat !== null && self::type($stat) === self::TYPE_DIR;
    }

    /**
     * The size in bytes of the regular file at the path. Throws
     * \RuntimeException when nothing is there or the path is anything but a
     * regular file, a symbolic link included (never followed).
     */
    public static function size(string $path): int
    {
        self::refuseNul($path);
        $stat = self::lstat($path);
        if ($stat === null || self::type($stat) !== self::TYPE_FILE) {
            throw new \RuntimeException('Cannot read the size: the path is not a regular file.');
        }

        return (int) $stat['size'];
    }

    /**
     * The SHA-256 of the regular file at the path, as 64 lower-case hex
     * characters, read in a stream (hash_init and hash_update_stream), so
     * the file is never held in memory (M4 spec section 3.2). Throws
     * \RuntimeException when nothing is there, when the path is a symbolic
     * link (never followed) or anything but a regular file, when the file
     * opened is not the one checked (it was swapped between the check and
     * the open), or when it cannot be read to the end.
     */
    public static function sha256(string $path): string
    {
        self::refuseNul($path);
        $stat = self::lstat($path);
        if ($stat === null || self::type($stat) !== self::TYPE_FILE) {
            throw new \RuntimeException('Cannot hash the file: the path is not a regular file.');
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'rb'), 'Cannot open the file.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the file.');
        }
        try {
            $opened = ErrorTrap::run(static fn (): array|false => fstat($handle), 'Cannot read the file status.');
            if ($opened === false || self::type($opened) !== self::TYPE_FILE || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new \RuntimeException('Cannot hash the file: it changed while it was opened.');
            }
            $context = hash_init('sha256');
            $read = ErrorTrap::run(static fn (): int => hash_update_stream($context, $handle), 'Cannot read the file.');
            if ($read !== (int) $opened['size'] || !feof($handle)) {
                throw new \RuntimeException('Cannot hash the file: it was not read to the end.');
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * The free space in bytes of the file system holding the path
     * (disk_free_space under ErrorTrap), or null when it is unknown: the
     * call failed or is disabled, or the path is empty or holds a NUL byte.
     */
    public static function freeBytes(string $path): ?int
    {
        if ($path === '' || str_contains($path, "\0") || !function_exists('disk_free_space')) {
            return null;
        }
        try {
            $free = ErrorTrap::run(static fn (): float|false => disk_free_space($path), 'Cannot read the free space.');
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_float($free) || is_nan($free) || $free < 0) {
            return null;
        }

        return (int) min($free, (float) PHP_INT_MAX);
    }

    /**
     * The modification time (Unix seconds) of whatever is at the path, read
     * with lstat (a symbolic link's own time, never its target's). Throws
     * \RuntimeException when nothing is there.
     */
    public static function modifiedAt(string $path): int
    {
        self::refuseNul($path);
        $stat = self::lstat($path);
        if ($stat === null) {
            throw new \RuntimeException('Cannot read the time: nothing is there.');
        }

        return (int) $stat['mtime'];
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

    /** Removes one entry of a staging tree: a link or file by unlink, a directory after its entries. */
    private static function removeEntry(string $path): void
    {
        $stat = self::lstat($path);
        if ($stat === null) {
            return;
        }
        if (self::type($stat) === self::TYPE_DIR) {
            foreach (self::entries($path) as $entry) {
                self::removeEntry($path . '/' . $entry);
            }
            $removed = ErrorTrap::run(static fn (): bool => rmdir($path), 'Cannot remove the directory.');
            if (!$removed) {
                throw new \RuntimeException('Cannot remove the directory.');
            }

            return;
        }
        $removed = ErrorTrap::run(static fn (): bool => unlink($path), 'Cannot remove the file.');
        if (!$removed) {
            throw new \RuntimeException('Cannot remove the file.');
        }
    }

    /** Copies a regular file to a new file (created exclusively); a partial copy is removed. */
    private static function copyNew(string $from, string $to): void
    {
        $in = ErrorTrap::run(static fn (): mixed => fopen($from, 'rb'), 'Cannot open the file.');
        if (!is_resource($in)) {
            throw new \RuntimeException('Cannot open the file.');
        }
        try {
            $out = ErrorTrap::run(static fn (): mixed => fopen($to, 'xb'), 'Cannot create the file.');
            if (!is_resource($out)) {
                throw new \RuntimeException('Cannot create the file.');
            }
            try {
                $copied = ErrorTrap::run(static fn (): int|false => stream_copy_to_stream($in, $out), 'Cannot copy the file.');
                $closed = ErrorTrap::run(static fn (): bool => fclose($out), 'Cannot close the file.');
                if ($copied === false || !$closed) {
                    throw new \RuntimeException('Cannot copy the file.');
                }
            } catch (\Throwable $error) {
                if (is_resource($out)) {
                    fclose($out);
                }
                self::remove($to);

                throw $error;
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * lstat() of the path, fresh (the stat cache is cleared for it), or
     * null when nothing is there, not even a dangling symbolic link.
     *
     * @return array<array-key, int>|null
     */
    private static function lstat(string $path): ?array
    {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $stat = ErrorTrap::run(static fn (): array|false => lstat($path), 'Cannot read the file status.');
        if ($stat === false) {
            throw new \RuntimeException('Cannot read the file status.');
        }

        return $stat;
    }

    /** @return array<array-key, int>|null */
    private static function lstatOrNull(string $path): ?array
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        try {
            return self::lstat($path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @param array<array-key, int> $stat */
    private static function type(array $stat): int
    {
        return $stat['mode'] & self::TYPE_MASK;
    }

    private static function refuseNul(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('A path must not be empty or contain a NUL byte.');
        }
    }
}
