<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-REPO-5: every PHP file passes `php -l` (the same file set as
 * tools/dev/lint.php) and declares `strict_types=1` as its first statement,
 * except the standalone scripts directly under tools/discovery/ and
 * tools/host-check/ (M0 spec section 2.2), which are linted only.
 * tools/host-check/host-check.php keeps to syntax that old PHP versions
 * parse; this test never asks it to declare strict types.
 */
#[CoversNothing]
final class PhpSourceRulesTest extends TestCase
{
    /** Directories whose direct children are exempt from the strict_types rule. */
    public const STRICT_TYPES_EXEMPT_DIRECTORIES = ['tools/discovery', 'tools/host-check'];

    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            foreach (glob($this->temp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->temp);
            $this->temp = null;
        }
    }

    // ---------------------------------------------------------------- rules

    public static function isStrictTypesExempt(string $path): bool
    {
        return in_array(dirname($path), self::STRICT_TYPES_EXEMPT_DIRECTORIES, true);
    }

    /** Output of `php -l` when the file fails, or null when it passes. */
    public static function lintError(string $absolutePath): ?string
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $absolutePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the PHP binary.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? null : trim((string) $output);
    }

    /** Whether the first statement of the source is `declare(strict_types=1);`. */
    public static function declaresStrictTypes(string $source): bool
    {
        $tokens = token_get_all($source);
        $significant = [];
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                // A "#!" line before the open tag is inline text to the tokenizer.
                if ($index === 0 && $token[0] === T_INLINE_HTML && str_starts_with($token[1], '#!')) {
                    continue;
                }
                $significant[] = strtolower($token[1]);
            } else {
                $significant[] = $token;
            }
            if (count($significant) === 7) {
                break;
            }
        }

        return $significant === ['declare', '(', 'strict_types', '=', '1', ')', ';'];
    }

    // ------------------------------------------------------- the repository

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        foreach (RepoFiles::withExtension(['php']) as $path) {
            yield $path => [$path];
        }
    }

    public function testThereArePhpFilesToCheck(): void
    {
        $files = RepoFiles::withExtension(['php']);
        self::assertContains('tools/dev/lint.php', $files);
        self::assertContains('tools/host-check/host-check.php', $files);
        self::assertContains('tools/discovery/discover-structure.php', $files);
    }

    #[DataProvider('phpFiles')]
    public function testFilePassesLint(string $path): void
    {
        self::assertNull(self::lintError(RepoFiles::path($path)), "{$path} must pass php -l.");
    }

    #[DataProvider('phpFiles')]
    public function testFileDeclaresStrictTypesUnlessExempt(string $path): void
    {
        if (self::isStrictTypesExempt($path)) {
            // Standalone maintainer script: linted above, strict_types optional.
            self::assertContains(dirname($path), self::STRICT_TYPES_EXEMPT_DIRECTORIES);

            return;
        }

        self::assertTrue(
            self::declaresStrictTypes(RepoFiles::read($path)),
            "{$path} must declare strict_types=1 as its first statement.",
        );
    }

    public function testExemptionCoversOnlyTheStandaloneScripts(): void
    {
        self::assertSame(['tools/discovery', 'tools/host-check'], self::STRICT_TYPES_EXEMPT_DIRECTORIES);
        self::assertTrue(self::isStrictTypesExempt('tools/host-check/host-check.php'));
        self::assertTrue(self::isStrictTypesExempt('tools/discovery/discover-structure.php'));
        self::assertFalse(self::isStrictTypesExempt('tools/dev/lint.php'));
        self::assertFalse(self::isStrictTypesExempt('tools/host-check/lib/helper.php'), 'Only direct children are exempt.');
        self::assertFalse(self::isStrictTypesExempt('tests/tools/HostCheckTest.php'));
        self::assertFalse(self::isStrictTypesExempt('src/tools/discovery.php'));
    }

    // ------------------------------------------------------ fixtures: lint

    public function testLintDetectsASyntaxError(): void
    {
        $bad = $this->writeTemp('broken.php', "<?php\ndeclare(strict_types=1);\nfunction (\n");
        self::assertNotNull(self::lintError($bad));

        $good = $this->writeTemp('fine.php', "<?php\ndeclare(strict_types=1);\n\nreturn [];\n");
        self::assertNull(self::lintError($good));
    }

    // ------------------------------------------------ fixtures: strict_types

    private const HEADER = "<?php\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n * Copyright (C) TiffinDB contributors\n */\n";

    /** @return iterable<string, array{string, bool}> */
    public static function strictTypesCases(): iterable
    {
        yield 'class file' => [self::HEADER . "\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Web;\n", true];
        yield 'template' => [self::HEADER . "\ndeclare(strict_types=1);\n\n?>\n<p><?= \$this->e('x') ?></p>\n", true];
        yield 'executable script' => ["#!/usr/bin/env php\n" . self::HEADER . "\ndeclare(strict_types=1);\n", true];
        yield 'upper-case keyword' => [self::HEADER . "\nDECLARE(strict_types=1);\n", true];
        yield 'spaces inside' => [self::HEADER . "\ndeclare( strict_types = 1 );\n", true];

        yield 'missing' => [self::HEADER . "\nnamespace TiffinDB\\Web;\n", false];
        yield 'set to zero' => [self::HEADER . "\ndeclare(strict_types=0);\n", false];
        yield 'not the first statement' => [self::HEADER . "\nnamespace TiffinDB\\Web;\ndeclare(strict_types=1);\n", false];
        yield 'only inside a comment' => [self::HEADER . "\n// declare(strict_types=1);\n", false];
        yield 'only inside a string' => [self::HEADER . "\n\$x = 'declare(strict_types=1);';\n", false];
        yield 'other directive' => [self::HEADER . "\ndeclare(ticks=1);\n", false];
        yield 'block form' => [self::HEADER . "\ndeclare(strict_types=1) {\n}\n", false];
        yield 'markup before the code' => ["<p>x</p>\n" . self::HEADER . "declare(strict_types=1);\n", false];
        yield 'empty file' => ['', false];
    }

    #[DataProvider('strictTypesCases')]
    public function testStrictTypesDetection(string $source, bool $expected): void
    {
        self::assertSame($expected, self::declaresStrictTypes($source));
    }

    // -------------------------------------------------------------- helpers

    private function writeTemp(string $name, string $contents): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-php-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }
        $path = $this->temp . '/' . $name;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write {$name}.");
        }

        return $path;
    }
}
