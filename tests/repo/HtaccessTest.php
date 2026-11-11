<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-REPO-3: static checks of the two Apache .htaccess files and of the web
 * root's PHP entry points. Real-server behaviour is verified by the host
 * check and the M1 installer, not here.
 */
#[CoversNothing]
final class HtaccessTest extends TestCase
{
    /** @return list<string> Lines without comments and blank lines, trimmed. */
    private static function directives(string $relative): array
    {
        $lines = preg_split('/\r\n|\n|\r/', RepoFiles::read($relative));
        self::assertIsArray($lines);

        $directives = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $directives[] = $line;
            }
        }

        return $directives;
    }

    /** @param list<string> $directives */
    private static function countMatching(array $directives, string $pattern): int
    {
        return count(preg_grep($pattern, $directives) ?: []);
    }

    public function testBothFilesExist(): void
    {
        self::assertFileExists(RepoFiles::path('.htaccess'));
        self::assertFileExists(RepoFiles::path('public/.htaccess'));
    }

    public function testWebRootRoutesToTheFrontController(): void
    {
        $directives = self::directives('public/.htaccess');

        self::assertContains('RewriteEngine On', $directives);
        self::assertContains('RewriteRule ^ index.php [L]', $directives);
        // Existing files are served directly; the index.php rule comes last.
        self::assertContains('RewriteCond %{REQUEST_FILENAME} -f', $directives);
        $rules = array_values(preg_grep('/^RewriteRule\b/i', $directives) ?: []);
        self::assertSame('RewriteRule ^ index.php [L]', end($rules));
    }

    public function testWebRootHasNoOptionsDirective(): void
    {
        self::assertSame(0, self::countMatching(self::directives('public/.htaccess'), '/^Options\b/i'));
    }

    public function testRootGuardRewritesEverythingIntoPublic(): void
    {
        $directives = self::directives('.htaccess');

        self::assertSame(1, self::countMatching($directives, '/^RewriteRule\b/i'), 'Exactly one RewriteRule.');
        self::assertSame(0, self::countMatching($directives, '/^RewriteCond\b/i'), 'No RewriteCond: no path is exempt.');
        // The whole directive list is fixed, so the rule cannot be wrapped in
        // a narrowing container (<If>, <FilesMatch>, ...) or followed by
        // RewriteEngine Off, RewriteOptions, or anything else.
        self::assertSame(
            [
                '<IfModule mod_rewrite.c>',
                'RewriteEngine On',
                'RewriteRule ^(.*)$ public/$1 [L]',
                '</IfModule>',
                '<IfModule !mod_rewrite.c>',
                'Require all denied',
                '</IfModule>',
            ],
            $directives,
        );
    }

    public function testRootGuardDeniesWithoutModRewrite(): void
    {
        $contents = RepoFiles::read('.htaccess');

        self::assertMatchesRegularExpression(
            '/<IfModule\s+!mod_rewrite\.c>\s*Require all denied\s*<\/IfModule>/',
            $contents,
        );
    }

    public function testWebRootContainsExactlyOnePhpFile(): void
    {
        // Every extension a host might hand to the PHP interpreter, not only
        // *.php. Passes once the front controller (WP5) exists; must not be
        // weakened.
        $executable = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'pht', 'inc'];

        self::assertSame(['public/index.php'], RepoFiles::withExtension($executable, 'public'));
    }

    public function testNoOtherHtaccessFiles(): void
    {
        // A subdirectory .htaccess with any mod_rewrite directive replaces the
        // parent's rewrite rules for that subtree, which would switch the root
        // guard off there. Checked in the working tree and, where git is
        // available, in the tracked files.
        $root = RepoFiles::root();
        $skip = ['.git', '.claude', 'vendor'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => dirname($file->getPathname()) !== $root
                    || !in_array($file->getFilename(), $skip, true),
            ),
        );

        $found = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getFilename() === '.htaccess') {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        foreach (self::trackedFiles() as $tracked) {
            if (basename($tracked) === '.htaccess') {
                $found[] = $tracked;
            }
        }
        $found = array_values(array_unique($found));
        sort($found, SORT_STRING);

        self::assertSame(['.htaccess', 'public/.htaccess'], $found);
    }

    /**
     * Tracked paths from `git ls-files`, or an empty list when the tree is
     * not a git checkout or git is unavailable. Local and offline.
     *
     * @return list<string>
     */
    private static function trackedFiles(): array
    {
        $process = proc_open(
            ['git', 'ls-files', '-z'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            RepoFiles::root(),
        );
        if (!is_resource($process)) {
            return [];
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return [];
        }

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }
}
