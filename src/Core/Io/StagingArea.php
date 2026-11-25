<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;

/**
 * Job folders under `<data>/staging/<kind>/<key>/` (M3 spec section 5.1,
 * P127). Every name inside a job folder is chosen by the application; the
 * uploaded file's own name is never a path. A new folder holds `tmp/` (the
 * plugin's temporary folder) and `job.lock` (the job lock, section 5.2),
 * except an `uploads` folder (an attachment upload, M4 spec section 3.3),
 * which is made empty and later holds `upload.bin` only. A `restores` job
 * (M7 spec section 5.3) is made like an import's and holds the snapshot's
 * working copy. A `deleted` entry is a whole project folder moved there
 * by a deletion (reserve(), M7 spec section 6.2). Recursive removal
 * (Fs::removeTree) is used only here.
 */
final class StagingArea
{
    public const FOLDER = 'staging';
    public const KINDS = ['imports', 'exports', 'archives', 'uploads', 'restores', 'deleted'];
    /** @var array<string, int> seconds */
    public const MAX_AGE = ['imports' => 86400, 'exports' => 3600, 'archives' => 3600, 'uploads' => 3600, 'restores' => 3600, 'deleted' => 3600];
    /**
     * The kind that holds project folders being deleted (M7 spec section
     * 6.2): an entry is a moved project folder, made by reserve() and the
     * move, never by create().
     */
    public const DELETED = 'deleted';
    /** The project lock file inside a moved project folder (Core\Storage\ProjectLock::FILE). */
    public const PROJECT_LOCK = 'project.lock';
    /** The kind whose job folders hold only the uploaded file (no tmp/, no job.lock). */
    public const UPLOADS = 'uploads';
    public const TEMP = 'tmp';
    public const LOCK = 'job.lock';

    public function __construct(
        private readonly DataDirectory $data,
        private readonly Clock $clock,
        private readonly Keys $keys,
    ) {
    }

    /** The staging root, `<data>/staging`. */
    public function root(): string
    {
        return $this->data->path() . '/' . self::FOLDER;
    }

    /**
     * Creates `<data>/staging/<kind>/<new key>/` (mode 0750) with `tmp/`
     * and an empty `job.lock` (an `uploads` folder is left empty), and
     * returns [key, path]. The staging and kind
     * folders are made when missing; \RuntimeException when one of them is
     * anything but a real directory (a symbolic link included).
     *
     * @return array{0: string, 1: string}
     */
    public function create(string $kind): array
    {
        self::checkKind($kind);
        foreach ([$this->root(), $this->kindFolder($kind)] as $folder) {
            if (!Fs::exists($folder)) {
                Fs::makeDir($folder);
            }
            if (!Fs::isDir($folder)) {
                throw new \RuntimeException('The staging folder is not a directory.');
            }
        }
        $key = $this->keys->generateUnique(fn (string $candidate): bool => Fs::exists($this->kindFolder($kind) . '/' . $candidate));
        $path = $this->kindFolder($kind) . '/' . $key;
        Fs::makeDir($path);
        if ($kind !== self::UPLOADS) {
            Fs::makeDir($path . '/' . self::TEMP);
            Fs::writeNew($path . '/' . self::LOCK, '');
        }

        return [$key, $path];
    }

    /**
     * The path `<data>/staging/<kind>/<key>` for a folder the caller will
     * move there (a project being deleted), without creating it. The
     * staging and kind folders are made when missing (\RuntimeException when
     * one is anything but a real directory); \RuntimeException when anything
     * is already at the path; \InvalidArgumentException for a bad key.
     */
    public function reserve(string $kind, string $key): string
    {
        self::checkKind($kind);
        if (!Keys::isValid($key)) {
            throw new \InvalidArgumentException('Not a valid key.');
        }
        foreach ([$this->root(), $this->kindFolder($kind)] as $folder) {
            if (!Fs::exists($folder)) {
                Fs::makeDir($folder);
            }
            if (!Fs::isDir($folder)) {
                throw new \RuntimeException('The staging folder is not a directory.');
            }
        }
        $path = $this->kindFolder($kind) . '/' . $key;
        if (Fs::exists($path)) {
            throw new \RuntimeException('Something is already at the staging path.');
        }

        return $path;
    }

    /** The folder of an existing job; null when the kind or key is invalid, or the folder is absent or not a real directory. */
    public function folder(string $kind, string $key): ?string
    {
        if (!in_array($kind, self::KINDS, true) || !Keys::isValid($key)) {
            return null;
        }
        if (!Fs::isDir($this->root()) || !Fs::isDir($this->kindFolder($kind))) {
            return null;
        }
        $path = $this->kindFolder($kind) . '/' . $key;

        return Fs::isDir($path) ? $path : null;
    }

    /** Removes a job folder and everything in it; nothing when it is absent. */
    public function remove(string $kind, string $key): void
    {
        self::checkKind($kind);
        if (!Keys::isValid($key) || !Fs::isDir($this->root()) || !Fs::isDir($this->kindFolder($kind))) {
            return;
        }
        Fs::removeTree($this->kindFolder($kind) . '/' . $key, $this->root());
    }

    /**
     * Removes job folders of every kind whose modification time is older
     * than MAX_AGE. A job whose lock another request holds is in use and is
     * left alone, however old. Entries that are not job keys are left
     * alone; an entry that cannot be read or removed is logged and skipped.
     */
    public function sweep(): void
    {
        $now = $this->clock->now()->getTimestamp();
        foreach (self::KINDS as $kind) {
            try {
                if (!Fs::isDir($this->root()) || !Fs::isDir($this->kindFolder($kind))) {
                    continue;
                }
                $entries = Fs::entries($this->kindFolder($kind));
            } catch (\RuntimeException) {
                error_log('TiffinDB: could not list a staging folder.');

                continue;
            }
            foreach ($entries as $name) {
                if (!Keys::isValid($name)) {
                    continue;
                }
                $path = $this->kindFolder($kind) . '/' . $name;
                try {
                    if ($kind === self::DELETED ? $this->deletedIsDone($path, $now) : $now - Fs::modifiedAt($path) > self::MAX_AGE[$kind] && !self::inUse($path)) {
                        Fs::removeTree($path, $this->root());
                    }
                } catch (\RuntimeException) {
                    error_log('TiffinDB: could not remove an old staging job.');
                }
            }
        }
    }

    /**
     * Whether a `deleted` entry may be removed (M7 spec section 6.2): one
     * whose project.lock is there only when an exclusive lock on it can be
     * had at once (the file is opened without creating it; a held lock
     * means a deletion is still removing it); one without (the removal has
     * already taken it) only when its own time is older than MAX_AGE, since
     * a running removal keeps changing it.
     */
    private function deletedIsDone(string $path, int $now): bool
    {
        $lock = $path . '/' . self::PROJECT_LOCK;
        if (Fs::isDir($path) && Fs::isFile($lock)) {
            return self::lockFree($lock);
        }

        return $now - Fs::modifiedAt($path) > self::MAX_AGE[self::DELETED];
    }

    /** Whether an exclusive lock on an existing file can be had at once (released at once); false when it cannot be opened. */
    private static function lockFree(string $lock): bool
    {
        try {
            $handle = ErrorTrap::run(static fn (): mixed => fopen($lock, 'r'), 'Cannot open the lock.');
        } catch (\RuntimeException) {
            return false;
        }
        if (!is_resource($handle)) {
            return false;
        }
        // Where flock is disabled the lock cannot be tested: the entry is kept, never removed under a running deletion.
        $free = function_exists('flock') && flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $free;
    }

    /** Whether another request holds the job lock of the folder (tried without waiting, and released at once). */
    private static function inUse(string $path): bool
    {
        $lock = $path . '/' . self::LOCK;
        if (!Fs::isFile($lock)) {
            return false;
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($lock, 'r'), 'Cannot open the job lock.');
        if (!is_resource($handle)) {
            return true;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    private function kindFolder(string $kind): string
    {
        return $this->root() . '/' . $kind;
    }

    private static function checkKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown staging kind.');
        }
    }
}
