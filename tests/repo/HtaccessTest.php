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

        // Any dot-separated part after the first counts, so double-extension
        // names such as x.php.png (which some handler set-ups execute) are
        // caught too.
        $found = array_values(array_filter(
            RepoFiles::all('public'),
            static fn (string $path): bool => array_intersect(
                array_map('strtolower', array_slice(explode('.', basename($path)), 1)),
                $executable,
            ) !== [],
        ));

        self::assertSame(['public/index.php'], $found);
    }

    public function testNoOtherHtaccessFiles(): void
    {
        // A subdirectory .htaccess with any mod_rewrite directive replaces the
        // parent's rewrite rules for that subtree, which would switch the root
        // guard off there. Uses the shared repository enumeration (tracked
        // and non-ignored files in a git checkout, otherwise a directory walk).
        $found = array_values(array_filter(
            RepoFiles::all(),
            static fn (string $path): bool => basename($path) === '.htaccess',
        ));

        self::assertSame(['.htaccess', 'public/.htaccess'], $found);
    }
}
