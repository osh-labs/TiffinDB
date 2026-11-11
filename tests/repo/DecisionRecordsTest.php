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
 * T-REPO-8: the decision records `docs/decisions/NNNN-*.md` are numbered
 * from 0001 with no gap and no duplicate, include 0008 and 0009, each begins
 * with a heading `# ADR NNNN:` carrying its own number, and each has a line
 * starting `Status:` whose text begins, case-insensitively, with "accepted".
 */
#[CoversNothing]
final class DecisionRecordsTest extends TestCase
{
    private const DIRECTORY = 'docs/decisions';
    private const NAME = '/^(\d{4})-.+\.md$/';
    private const REQUIRED = ['0008', '0009'];

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

    /**
     * Problems with the decision records in a directory.
     *
     * @return list<string>
     */
    public static function problemsIn(string $directory): array
    {
        $names = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::NAME, $name) === 1 && is_file($directory . '/' . $name)) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);
        if ($names === []) {
            return ['no decision records found'];
        }

        $problems = [];
        $byNumber = [];
        foreach ($names as $name) {
            $byNumber[substr($name, 0, 4)][] = $name;
        }
        foreach ($byNumber as $number => $files) {
            if (count($files) > 1) {
                $problems[] = "duplicate number {$number}: " . implode(', ', $files);
            }
        }
        $numbers = array_map('intval', array_keys($byNumber));
        sort($numbers);
        $expected = range(1, max($numbers));
        foreach (array_diff($expected, $numbers) as $missing) {
            $problems[] = sprintf('missing number %04d', $missing);
        }
        if (min($numbers) < 1) {
            $problems[] = 'numbering must start at 0001';
        }
        foreach (self::REQUIRED as $required) {
            if (!isset($byNumber[$required])) {
                $problems[] = "required record {$required} is absent";
            }
        }

        foreach ($names as $name) {
            $number = substr($name, 0, 4);
            $contents = file_get_contents($directory . '/' . $name);
            if ($contents === false) {
                throw new RuntimeException("Cannot read: {$name}");
            }
            $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

            if (!str_starts_with($lines[0] ?? '', "# ADR {$number}:")) {
                $problems[] = "{$name}: must begin with the heading '# ADR {$number}:'";
            }

            $status = null;
            foreach ($lines as $line) {
                if (str_starts_with($line, 'Status:')) {
                    $status = ltrim(substr($line, strlen('Status:')));
                    break;
                }
            }
            if ($status === null) {
                $problems[] = "{$name}: has no line starting 'Status:'";
            } elseif (stripos($status, 'accepted') !== 0) {
                $problems[] = "{$name}: status must begin with 'accepted', found '{$status}'";
            }
        }

        return $problems;
    }

    // ------------------------------------------------------- the repository

    public function testRepositoryDecisionRecordsAreCompleteAndAccepted(): void
    {
        self::assertSame([], self::problemsIn(RepoFiles::path(self::DIRECTORY)));
    }

    // ------------------------------------------------------------ fixtures

    private static function record(string $number, string $status = 'Accepted (provisional).'): string
    {
        return "# ADR {$number}: Example decision\n\nStatus: {$status}\n\n## Context\n\nSynthetic fixture.\n";
    }

    /** @return array<string, string> */
    private static function validSet(): array
    {
        $set = [];
        foreach (range(1, 9) as $n) {
            $number = sprintf('%04d', $n);
            $set["{$number}-example-{$n}.md"] = self::record($number);
        }

        return $set;
    }

    /** @return iterable<string, array{array<string, ?string>, list<string>}> */
    public static function fixtureSets(): iterable
    {
        yield 'valid' => [[], []];
        yield 'lower-case accepted and other files ignored' => [[
            '0003-example-3.md' => self::record('0003', 'accepted by the lead developer'),
            'README.md' => "# Decisions\n",
            '003-short.md' => "Not a record.\n",
        ], []];
        yield 'gap' => [['0004-example-4.md' => null], ['missing number 0004']];
        yield 'duplicate' => [['0005-other.md' => self::record('0005')], ['duplicate number 0005: 0005-example-5.md, 0005-other.md']];
        yield 'records 0008 and 0009 absent' => [
            ['0008-example-8.md' => null, '0009-example-9.md' => null],
            ['required record 0008 is absent', 'required record 0009 is absent'],
        ];
        yield 'numbering starts at 0000' => [['0000-zero.md' => self::record('0000')], ['numbering must start at 0001']];
        yield 'proposed status' => [
            ['0002-example-2.md' => self::record('0002', 'Proposed')],
            ["0002-example-2.md: status must begin with 'accepted', found 'Proposed'"],
        ];
        yield 'status that only contains accepted' => [
            ['0002-example-2.md' => self::record('0002', 'Not accepted')],
            ["0002-example-2.md: status must begin with 'accepted', found 'Not accepted'"],
        ];
        yield 'no status line' => [
            ['0006-example-6.md' => "# ADR 0006: Example\n\nState: accepted\n"],
            ["0006-example-6.md: has no line starting 'Status:'"],
        ];
        yield 'indented status line' => [
            ['0006-example-6.md' => "# ADR 0006: Example\n\n  Status: accepted\n"],
            ["0006-example-6.md: has no line starting 'Status:'"],
        ];
        yield 'heading with another number' => [
            ['0007-example-7.md' => self::record('0001')],
            ["0007-example-7.md: must begin with the heading '# ADR 0007:'"],
        ];
        yield 'heading not on the first line' => [
            ['0007-example-7.md' => "\n" . self::record('0007')],
            ["0007-example-7.md: must begin with the heading '# ADR 0007:'"],
        ];
        yield 'heading of the wrong level' => [
            ['0001-example-1.md' => '#' . self::record('0001')],
            ["0001-example-1.md: must begin with the heading '# ADR 0001:'"],
        ];
    }

    /**
     * @param array<string, ?string> $changes file name => contents, or null to remove
     * @param list<string> $expected
     */
    #[DataProvider('fixtureSets')]
    public function testProblemsAreDetected(array $changes, array $expected): void
    {
        $set = self::validSet();
        foreach ($changes as $name => $contents) {
            if ($contents === null) {
                unset($set[$name]);
            } else {
                $set[$name] = $contents;
            }
        }

        $dir = $this->tempDir();
        foreach ($set as $name => $contents) {
            if (file_put_contents($dir . '/' . $name, $contents) === false) {
                throw new RuntimeException("Cannot write {$name}.");
            }
        }

        self::assertSame($expected, self::problemsIn($dir));
    }

    public function testEmptyDirectoryIsAProblem(): void
    {
        self::assertSame(['no decision records found'], self::problemsIn($this->tempDir()));
    }

    // -------------------------------------------------------------- helpers

    private function tempDir(): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-adr-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }

        return $this->temp;
    }
}
