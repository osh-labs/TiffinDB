<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-6 (M3 spec section 15.7): timings, recorded and never asserted. A
 * project of RECORDS parts linked to each other through a self-relation
 * (IoFixture::largeProject) is exported as a project archive, as JSON, as
 * XLSX, and its table as CSV; the archive is imported as a new project;
 * the CSV is imported into a new table (read, prepare, and commit timed
 * separately) and the XLSX sheet is read. Only the results are asserted
 * (record and link counts, the CSV coming back byte-identical).
 *
 * The record count is RECORDS (20,000) unless the environment variable
 * TIFFINDB_RT6_RECORDS gives another (the spec's 50,000-row figures are
 * taken with TIFFINDB_RT6_RECORDS=50000). When TIFFINDB_RT6_TIMINGS names
 * a file, the timings in seconds are written there as JSON; otherwise
 * they are only measured. Wall-clock time varies by machine, so no figure
 * is compared with a limit.
 */
#[CoversNothing]
final class TimingTest extends RoundTripCase
{
    public const RECORDS = 20000;

    public function testLargeSelfRelationProjectTimings(): void
    {
        $env = getenv('TIFFINDB_RT6_RECORDS');
        $count = is_string($env) && preg_match('/^[1-9]\d{0,5}\z/', $env) === 1 ? (int) $env : self::RECORDS;
        $times = ['records' => $count];
        $large = IoFixture::largeProject($this->core, $this->ops, $count);
        $p = $large['project'];
        $links = 2 * $count - 3;
        $time = static function (string $name, \Closure $work) use (&$times): mixed {
            $start = hrtime(true);
            $result = $work();
            $times[$name] = round((hrtime(true) - $start) / 1e9, 3);

            return $result;
        };

        $zip = $time('archive_export', fn (): string => $this->archive($p));
        $result = $time('archive_import', fn () => $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'parts.zip', 'Parts Store copy'));
        self::assertSame([1, $count], [$result->report->tables, $result->report->records]);
        $join = 'j_' . $this->core->projectDb($p)->value('SELECT key FROM _tiffin_relations');
        self::assertSame($links, (int) $this->core->projectDb($result->project->key)->value('SELECT COUNT(*) FROM "' . $join . '"'));

        $json = $time('json_export', fn (): string => $this->export($p, 'export/json', null));
        self::assertSame($count, count(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['tables'][0]['records']));
        $xlsx = $time('xlsx_export', fn (): string => $this->export($p, 'export/xlsx', null));
        $csv = $time('csv_export', fn (): string => $this->export($p, 'export/csv', $large['table']));
        self::assertSame($count + 1, substr_count($csv, "\r\n"));

        // The CSV into a new table: read, prepare, and commit timed apart.
        $imports = $this->ops->imports();
        $job = $time('csv_import_read', function () use ($imports, $p, $csv): string {
            $job = $imports->start($this->admin, $p, $this->core->upload($csv), 'parts-from-csv.csv', null);
            while (!$imports->readChunk($this->admin, $p, $job)->finished) {
            }

            return $job;
        });
        $review = $imports->review($this->admin, $p, $job, null, []);
        $imports->saveMapping($this->admin, $p, $job, self::input($review));
        $time('csv_import_prepare', function () use ($imports, $p, $job): void {
            while (!$imports->prepareChunk($this->admin, $p, $job)->finished) {
            }
        });
        $log = $time('csv_import_commit', fn (): string => $imports->commit($this->admin, $p, $job));
        $report = $imports->report($this->admin, $p, $log);
        self::assertSame($count, $report->report->rowsImported);
        self::assertNotNull($report->table);
        self::assertSame($csv, $this->export($p, 'export/csv', $report->table->key), 'The imported table exports the same bytes.');

        // The XLSX sheet read to the review.
        $time('xlsx_import_read', function () use ($p, $xlsx, $count): void {
            $job = $this->startImport($p, $xlsx, 'parts.xlsx');
            self::assertSame($count, $this->ops->imports()->job($this->admin, $p, $job)->storedRows);
            $this->ops->imports()->cancel($this->admin, $p, $job);
        });

        $target = getenv('TIFFINDB_RT6_TIMINGS');
        if (is_string($target) && $target !== '') {
            file_put_contents($target, json_encode($times, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
        self::assertCount(10, $times);
    }
}
