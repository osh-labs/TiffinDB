<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * P76, P78: the 7th constructor arguments of Core\Install\Installer (the
 * system-database factory) and Core\App\Operations (the test seam) are
 * test seams only. No file under src/ or public/ may construct either with
 * more than six arguments.
 *
 * Read with token_get_all: every `new Installer(` or `new Operations(`
 * (any qualification, any case) has its top-level arguments counted;
 * a spread argument (`...$args`) or a named argument is refused too,
 * since it could reach the seam without a 7th position. Known limits:
 * construction through reflection or a variable class name is not seen.
 */
#[CoversNothing]
final class CompositionSeamTest extends TestCase
{
    public const GUARDED = ['installer', 'operations'];
    public const MAX_ARGUMENTS = 6;

    /**
     * Problems in one source, as "Class: reason", in order.
     *
     * @return list<string>
     */
    public static function problems(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $parts = explode('\\', $name[1]);
            $class = strtolower((string) end($parts));
            if (!in_array($class, self::GUARDED, true) || ($tokens[$i + 2] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $arguments = 0;
            $sawToken = false;
            for ($j = $i + 3; $j < $count; $j++) {
                $t = $tokens[$j];
                $text = is_array($t) ? $t[1] : $t;
                if ($depth === 0 && $t === ')') {
                    break;
                }
                if (in_array($text, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                }
                if ($depth === 0 && $t === ',') {
                    $arguments += $sawToken ? 1 : 0;
                    $sawToken = false;
                    continue;
                }
                if ($depth === 0 && is_array($t) && $t[0] === T_ELLIPSIS) {
                    $found[] = "{$class}: spread argument";
                }
                if ($depth === 0 && $t === ':' && is_array($tokens[$j - 1]) && $tokens[$j - 1][0] === T_STRING
                    && in_array($tokens[$j - 2] ?? null, ['(', ','], true)) {
                    $found[] = "{$class}: named argument";
                }
                $sawToken = true;
            }
            $arguments += $sawToken ? 1 : 0;
            if ($arguments > self::MAX_ARGUMENTS) {
                $found[] = "{$class}: {$arguments} arguments";
            }
        }

        return $found;
    }

    /** @return list<string> "path: problem" for every PHP file under src/ and public/. */
    public static function violations(string $root): array
    {
        $found = [];
        foreach (['src', 'public'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($file->isFile() && $file->getExtension() === 'php') {
                    foreach (self::problems((string) file_get_contents($file->getPathname())) as $problem) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $problem;
                    }
                }
            }
        }

        return $found;
    }

    public function testProductionNeverSetsTheSeams(): void
    {
        self::assertSame([], self::violations(RepoFiles::root()));
    }

    public function testTheScanSeesTheCompositionRoot(): void
    {
        $source = RepoFiles::read('src/Web/CoreServices.php');
        self::assertSame(1, preg_match_all('/new Operations\(/', $source));
        self::assertSame(1, preg_match_all('/new Installer\(/', $source));
        self::assertSame([], self::problems($source));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'installer seventh' => ['new Installer($a, $b, $c, $d, $e, $f, $seam);'],
            'operations seventh' => ['new Operations($a, $b, $c, $d, $e, $f, static fn () => null);'],
            'qualified' => ['new \\TiffinDB\\Core\\App\\Operations($a, $b, $c, $d, $e, $f, $g);'],
            'lower case' => ['new installer(1, 2, 3, 4, 5, 6, 7);'],
            'trailing comma after seventh' => ["new Installer(\$a, \$b, \$c, \$d, \$e, \$f, \$g,\n);"],
            'nested calls before' => ['new Operations(f($x, $y), [$a, $b], $c, $d, $e, $f, $g);'],
            'spread' => ['new Installer(...$args);'],
            'named seam' => ['new Operations($a, $b, $c, $d, $e, $f, seam: $s);'],
            'named only' => ['new Installer(createSystem: $s);'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefused(string $code): void
    {
        self::assertNotSame([], self::problems("<?php\n{$code}\n"));
    }

    /** @return array<string, array{string}> */
    public static function allowed(): array
    {
        return [
            'six' => ['new Installer($a, $b, $c, $d, $e, $f);'],
            'six with trailing comma' => ["new Operations(\n\$a,\n\$b,\n\$c,\n\$d,\n\$e,\n\$f,\n);"],
            'nested commas' => ['new Operations(f($a, $b, $c, $d), [$x, $y, $z], $c, $d, $e, $f);'],
            'closure argument' => ['new Installer($a, $b, $c, $d, $e, function ($p, $q) { return [$p, $q]; });'],
            'other class' => ['new Something($a, $b, $c, $d, $e, $f, $g, $h);'],
            'ternary' => ['new Installer($a ? $b : $c, $b, $c, $d, $e, $f);'],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $code): void
    {
        self::assertSame([], self::problems("<?php\n{$code}\n"));
    }
}
