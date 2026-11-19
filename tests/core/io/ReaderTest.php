<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\ColumnStats;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Reader;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-4: the staging read loop with a fake importer. */
#[CoversClass(Reader::class)]
#[CoversClass(ColumnStats::class)]
final class ReaderTest extends TestCase
{
    use ImportFixture;

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-reader-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    /** @return list<array{n: int, cells: string}> */
    private static function stored(ImportJob $job): array
    {
        return $job->db()->all('SELECT n, cells FROM rows ORDER BY n');
    }

    public function testChunksOf2000(): void
    {
        $rows = (new IoFixture())->itemRows(4500);
        $importer = new FakeImporter(IoFixture::HEADINGS, $rows);
        $job = $this->newJob();
        $reader = new Reader($this->types);
        $settings = $importer->detect($job->sourceFile());
        self::assertFalse($reader->chunk($job, $importer, $settings, $importer->table()));
        self::assertSame(2000, $job->metaInt('read_rows'));
        self::assertSame(JobState::Reading, $job->state());
        self::assertFalse($reader->chunk($job, $importer, $settings, $importer->table()));
        self::assertTrue($reader->chunk($job, $importer, $settings, $importer->table()));
        self::assertSame([[0, 2000], [2000, 2000], [4000, 2000]], $importer->calls);
        self::assertSame(JobState::Review, $job->state());
        self::assertSame('1', $job->meta('read_done'));
        self::assertSame(4500, $job->rowCount());
        self::assertSame(range(1, 4500), array_map(static fn (array $r): int => (int) $r['n'], self::stored($job)));
        self::assertSame($rows[4499], json_decode(self::stored($job)[4499]['cells'], true));
        self::assertSame(IoLimits::CHUNK_ROWS, 2000);

        // A finished job does not read again.
        $this->expectException(\LogicException::class);
        $reader->chunk($job, $importer, $settings, $importer->table());
    }

    public function testTheTimeLimitEndsAChunkAndReadingResumes(): void
    {
        $rows = (new IoFixture())->itemRows(50);
        $importer = new FakeImporter(IoFixture::HEADINGS, $rows);
        $job = $this->newJob();
        $ticks = 0;
        // Each reading of the clock moves it on by one second: a chunk ends after about five readings.
        $reader = new Reader($this->types, static function () use (&$ticks): int {
            return ($ticks++) * 1000000000;
        });
        $chunks = $this->readAll($job, $importer, $reader);
        self::assertGreaterThan(5, $chunks);
        self::assertSame(50, $job->rowCount());
        self::assertSame(range(1, 50), array_map(static fn (array $r): int => (int) $r['n'], self::stored($job)));
        $froms = array_column($importer->calls, 0);
        self::assertSame($froms, array_values(array_unique($froms)), 'No row is read twice.');
    }

    public function testEmptyRowsLongCellsExtraCellsAndBadText(): void
    {
        $long = str_repeat('x', IoLimits::MAX_CELL_CHARS + 1);
        $exact = str_repeat("\u{00e9}", IoLimits::MAX_CELL_CHARS);
        $importer = new FakeImporter(['A', 'B'], [
            ['a1', 'b1'],
            [null, null],
            ['', '   '],
            [$long, 'b4'],
            [$exact, null],
            ['a6'],
            ['a7', 'b7', 'extra', 'more'],
            ["bad \xFF\xFE text", 'b8'],
        ]);
        $job = $this->newJob();
        $this->readAll($job, $importer);
        self::assertSame([1, 4, 5, 6, 7, 8], array_map(static fn (array $r): int => (int) $r['n'], self::stored($job)));
        self::assertSame(['empty' => 2, 'over_limit' => 0], $job->skipped());
        self::assertSame(8, $job->metaInt('read_rows'));
        $cells = array_map(static fn (array $r): array => json_decode($r['cells'], true), self::stored($job));
        self::assertSame([null, 'b4'], $cells[1]);
        self::assertSame([$exact, null], $cells[2]);
        self::assertSame(['a6', null], $cells[3]);
        self::assertSame(['a7', 'b7'], $cells[4]);
        self::assertSame(["bad \u{FFFD}\u{FFFD} text", 'b8'], $cells[5]);
        self::assertSame(2, $job->metaInt('replaced'));
        self::assertSame([
            ['n' => 4, 'col' => 0, 'kind' => 'too-long'],
            ['n' => 7, 'col' => 2, 'kind' => 'extra-cells'],
        ], $job->db()->all('SELECT n, col, kind FROM issues ORDER BY n, col'));
        self::assertSame(5, $job->stats()[0]->count);
    }

    public function testTheRowCapStopsReading(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [$i % 7 === 0 ? '' : 'Row ' . $i];
        }
        $importer = new FakeImporter(['A'], $rows);
        $job = $this->newJob();
        $this->readAll($job, $importer, new Reader($this->types, null, 4, 10));
        self::assertSame(10, $job->rowCount());
        self::assertSame(['empty' => 1, 'over_limit' => 1], $job->skipped());
        self::assertSame(12, $job->metaInt('read_rows'), 'Rows 1 to 11 (one empty), then the first row over the limit.');
        self::assertSame(JobState::Review, $job->state());
    }

    public function testTheRealRowCap(): void
    {
        $rows = array_fill(0, IoLimits::MAX_ROWS + 5, ['x']);
        $importer = new FakeImporter(['A'], $rows);
        $job = $this->newJob();
        $this->readAll($job, $importer);
        self::assertSame(IoLimits::MAX_ROWS, $job->rowCount());
        self::assertSame(1, $job->skipped()['over_limit']);
        self::assertSame(IoLimits::MAX_ROWS + 1, $job->metaInt('read_rows'));
        foreach ($importer->calls as [, $limit]) {
            self::assertLessThanOrEqual(IoLimits::CHUNK_ROWS, $limit);
        }
    }

    public function testTheSourceRowCapCountsEmptyRows(): void
    {
        $importer = new FakeImporter(['A'], array_fill(0, 30, ['']));
        $job = $this->newJob();
        $this->readAll($job, $importer, new Reader($this->types, null, 8, 100, 20));
        self::assertSame(20, $job->metaInt('read_rows'));
        self::assertSame(['empty' => 20, 'over_limit' => 1], $job->skipped());
        self::assertSame(0, $job->rowCount());
        self::assertSame(JobState::Review, $job->state());
    }

    public function testStatisticsAfterResumptionEqualOnePass(): void
    {
        $rows = (new IoFixture())->itemRows(300);
        $chunked = $this->newJob();
        $this->readAll($chunked, new FakeImporter(IoFixture::HEADINGS, $rows), new Reader($this->types, null, 7));
        $whole = $this->newJob();
        $this->readAll($whole, new FakeImporter(IoFixture::HEADINGS, $rows));
        self::assertSame(
            array_map(static fn (ColumnStats $s): array => $s->toArray(), $whole->stats()),
            array_map(static fn (ColumnStats $s): array => $s->toArray(), $chunked->stats()),
        );
        self::assertSame(300, $whole->stats()[0]->count);
    }

    public function testAMisbehavingPluginIsBounded(): void
    {
        $importer = new FakeImporter(['A'], array_fill(0, 50, ['x']));
        $importer->overYield = true;
        $job = $this->newJob();
        $this->readAll($job, $importer, new Reader($this->types, null, 10));
        self::assertSame(50, $job->rowCount());
        self::assertSame(range(1, 50), array_map(static fn (array $r): int => (int) $r['n'], self::stored($job)));
    }

    public function testPluginFailuresFailTheJobAndKeepNoPartialChunk(): void
    {
        $cases = [
            'expected failure' => [new PluginFailure(PluginFailure::REASON_UNREADABLE, 'Injected.'), 'plugin-unreadable', 'unreadable'],
            'other exception' => [new \RuntimeException('Injected.'), 'plugin-failed', 'failed'],
            'error' => [new \TypeError('Injected.'), 'plugin-failed', 'failed'],
        ];
        $log = $this->captureLog();
        foreach ($cases as $name => [$throwable, $reason, $pluginReason]) {
            $importer = new FakeImporter(['A'], array_fill(0, 20, ['x']));
            $importer->failAt = 15;
            $importer->failure = $throwable;
            $job = $this->newJob();
            $reader = new Reader($this->types, null, 10);
            self::assertFalse($reader->chunk($job, $importer, $importer->detect($job->sourceFile()), $importer->table()));
            try {
                $reader->chunk($job, $importer, $importer->detect($job->sourceFile()), $importer->table());
                self::fail($name . ' was not reported.');
            } catch (PluginFailure $failure) {
                self::assertSame($pluginReason, $failure->reason, $name);
            }
            self::assertSame(JobState::Failed, $job->state(), $name);
            self::assertSame($reason, $job->failure(), $name);
            self::assertSame(10, $job->rowCount(), $name . ': the failing chunk was rolled back.');
            self::assertSame(10, $job->metaInt('read_rows'), $name);
        }
        self::assertStringNotContainsString('Injected', (string) file_get_contents($log), 'The log names the class, not the message.');
    }

    public function testBadRowsAndCellsFromAPlugin(): void
    {
        foreach ([[['x', 1]], ['not a row'], [['x', ['nested']]], [[1.5]]] as $rows) {
            $importer = new FakeImporter(['A', 'B'], $rows);
            $job = $this->newJob();
            try {
                $this->readAll($job, $importer);
                self::fail('A bad row was stored.');
            } catch (PluginFailure $failure) {
                self::assertSame('failed', $failure->reason);
                self::assertSame(0, $job->rowCount());
            }
        }
    }

    public function testTheLockIsRequired(): void
    {
        $importer = new FakeImporter(['A'], [['x']]);
        $job = $this->newJob();
        $job->unlock();
        $this->expectException(\LogicException::class);
        (new Reader($this->types))->chunk($job, $importer, $importer->detect($job->sourceFile()), $importer->table());
    }
}
