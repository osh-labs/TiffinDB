<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-CF-4: Fs::entries and the stat helpers isFile, isDir, size, and exists,
 * none of which follows a symbolic link. Synthetic files only.
 */
#[CoversClass(Fs::class)]
final class FsEntriesTest extends TestCase
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

    public function testEntriesAreSortedWithoutDotAndDotDot(): void
    {
        $dir = $this->temp->path('list');
        mkdir($dir);
        foreach (['b.txt', 'a.txt', 'Z.txt', '.hidden', '10', '9'] as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        mkdir($dir . '/sub');

        self::assertSame(['.hidden', '10', '9', 'Z.txt', 'a.txt', 'b.txt', 'sub'], Fs::entries($dir));
        self::assertSame([], Fs::entries($dir . '/sub'));
    }

    public function testEntriesListLinksByNameWithoutFollowingThem(): void
    {
        $dir = $this->temp->path('list');
        $outside = $this->temp->path('outside');
        mkdir($dir);
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'x');
        $this->symlinkOrSkip($outside, $dir . '/linked');
        $this->symlinkOrSkip($this->temp->path('missing'), $dir . '/dangling');

        self::assertSame(['dangling', 'linked'], Fs::entries($dir));
    }

    public function testEntriesRefuseALinkedDirectory(): void
    {
        $real = $this->temp->path('real');
        mkdir($real);
        file_put_contents($real . '/a.txt', 'x');
        $this->symlinkOrSkip($real, $this->temp->path('link'));

        $this->expectException(RuntimeException::class);
        Fs::entries($this->temp->path('link'));
    }

    public function testEntriesRefuseAMissingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        Fs::entries($this->temp->path('missing'));
    }

    public function testEntriesRefuseAFile(): void
    {
        file_put_contents($this->temp->path('file.txt'), 'x');

        $this->expectException(RuntimeException::class);
        Fs::entries($this->temp->path('file.txt'));
    }

    public function testEntriesRefuseANulByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fs::entries($this->temp->path('a') . "\0b");
    }

    public function testStatHelpersOnPlainFilesAndDirectories(): void
    {
        $file = $this->temp->path('file.txt');
        $empty = $this->temp->path('empty.txt');
        $dir = $this->temp->path('dir');
        file_put_contents($file, 'abcde');
        file_put_contents($empty, '');
        mkdir($dir);
        $missing = $this->temp->path('missing');

        self::assertSame([true, false, true], [Fs::isFile($file), Fs::isDir($file), Fs::exists($file)]);
        self::assertSame([false, true, true], [Fs::isFile($dir), Fs::isDir($dir), Fs::exists($dir)]);
        self::assertSame([false, false, false], [Fs::isFile($missing), Fs::isDir($missing), Fs::exists($missing)]);
        self::assertSame(5, Fs::size($file));
        self::assertSame(0, Fs::size($empty));

        file_put_contents($file, 'abcdefgh');
        self::assertSame(8, Fs::size($file), 'The size is read fresh, not from the stat cache.');
        unlink($file);
        self::assertFalse(Fs::isFile($file), 'A removed file is seen at once.');
    }

    public function testStatHelpersDoNotFollowLinks(): void
    {
        $file = $this->temp->path('file.txt');
        $dir = $this->temp->path('dir');
        file_put_contents($file, 'abcde');
        mkdir($dir);
        $this->symlinkOrSkip($file, $this->temp->path('file-link'));
        $this->symlinkOrSkip($dir, $this->temp->path('dir-link'));
        $this->symlinkOrSkip($this->temp->path('missing'), $this->temp->path('dangling'));

        foreach (['file-link', 'dir-link', 'dangling'] as $name) {
            $path = $this->temp->path($name);
            self::assertFalse(Fs::isFile($path), $name);
            self::assertFalse(Fs::isDir($path), $name);
            self::assertTrue(Fs::exists($path), $name);
            try {
                Fs::size($path);
                self::fail("size() followed {$name}.");
            } catch (RuntimeException) {
                // Expected.
            }
        }
    }

    public function testSizeRefusesWhatIsNotARegularFile(): void
    {
        mkdir($this->temp->path('dir'));
        foreach (['dir', 'missing'] as $name) {
            try {
                Fs::size($this->temp->path($name));
                self::fail("size() accepted {$name}.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNulBytesAndEmptyPaths(): void
    {
        $file = $this->temp->path('file.txt');
        file_put_contents($file, 'x');
        foreach (['', $file . "\0"] as $path) {
            self::assertFalse(Fs::isFile($path));
            self::assertFalse(Fs::isDir($path));
            self::assertFalse(Fs::exists($path));
        }
        $this->expectException(InvalidArgumentException::class);
        Fs::size($file . "\0");
    }
}
