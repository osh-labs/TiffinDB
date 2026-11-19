<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\ImportJobView;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M2Fixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * An import that fails part-way leaves the target unchanged (M3 spec
 * section 1.3, exit audit item; the end-to-end form is T-E2E-9). The
 * import runs through ImportOperations on the M2 data set; the failure is
 * injected into the project file itself, as a trigger that raises an
 * error when the commit reaches a given point, because
 * ImportOperations::commit gives no seam into the committer (the
 * committer's own fault seam is covered by T-IO-12). The project file's
 * content (every table, every row, and the schema, read with plain SQL)
 * has the same checksum after the failed commit as before it, the job is
 * Failed with the reason commit-failed, and a snapshot was taken first.
 */
#[CoversNothing]
final class FailedImportTest extends RoundTripCase
{
    /**
     * @return iterable<string, array{string, bool, string}> point => [trigger table ('log', 'rooms', 'items'), new table, condition]
     */
    public static function points(): iterable
    {
        yield 'new table, at the import log row (every other step done)' => ['log', true, ''];
        yield 'new table, while adding a linked room' => ['rooms', true, ''];
        yield 'existing table, half-way through the rows' => ['items', false, 'WHEN NEW."id" > 70'];
    }

    #[DataProvider('points')]
    public function testAFailedCommitLeavesTheProjectUnchanged(string $point, bool $newTable, string $when): void
    {
        $m2 = M2Fixture::build($this->core, $this->ops);
        $p = $m2->project;
        $tables = ['log' => '_tiffin_import_log', 'rooms' => 't_' . $m2->rooms, 'items' => 't_' . $m2->items];
        $this->core->projectDb($p)->run(
            'CREATE TRIGGER "injected_failure" AFTER INSERT ON "' . $tables[$point] . '" ' . $when . ' BEGIN SELECT RAISE(ABORT, \'injected failure\'); END',
        );

        $rows = (new IoFixture())->canonicalRows(60, [...M2Fixture::ROOMS, 'Attic', 'Shed']);
        $headings = IoFixture::CANONICAL_HEADINGS;
        if (!$newTable) {
            // Appending to Items: a file with Items' own headings.
            $headings = ['Name', 'Serial number', 'Notes'];
            $rows = array_map(static fn (array $r): array => [$r[0], 'EX-' . substr($r[12], 4), $r[13]], $rows);
        }
        $job = $this->startImport($p, IoFixture::exportCsv($headings, $rows), 'inventory.csv');
        $imports = $this->ops->imports();
        $review = $imports->review($this->admin, $p, $job, null, []);
        $input = self::input($review);
        if (!$newTable) {
            $imports->recheck($this->admin, $p, $job, ['target' => 'existing', 'table' => $m2->items]);
            $input = self::input($imports->review($this->admin, $p, $job, null, []));
            self::assertSame([$m2->itemFields['Name'], $m2->itemFields['Serial number'], $m2->itemFields['Notes']], $input['field']);
        }
        $imports->saveMapping($this->admin, $p, $job, $input);
        while (!$imports->prepareChunk($this->admin, $p, $job)->finished) {
        }

        $before = self::checksum($this->projectFile($p));
        $snapshots = count($this->ops->snapshots()->list($this->admin, $p));
        $this->captureLog();
        try {
            $imports->commit($this->admin, $p, $job);
            self::fail('The commit fails.');
        } catch (\Throwable $error) {
            $messages = '';
            for ($e = $error; $e !== null; $e = $e->getPrevious()) {
                $messages .= $e->getMessage() . "\n";
            }
            self::assertStringContainsString('injected failure', $messages, 'The injected failure stopped the commit.');
        }
        self::assertSame($before, self::checksum($this->projectFile($p)), "{$point}: the project file's content is unchanged.");
        $view = $imports->job($this->admin, $p, $job);
        self::assertSame([ImportJobView::STATE_FAILED, 'commit-failed'], [$view->state, $view->failure]);
        self::assertSame($snapshots + 1, count($this->ops->snapshots()->list($this->admin, $p)), 'The import snapshot was taken first.');
    }

    private string|false $previousLog = false;

    /** Sends error_log() to a file for the rest of the test (the failure is logged). */
    private function captureLog(): void
    {
        $this->previousLog = ini_set('error_log', $this->core->temp->path('error.log'));
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        parent::tearDown();
    }
}
