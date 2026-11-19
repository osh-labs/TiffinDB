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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Strings;
use UnexpectedValueException;

/**
 * T-WEB-4 (strings): lookup, placeholders, missing keys; T-WEB-19: the parts
 * and the specified texts; T-WEB-24: the plural placeholder (M2 spec part 2
 * section 6.2) and the M2 problem keys (part 1 section 8).
 */
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

    // T-WEB-24: the plural placeholder.

    /** @return iterable<string, array{int, string}> */
    public static function pluralCounts(): iterable
    {
        yield 'zero' => [0, '0 records'];
        yield 'one' => [1, '1 record'];
        yield 'two' => [2, '2 records'];
        yield 'thousands' => [1234, '1,234 records'];
        yield 'negative one' => [-1, '-1 records'];
        yield 'negative' => [-1234, '-1,234 records'];
    }

    #[DataProvider('pluralCounts')]
    public function testPluralPlaceholder(int $n, string $expected): void
    {
        self::assertSame($expected, self::strings()->get('test.plural', ['count' => $n]));
    }

    public function testSeveralPluralsAndPlainPlaceholdersInOneString(): void
    {
        self::assertSame('This clears 1 value in 1 record.', self::strings()->get('test.plural_two', ['values' => 1, 'records' => 1]));
        self::assertSame('This clears 3 values in 2 records.', self::strings()->get('test.plural_two', ['values' => 3, 'records' => 2]));
        self::assertSame('{count} has one item & more', self::strings()->get('test.plural_mixed', ['name' => '{count}', 'count' => 1]));
        self::assertSame('a single thing', self::strings()->get('test.plural_words', ['count' => 1]));
        self::assertSame('several things', self::strings()->get('test.plural_words', ['count' => 7]));
    }

    public function testPluralTextAndNumberAreEscapedOnce(): void
    {
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        self::assertSame(
            '&lt;b&gt; has 2 items &amp; more',
            self::strings()->format('test.plural_mixed', ['name' => '<b>', 'count' => 2], $escape),
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonIntegers(): iterable
    {
        yield 'numeric string' => ['1'];
        yield 'float' => [1.0];
        yield 'null' => [null];
        yield 'bool' => [true];
    }

    #[DataProvider('nonIntegers')]
    public function testPluralParameterMustBeAnInteger(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::strings()->get('test.plural', ['count' => $value]);
    }

    public function testPluralParameterMustBeGiven(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::strings()->get('test.plural', []);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPlurals(): iterable
    {
        yield 'one text' => ['test.plural_bad_pipes'];
        yield 'capital in the name' => ['test.plural_bad_name'];
        yield 'three texts' => ['test.plural_bad_extra'];
        yield 'unclosed' => ['test.plural_bad_brace'];
    }

    #[DataProvider('malformedPlurals')]
    public function testMalformedPluralPatternsThrow(string $key): void
    {
        $this->expectException(UnexpectedValueException::class);
        self::strings()->get($key, ['count' => 1, 'Count' => 1]);
    }

    public function testBracesThatAreNotPlaceholdersStayText(): void
    {
        self::assertSame('Keep { these } as text', self::strings()->get('test.braces'));
    }

    public function testEveryRealStringParses(): void
    {
        $strings = require RepoFiles::path('strings/en.php');
        self::assertIsArray($strings);
        foreach ($strings as $key => $text) {
            self::assertIsString($text);
            preg_match_all('/\{[^{}]*\}/', $text, $m);
            foreach ($m[0] as $group) {
                self::assertMatchesRegularExpression('/^\{(?:[A-Za-z0-9_.-]+|[a-z_]+\|[^{}|]*\|[^{}|]*)\}$/', $group, $key);
            }
        }
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

    /**
     * The rows `| `problem.x` | text |` of one section of a specification.
     *
     * @return array<string, string>
     */
    private static function problemRows(string $file, string $start, string $end): array
    {
        $spec = RepoFiles::read($file);
        $from = strpos($spec, $start);
        self::assertNotFalse($from, $start);
        $to = strpos($spec, $end, $from);
        self::assertNotFalse($to, $end);
        preg_match_all('/^\| `(problem\.[^`]+)` \| (.*) \|$/m', substr($spec, $from, $to - $from), $rows, PREG_SET_ORDER);
        $expected = [];
        foreach ($rows as [, $key, $text]) {
            $expected[$key] = $text;
        }

        return $expected;
    }

    public function testProblemStringsAreExactlyTheSpecification(): void
    {
        $m1 = self::problemRows('docs/specs/M1-foundation.md', "\n## 12. Message keys produced by the core", "\n## 13. ");
        self::assertCount(58, $m1, 'The M1 specification table was read.');
        $m2 = self::problemRows('docs/specs/M2-records.md', "\n## 8. Message keys produced by the core", "\n## 9. ");
        self::assertCount(8, $m2, 'The M2 specification table was read.');
        self::assertSame([], array_intersect_key($m1, $m2), 'The M2 table adds keys only.');
        $m3 = self::problemRows('docs/specs/M3-io.md', "\n## 14. Message keys produced by the core", "\n## 15. ");
        self::assertCount(33, $m3, 'The M3 specification table was read.');
        self::assertSame([], array_intersect_key($m1 + $m2, $m3), 'The M3 table adds keys only.');

        $problems = require RepoFiles::path('strings/en/problems.php');
        self::assertSame($m1 + $m2 + $m3, $problems);
    }

    /** M3 spec part 2 sections 2.3, 4, and 6, and docs/design/m3/import.md section 1. */
    public function testM3SharedStringsAreTheSpecification(): void
    {
        $strings = require RepoFiles::path('strings/en.php');
        self::assertIsArray($strings);
        $expected = [
            'error.too_large.title' => 'That file is too large',
            'error.too_large.body' => 'The largest file this installation accepts is {max}.',
            'shell.nav.plugins' => 'Plugins',
            'sidebar.data' => 'Data',
            'sidebar.import' => 'Import a file',
            'sidebar.export' => 'Export',
            'steps.done' => 'Step {n}, done',
            'steps.now' => 'Step {n}, now',
            'steps.next' => 'Step {n}',
            'steps.import.label' => 'Import steps',
            'steps.import.choose' => 'Choose a file',
            'steps.import.check' => 'Check the fields',
            'steps.import.import' => 'Import',
            'steps.import.report' => 'Read the report',
        ];
        foreach ($expected as $key => $text) {
            self::assertSame($text, $strings[$key] ?? null, $key);
        }
        $io = require RepoFiles::path('strings/en/io.php');
        self::assertIsArray($io);
        foreach (array_keys($io) as $key) {
            self::assertStringStartsWith('steps.', $key, 'strings/en/io.php holds the shared step strings.');
        }
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
