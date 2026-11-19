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
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvFile;
use TiffinDB\Core\Io\Csv\CsvReader;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/CsvFiles.php';

/**
 * T-CSV-4: rows from any offset equal the same rows of one pass, with and
 * without the offset cache, in every character set (checkpoints fall at
 * any byte position, inside multi-byte characters' pieces and after CR).
 */
#[CoversClass(CsvFile::class)]
#[CoversClass(CsvReader::class)]
final class CsvResumeTest extends TestCase
{
    use CsvFiles;

    private const ROWS = 6500;

    /** @return iterable<string, array{0: string}> */
    public static function charsets(): iterable
    {
        foreach (['utf-8', 'utf-16le', 'utf-16be', 'windows-1252'] as $charset) {
            yield $charset => [$charset];
        }
    }

    #[DataProvider('charsets')]
    public function testRowsFromAnyOffset(string $charset): void
    {
        $path = $this->file(self::encode(self::text(), $charset));
        $dialect = new CsvDialect($charset, ',');
        $all = [];
        $plain = new CsvFile($path, $dialect);
        for ($from = 0; ; $from += 2000) {
            $chunk = iterator_to_array($plain->rows($from, 2000), false);
            $all = [...$all, ...$chunk];
            if (count($chunk) < 2000) {
                break;
            }
        }
        self::assertCount(self::ROWS, $all);
        self::assertSame("Note 7\nsecond line", $all[6][2]);

        $cacheDir = $this->dir() . '/temp-' . $charset;
        mkdir($cacheDir);
        $cached = new CsvFile($path, $dialect, $cacheDir);
        // Fill the cache in the read loop's order, then jump around.
        for ($from = 0; $from < self::ROWS; $from += 2000) {
            self::assertSame(array_slice($all, $from, 2000), iterator_to_array($cached->rows($from, 2000), false));
        }
        $cache = json_decode((string) file_get_contents($cacheDir . '/' . CsvFile::CACHE_FILE), true);
        self::assertSame(['2000', '4000', '6000'], array_map('strval', array_keys($cache['at'])), 'Every 2,000th record (heading included) has a checkpoint.');
        foreach ([0, 1, 1999, 2000, 2001, 3999, 4000, 4001, 5000, 6499, 6500] as $from) {
            $expected = array_slice($all, $from, 7);
            self::assertSame($expected, iterator_to_array($cached->rows($from, 7), false), 'cached from ' . $from);
            self::assertSame($expected, iterator_to_array((new CsvFile($path, $dialect))->rows($from, 7), false), 'uncached from ' . $from);
        }
    }

    public function testACacheForAnotherFileOrDialectIsIgnored(): void
    {
        $cacheDir = $this->dir() . '/temp';
        mkdir($cacheDir);
        $text = self::text();
        $path = $this->file($text);
        $first = new CsvFile($path, new CsvDialect('utf-8', ','), $cacheDir);
        iterator_to_array($first->rows(0, 2000), false);
        iterator_to_array($first->rows(2000, 2000), false);
        // The same cache folder, a different dialect: the semicolon reading must not use comma offsets.
        $other = new CsvFile($path, new CsvDialect('utf-8', ';'), $cacheDir);
        $expected = iterator_to_array((new CsvFile($path, new CsvDialect('utf-8', ';')))->rows(2500, 3), false);
        self::assertSame($expected, iterator_to_array($other->rows(2500, 3), false));

        file_put_contents($cacheDir . '/' . CsvFile::CACHE_FILE, '{"key": 1, "at": {"2000": [-5, "x"]}}');
        self::assertSame(iterator_to_array((new CsvFile($path, new CsvDialect('utf-8', ',')))->rows(2500, 3), false), iterator_to_array($first->rows(2500, 3), false));
    }

    public function testAtMostOneChunkPerCall(): void
    {
        $file = new CsvFile($this->file(self::text()), new CsvDialect('utf-8', ','));
        self::assertCount(CsvFile::MAX_ROWS_PER_CALL, iterator_to_array($file->rows(0, 5000), false));
        self::assertSame([], iterator_to_array($file->rows(-1, 5), false));
        self::assertSame([], iterator_to_array($file->rows(0, 0), false));
    }

    private static function text(): string
    {
        $fixture = new IoFixture();
        $rows = $fixture->itemRows(self::ROWS);
        $out = "Name,Room,Notes\r\n";
        foreach ($rows as $i => $row) {
            $note = $i % 7 === 6 ? '"Note ' . ($i + 1) . "\r\nsecond line\"" : 'caf' . "\u{E9} \u{201C}" . $i . "\u{201D}";
            $out .= $row[0] . ',' . $row[1] . ',' . $note . ($i % 3 === 0 ? "\r" : "\n");
        }

        return $out;
    }

    private static function encode(string $utf8, string $charset): string
    {
        if ($charset === 'utf-8') {
            return "\xEF\xBB\xBF" . $utf8;
        }
        $points = [];
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $points[] = match ($char) {
                "\u{E9}" => 0xE9,
                "\u{201C}" => 0x201C,
                "\u{201D}" => 0x201D,
                default => ord($char),
            };
        }
        if ($charset === 'windows-1252') {
            return implode('', array_map(static fn (int $p): string => match ($p) { 0x201C => "\x93", 0x201D => "\x94", default => chr($p) }, $points));
        }

        return ($charset === 'utf-16le' ? "\xFF\xFE" : "\xFE\xFF") . pack($charset === 'utf-16le' ? 'v*' : 'n*', ...$points);
    }
}
