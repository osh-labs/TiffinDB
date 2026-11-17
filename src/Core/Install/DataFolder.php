<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Storage\DataCandidate;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * Choosing the data folder and checking what an existing one holds (M1
 * spec section 10.3).
 *
 * Fail safe: a candidate counts as *outside* the document root only when
 * the document root is known, both paths can be resolved
 * (Fs::nearestExisting), and the candidate is not inside it. A path that
 * cannot be resolved is treated as inside, so it is never chosen as
 * outside and never skips the exposure probe.
 */
final class DataFolder
{
    /** The data folder's .htaccess, exactly (section 10.3, T-REPO-10). */
    public const HTACCESS = "# TiffinDB data folder. Nothing here may be served over the web.\nRequire all denied\n";
    public const HTACCESS_FILE = '.htaccess';
    /** Names of probe files left behind by an interrupted probe. */
    public const PROBE_FILE = '/^probe-[0-9a-f]{16}\.txt\z/';

    private function __construct()
    {
    }

    /**
     * The candidate to use, or null when none is usable. With a configured
     * data_dir, only that one. Otherwise the first usable candidate that is
     * outside a known document root; failing that, `<app>/data` when
     * usable; the sibling inside (or possibly inside) the document root is
     * never chosen.
     */
    public static function choose(string $appRoot, ?string $configured, ?string $documentRoot): ?DataCandidate
    {
        $candidates = DataDirectory::candidates($appRoot, $configured);
        if ($configured !== null && $configured !== '') {
            return self::usable($candidates[0]->path) ? $candidates[0] : null;
        }
        foreach ($candidates as $candidate) {
            if (self::usable($candidate->path) && self::isOutside($candidate->path, $documentRoot)) {
                return $candidate;
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate->inTree && self::usable($candidate->path)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The first candidate, whose label failure messages show. */
    public static function firstCandidate(string $appRoot, ?string $configured): DataCandidate
    {
        return DataDirectory::candidates($appRoot, $configured)[0];
    }

    /**
     * True only when the document root is known, both paths resolve, and
     * $path is not the document root or below it.
     */
    public static function isOutside(string $path, ?string $documentRoot): bool
    {
        if ($documentRoot === null || $documentRoot === '') {
            return false;
        }
        if (Fs::nearestExisting($path) === null || Fs::nearestExisting($documentRoot) === null) {
            return false;
        }

        return !Fs::isInside($path, $documentRoot);
    }

    /**
     * Usable: an existing writable directory, or nothing at the path and a
     * writable directory as its parent.
     */
    public static function usable(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (file_exists($path) || is_link($path)) {
            return Fs::isWritableDir($path);
        }

        return Fs::isWritableDir(dirname($path));
    }

    /**
     * Whether an existing folder holds only what TiffinDB may have left in
     * it before it was installed: its own .htaccess (exact text), an empty
     * `projects` folder, a `setup-code.txt` file, and probe files. Symbolic
     * links are never accepted.
     */
    public static function holdsOnlyLeftovers(string $path): bool
    {
        $entries = ErrorTrap::run(static fn (): array|false => scandir($path), 'Cannot read the data folder.');
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $path . '/' . $name;
            if (is_link($full)) {
                return false;
            }
            $ok = match (true) {
                $name === self::HTACCESS_FILE => is_file($full) && self::readSmall($full) === self::HTACCESS,
                $name === DataDirectory::PROJECTS => is_dir($full) && self::isEmptyDir($full),
                $name === SetupCode::FILE => is_file($full),
                preg_match(self::PROBE_FILE, $name) === 1 => is_file($full),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> names of probe files in the folder */
    public static function probeFiles(string $path): array
    {
        $entries = ErrorTrap::run(static fn (): array|false => scandir($path), 'Cannot read the data folder.');
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (string $name): bool => preg_match(self::PROBE_FILE, $name) === 1,
        ));
    }

    private static function isEmptyDir(string $path): bool
    {
        $entries = ErrorTrap::run(static fn (): array|false => scandir($path), 'Cannot read a folder.');

        return $entries !== false && array_diff($entries, ['.', '..']) === [];
    }

    private static function readSmall(string $path): ?string
    {
        $contents = ErrorTrap::run(
            static fn (): string|false => file_get_contents($path, false, null, 0, 4096),
            'Cannot read a file.',
        );

        return $contents === false ? null : $contents;
    }
}
