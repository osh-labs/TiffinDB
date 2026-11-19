<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ImportReportView;
use TiffinDB\Core\App\ImportReview;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\CoreFixture;

/**
 * Shared set-up for the round-trip tests (M3 spec section 15.7): a
 * CoreFixture instance with one Operations over it, the Admin actor, and
 * helpers that drive an import and an export through the core operations
 * exactly as the screens do (start, read every chunk, review, save the
 * mapping, prepare every chunk, commit; export through an enabled plugin),
 * plus readers that look at what was written without TiffinDB code: a
 * table-by-table dump of a project file with plain SQL and a hand parser
 * for XLSX files (the workbook, its relationships, shared strings, cell
 * formats, and each sheet's cells, read with DOM from the zip).
 * Synthetic data only.
 */
abstract class RoundTripCase extends TestCase
{
    protected CoreFixture $core;
    protected Operations $ops;
    protected Actor $admin;

    protected function setUp(): void
    {
        $this->core = new CoreFixture(static::class);
        $this->ops = $this->core->operations();
        $this->admin = $this->core->actor('admin');
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    /**
     * Uploads $contents as $name into $project and reads it to the review;
     * with $tableIndex (a sheet), reads that source table instead.
     */
    protected function startImport(string $project, string $contents, string $name, ?int $tableIndex = null): string
    {
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $project, $this->core->upload($contents), $name, null);
        if ($tableIndex !== null) {
            $imports->setReadingOptions($this->admin, $project, $job, [], $tableIndex);
        }
        while (!$imports->readChunk($this->admin, $project, $job)->finished) {
        }

        return $job;
    }

    /**
     * Saves the mapping (the review's defaults, changed by $change),
     * prepares every chunk, commits, and returns the report.
     *
     * @param (\Closure(array<string, mixed>, ImportReview): array<string, mixed>)|null $change
     */
    protected function finishImport(string $project, string $job, ?\Closure $change = null): ImportReportView
    {
        $imports = $this->ops->imports();
        $review = $imports->review($this->admin, $project, $job, null, []);
        $input = self::input($review);
        if ($change !== null) {
            $input = $change($input, $review);
        }
        $imports->saveMapping($this->admin, $project, $job, $input);
        while (!$imports->prepareChunk($this->admin, $project, $job)->finished) {
        }

        return $imports->report($this->admin, $project, $imports->commit($this->admin, $project, $job));
    }

    /**
     * The review form as the screen posts it with nothing changed.
     *
     * @return array<string, mixed>
     */
    protected static function input(ImportReview $review): array
    {
        $input = ['target' => $review->target, 'table_name' => (string) $review->newTableName, 'table' => (string) $review->tableKey, 'include' => [], 'name' => [], 'type' => [], 'field' => []];
        foreach ($review->columns as $column) {
            if ($column->include) {
                $input['include'][$column->index] = '1';
            }
            $input['name'][$column->index] = $column->fieldName;
            $input['type'][$column->index] = $column->type;
            $input['field'][$column->index] = (string) $column->fieldKey;
        }

        return $input;
    }

    /** Exports with an enabled plugin ($tableKey null: the whole project) and returns the file's bytes; the job folder is removed. */
    protected function export(string $project, string $format, ?string $tableKey, array $settings = []): string
    {
        $file = $this->ops->exports()->export($this->admin, $project, $format, $tableKey, $settings);
        $bytes = (string) file_get_contents($file->path);
        $this->ops->exports()->finish($this->admin, $project, $file->jobKey);

        return $bytes;
    }

    /** The project archive's bytes; the job folder is removed. */
    protected function archive(string $project): string
    {
        $file = $this->ops->exports()->archive($this->admin, $project);
        $bytes = (string) file_get_contents($file->path);
        $this->ops->exports()->finish($this->admin, $project, $file->jobKey);

        return $bytes;
    }

    protected function projectFile(string $project): string
    {
        return $this->core->projectFolder($project) . '/project.tiffin';
    }

    /**
     * Every table of a project file with every row, read with plain SQL on
     * a read-only connection: table name => list of rows (columns by name),
     * rows in rowid order (by content for WITHOUT ROWID tables and
     * `sqlite_sequence`); plus `sqlite_master` and the
     * user_version and application_id pragmas.
     *
     * @return array<string, mixed>
     */
    protected static function dump(string $path): array
    {
        $db = Sqlite::openUntrusted($path);
        try {
            $dump = [
                '#user_version' => $db->value('PRAGMA user_version'),
                '#application_id' => $db->value('PRAGMA application_id'),
                '#schema' => $db->all('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name'),
            ];
            foreach ($db->all("SELECT name, sql FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
                $name = (string) $table['name'];
                $withoutRowid = str_contains((string) $table['sql'], 'WITHOUT ROWID');
                $rows = $db->all('SELECT * FROM "' . str_replace('"', '""', $name) . '"' . ($withoutRowid ? '' : ' ORDER BY rowid'));
                if ($withoutRowid || $name === 'sqlite_sequence') {
                    // A set: rows by content (the sequence table's row order follows insertion).
                    sort($rows);
                }
                $dump[$name] = $rows;
            }
        } finally {
            $db->close();
        }

        return $dump;
    }

    /** SHA-256 of dump(): the content of a project file, independent of free pages and the WAL. */
    protected static function checksum(string $path): string
    {
        return hash('sha256', serialize(self::dump($path)));
    }

    /**
     * A hand reading of an XLSX file: sheet name => rows (1-based row
     * number => column letter(s) => cell), each cell
     * `['t' => 'str'|'n'|'b'|'e', 'v' => text, 'f' => number format code or '']`.
     * Shared and inline strings both read as `str`; rich text runs are
     * joined. Only the parts the format needs are read: no library.
     *
     * @return array<string, array<int, array<string, array{t: string, v: string, f: string}>>>
     */
    protected static function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tiffindb-xlsx-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        $part = static function (string $name) use ($zip): ?\DOMXPath {
            $xml = $zip->getFromName($name);
            if (!is_string($xml)) {
                return null;
            }
            $doc = new \DOMDocument();
            self::assertTrue($doc->loadXML($xml, LIBXML_NONET));
            $x = new \DOMXPath($doc);
            $x->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $x->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $x->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');

            return $x;
        };
        try {
            $workbook = $part('xl/workbook.xml');
            $rels = $part('xl/_rels/workbook.xml.rels');
            self::assertNotNull($workbook);
            self::assertNotNull($rels);
            $targets = [];
            foreach ($rels->query('//p:Relationship') as $rel) {
                /** @var \DOMElement $rel */
                $target = $rel->getAttribute('Target');
                $targets[$rel->getAttribute('Id')] = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
            }
            $strings = [];
            $shared = $part('xl/sharedStrings.xml');
            if ($shared !== null) {
                foreach ($shared->query('//m:si') as $si) {
                    $text = '';
                    foreach ($shared->query('.//m:t', $si) as $t) {
                        $text .= $t->textContent;
                    }
                    $strings[] = $text;
                }
            }
            $formats = [];
            $styles = $part('xl/styles.xml');
            $codes = [14 => 'm/d/yyyy', 22 => 'm/d/yyyy h:mm'];
            if ($styles !== null) {
                foreach ($styles->query('//m:numFmts/m:numFmt') as $fmt) {
                    /** @var \DOMElement $fmt */
                    $codes[(int) $fmt->getAttribute('numFmtId')] = $fmt->getAttribute('formatCode');
                }
                foreach ($styles->query('//m:cellXfs/m:xf') as $xf) {
                    /** @var \DOMElement $xf */
                    $id = (int) $xf->getAttribute('numFmtId');
                    $formats[] = $id === 0 ? '' : ($codes[$id] ?? 'builtin:' . $id);
                }
            }
            $sheets = [];
            foreach ($workbook->query('//m:sheets/m:sheet') as $sheet) {
                /** @var \DOMElement $sheet */
                $x = $part($targets[$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? '');
                self::assertNotNull($x, 'Each sheet has its part.');
                $rows = [];
                foreach ($x->query('//m:sheetData/m:row') as $row) {
                    /** @var \DOMElement $row */
                    $cells = [];
                    foreach ($x->query('m:c', $row) as $c) {
                        /** @var \DOMElement $c */
                        self::assertSame(1, preg_match('/^([A-Z]+)(\d+)\z/', $c->getAttribute('r'), $ref));
                        $type = $c->getAttribute('t');
                        $v = $x->query('m:v', $c)->item(0)?->textContent;
                        $format = $formats[(int) $c->getAttribute('s')] ?? '';
                        if ($type === 's') {
                            $cell = ['t' => 'str', 'v' => $strings[(int) $v], 'f' => $format];
                        } elseif ($type === 'inlineStr' || $type === 'str') {
                            $text = $type === 'str' ? (string) $v : '';
                            foreach ($x->query('m:is//m:t', $c) as $t) {
                                $text .= $t->textContent;
                            }
                            $cell = ['t' => 'str', 'v' => $text, 'f' => $format];
                        } elseif ($type === 'b' || $type === 'e') {
                            $cell = ['t' => $type, 'v' => (string) $v, 'f' => $format];
                        } elseif ($v === null) {
                            continue;
                        } else {
                            $cell = ['t' => 'n', 'v' => $v, 'f' => $format];
                        }
                        $cells[$ref[1]] = $cell;
                    }
                    if ($cells !== []) {
                        $rows[(int) $row->getAttribute('r')] = $cells;
                    }
                }
                $sheets[$sheet->getAttribute('name')] = $rows;
            }

            return $sheets;
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
