<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

use TiffinDB\Core\Error\Busy;

/**
 * The project lock (ADR 0022 decision 1, M7 spec section 4): an advisory
 * `flock` on `<project folder>/project.lock`, an empty file. Every opening
 * of a project takes it shared; restore and deletion take it exclusive.
 *
 * The lock file is opened with mode `c` (created when missing, never
 * truncated) only after lstat shows a regular file or nothing at its
 * path, inside a project folder that is a real directory (not a symbolic
 * link); the opened file must then be the one at the path (same device and
 * inode), so nothing is ever created or locked through a link. A lock
 * belongs to its open file: a process must hold at most one ProjectLock
 * per project (ProjectOpener sees to that). Waits use real time.
 */
final class ProjectLock
{
    public const FILE = 'project.lock';
    public const SHARED_WAIT_MS = 2000;
    public const EXCLUSIVE_WAIT_MS = 10000;
    public const STEP_MS = 50;

    /**
     * Multiplies both waits; tests shorten them with it.
     *
     * @internal Test seam only; application code never changes it.
     */
    public static float $waitScaleForTests = 1.0;

    /**
     * Makes this class behave as on a server where `flock` is disabled
     * (disable_functions), which tests cannot set at run time.
     *
     * @internal Test seam only; application code never changes it.
     */
    public static bool $flockDisabledForTests = false;

    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle, public readonly bool $exclusive)
    {
        $this->handle = $handle;
    }

    /**
     * A shared lock on <folder>/project.lock. Busy when it is not got within
     * SHARED_WAIT_MS (an exclusive lock is held). Null when the folder is
     * not a real directory (missing, moved, or a link) or the file system
     * cannot lock; the opener decides whether to log it.
     */
    public static function shared(string $projectFolder): ?self
    {
        try {
            $handle = self::openFile($projectFolder);
        } catch (LocksUnavailable) {
            return null;
        }
        if ($handle === null) {
            return null;
        }
        try {
            return self::acquire($handle, LOCK_SH, self::SHARED_WAIT_MS);
        } catch (LocksUnavailable) {
            return null;
        }
    }

    /**
     * An exclusive lock. Busy when it is not got within EXCLUSIVE_WAIT_MS
     * (others are still working); LocksUnavailable when the folder is not
     * a real directory or the file system cannot lock.
     */
    public static function exclusive(string $projectFolder): self
    {
        return self::exclusiveWithin($projectFolder, self::EXCLUSIVE_WAIT_MS);
    }

    /**
     * An exclusive lock only if it can be had at once; null when another
     * request holds the lock. LocksUnavailable as exclusive().
     */
    public static function tryExclusive(string $projectFolder): ?self
    {
        try {
            return self::exclusiveWithin($projectFolder, 0);
        } catch (Busy) {
            return null;
        }
    }

    /** Releases the lock and closes the file; idempotent. */
    public function release(): void
    {
        if (is_resource($this->handle)) {
            if (self::canLock()) {
                flock($this->handle, LOCK_UN);
            }
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function isHeld(): bool
    {
        return is_resource($this->handle);
    }

    public function __destruct()
    {
        $this->release();
    }

    /** Whether `flock` can be called here (it is missing when listed in disable_functions). */
    private static function canLock(): bool
    {
        return !self::$flockDisabledForTests && function_exists('flock');
    }

    private static function exclusiveWithin(string $projectFolder, int $waitMs): self
    {
        $handle = self::openFile($projectFolder);
        if ($handle === null) {
            throw new LocksUnavailable('The project folder is not a directory.');
        }

        return self::acquire($handle, LOCK_EX, $waitMs);
    }

    /**
     * The opened lock file; null when the folder is missing. LocksUnavailable
     * when the folder or the lock file is anything but a real directory and
     * a regular file, or the file cannot be opened.
     *
     * @return resource|null
     */
    private static function openFile(string $projectFolder)
    {
        $folder = rtrim($projectFolder, '/');
        if ($folder === '' || str_contains($folder, "\0")) {
            throw new \InvalidArgumentException('A project folder path must not be empty or contain a NUL byte.');
        }
        if (!Fs::exists($folder)) {
            return null;
        }
        if (!Fs::isDir($folder)) {
            throw new LocksUnavailable('The project folder is not a directory.');
        }
        $path = $folder . '/' . self::FILE;
        $isNew = !Fs::exists($path);
        if (!$isNew && !Fs::isFile($path)) {
            throw new LocksUnavailable('The project lock is not a regular file.');
        }
        try {
            $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'c'), 'Cannot open the project lock.');
        } catch (\RuntimeException) {
            $handle = false;
        }
        if (!is_resource($handle)) {
            if (!Fs::exists($folder)) {
                return null;
            }

            throw new LocksUnavailable('Cannot open the project lock.');
        }
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $atPath = Fs::isFile($path) ? lstat($path) : false;
        if ($opened === false || $atPath === false || $opened['dev'] !== $atPath['dev'] || $opened['ino'] !== $atPath['ino']) {
            fclose($handle);

            throw new LocksUnavailable('The project lock changed while it was opened.');
        }
        if ($isNew) {
            try {
                ErrorTrap::run(static fn (): bool => chmod($path, Fs::FILE_MODE), 'Cannot set the lock file mode.');
            } catch (\RuntimeException) {
                // The mode of an empty lock file is not worth failing the request for.
            }
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function acquire($handle, int $operation, int $waitMs): self
    {
        if (!self::canLock()) {
            // flock disabled (disable_functions): shared() degrades to no lock, exclusive() refuses.
            fclose($handle);

            throw new LocksUnavailable('This server cannot lock files.');
        }
        $deadline = hrtime(true) + (int) ($waitMs * self::$waitScaleForTests * 1_000_000);
        while (true) {
            $wouldBlock = 0;
            if (flock($handle, $operation | LOCK_NB, $wouldBlock)) {
                return new self($handle, $operation === LOCK_EX);
            }
            if (!(bool) $wouldBlock) {
                fclose($handle);

                throw new LocksUnavailable('The file system cannot lock the project.');
            }
            if (hrtime(true) >= $deadline) {
                fclose($handle);

                throw new Busy('The project is in use.');
            }
            usleep(self::STEP_MS * 1000);
        }
    }
}
