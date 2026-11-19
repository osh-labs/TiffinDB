<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\Inspection;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\SettingChoice;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;
use TiffinDB\Core\Io\Csv\Charset;

/**
 * The shipped XLSX import plugin (M3 spec section 9, ADR 0016 decision 4).
 * One source table per worksheet, in workbook order, named by the sheet
 * name; hidden sheets are listed with `hidden` and are not the default
 * choice. Settings: `sheet` (the sheet to read) and `heading` (the first
 * row holds the column names, default on). Labels are plain English text
 * (P133).
 *
 * Every call first checks the file (WorkbookCheck) and then reads it with
 * PHP's XMLReader (Workbook, SheetCursor); nothing is written to disk.
 *
 * Deviation from spec 9.2 (WP5, for the design review): OpenSpout's XLSX
 * reader is not used. At v5.2.2 it reports a formula without a cached
 * value as 0, turns a text cell starting with `=` into a formula cell (so
 * the text cannot be told from an error formula), aborts the whole read on
 * one out-of-range date, fills every row to the width declared by
 * `<dimension>` or `spans` (a hostile `spans="1:2000000000"` exhausts
 * memory), rebuilds the row for every cell (quadratic in the row width),
 * and took about 8 seconds for one pass over 50,000 rows by 20 columns on
 * the build machine, which every chunk would repeat up to its start row.
 * This reader is linear, skips rows before `$from` without reading their
 * cells, and reads the cell types itself.
 * The workbook's own XML is streamed, so memory does not grow with the
 * number of rows; shared strings are held compactly up to their limits.
 *
 * Columns: the widest row, counting only cells with a value, among the
 * heading row and the first 1,000 rows after it; more than 200 is refused
 * as too large (as CSV). Headings are the heading row's texts, "Column n"
 * when empty. A later row with a value past the last column gets one extra
 * null cell, which the core records as issue `extra-cells` (as CSV).
 *
 * Rows (P139): each call yields at most `$limit` rows and never more than
 * 2,000; rows are counted from the first row after the heading row, empty
 * rows included (a gap in the sheet's row numbers is a run of empty rows);
 * nothing is yielded at or past 100,000. Reaching `$from` skips row
 * elements without reading their cells.
 */
final class XlsxImporter implements Importer
{
    private const COLUMN = 'Column ';

    public function detect(SourceFile $file): Settings
    {
        $workbook = Workbook::open($file->path);
        $choices = [];
        $default = null;
        foreach ($workbook->sheets as $index => $sheet) {
            $choices[] = new SettingChoice((string) $index, self::label($sheet['name'], $index, $sheet['hidden']));
            if ($default === null && !$sheet['hidden']) {
                $default = (string) $index;
            }
        }

        return new Settings([
            new Setting('sheet', 'Sheet', 'choice', $default ?? '0', $choices, 'The worksheet whose rows are imported.'),
            new Setting('heading', 'The first row holds the column names', 'boolean', '1'),
        ]);
    }

    public function inspect(SourceFile $file, Settings $settings): Inspection
    {
        $workbook = Workbook::open($file->path);
        $heading = $settings->value('heading') !== '0';
        $tables = [];
        $hidden = 0;
        foreach ($workbook->sheets as $index => $sheet) {
            $tables[] = new SourceTable(
                Charset::cut(Charset::scrub($sheet['name']), SourceTable::MAX_NAME_BYTES),
                self::columns($workbook, $index, $heading),
                null,
                $sheet['hidden'],
            );
            $hidden += $sheet['hidden'] ? 1 : 0;
        }
        $count = count($tables);
        $readAs = 'Excel workbook, ' . $count . ($count === 1 ? ' sheet' : ' sheets') . ($hidden > 0 ? ' (' . $hidden . ' hidden)' : '');

        return new Inspection($tables, $readAs);
    }

    public function rows(SourceFile $file, Settings $settings, int $table, int $from, int $limit): iterable
    {
        $limit = min($limit, Limits::CHUNK_ROWS, Limits::MAX_SOURCE_ROWS - $from);
        if ($from < 0 || $limit < 1 || $table < 0) {
            return [];
        }
        $workbook = Workbook::open($file->path);
        if (!isset($workbook->sheets[$table])) {
            return [];
        }
        $heading = $settings->value('heading') !== '0';
        $width = count(self::columns($workbook, $table, $heading));
        Xml::run(static function () use ($workbook): void {
            $workbook->styles();
            $workbook->strings();
        });

        return self::generate($workbook, $workbook->sheets[$table]['part'], $width, $from + ($heading ? 2 : 1), $limit);
    }

    /**
     * Rows $first to $first + $limit - 1 of the sheet (1-based sheet row
     * numbers), each as $width cells, stopping at the sheet's last row.
     *
     * @return \Generator<int, list<?string>>
     */
    private static function generate(Workbook $workbook, string $part, int $width, int $first, int $limit): \Generator
    {
        $cursor = Xml::run(static fn (): SheetCursor => new SheetCursor($workbook, $part));
        try {
            $next = Xml::run(static function () use ($cursor, $first): ?int {
                $number = $cursor->next();
                while ($number !== null && $number < $first) {
                    $cursor->skip();
                    $number = $cursor->next();
                }

                return $number;
            });
            $last = $first + $limit - 1;
            for ($row = $first; $row <= $last && $next !== null; $row++) {
                if ($next > $row) {
                    yield array_fill(0, $width, null);
                    continue;
                }
                $cells = Xml::run(static fn (): array => $cursor->row());
                if ($row < $last) {
                    $next = Xml::run(static fn (): ?int => $cursor->next());
                }

                yield self::cells($cells, $width);
            }
        } finally {
            $cursor->close();
        }
    }

    /**
     * @param array<int, string> $cells
     * @return list<?string>
     */
    private static function cells(array $cells, int $width): array
    {
        $row = [];
        for ($i = 0; $i < $width; $i++) {
            $row[] = $cells[$i] ?? null;
        }
        foreach ($cells as $column => $text) {
            if ($column >= $width) {
                // One extra cell: the core reports the row's cells past the last column.
                $row[] = null;
                break;
            }
        }

        return $row;
    }

    /**
     * The column headings of sheet $index (spec 9.2).
     *
     * @return list<string>
     */
    private static function columns(Workbook $workbook, int $index, bool $heading): array
    {
        [$headings, $widest] = Xml::run(static function () use ($workbook, $index, $heading): array {
            $cursor = new SheetCursor($workbook, $workbook->sheets[$index]['part']);
            try {
                $headings = [];
                $widest = 0;
                $lastRow = Limits::WIDTH_ROWS + ($heading ? 1 : 0);
                $number = $cursor->next();
                if ($heading && $number === 1) {
                    $headings = $cursor->row();
                    $widest = self::width($headings);
                    $number = $cursor->next();
                }
                while ($number !== null && $number <= $lastRow) {
                    $widest = max($widest, self::width($cursor->row()));
                    if ($widest > Limits::MAX_COLUMNS) {
                        break;
                    }
                    $number = $cursor->next();
                }

                return [$headings, $widest];
            } finally {
                $cursor->close();
            }
        });
        if ($widest > Limits::MAX_COLUMNS) {
            throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'A sheet has more than the column limit.');
        }
        $columns = [];
        for ($i = 0; $i < max(1, $widest); $i++) {
            $text = $heading && isset($headings[$i]) ? Charset::cut(Charset::scrub($headings[$i]), SourceTable::MAX_HEADING_BYTES) : '';
            $columns[] = trim($text, " \t\n") === '' ? self::COLUMN . ($i + 1) : $text;
        }

        return $columns;
    }

    /** @param array<int, string> $cells */
    private static function width(array $cells): int
    {
        return $cells === [] ? 0 : max(array_keys($cells)) + 1;
    }

    /** A sheet's label in the sheet choice: its name as plain one-line text, "(hidden)" added. */
    private static function label(string $name, int $index, bool $hidden): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', Charset::scrub($name)));
        if (preg_match('/^.{0,60}/su', $name, $m) === 1) {
            $name = rtrim($m[0]);
        }
        if ($name === '') {
            $name = 'Sheet ' . ($index + 1);
        }

        return $hidden ? $name . ' (hidden)' : $name;
    }
}
