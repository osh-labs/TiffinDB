<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A fresh directory under sys_get_temp_dir() for one test, removed with
 * everything in it by remove() (call it from tearDown). Symbolic links
 * inside are removed, never followed. Nothing is ever written into the
 * repository tree.
 */
final class TempDir
{
    private bool $removed = false;

    private function __construct(private readonly string $path)
    {
    }

    public static function create(string $prefix = 'tiffindb-test-'): self
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new RuntimeException('The system temporary directory does not exist.');
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $base . '/' . $prefix . bin2hex(random_bytes(8));
            if (!file_exists($path) && self::quietly(static fn (): bool => mkdir($path, 0700))) {
                return new self($path);
            }
        }

        throw new RuntimeException('Cannot create a temporary directory.');
    }

    /** Absolute path, no trailing slash. */
    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->path : $this->path . '/' . ltrim($relative, '/');
    }

    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        $this->removed = true;
        if (!is_dir($this->path)) {
            return;
        }
        // Restore write and search permission first, so tests may change modes freely.
        self::quietly(fn (): bool => chmod($this->path, 0700));
        $directories = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($directories as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                self::quietly(static fn (): bool => chmod($entry->getPathname(), 0700));
            }
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $name = $entry->getPathname();
            $ok = $entry->isDir() && !$entry->isLink()
                ? self::quietly(static fn (): bool => rmdir($name))
                : self::quietly(static fn (): bool => unlink($name));
            if (!$ok) {
                throw new RuntimeException('Cannot remove a temporary file.');
            }
        }
        if (!self::quietly(fn (): bool => rmdir($this->path))) {
            throw new RuntimeException('Cannot remove the temporary directory.');
        }
    }

    /** @param callable(): bool $call */
    private static function quietly(callable $call): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
