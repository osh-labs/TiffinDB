<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\LocksUnavailable;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Tests\Support\LockHolder;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-ADM-1 to T-ADM-3 (M7 spec sections 4.1 and 16.1): the project lock
 * between two processes, and a lock file that is not a regular file. The
 * waits are shortened through ProjectLock::$waitScaleForTests.
 */
#[CoversClass(ProjectLock::class)]
#[CoversClass(LocksUnavailable::class)]
final class ProjectLockTest extends TestCase
{
    private TempDir $temp;
    private string $folder;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->folder = $this->temp->path('projects/q7mzk2wbx4ae');
        mkdir($this->folder, 0750, true);
        ProjectLock::$waitScaleForTests = 0.02;
    }

    protected function tearDown(): void
    {
        ProjectLock::$waitScaleForTests = 1.0;
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        $this->temp->remove();
    }

    private function hold(string $mode, ?string $path = null): LockHolder
    {
        $holder = LockHolder::start($mode, $path ?? $this->folder);
        $this->holders[] = $holder;

        return $holder;
    }

    public function testTheConstants(): void
    {
        self::assertSame('project.lock', ProjectLock::FILE);
        self::assertSame(2000, ProjectLock::SHARED_WAIT_MS);
        self::assertSame(10000, ProjectLock::EXCLUSIVE_WAIT_MS);
        self::assertSame(50, ProjectLock::STEP_MS);
    }

    public function testTheLockFileIsCreatedEmptyWithMode0640AndNeverTruncated(): void
    {
        $lock = ProjectLock::shared($this->folder);
        self::assertNotNull($lock);
        self::assertTrue($lock->isHeld());
        self::assertFalse($lock->exclusive);
        $path = $this->folder . '/project.lock';
        self::assertSame('', file_get_contents($path));
        clearstatcache(true, $path);
        self::assertSame(Fs::FILE_MODE, fileperms($path) & 0777);
        $lock->release();
        self::assertFalse($lock->isHeld());
        $lock->release();

        file_put_contents($path, 'synthetic');
        ProjectLock::exclusive($this->folder)->release();
        self::assertSame('synthetic', file_get_contents($path), 'Opening never truncates.');
    }

    // ------------------------------------------------------------ T-ADM-1

    public function testSharedLocksInTwoProcessesAndExclusiveIsBusyMeanwhile(): void
    {
        self::assertSame('held', $this->hold('shared')->answer);
        $mine = ProjectLock::shared($this->folder);
        self::assertNotNull($mine, 'A second shared lock is granted at once.');
        $mine->release();

        $started = hrtime(true);
        try {
            ProjectLock::exclusive($this->folder);
            self::fail('An exclusive lock was granted while another process holds a shared one.');
        } catch (Busy) {
            $waitedMs = (hrtime(true) - $started) / 1_000_000;
            self::assertGreaterThanOrEqual(ProjectLock::EXCLUSIVE_WAIT_MS * ProjectLock::$waitScaleForTests - 1, $waitedMs, 'It waited for the scaled wait.');
        }
        self::assertNull(ProjectLock::tryExclusive($this->folder), 'tryExclusive does not wait.');
    }

    public function testExclusiveIsGrantedOnceTheOtherProcessReleases(): void
    {
        $holder = $this->hold('shared');
        $holder->stop();
        $lock = ProjectLock::exclusive($this->folder);
        self::assertTrue($lock->exclusive);
        $lock->release();
    }

    // ------------------------------------------------------------ T-ADM-2

    public function testSharedIsBusyWhileAnotherProcessHoldsExclusiveAndGrantedAfter(): void
    {
        $holder = $this->hold('exclusive');
        self::assertSame('held', $holder->answer);
        $this->expectBusy(fn () => ProjectLock::shared($this->folder));
        $this->expectBusy(fn () => ProjectLock::exclusive($this->folder));
        self::assertSame('busy', $this->hold('shared')->answer, 'Another process is refused too.');

        $holder->stop();
        $lock = ProjectLock::shared($this->folder);
        self::assertNotNull($lock);
        $lock->release();
    }

    public function testAnExclusiveLockHereKeepsAnotherProcessOut(): void
    {
        $lock = ProjectLock::exclusive($this->folder);
        self::assertSame('busy', $this->hold('shared')->answer);
        $lock->release();
        self::assertSame('held', $this->hold('shared')->answer);
    }

    public function testDestroyingTheLockReleasesIt(): void
    {
        $lock = ProjectLock::exclusive($this->folder);
        unset($lock);
        self::assertSame('held', $this->hold('exclusive')->answer);
    }

    // ------------------------------------------------------------ T-ADM-3

    public function testASymbolicLinkAtTheLockFileIsNeverFollowed(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        symlink($outside . '/project.lock', $this->folder . '/project.lock');

        $this->expectUnavailable(fn () => ProjectLock::exclusive($this->folder));
        $this->expectUnavailable(fn () => ProjectLock::tryExclusive($this->folder));
        self::assertNull(ProjectLock::shared($this->folder));
        self::assertSame(['.', '..'], scandir($outside), 'Nothing is created through the link.');
        self::assertSame('unavailable', $this->hold('exclusive')->answer);

        file_put_contents($outside . '/project.lock', '');
        $this->expectUnavailable(fn () => ProjectLock::exclusive($this->folder));
        self::assertNull(ProjectLock::shared($this->folder), 'A link to a real file is refused as well.');
    }

    public function testAFolderAtTheLockFileIsRefused(): void
    {
        mkdir($this->folder . '/project.lock');
        $this->expectUnavailable(fn () => ProjectLock::exclusive($this->folder));
        self::assertNull(ProjectLock::shared($this->folder));
    }

    public function testALinkedProjectFolderIsRefusedAndNothingIsWrittenWhereItPoints(): void
    {
        $outside = $this->temp->path('outside');
        mkdir($outside);
        $linked = $this->temp->path('projects/b7xk2mq4nz3p');
        symlink($outside, $linked);
        $this->expectUnavailable(fn () => ProjectLock::exclusive($linked));
        self::assertNull(ProjectLock::shared($linked));
        self::assertSame(['.', '..'], scandir($outside));
    }

    public function testAMissingFolderIsNotALockFailure(): void
    {
        $missing = $this->temp->path('projects/m2kq7vn3xbpa');
        self::assertNull(ProjectLock::shared($missing));
        $this->expectUnavailable(fn () => ProjectLock::exclusive($missing));
        self::assertFalse(Fs::exists($missing), 'Nothing is created.');
    }

    private function expectBusy(callable $work): void
    {
        try {
            $work();
            self::fail('Busy was expected.');
        } catch (Busy) {
            $this->addToAssertionCount(1);
        }
    }

    private function expectUnavailable(callable $work): void
    {
        try {
            $work();
            self::fail('LocksUnavailable was expected.');
        } catch (LocksUnavailable) {
            $this->addToAssertionCount(1);
        }
    }
}
