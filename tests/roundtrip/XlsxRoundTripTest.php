<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Core\App\ImportReview;
use TiffinDB\Tests\Plugins\Xlsx\WorkbookBuilder;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M2Fixture;

require_once __DIR__ . '/RoundTripCase.php';
require_once __DIR__ . '/../plugins/xlsx/WorkbookBuilder.php';

/**
 * T-RT-2 (M3 spec section 15.7), two halves:
 *
 * 1. The fixture project (IoFixture::project: the M2 data set plus
 *    Samples) exported as one XLSX workbook by the export plugin, each
 *    sheet imported into a new table of an empty project, and that project
 *    exported again: every sheet, row, and cell is equal (cell kind, value,
 *    and number format), both ways, read with RoundTripCase::readXlsx (a
 *    hand parser, not the plugin's reader or the library). Each column is
 *    imported as the type of the field it came from wherever the review's
 *    type menu offers that type (the test checks it does), links and file
 *    names as text (so no reverse link field is added to another table),
 *    and auto-numbers and phone numbers as proposed (number and text), as
 *    a person keeping the types would choose.
 * 2. A workbook assembled by hand from XML strings (WorkbookBuilder: shared
 *    and inline strings, numbers, date and date-time serials with date
 *    formats) holding the T-RT-1 rows, imported with the default mapping,
 *    gives the same records as the equivalent CSV: the CSV exports of the
 *    two new tables are byte-identical.
 */
#[CoversNothing]
final class XlsxRoundTripTest extends RoundTripCase
{
    /**
     * Field types that are chosen in the type menu. Not relation,
     * attachment, or auto_number (not importable as such), and not phone:
     * the menu never offers it (M3 spec section 6.3 has no phone counter),
     * so a phone column comes back as text with the same cells.
     */
    private const KEPT = ['text', 'long_text', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice', 'url', 'email'];

    public function testProjectToXlsxToProjectToXlsxIsCellForCellEqual(): void
    {
        $fx = IoFixture::project($this->core, $this->ops);
        $p = $fx['m2']->project;
        $xlsx = $this->export($p, 'export/xlsx', null);
        $before = self::readXlsx($xlsx);
        self::assertSame(['Items', 'Rooms', 'Warranties', 'Samples'], array_keys($before));
        self::assertCount(49, $before['Items'], 'A heading row and 48 live items.');
        self::assertCount(20, $before['Samples'], 'A heading row and 19 live samples.');

        // The original field type of each column, by sheet and heading.
        $types = [];
        foreach ($this->ops->schema()->tables($this->admin, $p) as $summary) {
            foreach ($this->ops->schema()->design($this->admin, $p, $summary->table->key)->fields as $design) {
                $types[$summary->table->name][$design->field->name] = $design->field->type;
            }
        }

        $copy = $this->ops->projects()->create($this->admin, 'Home Inventory copy', null)->key;
        foreach (array_keys($before) as $index => $sheet) {
            $job = $this->startImport($copy, $xlsx, 'home-inventory.xlsx', $index);
            $report = $this->finishImport($copy, $job, static function (array $input, ImportReview $review) use ($types, $sheet): array {
                self::assertSame($sheet, $review->newTableName);
                foreach ($review->columns as $column) {
                    $type = $types[$sheet][$column->heading] ?? null;
                    self::assertIsString($type, "{$sheet}: {$column->heading} is a field of the original.");
                    $input['include'][$column->index] = '1';
                    if (in_array($type, ['relation', 'attachment'], true)) {
                        // Linked titles and file names come back as text, so no reverse link field is added.
                        $input['type'][$column->index] = 'text';
                    } elseif (in_array($type, self::KEPT, true)) {
                        $offered = array_map(static fn ($c): string => $c->value, $column->typeChoices);
                        self::assertContains($type, $offered, "{$sheet}: {$column->heading} can be imported as {$type}.");
                        $input['type'][$column->index] = $type;
                    }
                }

                return $input;
            });
            self::assertSame(count($before[$sheet]) - 1, $report->report->rowsImported, $sheet);
            self::assertSame(0, $report->report->valuesEmptied, "{$sheet}: no value left empty.");
        }

        $after = self::readXlsx($this->export($copy, 'export/xlsx', null));
        self::assertSame(array_keys($before), array_keys($after), 'The same sheets in the same order.');
        foreach ($before as $sheet => $rows) {
            self::assertSame(array_keys($rows), array_keys($after[$sheet]), "{$sheet}: the same rows.");
            foreach ($rows as $r => $cells) {
                self::assertSame($cells, $after[$sheet][$r], "{$sheet}, row {$r}: every cell equal.");
            }
        }
    }

    public function testAHandAssembledWorkbookGivesTheSameRecordsAsTheEquivalentCsv(): void
    {
        $m2 = M2Fixture::build($this->core, $this->ops);
        $p = $m2->project;
        $rows = (new IoFixture())->canonicalRows(60, M2Fixture::ROOMS);
        $csv = IoFixture::exportCsv(IoFixture::CANONICAL_HEADINGS, $rows);

        $fromCsv = $this->finishImport($p, $this->startImport($p, $csv, 'from-csv.csv'));
        $job = $this->startImport($p, $this->workbook($rows), 'inventory.xlsx');
        $review = $this->ops->imports()->review($this->admin, $p, $job, null, []);
        self::assertSame('Excel workbook, 1 sheet', $review->readAs);
        $types = array_map(static fn ($c): string => str_starts_with($c->type, 'link:') ? 'link' : $c->type, $review->columns);
        self::assertSame(IoFixture::CANONICAL_TYPES, $types, 'The same proposals as for the CSV.');
        $fromXlsx = $this->finishImport($p, $job);

        self::assertNotNull($fromCsv->table);
        self::assertNotNull($fromXlsx->table);
        self::assertSame('Inventory', $fromXlsx->table->name, 'Named after the sheet.');
        self::assertSame([60, 0, 0], [$fromXlsx->report->rowsImported, $fromXlsx->report->valuesEmptied, $fromXlsx->report->linksAdded]);
        self::assertSame($csv, $this->export($p, 'export/csv', $fromCsv->table->key));
        self::assertSame($csv, $this->export($p, 'export/csv', $fromXlsx->table->key), 'The same records as the equivalent CSV.');
    }

    /**
     * $rows (T-RT-1 order) as a hand-assembled workbook: names, rooms, and
     * categories as shared strings; Purchased as date serials (format 14)
     * and Delivered as date-time serials (a custom format); Weight,
     * Quantity, and In use as numbers; everything else as inline strings.
     *
     * @param list<list<string>> $rows
     */
    private function workbook(array $rows): string
    {
        $shared = [];
        $sharedIndex = static function (string $text) use (&$shared): int {
            $at = array_search($text, $shared, true);
            if (!is_int($at)) {
                $shared[] = $text;
                $at = count($shared) - 1;
            }

            return $at;
        };
        $builder = new WorkbookBuilder();
        $date = $builder->cellFormat(14);
        $dateTime = $builder->cellFormat(164, 'yyyy-mm-dd hh:mm:ss');
        $xml = WorkbookBuilder::textRow(1, IoFixture::CANONICAL_HEADINGS);
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $cells = '';
            foreach ($row as $c => $text) {
                $ref = WorkbookBuilder::ref($c, $r);
                if ($text === '') {
                    continue;
                }
                $cells .= match ($c) {
                    0, 1, 2 => '<c r="' . $ref . '" t="s"><v>' . $sharedIndex($text) . '</v></c>',
                    3 => '<c r="' . $ref . '" s="' . $date . '"><v>' . self::serial($text) . '</v></c>',
                    7 => '<c r="' . $ref . '" s="' . $dateTime . '"><v>' . self::serial($text) . '</v></c>',
                    5, 6, 14 => '<c r="' . $ref . '"><v>' . $text . '</v></c>',
                    default => WorkbookBuilder::inline($ref, $text),
                };
            }
            $xml .= '<row r="' . $r . '">' . $cells . '</row>';
        }
        $builder->sheet('Inventory', $xml)->sharedStrings(array_map(
            static fn (string $s): string => '<t xml:space="preserve">' . htmlspecialchars($s, ENT_QUOTES | ENT_XML1) . '</t>',
            $shared,
        ));
        $path = $this->core->temp->path('inventory.xlsx');
        $builder->write($path);

        return (string) file_get_contents($path);
    }

    /** The 1900-system serial of `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM:SS` (UTC), as the shortest decimal text. */
    private static function serial(string $text): string
    {
        $time = new \DateTimeImmutable(strlen($text) === 10 ? $text . 'T00:00:00Z' : $text . 'Z');
        $seconds = $time->getTimestamp();
        $days = intdiv($seconds, 86400) + 25569;
        $rest = $seconds % 86400;

        return $rest === 0 ? (string) $days : var_export($days + $rest / 86400, true);
    }
}
