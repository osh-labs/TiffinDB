<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Csv;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Csv\CsvDetector;
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvFile;
use TiffinDB\Core\Io\Csv\CsvReader;

require_once __DIR__ . '/CsvFiles.php';

/**
 * T-CSV-9: a hostile corpus. Each file is detected, its columns read, and
 * its first rows read; each ends with a PluginFailure or a correct read,
 * within 5 seconds and 64 MB of memory. The files are made from a fixed
 * seed (no random input), written in pieces so the test itself stays small.
 */
#[CoversClass(CsvReader::class)]
#[CoversClass(CsvFile::class)]
#[CoversClass(CsvDetector::class)]
final class CsvHostileTest extends TestCase
{
    use CsvFiles;

    private const SEED = 20261119;

    /** @return iterable<string, array{0: string, 1: ?string}> generator name, expected failure reason (null: a read) */
    public static function corpus(): iterable
    {
        yield 'a 10 MB single quoted field' => ['bigQuoted', PluginFailure::REASON_TOO_LARGE];
        yield 'a 10 MB unquoted line' => ['bigLine', PluginFailure::REASON_TOO_LARGE];
        yield 'a million delimiters on one line' => ['millionDelimiters', PluginFailure::REASON_TOO_LARGE];
        yield 'a million delimiters on the second line' => ['wideDataRow', null];
        yield 'deeply alternating quotes' => ['alternatingQuotes', null];
        yield 'a quote bomb of doubled quotes' => ['doubledQuotes', null];
        yield 'an unterminated quote' => ['unterminated', PluginFailure::REASON_TOO_LARGE];
        yield 'many line breaks' => ['newlines', null];
        yield 'binary data' => ['binary', 'any'];
        yield 'nul bytes' => ['nuls', null];
        yield 'a file of only a bom' => ['onlyBom', PluginFailure::REASON_UNREADABLE];
        yield 'an empty file' => ['emptyFile', PluginFailure::REASON_UNREADABLE];
        yield 'a utf-16 file of odd length' => ['oddUtf16', null];
        yield 'a utf-16 bom alone with one byte' => ['utf16Byte', null];
        yield 'invalid utf-8 everywhere' => ['invalidUtf8', null];
        yield 'formula-like cells' => ['formulas', null];
        yield 'duplicate and empty headings' => ['headings', null];
    }

    #[DataProvider('corpus')]
    public function testHostileFile(string $generator, ?string $reason): void
    {
        $path = $this->dir() . '/hostile.csv';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        $this->{$generator}($handle);
        fclose($handle);

        memory_reset_peak_usage();
        $base = memory_get_usage();
        $started = hrtime(true);
        $rows = null;
        $failure = null;
        try {
            $dialect = CsvDetector::detect($path);
            $file = new CsvFile($path, $dialect, null);
            $columns = $file->columns();
            $rows = iterator_to_array($file->rows(0, 2000), false);
            foreach ($rows as $row) {
                self::assertGreaterThanOrEqual(count($columns), count($row));
                self::assertLessThanOrEqual(count($columns) + 1, count($row));
            }
        } catch (PluginFailure $caught) {
            $failure = $caught;
        }
        $seconds = (hrtime(true) - $started) / 1e9;
        $memory = memory_get_peak_usage() - $base;
        self::assertLessThan(5.0, $seconds, 'Time');
        self::assertLessThan(64 * 1024 * 1024, $memory, 'Memory');
        if ($reason === 'any') {
            self::assertTrue($failure !== null || $rows !== null);
        } elseif ($reason === null) {
            self::assertNull($failure, $failure?->getMessage() ?? '');
            $this->check($generator, $columns ?? [], $rows ?? []);
        } else {
            self::assertNotNull($failure, 'A failure was expected.');
            self::assertSame($reason, $failure->reason);
        }
    }

    /**
     * @param list<string> $columns
     * @param list<list<?string>> $rows
     */
    private function check(string $generator, array $columns, array $rows): void
    {
        match ($generator) {
            'wideDataRow' => self::assertSame([['x', 'y', null]], $rows, 'The wide row is cut to the columns and marked for extra cells.'),
            'alternatingQuotes' => self::assertSame(['ab', 'c'], [substr((string) $rows[0][0], 0, 2), $rows[0][1]]),
            'doubledQuotes' => self::assertSame(str_repeat('"', 200000), $rows[0][0]),
            'newlines' => self::assertSame(array_fill(0, 2000, [null]), $rows, 'Empty lines are empty rows (the core skips them); one chunk at most.'),
            'nuls' => self::assertSame("a\u{FFFD}b", $rows[0][0]),
            'oddUtf16' => self::assertSame(['Name', "Lamp\u{FFFD}"], [$columns[0], $rows[0][0]]),
            'utf16Byte' => self::assertSame(["\u{FFFD}"], $columns),
            'invalidUtf8' => self::assertSame(['ok'], $columns),
            'formulas' => self::assertSame(['=1+2', '+A1', '-3', '@SUM(A1)', "\tx", "\ncmd"], $rows[0], 'Kept as text (a quoted CR becomes LF).'),
            'headings' => self::assertSame(['Name', 'Name', 'Column 3', 'Column 4'], $columns),
            default => self::assertNotNull($rows),
        };
    }

    /** @param resource $h */
    private function repeat($h, string $piece, int $times): void
    {
        $block = str_repeat($piece, max(1, intdiv(65536, max(1, strlen($piece)))));
        $perBlock = intdiv(strlen($block), strlen($piece));
        for ($done = 0; $done + $perBlock <= $times; $done += $perBlock) {
            fwrite($h, $block);
        }
        fwrite($h, str_repeat($piece, $times - $done));
    }

    /** @param resource $h */
    private function bigQuoted($h): void
    {
        fwrite($h, "Note\r\n\"");
        $this->repeat($h, 'a', 10_000_000);
        fwrite($h, "\"\r\n");
    }

    /** @param resource $h */
    private function bigLine($h): void
    {
        fwrite($h, "Note\n");
        $this->repeat($h, 'b', 10_000_000);
    }

    /** @param resource $h */
    private function millionDelimiters($h): void
    {
        $this->repeat($h, ',', 1_000_000);
        fwrite($h, "\n");
    }

    /** @param resource $h */
    private function wideDataRow($h): void
    {
        fwrite($h, "A,B\nx,y,z");
        $this->repeat($h, ',', 1_000_000);
        fwrite($h, "\n");
    }

    /** @param resource $h */
    private function alternatingQuotes($h): void
    {
        fwrite($h, "A,B\n\"a\"b");
        $this->repeat($h, '"q"', 300_000);
        fwrite($h, ",c\n");
    }

    /** @param resource $h */
    private function doubledQuotes($h): void
    {
        fwrite($h, "A\n\"");
        $this->repeat($h, '""', 200_000);
        fwrite($h, "\"\n");
    }

    /** @param resource $h */
    private function unterminated($h): void
    {
        fwrite($h, "A\n\"open");
        $this->repeat($h, "line\r\n", 400_000);
    }

    /** @param resource $h */
    private function newlines($h): void
    {
        fwrite($h, "A\n");
        $this->repeat($h, "\r", 3_000_000);
    }

    /** @param resource $h */
    private function binary($h): void
    {
        $state = self::SEED;
        for ($block = 0; $block < 32; $block++) {
            $bytes = '';
            for ($i = 0; $i < 32768; $i++) {
                $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;
                $bytes .= chr(($state >> 16) & 0xFF);
            }
            fwrite($h, $bytes);
        }
    }

    /** @param resource $h */
    private function nuls($h): void
    {
        fwrite($h, "A\na\0b\n");
        $this->repeat($h, "\0", 100_000);
    }

    /** @param resource $h */
    private function onlyBom($h): void
    {
        fwrite($h, "\xEF\xBB\xBF");
    }

    /** @param resource $h */
    private function emptyFile($h): void
    {
        unset($h);
    }

    /** @param resource $h */
    private function oddUtf16($h): void
    {
        fwrite($h, "\xFF\xFE" . pack('v*', ...array_map('ord', str_split("Name\r\nLamp"))) . "\x41");
    }

    /** @param resource $h */
    private function utf16Byte($h): void
    {
        fwrite($h, "\xFE\xFF\x41");
    }

    /** @param resource $h */
    private function invalidUtf8($h): void
    {
        // Valid at first (so the sample says UTF-8), ill-formed later.
        $this->repeat($h, "ok\n", 30_000);
        $this->repeat($h, "\xC3\x28\xFF\n", 1000);
    }

    /** @param resource $h */
    private function formulas($h): void
    {
        fwrite($h, "A,B,C,D,E,F\n=1+2,+A1,-3,@SUM(A1),\"\tx\",\"\rcmd\"\n");
    }

    /** @param resource $h */
    private function headings($h): void
    {
        fwrite($h, "Name,Name,,  \nLamp,Kettle,,\n");
    }
}
