<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Tests\Support\TempDir;

/** T-STO-7: Fs modes, writeNew, remove, nearestExisting, isInside. */
#[CoversClass(Fs::class)]
#[CoversClass(ErrorTrap::class)]
final class FsTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function skipUnlessModesAreKept(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This file system does not keep POSIX permission bits (non-POSIX platform).');
        }
        $probe = $this->temp->path('mode-probe');
        file_put_contents($probe, '');
        chmod($probe, 0604);
        clearstatcache(true, $probe);
        $kept = (fileperms($probe) & 0777) === 0604;
        unlink($probe);
        if (!$kept) {
            self::markTestSkipped('The temporary file system ignores permission bits set with chmod.');
        }
    }

    private function symlinkOrSkip(string $target, string $link): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $made = function_exists('symlink') && symlink($target, $link);
        } finally {
            restore_error_handler();
        }
        if (!$made) {
            self::markTestSkipped('Symbolic links cannot be made in the temporary directory here.');
        }
    }

    public function testMakeDirUsesMode0750(): void
    {
        $this->skipUnlessModesAreKept();
        $old = umask(0077);
        try {
            $dir = $this->temp->path('made');
            Fs::makeDir($dir);
        } finally {
            umask($old);
        }
        self::assertDirectoryExists($dir);
        clearstatcache(true, $dir);
        self::assertSame(0750, fileperms($dir) & 0777);
    }

    public function testMakeDirIsNotRecursive(): void
    {
        $this->expectException(RuntimeException::class);
        Fs::makeDir($this->temp->path('missing/child'));
    }

    public function testMakeDirRefusesAnExistingDirectory(): void
    {
        $dir = $this->temp->path('existing');
        mkdir($dir, 0700);
        $this->expectException(RuntimeException::class);
        Fs::makeDir($dir);
    }

    public function testMakeDirRefusesASymbolicLinkToADirectory(): void
    {
        mkdir($this->temp->path('elsewhere'));
        $this->symlinkOrSkip($this->temp->path('elsewhere'), $this->temp->path('link'));
        $this->expectException(RuntimeException::class);
        Fs::makeDir($this->temp->path('link'));
    }

    public function testMakeDirRefusesADanglingSymbolicLink(): void
    {
        $this->symlinkOrSkip($this->temp->path('not-there'), $this->temp->path('dangling'));
        try {
            Fs::makeDir($this->temp->path('dangling'));
            self::fail('makeDir must refuse a dangling symbolic link.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->temp->path('not-there'));
        }
    }

    public function testMakeDirRefusesANulByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fs::makeDir($this->temp->path("bad\0name"));
    }

    public function testMakeDirRefusesAFile(): void
    {
        $file = $this->temp->path('file');
        file_put_contents($file, 'x');
        $this->expectException(RuntimeException::class);
        Fs::makeDir($file);
    }

    public function testWriteNewUsesMode0640(): void
    {
        $this->skipUnlessModesAreKept();
        $file = $this->temp->path('new.txt');
        $old = umask(0);
        try {
            Fs::writeNew($file, "synthetic content\n");
        } finally {
            umask($old);
        }
        self::assertSame("synthetic content\n", file_get_contents($file));
        clearstatcache(true, $file);
        self::assertSame(0640, fileperms($file) & 0777);
    }

    public function testWriteNewRefusesAnExistingFile(): void
    {
        $file = $this->temp->path('taken.txt');
        file_put_contents($file, 'original');

        try {
            Fs::writeNew($file, 'replacement');
            self::fail('writeNew must refuse an existing file.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($this->temp->path(), $e->getMessage(), 'No path in the message.');
        }
        self::assertSame('original', file_get_contents($file));
    }

    public function testWriteNewRefusesADanglingSymbolicLink(): void
    {
        $target = $this->temp->path('target.txt');
        $link = $this->temp->path('dangling.txt');
        $this->symlinkOrSkip($target, $link);

        $this->expectException(RuntimeException::class);
        try {
            Fs::writeNew($link, 'must not be written through the link');
        } finally {
            self::assertFileDoesNotExist($target);
            self::assertTrue(is_link($link), 'The link itself is left alone.');
        }
    }

    public function testWriteNewRefusesASymbolicLinkToAFile(): void
    {
        $target = $this->temp->path('target.txt');
        file_put_contents($target, 'original');
        $link = $this->temp->path('link.txt');
        $this->symlinkOrSkip($target, $link);

        try {
            Fs::writeNew($link, 'replacement');
            self::fail('writeNew must refuse a symbolic link.');
        } catch (RuntimeException) {
            self::assertSame('original', file_get_contents($target));
        }
    }

    public function testWriteNewRefusesAMissingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        Fs::writeNew($this->temp->path('missing/new.txt'), 'x');
    }

    public function testWriteNewWritesEmptyContent(): void
    {
        $file = $this->temp->path('empty.txt');
        Fs::writeNew($file, '');
        self::assertSame('', file_get_contents($file));
    }

    public function testRemove(): void
    {
        $file = $this->temp->path('gone.txt');
        file_put_contents($file, 'x');
        Fs::remove($file);
        self::assertFileDoesNotExist($file);
        Fs::remove($file);
        Fs::remove($this->temp->path('never/existed'));
        $this->addToAssertionCount(1);
    }

    public function testErrorHandlerIsRestored(): void
    {
        $marker = static fn (): bool => false;
        set_error_handler($marker);
        try {
            try {
                Fs::writeNew($this->temp->path('missing/new.txt'), 'x');
            } catch (RuntimeException) {
                // Expected.
            }
            $current = set_error_handler(null);
            self::assertSame($marker, $current);
        } finally {
            restore_error_handler();
            restore_error_handler();
        }
    }

    public function testRemoveDirRemovesAnEmptyDirectory(): void
    {
        $dir = $this->temp->path('empty');
        mkdir($dir);
        Fs::removeDir($dir);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRemoveDirRefusesANonEmptyDirectory(): void
    {
        $dir = $this->temp->path('full');
        mkdir($dir);
        file_put_contents($dir . '/.hidden', 'x');
        try {
            Fs::removeDir($dir);
            self::fail('removeDir must refuse a directory that is not empty.');
        } catch (RuntimeException) {
            self::assertFileExists($dir . '/.hidden');
        }
    }

    public function testRemoveDirRefusesALinkToADirectory(): void
    {
        $target = $this->temp->path('target');
        mkdir($target);
        $link = $this->temp->path('link');
        $this->symlinkOrSkip($target, $link);
        try {
            Fs::removeDir($link);
            self::fail('removeDir must refuse a symbolic link.');
        } catch (RuntimeException) {
            self::assertTrue(is_link($link));
            self::assertDirectoryExists($target);
        }
    }

    public function testRemoveDirRefusesAFile(): void
    {
        $file = $this->temp->path('file');
        file_put_contents($file, 'x');
        try {
            Fs::removeDir($file);
            self::fail('removeDir must refuse a file.');
        } catch (RuntimeException) {
            self::assertFileExists($file);
        }
    }

    public function testRemoveDirRefusesAMissingPath(): void
    {
        $this->expectException(RuntimeException::class);
        Fs::removeDir($this->temp->path('missing'));
    }

    public function testRemoveDirRefusesANulByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fs::removeDir($this->temp->path() . "\0");
    }

    public function testIsWritableDir(): void
    {
        $file = $this->temp->path('file');
        file_put_contents($file, 'x');
        self::assertTrue(Fs::isWritableDir($this->temp->path()));
        self::assertFalse(Fs::isWritableDir($file));
        self::assertFalse(Fs::isWritableDir($this->temp->path('missing')));
    }

    public function testNearestExisting(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/a');
        self::assertSame($root . '/a', Fs::nearestExisting($root . '/a'));
        self::assertSame($root . '/a', Fs::nearestExisting($root . '/a/b/c'));
        self::assertSame($root, Fs::nearestExisting($root . '/a/..'));
        self::assertSame($root, Fs::nearestExisting($root . '/missing/deeper'));
        self::assertNull(Fs::nearestExisting(''));
        self::assertNull(Fs::nearestExisting($root . '/missing/../a'), '.. beyond the existing part is refused, not dropped.');
        self::assertNull(Fs::nearestExisting($root . '/a/missing/..'));
        self::assertNull(Fs::nearestExisting($root . '/a/missing/./x'));
        self::assertNull(Fs::nearestExisting($root . "/a\0"));
    }

    public function testIsInsideWithDotDot(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/docroot');
        mkdir($root . '/docroot/sub');
        mkdir($root . '/docroot2');
        mkdir($root . '/outside');

        self::assertTrue(Fs::isInside($root . '/docroot', $root . '/docroot'), 'Equal paths are inside.');
        self::assertTrue(Fs::isInside($root . '/docroot/sub', $root . '/docroot'));
        self::assertTrue(Fs::isInside($root . '/docroot/sub/not-yet', $root . '/docroot'), 'A path still to be made.');
        self::assertTrue(Fs::isInside($root . '/outside/../docroot/sub', $root . '/docroot'));
        self::assertFalse(Fs::isInside($root . '/docroot/../outside', $root . '/docroot'));
        self::assertFalse(Fs::isInside($root . '/docroot/sub/../../outside', $root . '/docroot'));
        self::assertFalse(Fs::isInside($root . '/docroot2', $root . '/docroot'), 'A shared prefix is not containment.');
        self::assertFalse(Fs::isInside($root . '/docroot', $root . '/docroot/sub'));
        self::assertFalse(Fs::isInside($root . '/tiffindb-data', $root . '/docroot'), 'A sibling still to be made.');
        self::assertTrue(Fs::isInside($root . '/docroot/sub', $root . '/docroot/./'));
    }

    public function testIsInsideRefusesDotSegmentsBeyondTheExistingPart(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/docroot');
        mkdir($root . '/docroot/sub');
        mkdir($root . '/outside');
        $r = $root . '/docroot';

        self::assertFalse(Fs::isInside("{$r}/nx/../../outside", $r));
        self::assertFalse(Fs::isInside("{$r}/nx/..", $r), 'Even when it would resolve inside.');
        self::assertFalse(Fs::isInside("{$r}/sub/nx/./deeper", $r));
        self::assertFalse(Fs::isInside("{$r}/nx/../../outside", "{$r}/nx/.."));
    }

    public function testIsInsideRefusesNulBytes(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/docroot');
        mkdir($root . '/docroot/sub');
        mkdir($root . '/outside');
        $r = $root . '/docroot';

        self::assertFalse(Fs::isInside("{$r}/sub\0/../../outside", $r));
        self::assertFalse(Fs::isInside("{$r}/sub\0", $r));
        self::assertFalse(Fs::isInside($root . '/outside', "{$r}\0/.."), 'A NUL byte in the root does not widen it.');
        self::assertFalse(Fs::isInside($root . '/outside', "{$root}\0"));
    }

    public function testIsInsideRefusesADanglingLinkInTheNewPart(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/docroot');
        $this->symlinkOrSkip($root . '/outside-not-yet', $root . '/docroot/dangling');
        self::assertFalse(Fs::isInside($root . '/docroot/dangling', $root . '/docroot'));
        self::assertFalse(Fs::isInside($root . '/docroot/dangling/new', $root . '/docroot'));
    }

    public function testIsInsideFollowsSymbolicLinks(): void
    {
        $root = $this->temp->path();
        mkdir($root . '/docroot');
        mkdir($root . '/docroot/sub');
        mkdir($root . '/outside');
        $this->symlinkOrSkip($root . '/outside', $root . '/docroot/link-out');
        $this->symlinkOrSkip($root . '/docroot/sub', $root . '/link-in');
        $this->symlinkOrSkip($root . '/docroot', $root . '/docroot-alias');

        self::assertFalse(Fs::isInside($root . '/docroot/link-out', $root . '/docroot'), 'A link inside pointing out is outside.');
        self::assertFalse(Fs::isInside($root . '/docroot/link-out/new', $root . '/docroot'));
        self::assertTrue(Fs::isInside($root . '/link-in', $root . '/docroot'), 'A link outside pointing in is inside.');
        self::assertTrue(Fs::isInside($root . '/link-in/new', $root . '/docroot'));
        self::assertTrue(Fs::isInside($root . '/docroot/sub', $root . '/docroot-alias'), 'The root may be a link too.');
    }
}
