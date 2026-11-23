<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tools\Release\ArchiveBuilder;
use TiffinDB\Tools\Release\ReleaseRules;
use ZipArchive;

require_once __DIR__ . '/../../tools/release/ReleaseRules.php';
require_once __DIR__ . '/../../tools/release/ArchiveBuilder.php';

/**
 * Reviewer checks for M7-WP6 (synthetic fixtures only): a package folder
 * that is not a shipped row never ships, a linked package folder is refused,
 * and every entry the archive writer produces sits below the top folder with
 * a safe path (no zip-slip entry).
 */
#[CoversNothing]
final class ReleaseReviewTest extends TestCase
{
    private const RECORD = "# Dependencies\n\n## Runtime dependencies (shipped)\n\n"
        . "| Name | Version | Commit | Purpose | Licence | Licence files (SHA-256) | Shipped |\n|---|---|---|---|---|---|---|\n"
        . "| example/pkg | 1.0.0 | 0123456789abcdef0123456789abcdef01234567 | Example | MIT | LICENSE (MIT) "
        . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa | yes |' . "\n";

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/tiffindb-release-review-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/vendor/composer', 0700, true);
        mkdir($this->dir . '/vendor/example/pkg/src', 0700, true);
        mkdir($this->dir . '/vendor/other/extra/src', 0700, true);
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->dir . '/vendor/composer/LICENSE', "MIT\n");
        file_put_contents($this->dir . '/vendor/example/pkg/LICENSE', "MIT\n");
        file_put_contents($this->dir . '/vendor/example/pkg/src/A.php', "<?php\n");
        file_put_contents($this->dir . '/vendor/other/extra/src/B.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testAPackageFolderThatIsNotAShippedRowNeverShips(): void
    {
        $files = ReleaseRules::vendorFiles($this->dir . '/vendor', self::RECORD);

        self::assertContains('vendor/example/pkg/src/A.php', $files);
        foreach ($files as $file) {
            self::assertStringStartsNotWith('vendor/other/', $file);
        }
    }

    public function testALinkedPackageFolderIsListedAndThenRefused(): void
    {
        $vendor = $this->dir . '/vendor';
        rename($vendor . '/example/pkg', $this->dir . '/pkg-elsewhere');
        symlink($this->dir . '/pkg-elsewhere', $vendor . '/example/pkg');

        $entries = [];
        foreach (ReleaseRules::vendorFiles($vendor, self::RECORD) as $path) {
            $entries[$path] = $vendor . '/' . substr($path, strlen('vendor/'));
        }
        self::assertArrayHasKey('vendor/example/pkg', $entries);

        mkdir($this->dir . '/out');
        try {
            ArchiveBuilder::write($entries, $this->dir . '/out', '1.0.0');
            self::fail('A linked package folder was archived.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }
        self::assertSame([], glob($this->dir . '/out/*') ?: []);
    }

    public function testEveryArchiveEntryIsASafePathBelowTheTopFolder(): void
    {
        $vendor = $this->dir . '/vendor';
        $entries = [];
        foreach (ReleaseRules::vendorFiles($vendor, self::RECORD) as $path) {
            $entries[$path] = $vendor . '/' . substr($path, strlen('vendor/'));
        }
        mkdir($this->dir . '/out');
        ArchiveBuilder::write($entries, $this->dir . '/out', '1.0.0');

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->dir . '/out/tiffindb-1.0.0.zip', ZipArchive::RDONLY));
        self::assertGreaterThan(0, $zip->numFiles);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            self::assertStringStartsWith('tiffindb-1.0.0/', $name);
            $below = rtrim(substr($name, strlen('tiffindb-1.0.0/')), '/');
            if ($below !== '') {
                self::assertTrue(ReleaseRules::safePath($below), $name);
            }
            self::assertSame('', (string) $zip->getCommentIndex($i));
        }
        self::assertSame('', (string) $zip->getArchiveComment());
        $zip->close();
    }
}
