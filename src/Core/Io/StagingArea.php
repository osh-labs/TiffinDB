<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;

/**
 * Job folders under `<data>/staging/<kind>/<key>/` (M3 spec section 5.1,
 * P127). Every name inside a job folder is chosen by the application; the
 * uploaded file's own name is never a path. A new folder holds `tmp/` (the
 * plugin's temporary folder) and `job.lock` (the job lock, section 5.2).
 * Recursive removal (Fs::removeTree) is used only here.
 */
final class StagingArea
{
    public const FOLDER = 'staging';
    public const KINDS = ['imports', 'exports', 'archives'];
    /** @var array<string, int> seconds */
    public const MAX_AGE = ['imports' => 86400, 'exports' => 3600, 'archives' => 3600];
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
     * and an empty `job.lock`, and returns [key, path]. The staging and kind
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
        Fs::makeDir($path . '/' . self::TEMP);
        Fs::writeNew($path . '/' . self::LOCK, '');

        return [$key, $path];
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
     * than MAX_AGE. Entries that are not job keys are left alone; an entry
     * that cannot be read or removed is logged and skipped.
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
                    if ($now - Fs::modifiedAt($path) > self::MAX_AGE[$kind]) {
                        Fs::removeTree($path, $this->root());
                    }
                } catch (\RuntimeException) {
                    error_log('TiffinDB: could not remove an old staging job.');
                }
            }
        }
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
