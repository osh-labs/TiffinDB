<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M2Fixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-1 (M3 spec section 15.7): the T-RT-1 CSV of IoFixture (seed
 * 20261119; every type the import proposes, in the form the CSV export
 * writes it, and a Room column that links to the M2 fixture's Rooms)
 * imported into a new table of "Home Inventory" with the default mapping
 * and exported again as CSV gives the same bytes. The proposed types, the
 * new fields, and the links are checked on the way.
 */
#[CoversNothing]
final class CsvRoundTripTest extends RoundTripCase
{
    public function testCsvToProjectToCsvIsByteIdentical(): void
    {
        $m2 = M2Fixture::build($this->core, $this->ops);
        $p = $m2->project;
        $rows = (new IoFixture())->canonicalRows(60, M2Fixture::ROOMS);
        $csv = IoFixture::exportCsv(IoFixture::CANONICAL_HEADINGS, $rows);
        self::assertSame($csv, (new IoFixture())->canonicalCsv(60, M2Fixture::ROOMS), 'The same seed gives the same file.');

        $job = $this->startImport($p, $csv, 'inventory.csv');
        $review = $this->ops->imports()->review($this->admin, $p, $job, null, []);
        self::assertSame(['new', 'inventory', 60, true], [$review->target, $review->newTableName, $review->rows, $review->headingRow]);
        $types = array_map(static fn ($c): string => str_starts_with($c->type, 'link:') ? 'link' : $c->type, $review->columns);
        self::assertSame(IoFixture::CANONICAL_TYPES, $types, 'The proposed types.');
        self::assertSame('link:' . $m2->rooms, $review->columns[1]->type, 'Room links to Rooms.');
        self::assertSame(IoFixture::CANONICAL_HEADINGS, array_map(static fn ($c): string => $c->fieldName, $review->columns));
        self::assertSame(array_fill(0, count(IoFixture::CANONICAL_HEADINGS), true), array_map(static fn ($c): bool => $c->include, $review->columns));

        $report = $this->finishImport($p, $job);
        self::assertNotNull($report->table);
        self::assertSame([60, 60, 0, 0], [$report->report->rowsRead, $report->report->rowsImported, $report->report->linksAdded, $report->report->valuesEmptied]);
        $roomCount = (int) $this->core->projectDb($p)->value('SELECT COUNT(*) FROM "t_' . $m2->rooms . '"');
        self::assertSame(count(M2Fixture::ROOMS), $roomCount, 'No room was added: every title was found.');

        $out = $this->export($p, 'export/csv', $report->table->key);
        self::assertSame($csv, $out, 'CSV to project to CSV is byte-identical.');
        self::assertSame(bin2hex(substr($csv, 0, 3)), 'efbbbf');
    }
}
