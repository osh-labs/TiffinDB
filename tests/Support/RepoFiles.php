<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Shared repository walker for the repository-rule and design tests.
 *
 * Paths returned are relative to the project root, use `/` as separator,
 * and are sorted, so results are deterministic.
 */
final class RepoFiles
{
    /**
     * Top-level directories that are never scanned (generated, ignored, or
     * version-control data). `.claude` holds local agent worktrees, which
     * are full copies of the repository. tools/dev/lint.php uses this class,
     * so the lint command and the tests scan the same files.
     */
    public const EXCLUDED_DIRECTORIES = ['.git', '.claude', 'vendor', 'node_modules', 'data', 'local', '.phpunit.cache'];

    private function __construct()
    {
    }

    /** Absolute path of the project root, without a trailing separator. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Absolute path of a repository-relative path. */
    public static function path(string $relative): string
    {
        return self::root() . '/' . ltrim($relative, '/');
    }

    /** Contents of a repository file; throws if it cannot be read. */
    public static function read(string $relative): string
    {
        $path = self::path($relative);
        if (!is_file($path)) {
            throw new RuntimeException("Not a file: {$relative}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read: {$relative}");
        }

        return $contents;
    }

    /** Whether a repository-relative path lies inside a non-scanned directory. */
    public static function isExcluded(string $relative): bool
    {
        $first = explode('/', ltrim(str_replace('\\', '/', $relative), '/'), 2)[0];

        return in_array($first, self::EXCLUDED_DIRECTORIES, true);
    }

    /**
     * Every regular file outside the non-scanned directories, optionally
     * below one repository-relative directory.
     *
     * In a git checkout this is the set git would consider: tracked files
     * plus untracked files that are not git-ignored (`git ls-files --cached
     * --others --exclude-standard`), so ignored local files are never
     * scanned. Without git (for example in a release archive) it falls back
     * to a directory walk. Either way the non-scanned directories are left
     * out and the result is sorted.
     *
     * @return list<string>
     */
    public static function all(string $under = ''): array
    {
        $under = trim(str_replace('\\', '/', $under), '/');
        $root = self::root();
        $start = $under === '' ? $root : self::path($under);
        if (!is_dir($start)) {
            return [];
        }

        $listed = self::gitFiles();
        if ($listed !== null) {
            $files = array_values(array_filter(
                $listed,
                static fn (string $path): bool => !self::isExcluded($path)
                    && ($under === '' || str_starts_with($path, $under . '/'))
                    && is_file($root . '/' . $path),
            ));
            sort($files, SORT_STRING);

            return $files;
        }

        return self::walk($start);
    }

    /**
     * Files git would consider, or null when the root is not a git checkout
     * or git cannot be run. Local and offline.
     *
     * @return list<string>|null
     */
    private static function gitFiles(): ?array
    {
        $root = self::root();
        if (!file_exists($root . '/.git')) {
            return null;
        }
        $process = proc_open(
            ['git', '-c', 'core.quotePath=off', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return null;
        }

        $paths = array_filter(explode("\0", $output), static fn (string $path): bool => $path !== '');

        return array_values(array_unique($paths));
    }

    /**
     * Directory walk used when git is not available.
     *
     * @return list<string>
     */
    private static function walk(string $start): array
    {
        $root = self::root();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file) use ($root): bool {
                    if (dirname($file->getPathname()) === $root) {
                        return !in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true);
                    }
                    return true;
                },
            ),
        );

        $files = [];
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Files whose extension (case-insensitive, without the dot) is one of
     * those given, optionally below one repository-relative directory.
     *
     * @param list<string> $extensions
     * @return list<string>
     */
    public static function withExtension(array $extensions, string $under = ''): array
    {
        $wanted = array_map('strtolower', $extensions);

        return array_values(array_filter(
            self::all($under),
            static fn (string $path): bool => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                $wanted,
                true,
            ),
        ));
    }
}
