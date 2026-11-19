<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-IO-2 (Fs part): Fs::removeTree only below a root ending in /staging,
 * never following links, and Fs::moveIn. Synthetic files only.
 */
#[CoversClass(Fs::class)]
final class FsTreeTest extends TestCase
{
    private TempDir $temp;
    private string $staging;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->staging = $this->temp->path('data/staging');
        mkdir($this->staging . '/imports/job', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function logic(callable $work): void
    {
        try {
            $work();
            self::fail('Refusal expected.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRemovesATreeBottomUp(): void
    {
        $job = $this->staging . '/imports/job';
        mkdir($job . '/tmp/deep/deeper', 0750, true);
        file_put_contents($job . '/upload.bin', 'synthetic');
        file_put_contents($job . '/tmp/deep/deeper/x.txt', 'synthetic');
        file_put_contents($job . '/.hidden', 'synthetic');
        Fs::removeTree($job, $this->staging);
        self::assertFalse(Fs::exists($job));
        self::assertTrue(Fs::isDir($this->staging . '/imports'));
        // Nothing there: no error.
        Fs::removeTree($job, $this->staging);
        // A trailing slash on the root is accepted.
        mkdir($job);
        Fs::removeTree($job, $this->staging . '/');
        self::assertFalse(Fs::exists($job));
    }

    public function testLinksAreRemovedAsLinks(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'synthetic');
        $job = $this->staging . '/imports/job';
        symlink($outside . '/keep.txt', $job . '/file-link');
        symlink($outside, $job . '/dir-link');
        symlink($this->temp->path('absent'), $job . '/dangling');
        Fs::removeTree($job, $this->staging);
        self::assertFalse(Fs::exists($job));
        self::assertFileExists($outside . '/keep.txt');
        self::assertDirectoryExists($outside);

        // A job path that is itself a link to a folder outside: the link goes, the folder stays.
        symlink($outside, $this->staging . '/imports/linked');
        Fs::removeTree($this->staging . '/imports/linked', $this->staging);
        self::assertFalse(Fs::exists($this->staging . '/imports/linked'));
        self::assertFileExists($outside . '/keep.txt');
    }

    public function testRefusesRootsAndPathsOutsideTheStagingArea(): void
    {
        $other = $this->temp->path('data/projects');
        mkdir($other . '/p', 0750, true);
        file_put_contents($other . '/p/project.tiffin', 'synthetic');
        $this->logic(fn () => Fs::removeTree($other . '/p', $this->temp->path('data/projects')));
        $this->logic(fn () => Fs::removeTree($other . '/p', $this->temp->path('data')));
        $this->logic(fn () => Fs::removeTree($other . '/p', $this->staging));
        $this->logic(fn () => Fs::removeTree($this->staging . '/../projects/p', $this->staging));
        $this->logic(fn () => Fs::removeTree($this->staging . '/imports/..', $this->staging));
        $this->logic(fn () => Fs::removeTree($this->staging . '/.', $this->staging));
        $this->logic(fn () => Fs::removeTree($this->staging, $this->staging));
        $this->logic(fn () => Fs::removeTree($this->staging . 'x/imports', $this->staging . 'x'));
        self::assertFileExists($other . '/p/project.tiffin');

        // A folder whose name ends in staging but is not the staging root.
        mkdir($this->temp->path('notstaging'), 0750);
        $this->logic(fn () => Fs::removeTree($this->temp->path('notstaging/x'), $this->temp->path('notstaging')));
        // A parent inside the staging area reached through a link to outside is refused.
        symlink($this->temp->path('data/projects'), $this->staging . '/exports');
        $this->logic(fn () => Fs::removeTree($this->staging . '/exports/p', $this->staging));
        self::assertFileExists($other . '/p/project.tiffin');

        $this->expectException(\InvalidArgumentException::class);
        Fs::removeTree($this->staging . "/imports/job\0", $this->staging);
    }

    public function testARootThatIsALinkNamedStagingIsRefused(): void
    {
        $target = $this->temp->path('elsewhere');
        mkdir($target . '/job', 0750, true);
        mkdir($this->temp->path('second'));
        symlink($target, $this->temp->path('second/staging'));
        $this->logic(fn () => Fs::removeTree($this->temp->path('second/staging/job'), $this->temp->path('second/staging')));
        self::assertDirectoryExists($target . '/job');
    }

    public function testMoveIn(): void
    {
        $from = $this->temp->path('upload.tmp');
        file_put_contents($from, 'synthetic upload');
        chmod($from, 0600);
        $to = $this->staging . '/imports/job/upload.bin';
        Fs::moveIn($from, $to);
        self::assertFalse(Fs::exists($from));
        self::assertSame('synthetic upload', file_get_contents($to));
        self::assertSame(Fs::FILE_MODE, fileperms($to) & 0777);

        // The target must not exist.
        file_put_contents($from, 'again');
        try {
            Fs::moveIn($from, $to);
            self::fail('An existing target was replaced.');
        } catch (\RuntimeException) {
            self::assertSame('synthetic upload', file_get_contents($to));
            self::assertFileExists($from);
        }
        symlink($this->temp->path('absent'), $this->staging . '/imports/job/dangling');
        try {
            Fs::moveIn($from, $this->staging . '/imports/job/dangling');
            self::fail('A dangling link was replaced.');
        } catch (\RuntimeException) {
            self::assertFileExists($from);
        }
        // The source must be a file or a directory, not a link.
        symlink($from, $this->temp->path('link'));
        try {
            Fs::moveIn($this->temp->path('link'), $this->staging . '/imports/job/other');
            self::fail('A link was moved.');
        } catch (\RuntimeException) {
            self::assertFalse(Fs::exists($this->staging . '/imports/job/other'));
        }
        // A directory moves by rename.
        mkdir($this->temp->path('rebuilt/attachments'), 0750, true);
        Fs::moveIn($this->temp->path('rebuilt'), $this->temp->path('data/moved'));
        self::assertDirectoryExists($this->temp->path('data/moved/attachments'));
    }
}
