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
use TiffinDB\Core\Io\Csv\CsvReader;
use TiffinDB\Core\Io\Csv\CsvWriter;

require_once __DIR__ . '/CsvFiles.php';

/**
 * T-CSV-5 (writer: quoting rules, BOM, CRLF) and the writer half of
 * T-CSV-6 (the spreadsheet-safe prefix); the heading row and the record
 * ids option are tested through the plugin (tests/plugins/csv/).
 */
#[CoversClass(CsvWriter::class)]
final class CsvWriterTest extends TestCase
{
    use CsvFiles;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function fields(): iterable
    {
        yield 'plain' => ['Lamp', 'Lamp'];
        yield 'empty' => ['', ''];
        yield 'inner space' => ['Blue Lamp', 'Blue Lamp'];
        yield 'comma' => ['a,b', '"a,b"'];
        yield 'quote' => ['say "hi"', '"say ""hi"""'];
        yield 'lf' => ["a\nb", "\"a\nb\""];
        yield 'cr' => ["a\rb", "\"a\rb\""];
        yield 'leading space' => [' a', '" a"'];
        yield 'trailing space' => ['a ', '"a "'];
        yield 'leading tab' => ["\ta", "\"\ta\""];
        yield 'trailing tab' => ["a\t", "\"a\t\""];
        yield 'semicolon' => ['a;b', 'a;b'];
        yield 'formula kept exactly' => ['=1+2', '=1+2'];
    }

    #[DataProvider('fields')]
    public function testQuoting(string $text, string $expected): void
    {
        self::assertSame($expected, CsvWriter::field($text));
    }

    public function testFileShapeAndRoundTrip(): void
    {
        $rows = [['Name', 'Note'], ['Lamp', "two\nlines"], ['', 'say "hi"'], [' pad ', '=SUM(A1)'], ["caf\u{E9}", '']];
        $path = $this->dir() . '/out.csv';
        $writer = new CsvWriter($path);
        foreach ($rows as $row) {
            $writer->row($row);
        }
        $writer->close();
        $bytes = (string) file_get_contents($path);
        self::assertSame("\xEF\xBB\xBFName,Note\r\nLamp,\"two\nlines\"\r\n,\"say \"\"hi\"\"\"\r\n\" pad \",=SUM(A1)\r\ncaf\u{E9},\r\n", $bytes);

        $reader = new CsvReader($path, new CsvDialect('utf-8', ','));
        $back = [];
        while (($record = $reader->next()) !== null) {
            $back[] = $record;
        }
        self::assertSame($rows, $back, 'The reader reads back exactly what the writer wrote.');
    }

    public function testSpreadsheetSafe(): void
    {
        foreach (['=1', '+1', '-1', '@x', "\tx", "\rx"] as $text) {
            self::assertSame("'" . $text, CsvWriter::spreadsheetSafe($text));
        }
        foreach (['', 'a=1', ' =1', "'=1", '1-2'] as $text) {
            self::assertSame($text, CsvWriter::spreadsheetSafe($text));
        }
    }

    public function testFileName(): void
    {
        self::assertSame('home-inventory.csv', CsvWriter::fileName('Home Inventory'));
        self::assertSame('items_2025.csv', CsvWriter::fileName('Items_2025!'));
        self::assertSame("caf\u{E9}-list.csv", CsvWriter::fileName("Caf\u{E9} list"), 'Letters outside ASCII are kept; only ASCII is lower-cased.');
        self::assertSame('export.csv', CsvWriter::fileName('../"<>'));
        self::assertSame(str_repeat('a', 60) . '.csv', CsvWriter::fileName(str_repeat('a', 80)));
    }

    public function testUnwritableTarget(): void
    {
        $this->expectException(PluginFailure::class);
        new CsvWriter($this->dir() . '/missing/out.csv');
    }
}
