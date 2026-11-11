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
     * are full copies of the repository. Must stay the same as the list in
     * tools/dev/lint.php.
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
     * @return list<string>
     */
    public static function all(string $under = ''): array
    {
        $root = self::root();
        $start = $under === '' ? $root : self::path(rtrim($under, '/'));
        if (!is_dir($start)) {
            return [];
        }

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
