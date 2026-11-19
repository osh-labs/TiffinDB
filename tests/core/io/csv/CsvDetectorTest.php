<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Csv;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Csv\Charset;
use TiffinDB\Core\Io\Csv\CsvDetector;
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/CsvFiles.php';

/**
 * T-CSV-2: delimiter detection on seeded samples for each delimiter,
 * quoted delimiters ignored, ties in the order comma, semicolon, tab, bar;
 * and the dialect of a whole file.
 */
#[CoversClass(CsvDetector::class)]
#[CoversClass(CsvDialect::class)]
final class CsvDetectorTest extends TestCase
{
    use CsvFiles;

    public function testEachDelimiterOnSeededSamples(): void
    {
        $rows = (new IoFixture())->itemRows(60);
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $lines = [];
            foreach ([IoFixture::HEADINGS, ...$rows] as $row) {
                $cells = [];
                foreach ($row as $cell) {
                    $cells[] = str_contains($cell, $delimiter) ? '"' . $cell . '"' : $cell;
                }
                $lines[] = implode($delimiter, $cells);
            }
            self::assertSame($delimiter, CsvDetector::delimiter(implode("\r\n", $lines)), bin2hex($delimiter));
        }
    }

    public function testQuotedDelimitersAreIgnored(): void
    {
        $sample = "\"a,b,c\";\"d,e\"\n\"f,g,h\";i\n\"j,k\";l\n";
        self::assertSame(';', CsvDetector::delimiter($sample));
        self::assertSame(';', CsvDetector::delimiter("\"x\ny,z,w\";1\n\"p\";2\n"), 'A quoted line break does not end the record.');
    }

    public function testTiesAndNone(): void
    {
        self::assertSame(',', CsvDetector::delimiter("a,b;c\nd,e;f\n"), 'A tie goes to the comma.');
        self::assertSame(';', CsvDetector::delimiter("a;b\tc\nd;e\tf\n"), 'A tie goes to the semicolon before the tab.');
        self::assertSame("\t", CsvDetector::delimiter("a\tb|c\nd\te|f\n"));
        self::assertSame(',', CsvDetector::delimiter("one\ntwo\n"));
        self::assertSame(',', CsvDetector::delimiter(''));
    }

    public function testMostCommonCountWins(): void
    {
        // Commas vary in number; semicolons are steady at one per record.
        $sample = "a;b,c\nd;e\nf;g,h,i\nj;k\nl;m\n";
        self::assertSame(';', CsvDetector::delimiter($sample));
    }

    public function testOnlyTheFirstFiftyRecordsCount(): void
    {
        $sample = str_repeat("a;b\n", CsvDetector::SAMPLE_RECORDS) . str_repeat("a,b,c\n", 200);
        self::assertSame(';', CsvDetector::delimiter($sample));
    }

    public function testDetectAFile(): void
    {
        $dialect = CsvDetector::detect($this->file("\xEF\xBB\xBFName;Room\r\nLamp;Hall\r\n"));
        self::assertSame([Charset::UTF8, ';', true], [$dialect->charset, $dialect->delimiter, $dialect->heading]);
        self::assertSame('CSV, semicolons, UTF-8', $dialect->readAs());

        $utf16 = "\xFF\xFE" . pack('v*', ...array_map('ord', str_split("Name\tRoom\r\nLamp\tHall\r\n")));
        $dialect = CsvDetector::detect($this->file($utf16));
        self::assertSame([Charset::UTF16LE, "\t"], [$dialect->charset, $dialect->delimiter]);
        self::assertSame('CSV, tabs, UTF-16 little-endian', $dialect->readAs());

        $dialect = CsvDetector::detect($this->file("Name|Note\r\nCaf\xE9|cr\xE8me\r\n"));
        self::assertSame([Charset::WINDOWS_1252, '|'], [$dialect->charset, $dialect->delimiter]);
        self::assertSame('CSV, vertical bars, Windows Latin 1', $dialect->readAs());
    }

    public function testDialectValues(): void
    {
        $dialect = CsvDialect::fromValues(['encoding' => 'utf-16be', 'delimiter' => 'tab', 'heading' => '0']);
        self::assertSame(['encoding' => 'utf-16be', 'delimiter' => 'tab', 'heading' => '0'], $dialect->values());
        $fallback = CsvDialect::fromValues(['encoding' => 'ebcdic', 'delimiter' => 'colon']);
        self::assertSame(['encoding' => 'utf-8', 'delimiter' => 'comma', 'heading' => '1'], $fallback->values());
        self::assertSame('utf-8', (new CsvDialect(Charset::UTF8_BOM, ','))->charset);
        $this->expectException(\InvalidArgumentException::class);
        new CsvDialect('utf-8', ':');
    }
}
