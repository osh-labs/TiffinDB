<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Csv;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Plugin\Import\Csv\CsvImporter;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/CsvPlugins.php';

/** T-CSV-7: the CSV import plugin through the contract: detect, inspect (readAs text), rows. */
#[CoversNothing]
final class CsvImporterTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-csv-plugin-test-');
        mkdir($this->temp->path('temp'));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function source(string $bytes): SourceFile
    {
        $path = $this->temp->path('upload.bin');
        file_put_contents($path, $bytes);

        return new SourceFile($path, 'csv', strlen($bytes), $this->temp->path('temp'));
    }

    public function testDetectInspectRows(): void
    {
        $importer = CsvPlugins::importer();
        self::assertInstanceOf(CsvImporter::class, $importer);
        $rows = (new IoFixture())->itemRows(2500);
        $file = $this->source("\xEF\xBB\xBF" . IoFixture::csv(IoFixture::HEADINGS, $rows));

        $settings = $importer->detect($file);
        self::assertSame(['encoding' => 'utf-8', 'delimiter' => 'comma', 'heading' => '1'], $settings->values());
        self::assertSame(['Text encoding', 'Values are separated by', 'The first row holds the column names'], array_map(static fn ($s): string => $s->label, $settings->items));
        self::assertSame(['utf-8', 'utf-16le', 'utf-16be', 'windows-1252'], array_map(static fn ($c): string => $c->value, $settings->items[0]->choices));

        $inspection = $importer->inspect($file, $settings);
        self::assertSame('CSV, commas, UTF-8', $inspection->readAs);
        self::assertCount(1, $inspection->tables);
        self::assertSame(IoFixture::HEADINGS, $inspection->tables[0]->columns);
        self::assertNull($inspection->tables[0]->rowCount);

        $expected = array_map(static fn (array $row): array => array_map(static fn (string $c): ?string => $c === '' ? null : $c, $row), $rows);
        $got = [...self::list($importer->rows($file, $settings, 0, 0, 2000)), ...self::list($importer->rows($file, $settings, 0, 2000, 2000))];
        self::assertSame($expected, $got);
        self::assertSame(array_slice($expected, 2400, 3), self::list($importer->rows($file, $settings, 0, 2400, 3)));
        self::assertSame([], self::list($importer->rows($file, $settings, 1, 0, 10)), 'A CSV has one table.');
        self::assertCount(2000, self::list($importer->rows($file, $settings, 0, 0, 9999)), 'At most one chunk per call.');
    }

    public function testChangedReadingOptions(): void
    {
        $importer = CsvPlugins::importer();
        $file = $this->source("a;b\r\nc;d\r\n");
        $settings = $importer->detect($file);
        self::assertSame('semicolon', $settings->value('delimiter'));
        $plain = $settings->with(['heading' => '0', 'delimiter' => 'comma', 'encoding' => 'windows-1252']);
        $inspection = $importer->inspect($file, $plain);
        self::assertSame('CSV, commas, Windows Latin 1', $inspection->readAs);
        self::assertSame(['Column 1'], $inspection->tables[0]->columns);
        self::assertSame([['a;b'], ['c;d']], self::list($importer->rows($file, $plain, 0, 0, 10)));
        $ignored = $settings->with(['delimiter' => "\t", 'encoding' => 'ebcdic']);
        self::assertSame($settings->values(), $ignored->values(), 'Values outside the choices are ignored.');
    }

    public function testFailures(): void
    {
        $importer = CsvPlugins::importer();
        $empty = $this->source('');
        try {
            $importer->inspect($empty, $importer->detect($empty));
            self::fail('An empty file was read.');
        } catch (PluginFailure $failure) {
            self::assertSame(PluginFailure::REASON_UNREADABLE, $failure->reason);
        }
        $wide = $this->source(str_repeat('h,', 300) . "\n");
        $this->expectException(PluginFailure::class);
        $importer->inspect($wide, $importer->detect($wide));
    }

    /**
     * @param iterable<int, list<?string>> $rows
     * @return list<list<?string>>
     */
    private static function list(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
