<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Plugin\Import\Xlsx\XlsxImporter;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/WorkbookBuilder.php';
require_once __DIR__ . '/XlsxPlugins.php';

/**
 * T-XLSX-2: a workbook assembled by hand from XML strings (no XLSX writer
 * involved) read through the importer contract gives exactly the texts of
 * M3 spec section 9.2: shared and inline strings, rich text, numbers
 * (floats such as 0.1 and 1e-7), booleans, dates and date-times in the
 * 1900 and 1904 systems, formulas with and without a cached value (never
 * the formula), error cells, empty cells, a hidden sheet, settings,
 * headings, gaps in row numbers, and the row and column rules (P139).
 */
#[CoversNothing]
final class XlsxImporterTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-xlsx-import-test-');
        mkdir($this->temp->path('tmp'));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function file(WorkbookBuilder $builder, string $name = 'upload.bin'): SourceFile
    {
        $path = $builder->write($this->temp->path($name));

        return new SourceFile($path, 'xlsx', (int) filesize($path), $this->temp->path('tmp'));
    }

    /** The hand-assembled workbook of the cell table, in the given date system. */
    private static function cellsWorkbook(bool $date1904): WorkbookBuilder
    {
        $b = new WorkbookBuilder();
        $date = $b->cellFormat(14);
        $dateTime = $b->cellFormat(164, 'yyyy\-mm\-dd\ hh:mm:ss;@');
        $time = $b->cellFormat(21);
        $elapsed = $b->cellFormat(165, '[h]:mm:ss');
        $money = $b->cellFormat(166, '"$"#,##0.00_);[Red]\("$"#,##0.00\)');
        $b->sharedStrings([
            '<t>Kind</t>',
            '<t>Value</t>',
            '<t xml:space="preserve"> padded text </t>',
            '<r><rPr><b/></rPr><t>Rich</t></r><r><t xml:space="preserve"> text</t></r><rPh sb="0" eb="1"><t>ignored</t></rPh>',
            '<t>=1+1</t>',
            '<t>Line one' . "\n" . 'line two &amp; &lt;more&gt;</t>',
            '<t>Tab_x0009_and_x005F_x0041_ literal</t>',
        ]);
        $rows = [
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>',
            '<row r="2"><c r="A2" t="inlineStr"><is><t>shared</t></is></c><c r="B2" t="s"><v>2</v></c></row>',
            '<row r="3"><c r="A3" t="inlineStr"><is><t>rich</t></is></c><c r="B3" t="s"><v>3</v></c></row>',
            '<row r="4"><c r="A4" t="inlineStr"><is><t>inline</t></is></c><c r="B4" t="inlineStr"><is><r><t>In</t></r><r><t>line</t></r></is></c></row>',
            '<row r="5"><c r="A5" t="inlineStr"><is><t>int</t></is></c><c r="B5"><v>42</v></c></row>',
            '<row r="6"><c r="A6" t="inlineStr"><is><t>negative</t></is></c><c r="B6"><v>-17</v></c></row>',
            '<row r="7"><c r="A7" t="inlineStr"><is><t>tenth</t></is></c><c r="B7"><v>0.1</v></c></row>',
            '<row r="8"><c r="A8" t="inlineStr"><is><t>tiny</t></is></c><c r="B8"><v>1E-7</v></c></row>',
            '<row r="9"><c r="A9" t="inlineStr"><is><t>sum</t></is></c><c r="B9"><v>0.30000000000000004</v></c></row>',
            '<row r="10"><c r="A10" t="inlineStr"><is><t>large</t></is></c><c r="B10"><v>1.5E+20</v></c></row>',
            '<row r="11"><c r="A11" t="inlineStr"><is><t>minus zero</t></is></c><c r="B11"><v>-0</v></c></row>',
            '<row r="12"><c r="A12" t="inlineStr"><is><t>true</t></is></c><c r="B12" t="b"><v>1</v></c></row>',
            '<row r="13"><c r="A13" t="inlineStr"><is><t>false</t></is></c><c r="B13" t="b"><v>0</v></c></row>',
            '<row r="14"><c r="A14" t="inlineStr"><is><t>date</t></is></c><c r="B14" s="' . $date . '"><v>45000</v></c></row>',
            '<row r="15"><c r="A15" t="inlineStr"><is><t>date time</t></is></c><c r="B15" s="' . $dateTime . '"><v>45000.75</v></c></row>',
            '<row r="16"><c r="A16" t="inlineStr"><is><t>seconds</t></is></c><c r="B16" s="' . $dateTime . '"><v>45000.5000115740740741</v></c></row>',
            '<row r="17"><c r="A17" t="inlineStr"><is><t>time</t></is></c><c r="B17" s="' . $time . '"><v>0.375</v></c></row>',
            '<row r="18"><c r="A18" t="inlineStr"><is><t>duration</t></is></c><c r="B18" s="' . $elapsed . '"><v>1.5</v></c></row>',
            '<row r="19"><c r="A19" t="inlineStr"><is><t>money</t></is></c><c r="B19" s="' . $money . '"><v>12.5</v></c></row>',
            '<row r="20"><c r="A20" t="inlineStr"><is><t>formula</t></is></c><c r="B20"><f>SUM(B5:B6)</f><v>25</v></c></row>',
            '<row r="21"><c r="A21" t="inlineStr"><is><t>formula text</t></is></c><c r="B21" t="str"><f>"a"&amp;"b"</f><v>ab</v></c></row>',
            '<row r="22"><c r="A22" t="inlineStr"><is><t>no cached value</t></is></c><c r="B22"><f>SUM(B5:B6)</f></c></row>',
            '<row r="23"><c r="A23" t="inlineStr"><is><t>error</t></is></c><c r="B23" t="e"><v>#N/A</v></c></row>',
            '<row r="24"><c r="A24" t="inlineStr"><is><t>formula error</t></is></c><c r="B24" t="e"><f>1/0</f><v>#DIV/0!</v></c></row>',
            '<row r="25"><c r="A25" t="inlineStr"><is><t>equals text</t></is></c><c r="B25" t="s"><v>4</v></c></row>',
            '<row r="26"><c r="A26" t="inlineStr"><is><t>empty</t></is></c><c r="B26" s="' . $date . '"/></row>',
            '<row r="27"><c r="A27" t="inlineStr"><is><t>lines</t></is></c><c r="B27" t="s"><v>5</v></c></row>',
            '<row r="28"><c r="A28" t="inlineStr"><is><t>escapes</t></is></c><c r="B28" t="s"><v>6</v></c></row>',
            '<row r="29"><c r="A29" t="inlineStr"><is><t>iso date</t></is></c><c r="B29" t="d"><v>2025-03-14T09:30:00</v></c></row>',
            '<row r="30"><c r="A30" t="inlineStr"><is><t>bool formula</t></is></c><c r="B30" t="b"><f>TRUE()</f><v>1</v></c></row>',
            '<row r="31"><c r="A31" t="inlineStr"><is><t>early date</t></is></c><c r="B31" s="' . $date . '"><v>59</v></c></row>',
            '<row r="32"><c r="A32" t="inlineStr"><is><t>no such day</t></is></c><c r="B32" s="' . $date . '"><v>60</v></c></row>',
            '<row r="33"><c r="A33" t="inlineStr"><is><t>bad number</t></is></c><c r="B33"><v>twelve</v></c></row>',
        ];
        $b->sheet('Cells', implode('', $rows));
        $b->sheet('Secret', WorkbookBuilder::textRow(1, ['Hidden heading']) . WorkbookBuilder::textRow(2, ['hidden value']), 'hidden');
        $b->sheet('Plain', WorkbookBuilder::textRow(1, ['A', '', 'C']) . WorkbookBuilder::textRow(2, ['1', '2', '3', '4']));

        return $b->date1904($date1904);
    }

    /** Expected texts of column B for rows 2 to 33, by the kind in column A. */
    private const EXPECTED = [
        'shared' => ' padded text ', 'rich' => 'Rich text', 'inline' => 'Inline', 'int' => '42', 'negative' => '-17',
        'tenth' => '0.1', 'tiny' => '0.0000001', 'sum' => '0.30000000000000004', 'large' => '150000000000000000000',
        'minus zero' => '0', 'true' => 'true', 'false' => 'false', 'time' => '09:00', 'duration' => '36:00:00', 'money' => '12.5',
        'formula' => '25', 'formula text' => 'ab', 'no cached value' => null, 'error' => null, 'formula error' => null,
        'equals text' => '=1+1', 'empty' => null, 'lines' => "Line one\nline two & <more>", 'escapes' => "Tab\tand_x0041_ literal",
        'iso date' => '2025-03-14T09:30', 'bool formula' => 'true', 'bad number' => null,
    ];

    public function testEveryCellKindOf1900Workbook(): void
    {
        $importer = XlsxPlugins::importer();
        self::assertInstanceOf(XlsxImporter::class, $importer);
        $file = $this->file(self::cellsWorkbook(false));
        $settings = $importer->detect($file);
        self::assertSame(['sheet' => '0', 'heading' => '1'], $settings->values());
        self::assertSame(['Cells', 'Secret (hidden)', 'Plain'], array_map(static fn ($c): string => $c->label, $settings->items[0]->choices));

        $inspection = $importer->inspect($file, $settings);
        self::assertSame('Excel workbook, 3 sheets (1 hidden)', $inspection->readAs);
        self::assertSame(['Cells', 'Secret', 'Plain'], array_map(static fn ($t): string => $t->name, $inspection->tables));
        self::assertSame([false, true, false], array_map(static fn ($t): bool => $t->hidden, $inspection->tables));
        self::assertSame(['Kind', 'Value'], $inspection->tables[0]->columns);
        self::assertNull($inspection->tables[0]->rowCount);
        self::assertSame(['A', 'Column 2', 'C', 'Column 4'], $inspection->tables[2]->columns, 'Widest row; empty headings named.');

        $rows = XlsxPlugins::allRows($importer, $file, $settings);
        $got = [];
        foreach ($rows as $row) {
            self::assertCount(2, $row);
            $got[(string) $row[0]] = $row[1];
        }
        $expected = self::EXPECTED + [
            'date' => '2023-03-15', 'date time' => '2023-03-15T18:00', 'seconds' => '2023-03-15T12:00:01',
            'early date' => '1900-02-28', 'no such day' => null,
        ];
        ksort($expected);
        ksort($got);
        self::assertSame($expected, $got);

        self::assertSame([['hidden value']], XlsxPlugins::allRows($importer, $file, $settings, 1));
        self::assertSame([['1', '2', '3', '4']], XlsxPlugins::allRows($importer, $file, $settings, 2));
        self::assertSame([], XlsxPlugins::allRows($importer, $file, $settings, 3), 'No such sheet.');
    }

    public function testThe1904DateSystem(): void
    {
        $importer = XlsxPlugins::importer();
        $file = $this->file(self::cellsWorkbook(true));
        $settings = $importer->detect($file);
        $got = [];
        foreach (XlsxPlugins::allRows($importer, $file, $settings) as $row) {
            $got[(string) $row[0]] = $row[1];
        }
        self::assertSame('2027-03-16', $got['date']);
        self::assertSame('2027-03-16T18:00', $got['date time']);
        self::assertSame('09:00', $got['time']);
        self::assertSame('1904-02-29', $got['early date'], 'The 1904 system has no missing day.');
        self::assertSame('1904-03-01', $got['no such day']);
        self::assertSame('36:00:00', $got['duration'], 'Durations do not depend on the date system.');
    }

    public function testHeadingOffAndChunkedReading(): void
    {
        $importer = XlsxPlugins::importer();
        $rows = '';
        $expected = [];
        for ($r = 1; $r <= 2600; $r++) {
            if ($r % 500 === 0) {
                continue;
            }
            $rows .= WorkbookBuilder::textRow($r, ['Row ' . $r, (string) ($r * 3)]);
        }
        $file = $this->file((new WorkbookBuilder())->sheet('Long', $rows));
        $settings = $importer->detect($file)->with(['heading' => '0']);
        self::assertSame(['Column 1', 'Column 2'], $importer->inspect($file, $settings)->tables[0]->columns);
        for ($r = 1; $r <= 2600; $r++) {
            $expected[] = $r % 500 === 0 ? [null, null] : ['Row ' . $r, (string) ($r * 3)];
        }
        self::assertSame($expected, XlsxPlugins::allRows($importer, $file, $settings, 0, 700), 'Gaps in the row numbers are empty rows.');
        self::assertCount(2000, iterator_to_array($importer->rows($file, $settings, 0, 0, 5000), false), 'At most one chunk per call.');
        self::assertSame(array_slice($expected, 1234, 3), iterator_to_array($importer->rows($file, $settings, 0, 1234, 3), false));
        self::assertSame([], iterator_to_array($importer->rows($file, $settings, 0, 100000, 10), false), 'Nothing at or past the source row cap.');
        self::assertSame([], iterator_to_array($importer->rows($file, $settings, 0, 2600, 10), false), 'Rows past the end are not produced.');
    }

    public function testFarDownCellCountsEveryEmptyRowAndStopsAtTheCap(): void
    {
        $importer = XlsxPlugins::importer();
        $file = $this->file((new WorkbookBuilder())->sheet('Sparse', WorkbookBuilder::textRow(1, ['Name']) . WorkbookBuilder::textRow(2, ['first'])
            . WorkbookBuilder::textRow(1000000, ['far'])));
        $settings = $importer->detect($file);
        $start = hrtime(true);
        $chunk = iterator_to_array($importer->rows($file, $settings, 0, 0, 2000), false);
        self::assertCount(2000, $chunk);
        self::assertSame(['first'], $chunk[0]);
        self::assertSame([null], $chunk[1999]);
        $late = iterator_to_array($importer->rows($file, $settings, 0, 99990, 2000), false);
        self::assertCount(10, $late, 'A call never reaches past 100,000 rows.');
        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
    }

    public function testExtraCellsAndTheColumnLimit(): void
    {
        $importer = XlsxPlugins::importer();
        $rows = WorkbookBuilder::textRow(1, ['A', 'B']) . WorkbookBuilder::textRow(2, ['1', '2']);
        for ($r = 3; $r <= 1002; $r++) {
            $rows .= WorkbookBuilder::textRow($r, ['x']);
        }
        $rows .= '<row r="1003">' . WorkbookBuilder::inline('A1003', 'y') . WorkbookBuilder::inline('E1003', 'beyond') . '</row>';
        $file = $this->file((new WorkbookBuilder())->sheet('Wide later', $rows));
        $settings = $importer->detect($file);
        self::assertSame(['A', 'B'], $importer->inspect($file, $settings)->tables[0]->columns, 'Only the first 1,000 rows set the width.');
        $all = XlsxPlugins::allRows($importer, $file, $settings);
        self::assertSame(['y', null, null], $all[1001], 'One extra null cell marks cells past the last column.');

        $wide = '';
        for ($c = 0; $c < 201; $c++) {
            $wide .= WorkbookBuilder::inline(WorkbookBuilder::ref($c, 1), 'h' . $c);
        }
        $file = $this->file((new WorkbookBuilder())->sheet('Too wide', '<row r="1">' . $wide . '</row>'), 'wide.bin');
        self::assertSame(PluginFailure::REASON_TOO_LARGE, XlsxPlugins::failure(static fn () => $importer->inspect($file, $importer->detect($file))));
    }

    public function testEmptyAndOddSheets(): void
    {
        $importer = XlsxPlugins::importer();
        $b = (new WorkbookBuilder())->sheet('', '')->sheet("Tab\tname", '<row r="3"><c r="B3"><v>7</v></c></row>', 'veryHidden');
        $file = $this->file($b);
        $settings = $importer->detect($file);
        self::assertSame(['Sheet 1', 'Tab name (hidden)'], array_map(static fn ($c): string => $c->label, $settings->items[0]->choices));
        $inspection = $importer->inspect($file, $settings);
        self::assertSame(['Column 1'], $inspection->tables[0]->columns);
        self::assertSame(['Column 1', 'Column 2'], $inspection->tables[1]->columns, 'No row 1: the heading row is empty.');
        self::assertSame([], XlsxPlugins::allRows($importer, $file, $settings, 0));
        self::assertSame([[null, null], [null, '7']], XlsxPlugins::allRows($importer, $file, $settings, 1));
    }

    public function testRowAndCellReferencesAreChecked(): void
    {
        $importer = XlsxPlugins::importer();
        $cases = [
            'decreasing rows' => '<row r="2"><c r="A2"><v>1</v></c></row><row r="1"><c r="A1"><v>1</v></c></row>',
            'row past the format' => '<row r="1048577"><c r="A1048577"><v>1</v></c></row>',
            'column past the format' => '<row r="1"><c r="XFE1"><v>1</v></c></row>',
            'bad reference' => '<row r="1"><c r="1A"><v>1</v></c></row>',
            'not well-formed' => '<row r="1"><c r="A1"><v>1</v></row>',
        ];
        foreach ($cases as $name => $rows) {
            $file = $this->file((new WorkbookBuilder())->sheet('S', $rows), 'case.bin');
            self::assertSame(PluginFailure::REASON_UNREADABLE, XlsxPlugins::failure(static fn () => $importer->inspect($file, $importer->detect($file))), $name);
        }
    }
}
