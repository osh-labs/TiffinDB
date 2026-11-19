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
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvFile;
use TiffinDB\Core\Io\Csv\CsvReader;
use TiffinDB\Core\Io\IoLimits;

require_once __DIR__ . '/CsvFiles.php';

/**
 * T-CSV-3: the reader on an RFC 4180 corpus (quotes, doubled quotes,
 * embedded CR, LF, CRLF, trailing delimiter, empty fields, no final
 * newline, CR-only files), padding and extra cells, NUL, the record size
 * cap; and the limits this namespace repeats from IoLimits.
 */
#[CoversClass(CsvReader::class)]
#[CoversClass(CsvFile::class)]
final class CsvReaderTest extends TestCase
{
    use CsvFiles;

    /** @return iterable<string, array{0: string, 1: list<list<string>>}> */
    public static function corpus(): iterable
    {
        yield 'simple crlf' => ["a,b\r\nc,d\r\n", [['a', 'b'], ['c', 'd']]];
        yield 'lf only' => ["a,b\nc,d\n", [['a', 'b'], ['c', 'd']]];
        yield 'cr only' => ["a,b\rc,d\r", [['a', 'b'], ['c', 'd']]];
        yield 'mixed line ends' => ["a\r\nb\nc\rd", [['a'], ['b'], ['c'], ['d']]];
        yield 'no final newline' => ['a,b', [['a', 'b']]];
        yield 'quoted' => ["\"a\",\"b c\"\n", [['a', 'b c']]];
        yield 'doubled quotes' => ["\"say \"\"hi\"\"\",x\n", [['say "hi"', 'x']]];
        yield 'only a quote' => ["\"\"\"\"\n", [['"']]];
        yield 'embedded lf' => ["\"one\ntwo\",x\n", [["one\ntwo", 'x']]];
        yield 'embedded crlf becomes lf' => ["\"one\r\ntwo\",x\r\n", [["one\ntwo", 'x']]];
        yield 'embedded cr becomes lf' => ["\"one\rtwo\",x\r", [["one\ntwo", 'x']]];
        yield 'trailing delimiter' => ["a,b,\n", [['a', 'b', '']]];
        yield 'empty fields' => [",,\n,\n", [['', '', ''], ['', '']]];
        yield 'empty line' => ["a\n\nb\n", [['a'], [''], ['b']]];
        yield 'quoted empty' => ["\"\",x\n", [['', 'x']]];
        yield 'text after closing quote' => ["\"ab\"cd,e\n", [['abcd', 'e']]];
        yield 'quote inside unquoted field' => ["ab\"cd\",e\n", [['ab"cd"', 'e']]];
        yield 'unterminated quote runs to the end' => ["a,\"b\nc,d\n", [['a', "b\nc,d\n"]]];
        yield 'nul bytes' => ["a\0b,\"\0\"\n", [["a\u{FFFD}b", "\u{FFFD}"]]];
        yield 'bom removed' => ["\xEF\xBB\xBFName,x\n", [['Name', 'x']]];
        yield 'spaces kept' => [" a , b \n", [[' a ', ' b ']]];
    }

    /** @param list<list<string>> $expected */
    #[DataProvider('corpus')]
    public function testCorpus(string $bytes, array $expected): void
    {
        self::assertSame($expected, $this->records($bytes));
    }

    public function testOtherDelimitersAndCharsets(): void
    {
        self::assertSame([['a,b', 'c']], $this->records("a,b;c\n", 'utf-8', ';'));
        self::assertSame([['a', 'b c']], $this->records("a\tb c\n", 'utf-8', "\t"));
        $utf16 = "\xFE\xFF" . pack('n*', ...array_map('ord', str_split("\"x\r\ny\"|z\r\n")));
        self::assertSame([["x\ny", 'z']], $this->records($utf16, 'utf-16be', '|'));
        self::assertSame([["Caf\u{E9}", "\u{20AC}"]], $this->records("Caf\xE9,\x80\n", 'windows-1252'));
    }

    public function testFieldsPastTheLimitAreCountedNotKept(): void
    {
        $reader = new CsvReader($this->file("a,b,c,d\nx,y,,\nx,y,\"\",\n"), new CsvDialect('utf-8', ','), 2);
        self::assertSame(['a', 'b'], $reader->next());
        self::assertSame([4, true], [$reader->fieldCount(), $reader->hadExtra()]);
        self::assertSame(['x', 'y'], $reader->next());
        self::assertSame([4, false], [$reader->fieldCount(), $reader->hadExtra()], 'Empty fields past the limit are not extra cells.');
        self::assertSame(['x', 'y'], $reader->next());
        self::assertTrue($reader->hadExtra(), 'A quoted field counts as written.');
        self::assertNull($reader->next());
        self::assertSame(3, $reader->records());
    }

    public function testRecordSizeCap(): void
    {
        self::assertSame([[str_repeat('a', 100)]], $this->records(str_repeat('a', 100) . "\n", maxRecordBytes: 101));
        $this->expectException(PluginFailure::class);
        try {
            $this->records("ok\n\"" . str_repeat('a', 300) . "\"\n", maxRecordBytes: 200);
        } catch (PluginFailure $failure) {
            self::assertSame(PluginFailure::REASON_TOO_LARGE, $failure->reason);

            throw $failure;
        }
    }

    public function testRecordsSpanningPieces(): void
    {
        $long = str_repeat('x', CsvReader::CHUNK_BYTES + 10);
        $bytes = "a,\"" . $long . "\"\"q\"\r" . "\nb," . $long . "\r";
        self::assertSame([['a', $long . '"q'], ['b', $long]], $this->records($bytes));
        // A CR that ends a piece, followed by the LF in the next piece, is one line break.
        $bytes = str_repeat('y', CsvReader::CHUNK_BYTES - 1) . "\r\nz\n";
        self::assertSame([[str_repeat('y', CsvReader::CHUNK_BYTES - 1)], ['z']], $this->records($bytes));
    }

    public function testColumnsPaddingAndExtraCells(): void
    {
        $file = new CsvFile($this->file("Name,,Note\nLamp\nKettle,2,ok,extra\nFan,3,,\n"), new CsvDialect('utf-8', ','));
        self::assertSame(['Name', 'Column 2', 'Note'], $file->columns());
        self::assertSame([['Lamp', null, null], ['Kettle', '2', 'ok', null], ['Fan', '3', null]], iterator_to_array($file->rows(0, 10), false));
        self::assertSame([['Fan', '3', null]], iterator_to_array($file->rows(2, 10), false));
        self::assertSame([], iterator_to_array($file->rows(3, 10), false));

        $plain = new CsvFile($this->file("a\nb,c,d\ne,f\n"), new CsvDialect('utf-8', ',', false));
        self::assertSame(['Column 1', 'Column 2', 'Column 3'], $plain->columns());
        self::assertSame([['a', null, null], ['b', 'c', 'd'], ['e', 'f', null]], iterator_to_array($plain->rows(0, 10), false));
    }

    public function testInvalidUtf8IsPassedOnForTheCoreToCount(): void
    {
        $file = new CsvFile($this->file("N\xFF\nCaf\xE9\n"), new CsvDialect('utf-8', ','));
        self::assertSame(["N\u{FFFD}"], $file->columns(), 'Headings are always well-formed.');
        self::assertSame([["Caf\xE9"]], iterator_to_array($file->rows(0, 5), false), 'Cells keep the bytes; the read loop replaces and counts them.');
    }

    public function testColumnLimitAndEmptyFile(): void
    {
        $ok = new CsvFile($this->file(implode(',', range(1, 200)) . "\n"), new CsvDialect('utf-8', ','));
        self::assertCount(200, $ok->columns());
        foreach (["\xEF\xBB\xBF", '', implode(',', range(1, 201)) . "\n"] as $bytes) {
            try {
                (new CsvFile($this->file($bytes), new CsvDialect('utf-8', ',')))->columns();
                self::fail('No failure for ' . strlen($bytes) . ' bytes.');
            } catch (PluginFailure $failure) {
                self::assertSame($bytes === '' || strlen($bytes) === 3 ? PluginFailure::REASON_UNREADABLE : PluginFailure::REASON_TOO_LARGE, $failure->reason);
            }
        }
        $wide = new CsvFile($this->file("a\n" . str_repeat(',', 200) . "\n"), new CsvDialect('utf-8', ',', false));
        $this->expectException(PluginFailure::class);
        $wide->columns();
    }

    public function testMissingFile(): void
    {
        $this->expectException(PluginFailure::class);
        new CsvReader($this->dir() . '/missing.csv', new CsvDialect('utf-8', ','));
    }

    public function testRepeatedLimitsMatchIoLimits(): void
    {
        self::assertSame(IoLimits::MAX_CSV_RECORD_BYTES, CsvReader::MAX_RECORD_BYTES);
        self::assertSame(IoLimits::MAX_COLUMNS, CsvFile::MAX_COLUMNS);
        self::assertSame(IoLimits::CHUNK_ROWS, CsvFile::MAX_ROWS_PER_CALL);
        self::assertSame(IoLimits::MAX_SOURCE_ROWS + IoLimits::CHUNK_ROWS, CsvFile::CHECKPOINT_LIMIT);
        self::assertSame(IoLimits::CHUNK_ROWS, CsvFile::CHECKPOINT_EVERY);
    }
}
