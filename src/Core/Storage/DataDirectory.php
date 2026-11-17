<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * The installation's data directory (ADR 0011 decision 1, M1 spec section
 * 4.3). Candidates, in search order: the configured `data_dir` (when set,
 * the only one), `<app>/../tiffindb-data`, `<app>/data`.
 *
 * Paths are normalised lexically: `.` and `..` segments are removed and no
 * trailing slash is kept, without asking the file system. Symbolic links
 * are not resolved here. So when the application folder is itself a
 * symbolic link (for example `/srv/www/app` pointing to
 * `/opt/releases/7`), the sibling candidate is `/srv/www/tiffindb-data`,
 * beside the link, not `/opt/releases/tiffindb-data`, beside its target,
 * which is where the operating system would resolve `app/..`. Callers that
 * need the physical location (the installer's document-root check) resolve
 * it with Fs::nearestExisting().
 *
 * A configured `data_dir` must be an absolute path (M1-screens), without
 * NUL bytes; anything else is refused with \InvalidArgumentException.
 */
final class DataDirectory
{
    public const SIBLING = '../tiffindb-data';
    public const IN_TREE = 'data';
    public const CONFIGURED_LABEL = 'the folder set in config/local.php';
    public const SYSTEM_DATABASE = 'system.sqlite';
    public const PROJECTS = 'projects';

    private readonly string $path;

    /** $path must be absolute; it is normalised as described above. */
    public function __construct(string $path, private readonly string $label)
    {
        $this->path = self::normalise($path);
    }

    public static function fromCandidate(DataCandidate $candidate): self
    {
        return new self($candidate->path, $candidate->label);
    }

    /** The installed data directory: the first candidate holding system.sqlite, or null. */
    public static function locate(string $appRoot, ?string $configured): ?self
    {
        foreach (self::candidates($appRoot, $configured) as $candidate) {
            if (is_file($candidate->path . '/' . self::SYSTEM_DATABASE)) {
                return self::fromCandidate($candidate);
            }
        }

        return null;
    }

    /**
     * Candidates in search order. With $configured set (not null and not
     * empty), only that one, which must be absolute.
     *
     * @return list<DataCandidate>
     */
    public static function candidates(string $appRoot, ?string $configured): array
    {
        $root = self::normalise($appRoot);
        $inTree = self::normalise($root . '/' . self::IN_TREE);
        if ($configured !== null && $configured !== '') {
            if (str_contains($configured, "\0") || !self::isAbsolute($configured)) {
                throw new \InvalidArgumentException('The data_dir set in config/local.php must be an absolute path.');
            }
            $path = self::normalise($configured);

            return [new DataCandidate($path, self::CONFIGURED_LABEL, $path === $inTree)];
        }

        return [
            new DataCandidate(self::normalise($root . '/' . self::SIBLING), self::SIBLING, false),
            new DataCandidate($inTree, self::IN_TREE, true),
        ];
    }

    /** Absolute, no trailing slash. */
    public function path(): string
    {
        return $this->path;
    }

    /** For messages: '../tiffindb-data', 'data', or 'the folder set in config/local.php'. */
    public function label(): string
    {
        return $this->label;
    }

    public function systemDatabase(): string
    {
        return $this->path . '/' . self::SYSTEM_DATABASE;
    }

    /** `<path>/projects/<key>`; the key must be valid (Keys::isValid). */
    public function projectFolder(string $projectKey): string
    {
        if (!Keys::isValid($projectKey)) {
            throw new \InvalidArgumentException('Not a valid project key.');
        }

        return $this->path . '/' . self::PROJECTS . '/' . $projectKey;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    private static function normalise(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('The path must not contain a NUL byte.');
        }
        if (!self::isAbsolute($path)) {
            throw new \InvalidArgumentException('The path must be absolute.');
        }
        $path = str_replace('\\', '/', $path);
        $prefix = '/';
        if (preg_match('#^([A-Za-z]:)/#', $path, $match) === 1) {
            $prefix = $match[1] . '/';
            $path = substr($path, 2);
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $prefix === '/' && $segments === [] ? '/' : $prefix . implode('/', $segments);
    }
}
