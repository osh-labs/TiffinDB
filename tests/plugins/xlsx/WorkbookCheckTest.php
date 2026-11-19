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
use TiffinDB\Plugin\Import\Xlsx\WorkbookCheck;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/WorkbookBuilder.php';
require_once __DIR__ . '/XlsxPlugins.php';

/**
 * T-XLSX-1: the zip check of M3 spec section 9.1, through the importer
 * (detect, inspect, and rows all refuse) and on WorkbookCheck itself: not
 * a zip, missing parts, too many entries, oversized, too large in total and
 * high-ratio entries (declared in forged headers), an encrypted entry, an
 * unsupported compression method, names that are not allowed, duplicates,
 * and the prolog rules.
 */
#[CoversNothing]
final class WorkbookCheckTest extends TestCase
{
    private TempDir $temp;
    private Importer $importer;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-xlsx-check-test-');
        mkdir($this->temp->path('tmp'));
        $this->importer = XlsxPlugins::importer();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function source(string $bytes): SourceFile
    {
        $path = $this->temp->path('upload.bin');
        file_put_contents($path, $bytes);

        return new SourceFile($path, 'xlsx', strlen($bytes), $this->temp->path('tmp'));
    }

    /** The reason each importer method gives for $bytes (all three must agree). */
    private function reason(string $bytes): ?string
    {
        $file = $this->source($bytes);
        $importer = $this->importer;
        $settings = null;
        $detect = XlsxPlugins::failure(static function () use ($importer, $file, &$settings): void {
            $settings = $importer->detect($file);
        });
        $settings ??= XlsxPlugins::defaultSettings();
        $inspect = XlsxPlugins::failure(static fn () => $importer->inspect($file, $settings));
        $rows = XlsxPlugins::failure(static fn () => $importer->rows($file, $settings, 0, 0, 10));
        self::assertSame($detect, $inspect, 'inspect agrees with detect');
        self::assertSame($detect, $rows, 'rows agrees with detect');

        return $detect;
    }

    /** A minimal valid workbook's parts. */
    private static function parts(): array
    {
        return (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['Name']) . WorkbookBuilder::textRow(2, ['value']))->parts();
    }

    /** @param array<string, string> $parts */
    private static function raw(array $parts, array $extra = []): string
    {
        $entries = [];
        foreach ($parts as $name => $bytes) {
            $entries[] = ['name' => $name, 'data' => $bytes];
        }

        return WorkbookBuilder::rawZip([...$entries, ...$extra]);
    }

    public function testAValidWorkbookPasses(): void
    {
        self::assertNull($this->reason(self::raw(self::parts())), 'A hand-written zip with stored entries is read.');
    }

    public function testNotAZipAndDamagedZips(): void
    {
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason('Name,Value' . "\r\n" . 'a,b' . "\r\n"));
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(''));
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason($ole), 'An encrypted workbook is an OLE file, not a zip.');
        $zip = self::raw(self::parts());
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(substr($zip, 0, (int) (strlen($zip) * 0.6))), 'Truncated.');
    }

    public function testMissingParts(): void
    {
        $parts = self::parts();
        unset($parts['[Content_Types].xml']);
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw($parts)));
        $parts = self::parts();
        unset($parts['xl/workbook.xml']);
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw($parts)));
        $parts = self::parts();
        $parts['xl/_rels/workbook.xml.rels'] = WorkbookBuilder::HEAD . '<Relationships xmlns="' . WorkbookBuilder::PKG . '"/>';
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw($parts)), 'No worksheet.');
    }

    public function testTooManyEntries(): void
    {
        $extra = [];
        for ($i = count(self::parts()); $i <= 10000; $i++) {
            $extra[] = ['name' => 'xl/media/x' . $i . '.bin', 'data' => ''];
        }
        self::assertCount(10001, [...self::parts(), ...$extra]);
        self::assertSame(PluginFailure::REASON_TOO_LARGE, $this->reason(self::raw(self::parts(), $extra)));
        array_pop($extra);
        self::assertNull($this->reason(self::raw(self::parts(), $extra)), '10,000 entries are allowed.');
    }

    public function testDeclaredSizesAndRatios(): void
    {
        $packed = str_repeat("\x55", 2 * 1048576);
        $cases = [
            'over 256 MiB' => [['name' => 'xl/media/big.bin', 'data' => $packed, 'method' => 8, 'size' => 268435457]],
            'over 512 MiB in total' => [
                ['name' => 'xl/media/a.bin', 'data' => $packed, 'method' => 8, 'size' => 200 * 1048576],
                ['name' => 'xl/media/b.bin', 'data' => $packed, 'method' => 8, 'size' => 200 * 1048576],
                ['name' => 'xl/media/c.bin', 'data' => $packed, 'method' => 8, 'size' => 120 * 1048576],
            ],
            'ratio over 200' => [['name' => 'xl/media/r.bin', 'data' => str_repeat("\x55", 10000), 'method' => 8, 'size' => 2000001]],
            'nothing stored for 2 MiB' => [['name' => 'xl/media/z.bin', 'data' => '', 'method' => 8, 'size' => 2 * 1048576]],
        ];
        foreach ($cases as $name => $extra) {
            self::assertSame(PluginFailure::REASON_TOO_LARGE, $this->reason(self::raw(self::parts(), $extra)), $name);
        }
        $fine = [['name' => 'xl/media/ok.bin', 'data' => str_repeat("\x55", 10000), 'method' => 8, 'size' => 2000000]];
        // The forged bytes are not deflate data: these pass the size rules and are refused only as damaged.
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw(self::parts(), $fine)), 'A ratio of exactly 200 is allowed.');
        $small = [['name' => 'xl/media/s.bin', 'data' => 'x', 'method' => 8, 'size' => 1048576]];
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw(self::parts(), $small)), 'Entries up to 1 MiB are not held to the ratio.');
        $real = [['name' => 'xl/media/real.bin', 'data' => (string) gzdeflate(str_repeat('0123456789', 100000)), 'method' => 8, 'size' => 1000000,
            'crc' => crc32(str_repeat('0123456789', 100000))]];
        self::assertNull($this->reason(self::raw(self::parts(), $real)), 'A real deflated entry under 1 MiB passes.');
    }

    public function testEncryptedEntriesAndMethods(): void
    {
        $encrypted = [['name' => 'xl/media/e.bin', 'data' => str_repeat("\x00", 40), 'flags' => 1]];
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw(self::parts(), $encrypted)));
        $path = $this->temp->path('aes.bin');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (self::parts() as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->setPassword('synthetic-password');
        $zip->setEncryptionName('xl/worksheets/sheet1.xml', \ZipArchive::EM_AES_256);
        $zip->close();
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason((string) file_get_contents($path)), 'AES-encrypted entry.');
        foreach ([12 => 'bzip2', 14 => 'lzma', 9 => 'deflate64', 1 => 'shrink'] as $method => $label) {
            $extra = [['name' => 'xl/media/m.bin', 'data' => 'abc', 'method' => $method, 'size' => 3]];
            self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw(self::parts(), $extra)), $label);
        }
    }

    public function testNamesThatAreNotAllowed(): void
    {
        foreach (['../evil.xml', 'xl/../../evil.xml', '/abs.xml', 'xl\\back.xml', 'xl/..hidden'] as $name) {
            $extra = [['name' => $name, 'data' => 'x']];
            self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw(self::parts(), $extra)), $name);
        }
        $duplicate = [['name' => 'xl/worksheets/sheet1.xml', 'data' => WorkbookBuilder::HEAD . '<worksheet/>']];
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw(self::parts(), $duplicate)), 'Duplicate names.');
    }

    public function testMacros(): void
    {
        $b = (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['a']))->mainContentType('application/vnd.ms-excel.sheet.macroEnabled.main+xml');
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($b->parts())));
        $parts = self::parts();
        $parts['xl/vbaProject.bin'] = "\xD0\xCF\x11\xE0 synthetic";
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)));

        // Character references in the content types are decoded before the check.
        $b = (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['a']))->mainContentType('application/vnd.ms-excel.sheet.macro&#69;nabled.main+xml');
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($b->parts())), 'macro&#69;nabled');
        $parts = self::parts();
        $parts['[Content_Types].xml'] = str_replace('</Types>', '<Default Extension="bin" ContentType="application/vnd.ms-office.vba&#x50;roject"/></Types>', $parts['[Content_Types].xml']);
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)), 'VBA project content type by reference');

        // XLM macro sheets and VBA parts by name, whatever the content types say.
        foreach (['xl/macrosheets/sheet1.xml', 'XL/MacroSheets/x.xml', 'xl/dialogsheets/d.xml', 'xl/VBAPROJECT.BIN', 'xl/vbaProjectSignature.bin', 'xl/vbaData.xml'] as $name) {
            $parts = self::parts();
            $parts[$name] = WorkbookBuilder::HEAD . '<xm/>';
            self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)), $name);
        }
        $parts = self::parts();
        $parts['[Content_Types].xml'] = str_replace('</Types>', '<Override PartName="/xl/m/s.xml" ContentType="application/vnd.ms-excel.macrosheet+xml"/></Types>', $parts['[Content_Types].xml']);
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)), 'Macro sheet content type');
        $parts = self::parts();
        $parts['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rIdM" Type="' . WorkbookBuilder::REL
            . '/xlMacrosheet" Target="m/s.xml"/></Relationships>', $parts['xl/_rels/workbook.xml.rels']);
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)), 'Macro sheet relationship');
    }

    public function testNamesThatDifferOnlyInCase(): void
    {
        $parts = self::parts();
        $parts['XL/Worksheets/Sheet1.xml'] = WorkbookBuilder::HEAD . '<worksheet/>';
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw($parts)));
        $parts = self::parts();
        $parts['[content_types].xml'] = $parts['[Content_Types].xml'];
        unset($parts['[Content_Types].xml']);
        self::assertSame(PluginFailure::REASON_UNREADABLE, $this->reason(self::raw($parts)), 'The content types part needs its exact name.');
    }

    public function testTheSheetCap(): void
    {
        $builder = new WorkbookBuilder();
        for ($i = 1; $i <= 255; $i++) {
            $builder->sheet('S' . $i, '');
        }
        self::assertNull($this->reason(self::raw($builder->parts())), '255 worksheets');
        $builder->sheet('S256', '');
        self::assertSame(PluginFailure::REASON_TOO_LARGE, $this->reason(self::raw($builder->parts())), '256 worksheets');
    }

    public function testPrologRules(): void
    {
        $element = '<worksheet/>';
        $cases = [
            'plain' => [WorkbookBuilder::HEAD . $element, null],
            'byte order mark' => ["\xEF\xBB\xBF" . WorkbookBuilder::HEAD . $element, null],
            'comment and instruction' => [WorkbookBuilder::HEAD . "<!-- note -->\n<?pi data?>\n" . $element, null],
            'no declaration' => [$element, null],
            'not XML' => ["\x89PNG\r\n\x1A\n", null],
            'doctype' => [WorkbookBuilder::HEAD . '<!DOCTYPE worksheet>' . $element, PluginFailure::REASON_UNSUPPORTED],
            'doctype after a comment' => [WorkbookBuilder::HEAD . '<!-- x --><!DOCTYPE w [<!ENTITY a "b">]>' . $element, PluginFailure::REASON_UNSUPPORTED],
            'lower-case doctype' => [WorkbookBuilder::HEAD . '<!doctype w>' . $element, PluginFailure::REASON_UNSUPPORTED],
            'entity alone' => ['<!ENTITY a "b">' . $element, PluginFailure::REASON_UNSUPPORTED],
            'CDATA before the root' => ['<![CDATA[x]]>' . $element, PluginFailure::REASON_UNSUPPORTED],
            'prolog past 4,096 bytes' => [WorkbookBuilder::HEAD . '<!--' . str_repeat('x', 5000) . '-->' . $element, PluginFailure::REASON_UNSUPPORTED],
            'UTF-16 byte order mark' => ["\xFF\xFE<\0w\0/\0>\0", PluginFailure::REASON_UNSUPPORTED],
            'UTF-16 without a mark' => ["<\0?\0x\0m\0l\0", PluginFailure::REASON_UNSUPPORTED],
            'EBCDIC' => ["\x4C\x6F\xA7\x94\x93", PluginFailure::REASON_UNSUPPORTED],
            'declared UTF-16' => ['<?xml version="1.0" encoding="UTF-16"?>' . $element, PluginFailure::REASON_UNSUPPORTED],
            'declared Latin 1' => ["<?xml version='1.0' encoding='ISO-8859-1'?>" . $element, PluginFailure::REASON_UNSUPPORTED],
            'declared utf-8' => ["<?xml version='1.0' encoding='utf-8'?>" . $element, null],
            'unclosed declaration' => ['<?xml version="1.0"', PluginFailure::REASON_UNSUPPORTED],
            'text before the root' => [WorkbookBuilder::HEAD . 'text' . $element, PluginFailure::REASON_UNREADABLE],
        ];
        foreach ($cases as $name => [$bytes, $reason]) {
            self::assertSame($reason, WorkbookCheck::prologProblem($bytes, true), $name);
        }
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, WorkbookCheck::prologProblem(WorkbookBuilder::HEAD . '   ', false), 'A prolog cut off by the read limit.');
        self::assertNull(WorkbookCheck::prologProblem(WorkbookBuilder::HEAD . '   ', true), 'A whole entry that is only a prolog is left to the parser.');

        // Every entry is checked, whatever its name: a relationship may point at any part.
        $parts = self::parts();
        $parts['xl/media/image1.png'] = '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]><x>&e;</x>';
        self::assertSame(PluginFailure::REASON_UNSUPPORTED, $this->reason(self::raw($parts)));
    }
}

