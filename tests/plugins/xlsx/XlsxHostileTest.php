<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/WorkbookBuilder.php';
require_once __DIR__ . '/XlsxPlugins.php';

/**
 * T-XLSX-6: hostile workbooks. A zip bomb with honest headers is refused by
 * the zip check; a worksheet, shared strings, or workbook part with a
 * DOCTYPE (an external entity naming a local file or a network address,
 * and separately nested internal entities) is refused before any XML of
 * the workbook is parsed, so nothing is read, fetched, or expanded; shared
 * strings bombs, deep nesting, and over-wide rows end in a clean
 * PluginFailure; parts the plugin does not read (hyperlinks, merged cells,
 * comments, external links) are ignored; and 50,000 rows by 20 columns
 * are read in chunks within the chunk limits and 128 MB of memory.
 */
#[CoversNothing]
final class XlsxHostileTest extends TestCase
{
    private const SECRET = 'synthetic-secret-7f3a';

    private TempDir $temp;
    private Importer $importer;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-xlsx-hostile-test-');
        mkdir($this->temp->path('tmp'));
        file_put_contents($this->temp->path('secret.txt'), self::SECRET);
        $this->importer = XlsxPlugins::importer();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @param array<string, string> $parts */
    private function file(array $parts, array $stored = []): SourceFile
    {
        $path = $this->temp->path('upload.bin');
        WorkbookBuilder::zip($path, $parts, $stored);

        return new SourceFile($path, 'xlsx', (int) filesize($path), $this->temp->path('tmp'));
    }

    /** Detect, inspect, and read every row; returns the failure reason (null when all worked) and the rows read. */
    private function readAll(SourceFile $file): array
    {
        $importer = $this->importer;
        $rows = [];
        $reason = XlsxPlugins::failure(static function () use ($importer, $file, &$rows): void {
            $settings = $importer->detect($file);
            $importer->inspect($file, $settings);
            $rows = XlsxPlugins::allRows($importer, $file, $settings);
        });

        return [$reason, $rows];
    }

    /** A one-sheet workbook; its last row refers to shared string 0, so the shared strings part is read. */
    private static function basic(): WorkbookBuilder
    {
        return (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['Name']) . WorkbookBuilder::textRow(2, ['value'])
            . '<row r="3"><c r="A3" t="s"><v>0</v></c></row>');
    }

    public function testZipBombWithHonestHeaders(): void
    {
        $parts = self::basic()->parts();
        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData/>'
            . str_repeat('<!-- padding -->', 1310720) . '</worksheet>';
        $file = $this->file($parts);
        self::assertLessThan(1048576, $file->size, 'About 20 MiB deflated to well under 1 MiB.');
        $start = hrtime(true);
        self::assertSame([PluginFailure::REASON_TOO_LARGE, []], $this->readAll($file));
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
    }

    /** @return array<string, string> description => DOCTYPE */
    private function doctypes(): array
    {
        $secret = 'file://' . $this->temp->path('secret.txt');

        return [
            'external file entity' => '<!DOCTYPE x [<!ENTITY e SYSTEM "' . $secret . '">]>',
            'external network entity' => '<!DOCTYPE x [<!ENTITY e SYSTEM "http://192.0.2.10/x.xml">]>',
            'external parameter entity' => '<!DOCTYPE x [<!ENTITY % p SYSTEM "' . $secret . '"> %p;]>',
            'external DTD' => '<!DOCTYPE x SYSTEM "http://192.0.2.10/x.dtd">',
            'nested internal entities' => '<!DOCTYPE x [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
                . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
                . '<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;"><!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;">'
                . '<!ENTITY g "&f;&f;&f;&f;&f;&f;&f;&f;&f;&f;"><!ENTITY h "&g;&g;&g;&g;&g;&g;&g;&g;&g;&g;">]>',
        ];
    }

    public function testADoctypeInAnyPartIsRefusedBeforeParsing(): void
    {
        foreach ($this->doctypes() as $label => $doctype) {
            $builder = self::basic()->sharedStrings(['<t>shared</t>']);
            $parts = $builder->parts();
            $variants = [
                'worksheet' => ['xl/worksheets/sheet1.xml', '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>'],
                'shared strings' => ['xl/sharedStrings.xml', '<sst xmlns="' . WorkbookBuilder::MAIN . '"><si><t>&e;</t></si></sst>'],
                'workbook' => ['xl/workbook.xml', substr($parts['xl/workbook.xml'], strlen(WorkbookBuilder::HEAD))],
                'relationships' => ['xl/_rels/workbook.xml.rels', substr($parts['xl/_rels/workbook.xml.rels'], strlen(WorkbookBuilder::HEAD))],
                'styles' => ['xl/styles.xml', substr($parts['xl/styles.xml'], strlen(WorkbookBuilder::HEAD))],
            ];
            foreach ($variants as $where => [$name, $body]) {
                $hostile = $parts;
                $hostile[$name] = WorkbookBuilder::HEAD . $doctype . $body;
                memory_reset_peak_usage();
                $before = memory_get_usage();
                $start = hrtime(true);
                [$reason, $rows] = $this->readAll($this->file($hostile));
                self::assertSame(PluginFailure::REASON_UNSUPPORTED, $reason, $label . ' in the ' . $where . ' part');
                self::assertSame([], $rows);
                self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9, $label);
                self::assertLessThan(8 * 1048576, memory_get_peak_usage() - $before, $label . ': nothing expanded');
            }
        }
    }

    public function testEntityReferencesWithoutADeclarationAreNotRead(): void
    {
        $parts = self::basic()->parts();
        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>';
        [$reason, $rows] = $this->readAll($this->file($parts));
        self::assertSame(PluginFailure::REASON_UNREADABLE, $reason, 'An undeclared entity is an XML error.');
        self::assertSame([], $rows);
    }

    public function testSharedStringsBombs(): void
    {
        $count = self::basic()->sharedStrings([])->parts();
        $count['xl/sharedStrings.xml'] = WorkbookBuilder::HEAD . '<sst xmlns="' . WorkbookBuilder::MAIN . '" count="1" uniqueCount="1">'
            . str_repeat('<si/>', 2000001) . '</sst>';
        memory_reset_peak_usage();
        $before = memory_get_usage();
        self::assertSame(PluginFailure::REASON_TOO_LARGE, $this->readAll($this->file($count, ['xl/sharedStrings.xml']))[0], 'More strings than the limit.');
        unset($count);

        $bytes = self::basic()->sharedStrings([])->parts();
        $bytes['xl/sharedStrings.xml'] = WorkbookBuilder::HEAD . '<sst xmlns="' . WorkbookBuilder::MAIN . '">'
            . str_repeat('<si><t>' . str_repeat('ab', 40000) . '</t></si>', 430) . '</sst>';
        self::assertSame(PluginFailure::REASON_TOO_LARGE, $this->readAll($this->file($bytes, ['xl/sharedStrings.xml']))[0], 'More bytes than the limit.');
        unset($bytes);

        $claim = self::basic()->sharedStrings(['<t>one</t>'])->parts();
        $claim['xl/sharedStrings.xml'] = str_replace('uniqueCount="1"', 'uniqueCount="2000000000"', $claim['xl/sharedStrings.xml']);
        $claim['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1999999999</v></c><c r="C1" t="s"><v>-1</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>0</v></c></row></sheetData></worksheet>';
        self::assertSame([null, [['one']]], $this->readAll($this->file($claim)), 'Counts are not trusted; unknown indexes are empty.');
        self::assertLessThan(128 * 1048576, memory_get_peak_usage() - $before);
    }

    public function testDeepNestingAndOverWideRows(): void
    {
        $parts = self::basic()->parts();
        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is>' . str_repeat('<r>', 5000) . str_repeat('</r>', 5000) . '</is></c></row></sheetData></worksheet>';
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->readAll($this->file($parts))[0], 'Nesting past libxml\'s depth limit.');

        $cells = str_repeat('<c/>', 16385);
        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData>'
            . '<row r="1">' . $cells . '</row></sheetData></worksheet>';
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->readAll($this->file($parts))[0], 'More cells than the format has columns.');

        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><dimension ref="A1:XFD1048576"/>'
            . '<sheetData><row r="1" spans="1:2000000000"><c r="A1" t="inlineStr"><is><t>Name</t></is></c></row>'
            . '<row r="2" spans="1:16384"><c r="A2" t="inlineStr"><is><t>v</t></is></c><c r="XFD2"/></row></sheetData></worksheet>';
        $start = hrtime(true);
        self::assertSame([null, [['v']]], $this->readAll($this->file($parts)), 'Declared dimensions and spans are not used.');
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
    }

    public function testPartsThatAreNotReadAreIgnored(): void
    {
        $parts = self::basic()->parts();
        $parts['xl/worksheets/sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '" xmlns:r="' . WorkbookBuilder::REL . '">'
            . '<sheetData>' . WorkbookBuilder::textRow(1, ['Name', 'Link']) . '<row r="2">' . WorkbookBuilder::inline('A2', 'merged')
            . '<c r="B2" t="inlineStr"><is><t>Example site</t></is></c></row></sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A2:A3"/></mergeCells>'
            . '<hyperlinks><hyperlink ref="B2" r:id="rIdLink"/></hyperlinks></worksheet>';
        $parts['xl/worksheets/_rels/sheet1.xml.rels'] = WorkbookBuilder::HEAD . '<Relationships xmlns="' . WorkbookBuilder::PKG . '">'
            . '<Relationship Id="rIdLink" Type="' . WorkbookBuilder::REL . '/hyperlink" Target="https://example.com/" TargetMode="External"/>'
            . '<Relationship Id="rIdC" Type="' . WorkbookBuilder::REL . '/comments" Target="../comments1.xml"/></Relationships>';
        $parts['xl/comments1.xml'] = WorkbookBuilder::HEAD . '<comments xmlns="' . WorkbookBuilder::MAIN . '"><authors><author>Example Person</author></authors>'
            . '<commentList><comment ref="A2" authorId="0"><text><t>note</t></text></comment></commentList></comments>';
        $parts['xl/externalLinks/externalLink1.xml'] = WorkbookBuilder::HEAD . '<externalLink xmlns="' . WorkbookBuilder::MAIN . '"/>';
        $parts['xl/externalLinks/_rels/externalLink1.xml.rels'] = WorkbookBuilder::HEAD . '<Relationships xmlns="' . WorkbookBuilder::PKG . '">'
            . '<Relationship Id="rId1" Type="' . WorkbookBuilder::REL . '/externalLinkPath" Target="file:///' . $this->temp->path('secret.txt') . '" TargetMode="External"/></Relationships>';
        $parts['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rIdX" Type="' . WorkbookBuilder::REL
            . '/externalLink" Target="externalLinks/externalLink1.xml"/><Relationship Id="rIdOut" Type="' . WorkbookBuilder::REL
            . '/worksheet" Target="../../outside.xml"/><Relationship Id="rIdFar" Type="' . WorkbookBuilder::REL
            . '/worksheet" Target="file:///etc/hostname" TargetMode="External"/></Relationships>', $parts['xl/_rels/workbook.xml.rels']);
        $parts['xl/workbook.xml'] = str_replace('</sheets>', '<sheet name="Out" sheetId="8" r:id="rIdOut"/><sheet name="Far" sheetId="9" r:id="rIdFar"/></sheets>'
            . '<externalReferences><externalReference r:id="rIdX"/></externalReferences>', $parts['xl/workbook.xml']);
        $file = $this->file($parts);
        self::assertSame([null, [['merged', 'Example site']]], $this->readAll($file));
        self::assertCount(1, $this->importer->inspect($file, $this->importer->detect($file))->tables, 'Targets outside the package are not sheets.');
    }

    public function testFiftyThousandRowsByTwentyColumnsWithinTheChunkLimits(): void
    {
        $rows = [WorkbookBuilder::textRow(1, array_map(static fn (int $c): string => 'Heading ' . $c, range(1, 20)))];
        for ($r = 2; $r <= 50001; $r++) {
            $cells = '';
            for ($c = 0; $c < 20; $c++) {
                $ref = WorkbookBuilder::ref($c, $r);
                $cells .= $c % 2 === 0
                    ? '<c r="' . $ref . '" t="inlineStr"><is><t>Synthetic ' . $r . '-' . $c . '</t></is></c>'
                    : '<c r="' . $ref . '"><v>' . ($r * 31 + $c) . '.25</v></c>';
            }
            $rows[] = '<row r="' . $r . '">' . $cells . '</row>';
        }
        $path = (new WorkbookBuilder())->sheet('Big', implode('', $rows))->write($this->temp->path('upload.bin'));
        unset($rows);
        $file = new SourceFile($path, 'xlsx', (int) filesize($path), $this->temp->path('tmp'));
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $settings = $this->importer->detect($file);
        self::assertCount(20, $this->importer->inspect($file, $settings)->tables[0]->columns);
        $read = 0;
        $slowest = 0.0;
        while (true) {
            $start = hrtime(true);
            $got = 0;
            foreach ($this->importer->rows($file, $settings, 0, $read, 2000) as $row) {
                if ($got === 0) {
                    self::assertSame('Synthetic ' . ($read + 2) . '-0', $row[0]);
                    self::assertSame((($read + 2) * 31 + 1) . '.25', $row[1]);
                }
                $got++;
            }
            $slowest = max($slowest, (hrtime(true) - $start) / 1e9);
            $read += $got;
            if ($got < 2000) {
                break;
            }
        }
        self::assertSame(50000, $read);
        self::assertLessThan(5.0, $slowest, 'Every chunk within the 5-second chunk limit.');
        self::assertLessThan(128 * 1048576, memory_get_peak_usage() - $before);
    }
}
