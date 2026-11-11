<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * The shared repository walker: the git listing is used when a runnable git
 * exists, and a missing git binary falls back quietly (no warning) to the
 * directory walk.
 */
#[CoversNothing]
final class RepoFilesTest extends TestCase
{
    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            rmdir($this->temp);
            $this->temp = null;
        }
    }

    public function testMissingGitBinaryGivesNoListingAndNoWarning(): void
    {
        // An empty search path simulates a host without git; failOnWarning
        // would fail this test if proc_open were attempted and warned.
        self::assertNull(RepoFiles::findExecutable('git', ''));
        self::assertNull(RepoFiles::gitFiles(RepoFiles::root(), ''));

        $empty = $this->tempDir();
        self::assertNull(RepoFiles::findExecutable('git', $empty));
        self::assertNull(RepoFiles::gitFiles(RepoFiles::root(), $empty));
    }

    public function testNoGitCheckoutGivesNoListing(): void
    {
        self::assertNull(RepoFiles::gitFiles($this->tempDir(), (string) getenv('PATH')));
    }

    public function testDirectoryWalkFallbackFindsTheRepositoryFiles(): void
    {
        $walked = RepoFiles::walk(RepoFiles::root());

        self::assertContains('composer.json', $walked);
        self::assertContains('tests/Support/RepoFiles.php', $walked);
        foreach ($walked as $path) {
            self::assertFalse(RepoFiles::isExcluded($path), "{$path} lies in a non-scanned directory.");
        }
        $sorted = $walked;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $walked);
    }

    public function testRepeatedCallsGiveTheSameSortedResult(): void
    {
        $first = RepoFiles::all();
        self::assertContains('composer.json', $first);
        self::assertSame($first, RepoFiles::all());

        $sorted = $first;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $first);

        foreach (RepoFiles::all('tests') as $path) {
            self::assertStringStartsWith('tests/', $path);
        }
    }

    private function tempDir(): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-walk-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }

        return $this->temp;
    }
}
