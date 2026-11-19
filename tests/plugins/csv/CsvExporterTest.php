<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Csv;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportProject;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvReader;
use TiffinDB\Plugin\Export\Csv\CsvExporter;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/CsvPlugins.php';

/**
 * T-CSV-8 (the CSV export plugin through the contract, with an export
 * source built from IoFixture rows), T-CSV-5 (heading row, record ids
 * option), and T-CSV-6 (the spreadsheet-safe option on every text-like
 * type, never on numbers and dates; off by default, exact values).
 */
#[CoversNothing]
final class CsvExporterTest extends TestCase
{
    /** Every type with a formula-like text; text-like ones are protected when the option is on. */
    private const TYPES = [
        'text' => ['=1+2', true], 'long_text' => ["+line\nmore", true], 'url' => ['@x', true], 'email' => ['-a@example.com', true],
        'phone' => ["\t555-0100", true], 'choice' => ['=Red', true], 'relation' => ['-Kitchen; Hall', true], 'attachment' => ['@a.txt', true],
        'number' => ['-5', false], 'currency' => ['-12.30', false], 'rating' => ['3', false], 'duration' => ['-1:30', false],
        'date' => ['2025-02-03', false], 'time' => ['09:05', false], 'date_time' => ['2025-02-03T04:05:00Z', false],
        'checkbox' => ['yes', false], 'auto_number' => ['7', false],
    ];

    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-csv-export-test-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testSettings(): void
    {
        $exporter = CsvPlugins::exporter();
        self::assertInstanceOf(CsvExporter::class, $exporter);
        $settings = $exporter->settings('table');
        self::assertSame(['record_ids' => '0', 'spreadsheet_safe' => '0'], $settings->values(), 'Both options are off by default (P135).');
        self::assertSame('Protect against spreadsheet formulas', $settings->items[1]->label);
        self::assertSame([], $exporter->settings('project')->items);
    }

    public function testWritesFixtureRowsExactly(): void
    {
        $rows = (new IoFixture())->itemRows(1200);
        $fields = [];
        foreach (IoFixture::HEADINGS as $i => $heading) {
            $fields[] = new ExportField('f' . $i, $heading, 'text', [], false, '', null);
        }
        $records = [];
        foreach ($rows as $n => $row) {
            $values = [];
            foreach ($row as $i => $cell) {
                $values['f' . $i] = new ExportValue($cell === '' ? null : $cell, $cell, []);
            }
            $records[] = new ExportRecord($n + 1, '2026-01-01T00:00:00Z', null, '2026-01-01T00:00:00Z', null, $values);
        }
        $path = $this->write(new ExportTable('t1', 'Items', 'item', 'f0', $fields, count($records)), $records, []);
        self::assertSame("\xEF\xBB\xBF" . IoFixture::csv(IoFixture::HEADINGS, $rows), file_get_contents($path), 'Same bytes as the fixture CSV (with BOM).');

        $path = $this->write(new ExportTable('t1', 'Items', 'item', 'f0', $fields, count($records)), array_slice($records, 0, 2), ['record_ids' => '1']);
        $lines = explode("\r\n", substr((string) file_get_contents($path), 3));
        self::assertSame('id,' . implode(',', IoFixture::HEADINGS), $lines[0]);
        self::assertStringStartsWith('1,', $lines[1]);
        self::assertStringStartsWith('2,', $lines[2]);
    }

    public function testSpreadsheetSafeOption(): void
    {
        $fields = [];
        $values = [];
        foreach (self::TYPES as $type => [$text]) {
            $fields[] = new ExportField('k_' . $type, 'F ' . $type, $type, [], false, '', $type === 'relation' ? 'other' : null);
            $values['k_' . $type] = new ExportValue($text, $text, []);
        }
        $fields[] = new ExportField('k_named', '=Name', 'text', [], false, '', null);
        $values['k_named'] = new ExportValue(null, '', []);
        $table = new ExportTable('t1', 'Things', 'thing', null, $fields, 1);
        $record = new ExportRecord(1, '2026-01-01T00:00:00Z', null, '2026-01-01T00:00:00Z', null, $values);

        $exact = self::read($this->write($table, [$record], []));
        $safe = self::read($this->write($table, [$record], ['spreadsheet_safe' => '1']));
        $i = 0;
        foreach (self::TYPES as $type => [$text, $textLike]) {
            self::assertSame($text, $exact[1][$i], $type . ' exact by default');
            self::assertSame($textLike ? "'" . $text : $text, $safe[1][$i], $type . ' with the option');
            $i++;
        }
        self::assertSame('=Name', $exact[0][$i]);
        self::assertSame("'=Name", $safe[0][$i], 'Headings are protected too.');
    }

    public function testOneTableOnly(): void
    {
        $exporter = CsvPlugins::exporter();
        $source = new FakeSource([], []);
        $this->expectException(PluginFailure::class);
        $exporter->write($source, $exporter->settings('table'), $this->temp->path('x.csv'));
    }

    /**
     * @param list<ExportRecord> $records
     * @param array<string, string> $values
     */
    private function write(ExportTable $table, array $records, array $values): string
    {
        $exporter = CsvPlugins::exporter();
        $path = $this->temp->path('out-' . bin2hex(random_bytes(4)) . '.csv');
        $exporter->write(new FakeSource([$table], $records), $exporter->settings('table')->with($values), $path);

        return $path;
    }

    /** @return list<list<string>> */
    private static function read(string $path): array
    {
        $reader = new CsvReader($path, new CsvDialect('utf-8', ','));
        $out = [];
        while (($record = $reader->next()) !== null) {
            $out[] = $record;
        }

        return $out;
    }
}

/** An export source over fixed tables and records. */
final class FakeSource implements ExportSource
{
    /**
     * @param list<ExportTable> $tables
     * @param list<ExportRecord> $records
     */
    public function __construct(private readonly array $tables, private readonly array $records)
    {
    }

    public function project(): ExportProject
    {
        return new ExportProject('p1', 'Example Project', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 3, '2026-01-02T00:00:00Z', 'test');
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function records(string $tableKey): iterable
    {
        yield from $this->records;
    }

    public function relations(): array
    {
        return [];
    }

    public function attachments(): iterable
    {
        return [];
    }
}
