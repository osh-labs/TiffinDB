<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\ImportJobView;
use TiffinDB\Core\App\ImportOperations;
use TiffinDB\Core\App\ImportReview;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-APP-91 (import end to end through the operations), T-APP-97 (the
 * import snapshot), and the job rules of M3 spec sections 5.2 and 13.1.
 */
#[CoversClass(ImportOperations::class)]
final class ImportOperationsTest extends AppTestCase
{
    private ProjectRecord $project;
    private TableDef $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Home', null);
        $this->rooms = $this->ops->schema()->createTable($this->admin, $this->project->key, 'Rooms', 'room', true);
    }

    public function testImportEndToEndThroughTheOperations(): void
    {
        $rows = (new IoFixture())->itemRows(30);
        $imports = $this->ops->imports();
        $p = $this->project->key;

        $start = $imports->startScreen($this->admin, $p);
        self::assertSame(['import/csv'], array_values(array_intersect(['import/csv'], array_map(static fn ($f) => $f->ref, $start->formats))));
        self::assertSame(IoLimits::MAX_UPLOAD_BYTES, $start->maxBytes);
        self::assertSame(['Rooms'], array_map(static fn ($t) => $t->table->name, $start->tables));

        $job = $imports->start($this->admin, $p, $this->fx->upload(IoFixture::csv(IoFixture::HEADINGS, $rows)), "  inventory.csv\x07 ", null);
        $view = $imports->job($this->admin, $p, $job);
        self::assertSame([ImportJobView::STATE_REVIEW, 'inventory.csv', 30, true, null], [$view->state, $view->fileName, $view->storedRows, $view->readDone, $view->failure]);

        $review = $imports->review($this->admin, $p, $job, null, []);
        self::assertSame(['new', 'inventory', 30, 30, true, 7], [$review->target, $review->newTableName, $review->rows, $review->importCount, $review->headingRow, $review->columnCount]);
        self::assertSame(
            ['text', 'link:' . $this->rooms->key, 'currency', 'date', 'number', 'checkbox', 'text'],
            array_map(static fn ($c) => $c->type, $review->columns),
        );
        self::assertSame('Rooms', $review->columns[1]->typeChoices[0]->tableName);
        $warnings = array_map(static fn ($w) => [$w->kind, $w->params], $review->warnings);
        self::assertContains(['links_added', ['column' => 'Room', 'table' => 'Rooms', 'count' => 4, 'items' => 'room']], $warnings);

        $imports->saveMapping($this->admin, $p, $job, self::input($review));
        self::assertSame(ImportJobView::STATE_PREPARING, $imports->job($this->admin, $p, $job)->state);
        do {
            $progress = $imports->prepareChunk($this->admin, $p, $job);
        } while (!$progress->finished);
        self::assertSame([30, 30], [$progress->done, $progress->total]);
        self::assertSame(ImportJobView::STATE_READY, $imports->job($this->admin, $p, $job)->state);

        $logKey = $imports->commit($this->admin, $p, $job);
        self::assertSame($job, $logKey);
        $this->assertThrows(NotFound::class, fn () => $imports->job($this->admin, $p, $job));

        $report = $imports->report($this->admin, $p, $logKey);
        self::assertNotNull($report->table);
        self::assertSame(['inventory', 30, 30, true, 4, 'import/csv', 'inventory.csv'], [
            $report->table->name, $report->report->rowsImported, $report->report->rowsRead, $report->report->newTable,
            $report->report->linksAdded, $report->report->plugin, $report->report->fileName,
        ]);
        self::assertSame([], $report->columns);
        self::assertCount(1, $report->links);
        self::assertSame(['Rooms', 4], [$report->links[0]->tableName, $report->links[0]->added]);

        $listing = $this->ops->records()->listing($this->admin, $p, $report->table->key, ListRequest::none());
        self::assertSame(30, $listing->tableTotal);

        $audit = $this->lastAudit(AuditAction::IMPORT_COMPLETED);
        self::assertSame([$this->admin->userId, $this->project->id, 'table', $report->table->key], [$audit->actorUserId, $audit->projectId, $audit->targetKind, $audit->targetKey]);
        self::assertSame(['plugin' => 'import/csv', 'rows' => 30, 'new_table' => true], $audit->detail);
        self::assertStringNotContainsString('inventory', json_encode($this->auditEntries(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($rows[0][0], json_encode($this->auditEntries(), JSON_THROW_ON_ERROR));
    }

    /** T-APP-97: the import snapshot is recorded as snapshot.taken and listed with reason `import`. */
    public function testTheImportSnapshotIsRecordedAndListed(): void
    {
        $p = $this->project->key;
        $before = count($this->ops->snapshots()->list($this->admin, $p));
        $job = $this->readyJob("Name\nKettle\n");
        $this->ops->imports()->commit($this->admin, $p, $job);

        $snapshots = $this->ops->snapshots()->list($this->admin, $p);
        self::assertCount($before + 1, $snapshots);
        self::assertSame(SnapshotReason::Import, $snapshots[0]->reason);
        $entries = $this->auditEntries();
        $actions = array_map(static fn ($e) => $e->action, $entries);
        $taken = array_search(AuditAction::SNAPSHOT_TAKEN, array_reverse($actions, true), true);
        self::assertIsInt($taken);
        self::assertSame(['reason' => 'import'], $entries[$taken]->detail);
        self::assertSame($snapshots[0]->file, $entries[$taken]->targetKey);
        self::assertLessThan(array_search(AuditAction::IMPORT_COMPLETED, $actions, true), $taken);
    }

    public function testLargeFilesAreReadInChunks(): void
    {
        $rows = (new IoFixture())->itemRows(IoLimits::CHUNK_ROWS + 500);
        $imports = $this->ops->imports();
        $p = $this->project->key;
        $job = $imports->start($this->admin, $p, $this->fx->upload(IoFixture::csv(IoFixture::HEADINGS, $rows)), 'big.csv', 'import/csv');
        $view = $imports->job($this->admin, $p, $job);
        self::assertSame([ImportJobView::STATE_READING, IoLimits::CHUNK_ROWS, false], [$view->state, $view->readRows, $view->readDone]);
        $progress = $imports->readChunk($this->admin, $p, $job);
        self::assertSame([IoLimits::CHUNK_ROWS + 500, true], [$progress->done, $progress->finished]);
        // A repeated Continue reports the step as finished and changes nothing.
        self::assertTrue($imports->readChunk($this->admin, $p, $job)->finished);
        self::assertSame(IoLimits::CHUNK_ROWS + 500, $imports->job($this->admin, $p, $job)->storedRows);
    }

    public function testAppendingToAnExistingTableAndRecheck(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $p, $this->fx->upload("Name,Floor\nCellar,0\nAttic,2\n"), 'rooms.csv', null);
        $review = $imports->review($this->admin, $p, $job, null, []);
        self::assertSame('new', $review->target);

        // Check again for the existing table: the columns start from that table's defaults (P137).
        $imports->recheck($this->admin, $p, $job, ['target' => 'existing', 'table' => $this->rooms->key, 'include' => ['0' => '1'], 'type' => ['0' => 'number']]);
        $review = $imports->review($this->admin, $p, $job, null, []);
        self::assertSame(['existing', $this->rooms->key], [$review->target, $review->tableKey]);
        $name = $this->ops->schema()->design($this->admin, $p, $this->rooms->key)->fields[0]->field->key;
        self::assertSame([$name, null], [$review->columns[0]->fieldKey, $review->columns[1]->fieldKey]);
        self::assertSame(['Name'], array_map(static fn ($f) => $f->name, $review->columns[0]->fieldChoices));

        // Shape checks: a field key that is not a field of the table is dropped, not stored.
        $imports->recheck($this->admin, $p, $job, ['target' => 'existing', 'table' => $this->rooms->key, 'include' => ['0' => '1'], 'field' => ['0' => 'nokey0000000', '1' => '']]);
        self::assertSame($name, $imports->review($this->admin, $p, $job, null, [])->columns[0]->fieldKey);
        $this->assertThrows(NotFound::class, fn () => $imports->recheck($this->admin, $p, $job, ['target' => 'existing', 'table' => 'nokey0000000']));

        $problems = self::problems(fn () => $imports->saveMapping($this->admin, $p, $job, ['target' => 'existing', 'table' => $this->rooms->key, 'include' => ['0' => '1', '1' => '1'], 'field' => ['0' => $name, '1' => $name]]));
        self::assertSame([['col-1', 'problem.import.field_twice', []]], $problems);
        $imports->saveMapping($this->admin, $p, $job, ['target' => 'existing', 'table' => $this->rooms->key, 'include' => ['0' => '1'], 'field' => ['0' => $name, '1' => '']]);
        while (!$imports->prepareChunk($this->admin, $p, $job)->finished) {
        }
        $log = $imports->commit($this->admin, $p, $job);
        $report = $imports->report($this->admin, $p, $log);
        self::assertSame([false, 2, $this->rooms->key], [$report->report->newTable, $report->report->rowsImported, $report->table?->key]);
    }

    public function testTheTargetChangingDuringPreparationSendsTheJobBackToReview(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $p, $this->fx->upload("Name\nKettle\n"), 'rooms.csv', null);
        $imports->recheck($this->admin, $p, $job, ['target' => 'existing', 'table' => $this->rooms->key]);
        $imports->saveMapping($this->admin, $p, $job, self::input($imports->review($this->admin, $p, $job, null, [])));
        $this->ops->schema()->addField($this->admin, $p, $this->rooms->key, 'Floor', 'number', [], false, '');
        $problems = self::problems(fn () => $imports->prepareChunk($this->admin, $p, $job));
        self::assertSame([['table', 'problem.import.target_changed', []]], $problems);
        self::assertSame(ImportJobView::STATE_REVIEW, $imports->job($this->admin, $p, $job)->state);
    }

    public function testUploadAndReadingProblems(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        self::assertSame([['file', 'problem.upload.missing', []]], self::problems(fn () => $imports->start($this->admin, $p, $this->fx->temp->path('absent'), 'a.csv', null)));
        self::assertSame([['file', 'problem.import.format_unknown', []]], self::problems(fn () => $imports->start($this->admin, $p, $this->fx->upload('x'), 'notes.unknownext', null)));
        self::assertSame([['file', 'problem.import.format_unknown', []]], self::problems(fn () => $imports->start($this->admin, $p, $this->fx->upload('x'), 'a.csv', 'import/nothing')));
        $this->captureLog();
        $wide = implode(',', array_map(static fn (int $i): string => 'c' . $i, range(1, IoLimits::MAX_COLUMNS + 1))) . "\n";
        $problems = self::problems(fn () => $imports->start($this->admin, $p, $this->fx->upload($wide), 'wide.csv', null));
        // The CSV plugin refuses a heading row over the column limit as too large, which reads as unsupported.
        self::assertSame([['file', 'problem.import.unsupported', []]], $problems);
        self::assertStringContainsString('plugin import/csv failed (too-large)', $this->errorLog());
        self::assertSame([], $this->stagedJobs('imports'));
    }

    public function testReadingOptionsResetTheJob(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $p, $this->fx->upload("a;b\n1;2\n"), 'semi.csv', null);
        $screen = $imports->readingOptions($this->admin, $p, $job);
        self::assertSame(['encoding', 'delimiter', 'heading'], array_map(static fn ($s) => $s->name, $screen->settings));
        $imports->setReadingOptions($this->admin, $p, $job, ['heading' => '0', 'unknown' => 'x']);
        self::assertSame(ImportJobView::STATE_READING, $imports->job($this->admin, $p, $job)->state);
        self::assertTrue($imports->readChunk($this->admin, $p, $job)->finished);
        $review = $imports->review($this->admin, $p, $job, null, []);
        self::assertSame([false, 2], [$review->headingRow, $review->rows]);
    }

    public function testCancelRemovesTheJobAndALockedJobIsBusy(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        $job = $imports->start($this->admin, $p, $this->fx->upload("Name\nKettle\n"), 'a.csv', null);
        $lock = fopen($this->fx->data->path() . '/staging/imports/' . $job . '/job.lock', 'r');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->assertThrows(Busy::class, fn () => $imports->job($this->admin, $p, $job));
            $this->assertThrows(Busy::class, fn () => $imports->cancel($this->admin, $p, $job));
            // Another Admin learns nothing, not even that the job is busy.
            $other = $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
            $this->assertThrows(NotFound::class, fn () => $imports->job($other, $p, $job));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $imports->cancel($this->admin, $p, $job);
        self::assertSame([], $this->stagedJobs('imports'));
        $this->assertThrows(NotFound::class, fn () => $imports->job($this->admin, $p, $job));
    }

    public function testADisabledPluginFailsTheJobAtItsNextStep(): void
    {
        $p = $this->project->key;
        $imports = $this->ops->imports();
        $rows = (new IoFixture())->itemRows(IoLimits::CHUNK_ROWS + 10);
        $job = $imports->start($this->admin, $p, $this->fx->upload(IoFixture::csv(IoFixture::HEADINGS, $rows)), 'big.csv', null);
        $this->ops->plugins()->disable($this->admin, 'import', 'csv');
        self::assertTrue($imports->readChunk($this->admin, $p, $job)->finished);
        $view = $imports->job($this->admin, $p, $job);
        self::assertSame([ImportJobView::STATE_FAILED, ImportOperations::FAILURE_PLUGIN], [$view->state, $view->failure]);
        self::assertSame([['file', 'problem.import.format_unknown', []]], self::problems(fn () => $imports->start($this->admin, $p, $this->fx->upload('a'), 'a.csv', null)));
    }

    // ------------------------------------------------------------------ helpers

    /** A job read from $csv, mapped to a new table with the defaults, prepared, and Ready. */
    private function readyJob(string $csv): string
    {
        $imports = $this->ops->imports();
        $p = $this->project->key;
        $job = $imports->start($this->admin, $p, $this->fx->upload($csv), 'items.csv', null);
        $imports->saveMapping($this->admin, $p, $job, self::input($imports->review($this->admin, $p, $job, null, [])));
        while (!$imports->prepareChunk($this->admin, $p, $job)->finished) {
        }

        return $job;
    }

    /** @return array<string, mixed> the review form as the screen posts it */
    public static function input(ImportReview $review): array
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

    /** @return list<string> */
    private function stagedJobs(string $kind): array
    {
        $folder = $this->fx->data->path() . '/staging/' . $kind;

        return is_dir($folder) ? array_values(array_diff(scandir($folder) ?: [], ['.', '..'])) : [];
    }
}
