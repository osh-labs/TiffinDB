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
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

/**
 * ADR 0008 decision 6: no error-suppression operator (`@`) in application
 * code: every PHP file under src/, plugins/, and templates/, and (M7 spec
 * part 1 section 15.1, CF7-4) every PHP file under tools/. Read with
 * token_get_all: PHP has no T_AT token, the operator is the one-character
 * token `@`. An `@` inside a string, a comment, a docblock (`@param`), a
 * heredoc, or the HTML of a template is part of another token and is not
 * counted.
 */
#[CoversNothing]
final class NoErrorSuppressionTest extends TestCase
{
    public const DIRECTORIES = ['src', 'plugins', 'templates', 'tools'];

    /**
     * Line numbers of every `@` operator in $source.
     *
     * @return list<int>
     */
    public static function suppressions(string $source): array
    {
        $lines = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");
                continue;
            }
            if ($token === '@') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function testNoApplicationFileSuppressesErrors(): void
    {
        $found = [];
        $count = 0;
        foreach (self::DIRECTORIES as $directory) {
            foreach (RepoFiles::withExtension(['php'], $directory) as $path) {
                $count++;
                foreach (self::suppressions(RepoFiles::read($path)) as $line) {
                    $found[] = $path . ':' . $line;
                }
            }
        }
        self::assertGreaterThan(100, $count, 'The scan reads the application files.');
        self::assertSame([], $found, 'Error-suppression operators (ADR 0008 decision 6).');
    }

    /** T-CF7-4: the scan reaches both tools that used the operator, and every other PHP file under tools/. */
    public function testTheScanCoversTheTools(): void
    {
        $tools = RepoFiles::withExtension(['php'], 'tools');
        foreach (['tools/host-check/host-check.php', 'tools/discovery/discover-structure.php', 'tools/admin/reset-password.php'] as $path) {
            self::assertContains($path, $tools);
        }
        foreach ($tools as $path) {
            self::assertSame([], self::suppressions(RepoFiles::read($path)), $path);
        }
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function samples(): iterable
    {
        yield 'call' => ["<?php\n\$h = @fopen('x', 'rb');\n", [2]];
        yield 'static call and spaces' => ["<?php\n\$a = 1;\n\$b = @ \\Foo::bar();\n", [3]];
        yield 'two on one line' => ["<?php\n@unlink(\$a); @rmdir(\$b);\n", [2, 2]];
        yield 'inside a template' => ["<p>a@example.com</p>\n<?= @\$value ?>\n", [2]];
        yield 'in strings and comments' => ["<?php\n// @fopen(\n/** @param string \$x */\n\$m = 'user@example.com' . \"@{\$x}\";\n# @x\n", []];
        yield 'in a heredoc' => ["<?php\n\$t = <<<TXT\n@fopen(\$x)\nTXT;\n", []];
        yield 'in an attribute' => ["<?php\n#[Example('@a')]\nfinal class A {}\n", []];
        yield 'in template html' => ["<a href=\"mailto:user@example.com\">@home</a>\n", []];
    }

    /** @param list<int> $lines */
    #[DataProvider('samples')]
    public function testTheScanFindsExactlyTheOperator(string $source, array $lines): void
    {
        self::assertSame($lines, self::suppressions($source));
    }

    public function testMutationAScratchFileWithSuppressionIsCaught(): void
    {
        $temp = TempDir::create();
        try {
            $file = $temp->path('Scratch.php');
            file_put_contents($file, "<?php\n/** @internal */\nfunction scratch(string \$p): mixed\n{\n    return @fopen(\$p, 'rb');\n}\n");
            self::assertSame([5], self::suppressions((string) file_get_contents($file)));
        } finally {
            $temp->remove();
        }
    }
}
