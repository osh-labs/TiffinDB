<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\LockHolder;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-IO-2 (StagingArea part; the Fs part is tests/core/storage/FsTreeTest.php), T-STO-15 (kind `uploads`),
 * and the M7 kinds `restores` and `deleted` (M7 spec sections 5.3 and 6.2; T-ADM-18, sweep part).
 */
#[CoversClass(StagingArea::class)]
final class StagingAreaTest extends TestCase
{
    private TempDir $temp;
    private FixedClock $clock;
    private StagingArea $staging;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-11-12T03:15:00Z');
        $this->staging = new StagingArea(new DataDirectory($this->temp->path(), 'test'), $this->clock, new Keys(new SeededRandom('tiffindb-staging-test-1')));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testCreateFolderRemove(): void
    {
        [$key, $path] = $this->staging->create('imports');
        self::assertTrue(Keys::isValid($key));
        self::assertSame($this->temp->path('staging/imports/' . $key), $path);
        self::assertSame(Fs::DIR_MODE, fileperms($path) & 0777);
        self::assertTrue(Fs::isDir($path . '/tmp'));
        self::assertTrue(Fs::isFile($path . '/job.lock'));
        self::assertSame($path, $this->staging->folder('imports', $key));
        self::assertNull($this->staging->folder('exports', $key), 'Another kind.');

        [$other] = $this->staging->create('imports');
        self::assertNotSame($key, $other);
        foreach (['../imports', 'ABCDEFGHIJKL', '', $key . '/', "{$key}\0", 'aaaaaaaaaaaa'] as $bad) {
            self::assertNull($this->staging->folder('imports', $bad), $bad);
        }
        self::assertNull($this->staging->folder('projects', $key));

        file_put_contents($path . '/upload.bin', 'synthetic');
        mkdir($path . '/rebuilt/attachments', 0750, true);
        $this->staging->remove('imports', $key);
        self::assertNull($this->staging->folder('imports', $key));
        self::assertFalse(Fs::exists($path));
        $this->staging->remove('imports', $key);
        $this->staging->remove('imports', 'not a key');

        $this->expectException(\InvalidArgumentException::class);
        $this->staging->create('projects');
    }

    public function testAFolderThatIsALinkIsNotAJob(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        $this->staging->create('exports');
        symlink($outside, $this->temp->path('staging/exports/b7xk2mq4nz3p'));
        self::assertNull($this->staging->folder('exports', 'b7xk2mq4nz3p'));
        $this->staging->remove('exports', 'b7xk2mq4nz3p');
        self::assertDirectoryExists($outside);
        self::assertFalse(Fs::exists($this->temp->path('staging/exports/b7xk2mq4nz3p')));
    }

    public function testAStagingRootThatIsALinkIsRefused(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside . '/imports/b7xk2mq4nz3p', 0750, true);
        symlink($outside, $this->temp->path('staging'));
        self::assertNull($this->staging->folder('imports', 'b7xk2mq4nz3p'));
        try {
            $this->staging->create('imports');
            self::fail('A linked staging folder was used.');
        } catch (\RuntimeException) {
            self::assertSame([], array_values(array_diff(scandir($outside . '/imports') ?: [], ['.', '..', 'b7xk2mq4nz3p'])));
        }
        $this->staging->sweep();
        $this->staging->remove('imports', 'b7xk2mq4nz3p');
        self::assertDirectoryExists($outside . '/imports/b7xk2mq4nz3p');
    }

    public function testSweepLeavesALockedJobAlone(): void
    {
        $now = $this->clock->now()->getTimestamp();
        [$key, $path] = $this->staging->create('imports');
        touch($path, $now - StagingArea::MAX_AGE['imports'] - 10);
        $held = fopen($path . '/' . StagingArea::LOCK, 'r');
        self::assertIsResource($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));
        try {
            $this->staging->sweep();
            self::assertSame($path, $this->staging->folder('imports', $key), 'An old job in use is kept.');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        $this->staging->sweep();
        self::assertNull($this->staging->folder('imports', $key), 'Once the lock is released the old job goes.');
    }

    public function testSweepByAgePerKind(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $jobs = [];
        foreach (StagingArea::KINDS as $kind) {
            foreach (['old' => StagingArea::MAX_AGE[$kind] + 1, 'young' => StagingArea::MAX_AGE[$kind] - 1] as $age => $seconds) {
                [$key, $path] = $this->staging->create($kind);
                file_put_contents($path . ($kind === 'uploads' ? '/upload.bin' : '/tmp/x'), 'synthetic');
                touch($path, $now - $seconds);
                $jobs[$kind][$age] = $key;
            }
        }
        // An hour-old import survives; an hour-old export does not.
        [$hourImport, $hourImportPath] = $this->staging->create('imports');
        touch($hourImportPath, $now - 3601);
        mkdir($this->temp->path('staging/imports/not-a-key'));
        touch($this->temp->path('staging/imports/not-a-key'), $now - 999999);

        $this->staging->sweep();
        foreach (StagingArea::KINDS as $kind) {
            self::assertNull($this->staging->folder($kind, $jobs[$kind]['old']), $kind . ' old');
            self::assertNotNull($this->staging->folder($kind, $jobs[$kind]['young']), $kind . ' young');
        }
        self::assertNotNull($this->staging->folder('imports', $hourImport));
        self::assertDirectoryExists($this->temp->path('staging/imports/not-a-key'), 'Entries that are not job keys are left alone.');
        self::assertSame(['imports' => 86400, 'exports' => 3600, 'archives' => 3600, 'uploads' => 3600, 'restores' => 3600, 'deleted' => 3600], StagingArea::MAX_AGE);

        // Sweeping a data folder with no staging area does nothing.
        $empty = TempDir::create();
        try {
            (new StagingArea(new DataDirectory($empty->path(), 'test'), $this->clock, new Keys(new SeededRandom('x'))))->sweep();
            self::assertFalse(Fs::exists($empty->path('staging')));
        } finally {
            $empty->remove();
        }
    }

    public function testUploadsKind(): void
    {
        self::assertContains('uploads', StagingArea::KINDS);
        self::assertSame(3600, StagingArea::MAX_AGE['uploads']);
        [$key, $path] = $this->staging->create('uploads');
        self::assertTrue(Keys::isValid($key));
        self::assertSame($this->temp->path('staging/uploads/' . $key), $path);
        self::assertSame(Fs::DIR_MODE, fileperms($path) & 0777);
        self::assertSame([], Fs::entries($path), 'An upload job folder is made empty; it later holds upload.bin only.');
        self::assertSame($path, $this->staging->folder('uploads', $key));
        self::assertNull($this->staging->folder('imports', $key));

        file_put_contents($path . '/upload.bin', 'synthetic');
        $now = $this->clock->now()->getTimestamp();
        touch($path, $now - 3600);
        $this->staging->sweep();
        self::assertSame($path, $this->staging->folder('uploads', $key), 'Exactly one hour old is kept.');
        touch($path, $now - 3601);
        $this->staging->sweep();
        self::assertNull($this->staging->folder('uploads', $key), 'Older than an hour is swept.');
        self::assertFalse(Fs::exists($path));

        [$second, $secondPath] = $this->staging->create('uploads');
        file_put_contents($secondPath . '/upload.bin', 'synthetic');
        $this->staging->remove('uploads', $second);
        self::assertFalse(Fs::exists($secondPath));
    }

    public function testReserveNamesAFreePathWithoutCreatingIt(): void
    {
        $path = $this->staging->reserve('deleted', 'q7mzk2wbx4ae');
        self::assertSame($this->temp->path('staging/deleted/q7mzk2wbx4ae'), $path);
        self::assertFalse(Fs::exists($path));
        self::assertTrue(Fs::isDir($this->temp->path('staging/deleted')));

        mkdir($path);
        try {
            $this->staging->reserve('deleted', 'q7mzk2wbx4ae');
            self::fail('An existing entry was reserved again.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        symlink($this->temp->path('nothing-here'), $this->temp->path('staging/deleted/b7xk2mq4nz3p'));
        $this->expectException(\RuntimeException::class);
        $this->staging->reserve('deleted', 'b7xk2mq4nz3p');
    }

    public function testReserveRefusesBadKeysKindsAndALinkedKindFolder(): void
    {
        foreach (['../x', '', 'ABCDEFGHIJKL', "q7mzk2wbx4a\0"] as $bad) {
            try {
                $this->staging->reserve('deleted', $bad);
                self::fail('A bad key was reserved: ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->staging->reserve('projects', 'q7mzk2wbx4ae');
            self::fail('An unknown kind was reserved.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $outside = $this->temp->path('outside');
        mkdir($outside);
        mkdir($this->temp->path('staging'));
        symlink($outside, $this->temp->path('staging/deleted'));
        try {
            $this->staging->reserve('deleted', 'q7mzk2wbx4ae');
            self::fail('A linked kind folder was used.');
        } catch (\RuntimeException) {
            self::assertSame(['.', '..'], scandir($outside));
        }
    }

    public function testRestoresJobsAreMadeLikeImports(): void
    {
        [$key, $path] = $this->staging->create('restores');
        self::assertSame($this->temp->path('staging/restores/' . $key), $path);
        self::assertTrue(Fs::isFile($path . '/job.lock'));
        $this->staging->remove('restores', $key);
        self::assertFalse(Fs::exists($path));
    }

    public function testADeletedEntryWhoseLockIsHeldIsKeptUntilReleased(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $entry = $this->staging->reserve('deleted', 'q7mzk2wbx4ae');
        mkdir($entry . '/attachments/9f', 0750, true);
        file_put_contents($entry . '/attachments/9f/synthetic', 'synthetic');
        file_put_contents($entry . '/project.lock', '');
        $holder = LockHolder::start('flock', $entry . '/project.lock');
        try {
            self::assertSame('held', $holder->answer);
            $this->staging->sweep();
            self::assertDirectoryExists($entry, 'A deletion still holds the lock: the entry is kept, however young.');
            touch($entry, $now - 999999);
            $this->staging->sweep();
            self::assertDirectoryExists($entry, 'Nor however old.');
        } finally {
            $holder->stop();
        }
        $this->staging->sweep();
        self::assertFalse(Fs::exists($entry), 'Once the lock is released the entry goes at once, whatever its age.');
    }

    public function testADeletedEntryWithoutALockGoesByItsAge(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $young = $this->staging->reserve('deleted', 'q7mzk2wbx4ae');
        mkdir($young . '/snapshots', 0750, true);
        touch($young, $now - 60);
        $old = $this->staging->reserve('deleted', 'b7xk2mq4nz3p');
        mkdir($old . '/snapshots', 0750, true);
        $outside = $this->temp->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/keep', 'synthetic');
        symlink($outside, $old . '/link');
        touch($old, $now - StagingArea::MAX_AGE['deleted'] - 1);

        $this->staging->sweep();
        self::assertDirectoryExists($young, 'A removal may still be running.');
        self::assertFalse(Fs::exists($old));
        self::assertFileExists($outside . '/keep', 'A link inside is removed, never followed.');
    }
}
