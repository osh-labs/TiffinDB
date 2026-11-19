<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

/**
 * Assembles XLSX workbooks by hand from XML strings with ZipArchive, so
 * read tests do not depend on any XLSX writer (a reader and a writer that
 * shared one bug could otherwise pass each other). Each sheet is given as
 * the inner XML of its `sheetData`; shared strings, cell formats, the date
 * system, and any extra or replaced part can be set. Only synthetic data.
 */
final class WorkbookBuilder
{
    public const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    public const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public const PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';
    public const HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    /** @var list<array{name: string, data: string, state: string}> */
    private array $sheets = [];
    /** @var list<string>|null */
    private ?array $strings = null;
    /** @var array<int, string> custom number formats: id => code */
    private array $formats = [];
    /** @var list<int> number format id per cell format (cellXfs) */
    private array $cellFormats = [0];
    private bool $date1904 = false;
    /** @var array<string, string|null> part name => bytes (null removes a part) */
    private array $parts = [];
    private string $contentTypes = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    /** Adds a worksheet whose sheetData holds $rows (XML); $state '' (visible), 'hidden' or 'veryHidden'. */
    public function sheet(string $name, string $rows, string $state = ''): self
    {
        $this->sheets[] = ['name' => $name, 'data' => $rows, 'state' => $state];

        return $this;
    }

    /** @param list<string> $strings shared string items as XML (the inside of each `<si>`) */
    public function sharedStrings(array $strings): self
    {
        $this->strings = $strings;

        return $this;
    }

    /** Adds a cell format with number format $numFmtId (a custom one when $code is given); returns its `s` index. */
    public function cellFormat(int $numFmtId, ?string $code = null): int
    {
        if ($code !== null) {
            $this->formats[$numFmtId] = $code;
        }
        $this->cellFormats[] = $numFmtId;

        return count($this->cellFormats) - 1;
    }

    public function date1904(bool $on = true): self
    {
        $this->date1904 = $on;

        return $this;
    }

    /** Adds or replaces part $name with $bytes; null leaves the part out. */
    public function part(string $name, ?string $bytes): self
    {
        $this->parts[$name] = $bytes;

        return $this;
    }

    /** The workbook part's content type (macro-enabled workbooks use another). */
    public function mainContentType(string $type): self
    {
        $this->contentTypes = $type;

        return $this;
    }

    /** @return array<string, string> part name => bytes */
    public function parts(): array
    {
        $parts = [];
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="' . $this->contentTypes . '"/>';
        $workbookRels = '';
        $sheetList = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $parts['xl/worksheets/sheet' . $n . '.xml'] = self::HEAD . '<worksheet xmlns="' . self::MAIN . '" xmlns:r="' . self::REL . '">'
                . '<sheetData>' . $sheet['data'] . '</sheetData></worksheet>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookRels .= '<Relationship Id="rId' . $n . '" Type="' . self::REL . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $sheetList .= '<sheet name="' . htmlspecialchars($sheet['name'], ENT_QUOTES | ENT_XML1) . '" sheetId="' . $n . '"'
                . ($sheet['state'] !== '' ? ' state="' . $sheet['state'] . '"' : '') . ' r:id="rId' . $n . '"/>';
        }
        $workbookRels .= '<Relationship Id="rIdS" Type="' . self::REL . '/styles" Target="styles.xml"/>';
        $overrides .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        if ($this->strings !== null) {
            $workbookRels .= '<Relationship Id="rIdT" Type="' . self::REL . '/sharedStrings" Target="sharedStrings.xml"/>';
            $overrides .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
            $items = '';
            foreach ($this->strings as $item) {
                $items .= '<si>' . $item . '</si>';
            }
            $parts['xl/sharedStrings.xml'] = self::HEAD . '<sst xmlns="' . self::MAIN . '" count="' . count($this->strings) . '" uniqueCount="'
                . count($this->strings) . '">' . $items . '</sst>';
        }
        $numFmts = '';
        foreach ($this->formats as $id => $code) {
            $numFmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . htmlspecialchars($code, ENT_QUOTES | ENT_XML1) . '"/>';
        }
        $xfs = '';
        foreach ($this->cellFormats as $id) {
            $xfs .= '<xf numFmtId="' . $id . '" fontId="0" fillId="0" borderId="0" xfId="0"' . ($id !== 0 ? ' applyNumberFormat="1"' : '') . '/>';
        }
        $parts['xl/styles.xml'] = self::HEAD . '<styleSheet xmlns="' . self::MAIN . '">'
            . ($numFmts !== '' ? '<numFmts count="' . count($this->formats) . '">' . $numFmts . '</numFmts>' : '')
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->cellFormats) . '">' . $xfs . '</cellXfs></styleSheet>';
        $parts['xl/workbook.xml'] = self::HEAD . '<workbook xmlns="' . self::MAIN . '" xmlns:r="' . self::REL . '">'
            . '<workbookPr' . ($this->date1904 ? ' date1904="1"' : '') . '/><sheets>' . $sheetList . '</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = self::HEAD . '<Relationships xmlns="' . self::PKG . '">' . $workbookRels . '</Relationships>';
        $parts['_rels/.rels'] = self::HEAD . '<Relationships xmlns="' . self::PKG . '">'
            . '<Relationship Id="rId1" Type="' . self::REL . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $parts['[Content_Types].xml'] = self::HEAD . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>' . $overrides . '</Types>';
        foreach ($this->parts as $name => $bytes) {
            if ($bytes === null) {
                unset($parts[$name]);
            } else {
                $parts[$name] = $bytes;
            }
        }

        return $parts;
    }

    /** Writes the workbook to $path (deflated entries). */
    public function write(string $path): string
    {
        self::zip($path, $this->parts());

        return $path;
    }

    /**
     * Writes a zip of $entries to $path; each entry is deflated unless named
     * in $stored.
     *
     * @param array<string, string> $entries
     * @param list<string> $stored
     */
    public static function zip(string $path, array $entries, array $stored = []): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The test workbook cannot be created.');
        }
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setCompressionName($name, in_array($name, $stored, true) ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE);
        }
        $zip->close();
    }

    /**
     * The bytes of a zip archive written field by field, so that its headers
     * can declare anything (sizes, methods, flags, names). Each entry:
     * name, the bytes stored, and optionally `method` (default 0, stored),
     * `size` (declared uncompressed size, default the stored length),
     * `crc` (default the CRC-32 of the stored bytes), `flags`.
     *
     * @param list<array{name: string, data: string, method?: int, size?: int, crc?: int, flags?: int}> $entries
     */
    public static function rawZip(array $entries): string
    {
        $out = '';
        $central = '';
        foreach ($entries as $entry) {
            $data = $entry['data'];
            $method = $entry['method'] ?? 0;
            $size = $entry['size'] ?? strlen($data);
            $crc = $entry['crc'] ?? crc32($data);
            $flags = $entry['flags'] ?? 0;
            $offset = strlen($out);
            $common = pack('vvvvvVVVvv', 20, $flags, $method, 0, 0x21, $crc, strlen($data), $size, strlen($entry['name']), 0);
            $out .= pack('V', 0x04034b50) . $common . $entry['name'] . $data;
            $central .= pack('V', 0x02014b50) . pack('v', 20) . $common . pack('vvvVV', 0, 0, 0, 0, $offset) . $entry['name'];
        }

        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($out), 0);
    }

    /** An inline string cell. */
    public static function inline(string $ref, string $text): string
    {
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($text, ENT_QUOTES | ENT_XML1) . '</t></is></c>';
    }

    /** A row of inline string cells from column A. */
    public static function textRow(int $row, array $texts): string
    {
        $cells = '';
        foreach (array_values($texts) as $i => $text) {
            $cells .= self::inline(self::ref($i, $row), (string) $text);
        }

        return '<row r="' . $row . '">' . $cells . '</row>';
    }

    /** The reference of 0-based column $column in row $row ("A1"). */
    public static function ref(int $column, int $row): string
    {
        $letters = '';
        for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }

        return $letters . $row;
    }
}
