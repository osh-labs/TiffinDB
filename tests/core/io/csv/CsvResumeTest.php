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

    /**
     * Review M3-WP4 item 1: a cache with a valid key but offsets that are
     * negative, at or past the end of the file, not integers, or with a
     * skip past one decoded piece is dropped, so reads stay correct and
     * bounded (an offset at the end of the file would otherwise end the
     * table early, item 2).
     */
    public function testBadOffsetsUnderAValidKeyAreDropped(): void
    {
        $cacheDir = $this->dir() . '/temp';
        mkdir($cacheDir);
        $path = $this->file(self::text());
        $dialect = new CsvDialect('utf-8', ',');
        $expected = [];
        foreach ([2500, 4100, 6000] as $from) {
            $expected[$from] = iterator_to_array((new CsvFile($path, $dialect))->rows($from, 3), false);
        }
        $key = $this->validKey($path, $dialect, $cacheDir);
        $size = (int) filesize($path);
        $cases = [
            'negative offset' => [-5, 0], 'negative skip' => [0, -1], 'offset at the end' => [$size, 0], 'offset past the end' => [$size + 1, 0],
            'float offset' => [1.5, 0], 'string offset' => ['0', 0], 'nested offset' => [[1], 0], 'skip just past a piece' => [0, CsvReader::MAX_SKIP + 1],
            'huge skip' => [0, PHP_INT_MAX], 'three numbers' => [0, 0, 0], 'one number' => [0],
        ];
        foreach ($cases as $name => $offsets) {
            $this->plant($cacheDir, $key, ['2000' => $offsets, '4000' => $offsets, '6000' => $offsets]);
            $started = hrtime(true);
            foreach ($expected as $from => $rows) {
                self::assertSame($rows, iterator_to_array((new CsvFile($path, $dialect, $cacheDir))->rows($from, 3), false), $name . ', from ' . $from);
            }
            self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9, $name);
        }
    }

    /** The reader itself refuses a checkpoint it could not use safely. */
    public function testReaderRefusesBadCheckpoints(): void
    {
        $path = $this->file("a\nb\n");
        foreach ([[-1, 0], [0, -1], [0, CsvReader::MAX_SKIP + 1], [0, PHP_INT_MAX]] as $from) {
            try {
                new CsvReader($path, new CsvDialect('utf-8', ','), from: $from);
                self::fail('Accepted ' . json_encode($from));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $reader = new CsvReader($path, new CsvDialect('utf-8', ','), from: [2, 0]);
        self::assertSame(['b'], $reader->next());
    }

    /**
     * Review M3-WP4 item 2: the key covers the temporary folder and the
     * start of the file, so a cache copied from another job, or left from
     * a file of the same size and time but other bytes, is not used.
     */
    public function testKeyCoversFolderAndFileStart(): void
    {
        $dialect = new CsvDialect('utf-8', ',');
        $folderA = $this->dir() . '/a';
        $folderB = $this->dir() . '/b';
        mkdir($folderA);
        mkdir($folderB);
        $path = $this->file(self::text());
        $key = $this->validKey($path, $dialect, $folderA);
        $expected = iterator_to_array((new CsvFile($path, $dialect))->rows(2500, 3), false);
        // Mid-record offsets that would give wrong rows if used.
        $wrong = ['2000' => [7, 3]];
        $this->plant($folderA, $key, $wrong);
        self::assertNotSame($expected, iterator_to_array((new CsvFile($path, $dialect, $folderA))->rows(2500, 3), false), 'The planted offsets are used under their own key.');

        copy($folderA . '/' . CsvFile::CACHE_FILE, $folderB . '/' . CsvFile::CACHE_FILE);
        self::assertSame($expected, iterator_to_array((new CsvFile($path, $dialect, $folderB))->rows(2500, 3), false), 'Another job folder.');

        $time = (int) filemtime($path);
        $text = (string) file_get_contents($path);
        $changed = 'X' . substr($text, 1);
        file_put_contents($path, $changed);
        touch($path, $time);
        clearstatcache();
        self::assertSame([(int) strlen($text), $time], [(int) filesize($path), (int) filemtime($path)]);
        $expected = iterator_to_array((new CsvFile($path, $dialect))->rows(2500, 3), false);
        self::assertSame($expected, iterator_to_array((new CsvFile($path, $dialect, $folderA))->rows(2500, 3), false), 'Same size and time, other first bytes.');
    }

    public function testAtMostOneChunkPerCall(): void
    {
        $file = new CsvFile($this->file(self::text()), new CsvDialect('utf-8', ','));
        self::assertCount(CsvFile::MAX_ROWS_PER_CALL, iterator_to_array($file->rows(0, 5000), false));
        self::assertSame([], iterator_to_array($file->rows(-1, 5), false));
        self::assertSame([], iterator_to_array($file->rows(0, 0), false));
    }

    /** The key CsvFile writes for this file, dialect, and folder (read back from a cache it wrote). */
    private function validKey(string $path, CsvDialect $dialect, string $folder): string
    {
        iterator_to_array((new CsvFile($path, $dialect, $folder))->rows(0, 2000), false);
        iterator_to_array((new CsvFile($path, $dialect, $folder))->rows(2000, 2000), false);
        $cache = json_decode((string) file_get_contents($folder . '/' . CsvFile::CACHE_FILE), true);
        self::assertIsArray($cache);
        self::assertArrayHasKey('2000', $cache['at']);

        return (string) $cache['key'];
    }

    /** @param array<string, mixed> $at */
    private function plant(string $folder, string $key, array $at): void
    {
        file_put_contents($folder . '/' . CsvFile::CACHE_FILE, json_encode(['key' => $key, 'at' => $at], JSON_THROW_ON_ERROR));
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
