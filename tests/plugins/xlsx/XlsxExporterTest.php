<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Plugin\Export\Xlsx\XlsxExporter;
use TiffinDB\Tests\Plugins\Json\FakeExportSource;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/XlsxPlugins.php';
require_once __DIR__ . '/../json/FakeExportSource.php';

/**
 * T-XLSX-3 (every type's cell class and format, read back by parsing the
 * written XML directly, not with any XLSX library; text `=1+1` is a
 * string cell, never a formula), T-XLSX-4 (temporary files only in the
 * job's `tmp/`), and a round trip of rows generated from IoFixture::SEED
 * through the export plugin and the import plugin.
 */
#[CoversNothing]
final class XlsxExporterTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-xlsx-export-test-');
        mkdir($this->temp->path('tmp'));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private static function table(string $key, string $name, array $fields, int $count = 0): ExportTable
    {
        return new ExportTable($key, $name, strtolower($name), null, $fields, $count);
    }

    private static function record(int $id, array $values): ExportRecord
    {
        return new ExportRecord($id, '2026-11-19T10:00:00Z', null, '2026-11-19T10:00:00Z', null, $values);
    }

    /** Writes $source and returns the export file's path. */
    private function export(FakeExportSource $source, array $values = []): string
    {
        $exporter = XlsxPlugins::exporter();
        $target = $this->temp->path('export.xlsx');
        $exporter->write($source, $exporter->settings('project')->with($values), $target);

        return $target;
    }

    /** @return array<string, string> entry name => bytes */
    private static function entries(string $path): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        $entries = [];
        for ($i = 0; $i < $zip->count(); $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xpath;
    }

    /**
     * The cells of a sheet part: reference => [t, number format code or '', v, inline text, has formula].
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: bool}>
     */
    private static function cells(array $entries, string $sheetPart): array
    {
        $styles = self::xpath($entries['xl/styles.xml']);
        $codes = [3 => '#,##0', 4 => '#,##0.00']; // built-in formats the writer refers to by id
        foreach ($styles->query('//m:numFmts/m:numFmt') as $format) {
            $codes[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }
        $xfs = [];
        foreach ($styles->query('//m:cellXfs/m:xf') as $xf) {
            $xfs[] = $codes[(int) $xf->getAttribute('numFmtId')] ?? '';
        }
        $sheet = self::xpath($entries[$sheetPart]);
        $cells = [];
        foreach ($sheet->query('//m:sheetData/m:row/m:c') as $c) {
            $v = $sheet->query('m:v', $c)->item(0);
            $is = $sheet->query('m:is', $c)->item(0);
            $cells[$c->getAttribute('r')] = [
                $c->getAttribute('t'),
                $xfs[(int) $c->getAttribute('s')] ?? '',
                $v?->textContent,
                $is?->textContent,
                $sheet->query('m:f', $c)->length > 0,
            ];
        }

        return $cells;
    }

    public function testSettings(): void
    {
        $exporter = XlsxPlugins::exporter();
        self::assertInstanceOf(XlsxExporter::class, $exporter);
        foreach (['table', 'project'] as $scope) {
            self::assertSame(['record_ids' => '0'], $exporter->settings($scope)->values(), 'No spreadsheet-safe setting: text is never a formula.');
        }
    }

    public function testEveryTypesCellClassAndFormat(): void
    {
        $types = [
            ['text', [], new ExportValue('=1+1', '=1+1', [])],
            ['long_text', [], new ExportValue("@SUM(A1)\nsecond line", "@SUM(A1)\nsecond line", [])],
            ['url', [], new ExportValue('https://example.com/a', 'https://example.com/a', [])],
            ['email', [], new ExportValue('user@example.com', 'user@example.com', [])],
            ['phone', [], new ExportValue('+1 555-0100', '+1 555-0100', [])],
            ['choice', [], new ExportValue('-Red', '-Red', [])],
            ['relation', [], new ExportValue([3, 4], 'Kitchen; Hall', [3, 4])],
            ['attachment', [], new ExportValue([9], 'manual.pdf', [9])],
            ['number', ['decimals' => 2], new ExportValue(1234.5, '1234.50', [])],
            ['number', ['decimals' => 3], new ExportValue(123456789012.345, '123456789012.345', [])],
            ['rating', ['max' => 5], new ExportValue(4, '4', [])],
            ['auto_number', [], new ExportValue(17, '17', [])],
            ['currency', ['code' => 'USD', 'minor_digits' => 2], new ExportValue(-123456, '-1234.56', [])],
            ['currency', ['code' => 'JPY', 'minor_digits' => 0], new ExportValue(1200, '1200', [])],
            ['currency', ['code' => 'USD', 'minor_digits' => 3], new ExportValue(8999999999999999, '8999999999999.999', [])],
            ['date', [], new ExportValue('2025-03-14', '2025-03-14', [])],
            ['date', [], new ExportValue('1899-12-31', '1899-12-31', [])],
            ['date_time', ['seconds' => false], new ExportValue('2025-03-14T09:30:00', '2025-03-14T09:30', [])],
            ['date_time', ['seconds' => true], new ExportValue('2025-03-14T09:30:15', '2025-03-14T09:30:15', [])],
            ['time', [], new ExportValue('09:05:00', '09:05', [])],
            ['duration', [], new ExportValue(5400, '1:30', [])],
            ['checkbox', [], new ExportValue(true, 'yes', [])],
            ['checkbox', [], new ExportValue(false, 'no', [])],
            ['text', [], new ExportValue(null, '', [])],
            ['checkbox', [], null],
        ];
        $fields = [];
        $values = [];
        foreach ($types as $i => [$type, $options, $value]) {
            $fields[] = new ExportField('k' . $i, 'Field ' . $i, $type, $options, false, '', null);
            if ($value !== null) {
                $values['k' . $i] = $value;
            }
        }
        $source = new FakeExportSource([self::table('t1', 'Things', $fields, 1)], static fn (string $key): array => [self::record(7, $values)]);
        $entries = self::entries($this->export($source));
        foreach ($entries as $name => $bytes) {
            self::assertStringNotContainsString('<f>', $bytes, $name . ' holds no formula');
            self::assertStringNotContainsString('<f ', $bytes, $name . ' holds no formula');
        }
        self::assertStringNotContainsString('<si>', $entries['xl/sharedStrings.xml'] ?? '', 'Text is written inline.');
        $cells = self::cells($entries, 'xl/worksheets/sheet1.xml');

        self::assertSame(['inlineStr', '', null, 'Field 0', false], $cells['A1']);
        $expected = [
            'A2' => ['inlineStr', '', null, '=1+1', false],
            'B2' => ['inlineStr', '', null, "@SUM(A1)\nsecond line", false],
            'C2' => ['inlineStr', '', null, 'https://example.com/a', false],
            'D2' => ['inlineStr', '', null, 'user@example.com', false],
            'E2' => ['inlineStr', '', null, '+1 555-0100', false],
            'F2' => ['inlineStr', '', null, '-Red', false],
            'G2' => ['inlineStr', '', null, 'Kitchen; Hall', false],
            'H2' => ['inlineStr', '', null, 'manual.pdf', false],
            'I2' => ['', '', '1234.5', null, false],
            'J2' => ['inlineStr', '', null, '123456789012.345', false],
            'K2' => ['', '', '4', null, false],
            'L2' => ['', '', '17', null, false],
            'M2' => ['', '#,##0.00', '-1234.56', null, false],
            'N2' => ['', '#,##0', '1200', null, false],
            'O2' => ['inlineStr', '', null, '8999999999999.999', false],
            'P2' => ['', 'yyyy-mm-dd', '45730', null, false],
            'Q2' => ['inlineStr', '', null, '1899-12-31', false],
            'R2' => ['', 'yyyy-mm-dd hh:mm', '45730.395833333', null, false],
            'S2' => ['', 'yyyy-mm-dd hh:mm:ss', null, null, false],
            'T2' => ['inlineStr', '', null, '09:05', false],
            'U2' => ['inlineStr', '', null, '1:30', false],
            'V2' => ['b', '', '1', null, false],
            'W2' => ['b', '', '0', null, false],
            'Y2' => ['b', '', '0', null, false],
        ];
        foreach ($expected as $ref => $cell) {
            self::assertArrayHasKey($ref, $cells, $ref);
            if ($ref === 'R2' || $ref === 'S2') {
                self::assertSame($cell[0], $cells[$ref][0], $ref);
                self::assertSame($cell[1], $cells[$ref][1], $ref);
                continue;
            }
            self::assertSame($cell, $cells[$ref], $ref);
        }
        self::assertEqualsWithDelta(45730 + (9 * 3600 + 30 * 60) / 86400, (float) $cells['R2'][2], 1e-9);
        self::assertEqualsWithDelta(45730 + (9 * 3600 + 30 * 60 + 15) / 86400, (float) $cells['S2'][2], 1e-9);
        self::assertArrayNotHasKey('X2', $cells, 'An empty value is an empty cell, not written.');

        // The import plugin reads the same texts back (the currency amount as a plain number).
        $importer = XlsxPlugins::importer();
        $file = new SourceFile($this->temp->path('export.xlsx'), 'xlsx', (int) filesize($this->temp->path('export.xlsx')), $this->temp->path('tmp'));
        $rows = XlsxPlugins::allRows($importer, $file, $importer->detect($file));
        self::assertSame([[
            '=1+1', "@SUM(A1)\nsecond line", 'https://example.com/a', 'user@example.com', '+1 555-0100', '-Red', 'Kitchen; Hall', 'manual.pdf',
            '1234.5', '123456789012.345', '4', '17', '-1234.56', '1200', '8999999999999.999', '2025-03-14', '1899-12-31', '2025-03-14T09:30',
            '2025-03-14T09:30:15', '09:05', '1:30', 'true', 'false', null, 'false',
        ]], $rows);
    }

    public function testSheetsPerTableNamesAndRecordIds(): void
    {
        $field = [new ExportField('n', 'Name', 'text', [], false, '', null)];
        $tables = [
            self::table('a', 'Rooms: [main] / *all*?', $field),
            self::table('b', 'rooms: main  all', $field),
            self::table('c', "'Quoted'", $field),
            self::table('d', str_repeat('Long name ', 6), $field),
            self::table('e', str_repeat('Long name ', 6), $field),
            self::table('f', '[]', $field),
        ];
        $records = static fn (string $key): array => [self::record(3, ['n' => new ExportValue('x' . $key, 'x' . $key, [])]), self::record(9, [])];
        $entries = self::entries($this->export(new FakeExportSource($tables, $records), ['record_ids' => '1']));
        $names = [];
        foreach (self::xpath($entries['xl/workbook.xml'])->query('//m:sheets/m:sheet') as $sheet) {
            $names[] = $sheet->getAttribute('name');
        }
        self::assertSame(['Rooms main  all', 'rooms main  all 2', 'Quoted', 'Long name Long name Long name L', 'Long name Long name Long name 2', 'Sheet'], $names);
        $cells = self::cells($entries, 'xl/worksheets/sheet4.xml');
        self::assertSame('id', $cells['A1'][3]);
        self::assertSame('Name', $cells['B1'][3]);
        self::assertSame(['', '', '3', null, false], $cells['A2']);
        self::assertSame('xd', $cells['B2'][3]);
        self::assertSame(['', '', '9', null, false], $cells['A3']);
        self::assertArrayNotHasKey('B3', $cells);
        self::assertStringContainsString('<b/>', $entries['xl/styles.xml'], 'The heading row is bold.');
    }

    public function testTemporaryFilesStayInTheJobsTmpFolder(): void
    {
        $tmp = $this->temp->path('tmp');
        $system = sys_get_temp_dir();
        $before = self::xlsxEntries($system);
        $seen = [];
        $fields = [new ExportField('n', 'Name', 'text', [], false, '', null)];
        $source = new FakeExportSource([self::table('t', 'Things', $fields)], static function (string $key) use ($tmp, &$seen): \Generator {
            for ($i = 1; $i <= 300; $i++) {
                if ($i === 150) {
                    $seen = array_values(array_diff((array) scandir($tmp), ['.', '..']));
                }
                yield self::record($i, ['n' => new ExportValue('Item ' . $i, 'Item ' . $i, [])]);
            }
        });
        $this->export($source);
        self::assertCount(1, $seen, 'While writing, the library works in the job tmp/ folder.');
        self::assertStringStartsWith('xlsx', $seen[0]);
        self::assertSame([], array_values(array_diff((array) scandir($tmp), ['.', '..'])), 'Nothing is left in tmp/.');
        self::assertSame($before, self::xlsxEntries($system), 'Nothing is written to the system temporary folder.');

        $importer = XlsxPlugins::importer();
        $file = new SourceFile($this->temp->path('export.xlsx'), 'xlsx', (int) filesize($this->temp->path('export.xlsx')), $tmp);
        self::assertCount(300, XlsxPlugins::allRows($importer, $file, $importer->detect($file), 0, 100));
        self::assertSame([], array_values(array_diff((array) scandir($tmp), ['.', '..'])), 'Reading writes no temporary files.');
        self::assertSame($before, self::xlsxEntries($system));

        $missing = $this->temp->path('no-tmp');
        mkdir($missing);
        $exporter = XlsxPlugins::exporter();
        self::assertSame(PluginFailure::REASON_FAILED, XlsxPlugins::failure(static fn () => $exporter->write($source, $exporter->settings('table'), $missing . '/x.xlsx')));
        self::assertFileDoesNotExist($missing . '/x.xlsx');
    }

    /** @return list<string> */
    private static function xlsxEntries(string $folder): array
    {
        return array_values(array_filter((array) scandir($folder), static fn ($name): bool => is_string($name) && str_starts_with($name, 'xlsx')));
    }

    public function testAFailingSourceRemovesThePartialFile(): void
    {
        $fields = [new ExportField('n', 'Name', 'text', [], false, '', null)];
        $source = new FakeExportSource([self::table('t', 'Things', $fields)], static function (string $key): \Generator {
            yield self::record(1, ['n' => new ExportValue('a', 'a', [])]);

            throw new \RuntimeException('The source failed.');
        });
        try {
            $this->export($source);
            self::fail('The failure was not passed on.');
        } catch (\RuntimeException $error) {
            self::assertSame('The source failed.', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->temp->path('export.xlsx'));
    }

    public function testRoundTripOfSeededRows(): void
    {
        $rows = (new IoFixture())->itemRows(2500);
        $fields = [
            new ExportField('name', 'Name', 'text', [], true, '', null),
            new ExportField('room', 'Room', 'choice', ['choices' => IoFixture::ROOMS], false, '', null),
            new ExportField('price', 'Price', 'currency', ['code' => 'USD', 'minor_digits' => 2], false, '', null),
            new ExportField('purchased', 'Purchased', 'date', [], false, '', null),
            new ExportField('quantity', 'Quantity', 'number', ['decimals' => 0], false, '', null),
            new ExportField('in_use', 'In use', 'checkbox', [], false, '', null),
            new ExportField('notes', 'Notes', 'long_text', [], false, '', null),
        ];
        $records = static function (string $key) use ($rows): \Generator {
            foreach ($rows as $i => [$name, $room, $price, $date, $quantity, $inUse, $notes]) {
                $minor = (int) str_replace(['$', '.'], '', $price);
                yield self::record($i + 1, [
                    'name' => new ExportValue($name, $name, []),
                    'room' => new ExportValue($room, $room, []),
                    'price' => new ExportValue($minor, substr($price, 1), []),
                    'purchased' => new ExportValue($date, $date, []),
                    'quantity' => new ExportValue((int) $quantity, $quantity, []),
                    'in_use' => new ExportValue($inUse === 'yes', $inUse, []),
                    'notes' => new ExportValue($notes === '' ? null : $notes, $notes, []),
                ]);
            }
        };
        $path = $this->export(new FakeExportSource([self::table('items', 'Items', $fields, count($rows))], $records));
        $importer = XlsxPlugins::importer();
        $file = new SourceFile($path, 'xlsx', (int) filesize($path), $this->temp->path('tmp'));
        $settings = $importer->detect($file);
        self::assertSame(IoFixture::HEADINGS, $importer->inspect($file, $settings)->tables[0]->columns);
        $expected = [];
        foreach ($rows as [$name, $room, $price, $date, $quantity, $inUse, $notes]) {
            $amount = rtrim(rtrim(substr($price, 1), '0'), '.');
            $expected[] = [$name, $room, $amount, $date, $quantity, $inUse === 'yes' ? 'true' : 'false', $notes === '' ? null : $notes];
        }
        self::assertSame($expected, XlsxPlugins::allRows($importer, $file, $settings));
    }
}
