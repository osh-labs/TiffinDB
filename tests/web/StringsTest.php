<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Strings;
use UnexpectedValueException;

/** T-WEB-4 (strings): lookup, placeholders, missing keys; T-WEB-19: the parts and the specified texts. */
#[CoversClass(Strings::class)]
final class StringsTest extends TestCase
{
    private static function strings(): Strings
    {
        return new Strings(__DIR__ . '/fixtures/strings/en.php');
    }

    public function testLookup(): void
    {
        self::assertSame('Page not found', self::strings()->get('error.404.title'));
        self::assertTrue(self::strings()->has('app.name'));
        self::assertFalse(self::strings()->has('no.such.key'));
    }

    public function testPlaceholdersAreReplaced(): void
    {
        self::assertSame('Records - TiffinDB', self::strings()->get('shell.title', ['title' => 'Records']));
        self::assertSame('1 and 2.5', self::strings()->get('test.two', ['a' => 1, 'b' => 2.5]));
    }

    public function testValuesAreNotRescannedForPlaceholders(): void
    {
        self::assertSame('{b} and x', self::strings()->get('test.two', ['a' => '{b}', 'b' => 'x']));
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::strings()->get('no.such.key');
    }

    public function testMissingParameterThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::strings()->get('test.two', ['a' => 'x']);
    }

    public function testNonScalarParameterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::strings()->get('shell.title', ['title' => ['x']]);
    }

    public function testFormatEscapesTextAndValues(): void
    {
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        self::assertSame(
            '&lt;b&gt;&lt;i&gt;&amp;&quot;&apos;&lt;/b&gt;',
            self::strings()->format('test.markup', ['x' => '<i>&"\''], $escape),
        );
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new Strings(__DIR__ . '/fixtures/strings/none.php'))->get('app.name');
    }

    public function testFileIsLoadedLazily(): void
    {
        // Constructing with a missing file does not fail until first use.
        $strings = new Strings(__DIR__ . '/fixtures/strings/none.php');
        self::assertInstanceOf(Strings::class, $strings);
    }

    // T-WEB-19: strings/en.php combines the parts in strings/en/ (P40).

    /**
     * A copy of the real strings/en.php in a temporary directory with the
     * given parts in en/, so the real merging code runs on test parts.
     *
     * @param array<string, string> $parts File name => PHP source.
     */
    private function mergeInTemp(array $parts): mixed
    {
        $dir = sys_get_temp_dir() . '/tiffindb-strings-test-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;
        mkdir($dir . '/en', 0700, true);
        copy(RepoFiles::path('strings/en.php'), $dir . '/en.php');
        foreach ($parts as $name => $source) {
            file_put_contents($dir . '/en/' . $name, $source);
        }

        return (static fn (string $file): mixed => require $file)($dir . '/en.php');
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/en/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($dir . '/en')) {
                rmdir($dir . '/en');
            }
            if (is_file($dir . '/en.php')) {
                unlink($dir . '/en.php');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    private static function part(array $strings): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($strings, true) . ";\n";
    }

    public function testRealPartsAreMerged(): void
    {
        $merged = require RepoFiles::path('strings/en.php');
        self::assertIsArray($merged);

        $expected = [];
        $files = glob(RepoFiles::path('strings/en') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        self::assertContains(RepoFiles::path('strings/en/shell.php'), $files);
        foreach ($files as $file) {
            $part = require $file;
            self::assertIsArray($part);
            $expected += $part;
        }
        self::assertSame($expected, $merged);
        self::assertSame('TiffinDB', (new Strings(RepoFiles::path('strings/en.php')))->get('app.name'));
    }

    public function testPartsAreMergedInFileNameOrder(): void
    {
        $merged = $this->mergeInTemp([
            'b.php' => self::part(['b.one' => 'B']),
            'a.php' => self::part(['a.one' => 'A', 'a.two' => 'A2']),
            'notes.txt' => 'not a part',
        ]);

        self::assertSame(['a.one' => 'A', 'a.two' => 'A2', 'b.one' => 'B'], $merged);
    }

    public function testADuplicateKeyAcrossPartsThrowsNamingTheKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate string key shared\.key in b\.php/');
        $this->mergeInTemp([
            'a.php' => self::part(['shared.key' => 'A', 'a.only' => 'A']),
            'b.php' => self::part(['b.only' => 'B', 'shared.key' => 'B']),
        ]);
    }

    public function testAPartThatIsNotAMapThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->mergeInTemp(['a.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn ['no key'];\n"]);
    }

    public function testNoPartsThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->mergeInTemp([]);
    }

    public function testProblemStringsAreExactlyTheSpecification(): void
    {
        $spec = RepoFiles::read('docs/specs/M1-foundation.md');
        $start = strpos($spec, "\n## 12. Message keys produced by the core");
        $end = strpos($spec, "\n## 13. ");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        preg_match_all('/^\| `(problem\.[^`]+)` \| (.*) \|$/m', substr($spec, $start, $end - $start), $rows, PREG_SET_ORDER);
        $expected = [];
        foreach ($rows as [, $key, $text]) {
            $expected[$key] = $text;
        }
        self::assertCount(58, $expected, 'The specification table was read.');

        $problems = require RepoFiles::path('strings/en/problems.php');
        self::assertSame($expected, $problems);
    }

    public function testShellAndErrorStringsAreTheSpecification(): void
    {
        $spec = RepoFiles::read('docs/specs/M1-screens.md');
        $start = strpos($spec, "\n### 5.2 Shell and error strings");
        $end = strpos($spec, "\n### 5.3 ");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        preg_match_all('/^\| `([a-z0-9_.-]+)` \| (.*?)(?: \(changed[^)]*\))? \|$/m', substr($spec, $start, $end - $start), $rows, PREG_SET_ORDER);
        self::assertCount(32, $rows, 'The specification table was read.');

        $strings = require RepoFiles::path('strings/en.php');
        foreach ($rows as [, $key, $text]) {
            self::assertSame($text, $strings[$key] ?? null, $key);
        }
    }
}
