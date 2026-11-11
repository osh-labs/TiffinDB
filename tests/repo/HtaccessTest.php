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
        self::assertContains('RewriteEngine On', $directives);
        self::assertContains('RewriteRule ^(.*)$ public/$1 [L]', $directives);
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
        // Passes once the front controller (WP5) exists; must not be weakened.
        self::assertSame(['public/index.php'], RepoFiles::withExtension(['php'], 'public'));
    }
}
