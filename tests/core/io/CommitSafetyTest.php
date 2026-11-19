<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\Committer;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\MappingCheck;
use TiffinDB\Core\Io\Preparer;
use TiffinDB\Core\Io\Reader;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/ImportFixture.php';

/**
 * T-IO-10, T-IO-11, T-IO-12: the target changing before the commit, Busy,
 * the job lock, a repeated commit, and atomicity under injected failures.
 */
#[CoversClass(Committer::class)]
final class CommitSafetyTest extends TestCase
{
    use ImportFixture;

    private TableDef $rooms;
    private TableDef $items;

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-commit-safety-test-1');
        $editor = $this->hookedEditor();
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->items = $editor->createTable('Items', 'item', true);
        $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $records->create($this->rooms->key, [$this->schema()->fields($this->rooms->key)[0]->key => 'Kitchen'], InputMode::Form, self::USER_KEY);
        $records->create($this->items->key, [$this->schema()->fields($this->items->key)[0]->key => 'Existing'], InputMode::Form, self::USER_KEY);
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    private function importer(): FakeImporter
    {
        return new FakeImporter(IoFixture::HEADINGS, (new IoFixture())->itemRows(20));
    }

    private function existingMapping(ImportJob $job, FakeImporter $importer): Mapping
    {
        return MappingCheck::defaults(Mapping::EXISTING, $this->schema(), $this->guesses($job, $importer), 'unused', $this->items->key);
    }

    /** @return list<array{0: ?string, 1: string}> */
    private static function flat(ValidationFailed $failed): array
    {
        return array_map(static fn (Problem $p): array => [$p->field, $p->key], $failed->problems);
    }

    private function otherEditor(Database $db): SchemaEditor
    {
        return new SchemaEditor($db, $this->folder, $this->types, $this->keys, $this->clock, $this->snapshots, self::USER_KEY, static function (SnapshotInfo $i): void {
        }, function (Database $db, array $tableKeys): void {
            (new FormStore($db, $this->keys, $this->clock, new FormParser($this->types)))->regenerate(new Schema($db, $this->types), array_values($tableKeys));
        });
    }

    // ------------------------------------------------------------ T-IO-10

    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        yield 'new table' => ['new'];
        yield 'existing table' => ['existing'];
    }

    #[DataProvider('targets')]
    public function testTheTargetChangedBetweenReviewAndCommit(string $target): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer, fn (ImportJob $job): Mapping => $target === 'new' ? $this->defaultMapping($job, $importer) : $this->existingMapping($job, $importer));
        // Another request changes the target: a field added to Items, or a table added (new target).
        $other = $this->secondConnection();
        if ($target === 'new') {
            $this->otherEditor($other)->createTable('Boxes', 'box', true);
        } else {
            $this->otherEditor($other)->addField($this->items->key, 'Added later', 'text', [], false, '');
        }
        $before = $this->dump();
        try {
            $this->commit($job);
            self::fail('The changed target was not noticed.');
        } catch (ValidationFailed $failed) {
            self::assertSame([['table', 'problem.import.target_changed']], self::flat($failed));
        }
        self::assertSame(JobState::Review, $job->state());
        self::assertEquals($before, $this->dump());
        self::assertSame([], $this->importSnapshots);
        self::assertSame([], $this->snapshotFiles(), 'No snapshot.');
    }

    public function testATitleFieldChangeOfALinkTargetIsATargetChange(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer);
        $editor = $this->otherEditor($this->secondConnection());
        $editor->updateTable($this->rooms->key, 'Rooms', 'room', null);
        try {
            $this->commit($job);
            self::fail('The changed title field was not noticed.');
        } catch (ValidationFailed $failed) {
            self::assertSame([['table', 'problem.import.target_changed']], self::flat($failed));
        }
    }

    // ------------------------------------------------------------ T-IO-11

    public function testBusyLeavesTheJobReadyAndTheFileUnchanged(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer);
        $before = $this->dump();
        $fast = Sqlite::open($this->path(), 50);
        $blocker = $this->secondConnection();
        try {
            $blocker->write(function () use ($job, $fast): void {
                $mapping = $job->mapping();
                self::assertNotNull($mapping);
                try {
                    (new Committer())->commit($job, $mapping, $fast, $this->types, $this->clock, $this->otherEditor($fast), $this->snapshots, $this->folder, self::USER_KEY);
                    self::fail('The commit did not wait for the other writer.');
                } catch (Busy) {
                    $this->addToAssertionCount(1);
                }
            });
        } finally {
            $fast->close();
        }
        self::assertSame(JobState::Ready, $job->state());
        self::assertEquals($before, $this->dump());

        // A retry succeeds.
        $report = $this->commit($job);
        self::assertSame(20, $report->rowsImported);
    }

    public function testTheJobLockGatesEveryJobStep(): void
    {
        $importer = $this->importer();
        $job = $this->newJob();
        $second = ImportJob::open($job->folder());
        try {
            $second->lock();
            self::fail('Two holders of one job lock.');
        } catch (Busy) {
            $this->addToAssertionCount(1);
        }
        // Without the lock no step runs.
        $steps = [
            fn () => (new Reader($this->types))->chunk($second, $importer, $importer->detect($second->sourceFile()), $importer->table()),
            fn () => (new Preparer($this->types))->chunk($second, $this->schema()),
            fn () => (new Committer())->commit($second, new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'text', null)]), $this->db, $this->types, $this->clock, $this->hookedEditor(), $this->snapshots, $this->folder, self::USER_KEY),
        ];
        foreach ($steps as $step) {
            try {
                $step();
                self::fail('A job step ran without the lock.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $second->close();
        self::assertSame(JobState::Reading, $job->state());
    }

    public function testASecondCommitReturnsTheLogKeyAndImportsNothing(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer);
        $first = $this->commit($job);
        $after = $this->dump();
        $snapshots = $this->snapshotFiles();
        // The request died after the project commit, before its clean-up: the job file still says Ready.
        $job->db()->write(static fn (Database $db) => $db->run('UPDATE meta SET value = \'ready\' WHERE key = \'state\''));
        $again = $this->commit($job);
        self::assertSame($first->key, $again->key);
        self::assertEquals($first, $again);
        self::assertEquals($after, $this->dump());
        self::assertSame($snapshots, $this->snapshotFiles(), 'No second snapshot.');
        self::assertSame(JobState::Done, $job->state());
    }

    public function testALogRowWrittenBetweenTheCheckAndTheWriteLockIsNotImportedTwice(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer);
        $mapping = $job->mapping();
        self::assertNotNull($mapping);
        $other = $this->secondConnection();
        $count = (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_tables"');
        $report = (new Committer())->commit($job, $mapping, $this->db, $this->types, $this->clock, $this->hookedEditor(), $this->snapshots, $this->folder, self::USER_KEY,
            function () use ($other, $job): void {
                // Another request finished the same commit while this one took its snapshot.
                $other->write(static fn (Database $db) => $db->run(
                    'INSERT INTO "_tiffin_import_log" ("key", "imported_at", "source", "file_name", "file_size", "file_sha256", "rows_read", "rows_imported", "rows_skipped", "values_emptied")'
                    . ' VALUES (?, ?, \'file\', \'inventory.csv\', 16, ?, 20, 20, 0, 0)',
                    [$job->key(), self::START, str_repeat('0', 64)],
                ));
            },
        );
        self::assertSame($job->key(), $report->key);
        self::assertSame($count, (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_tables"'), 'Nothing imported twice.');
        self::assertSame(JobState::Done, $job->state());
    }

    public function testASchemaChangeBetweenStepOneAndTheWriteLockRollsBack(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer, fn (ImportJob $job): Mapping => $this->existingMapping($job, $importer));
        $mapping = $job->mapping();
        self::assertNotNull($mapping);
        $other = $this->secondConnection();
        $before = null;
        try {
            (new Committer())->commit($job, $mapping, $this->db, $this->types, $this->clock, $this->hookedEditor(), $this->snapshots, $this->folder, self::USER_KEY,
                function () use ($other, &$before): void {
                    $this->otherEditor($other)->addField($this->items->key, 'Raced', 'text', [], false, '');
                    $before = $this->dump();
                },
            );
            self::fail('The raced change was not noticed.');
        } catch (ValidationFailed $failed) {
            self::assertSame([['table', 'problem.import.target_changed']], self::flat($failed));
        }
        self::assertEquals($before, $this->dump());
        self::assertSame(JobState::Review, $job->state());
        self::assertCount(1, $this->snapshotFiles(), 'The snapshot stays.');
    }

    // ------------------------------------------------------------ T-IO-12

    /** @return iterable<string, array{string, string}> */
    public static function faults(): iterable
    {
        foreach (['table', 'fields', 'links', 'rows-half', 'rows', 'log'] as $step) {
            yield 'new table, after ' . $step => ['new', $step];
        }
        foreach (['links', 'rows-half', 'rows', 'log'] as $step) {
            yield 'existing table, after ' . $step => ['existing', $step];
        }
    }

    #[DataProvider('faults')]
    public function testAFailureAtAnyStepLeavesTheProjectUnchanged(string $target, string $step): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer, fn (ImportJob $job): Mapping => $target === 'new' ? $this->defaultMapping($job, $importer) : $this->existingMapping($job, $importer));
        $this->clock->advance(3600);
        $before = $this->dump();
        $reached = [];
        try {
            $this->commit($job, static function (string $at) use ($step, &$reached): void {
                $reached[] = $at;
                if ($at === $step) {
                    throw new \RuntimeException('Injected at ' . $at);
                }
            });
            self::fail('The injected failure was swallowed.');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected at ' . $step, $e->getMessage());
        }
        self::assertContains($step, $reached);
        $after = $this->dump();
        self::assertSame(array_keys($before['tables']), array_keys($after['tables']));
        foreach ($before['tables'] as $name => $rows) {
            self::assertSame($rows, $after['tables'][$name], $name . ' is byte-identical.');
        }
        self::assertSame($before['master'], $after['master']);
        self::assertSame(JobState::Failed, $job->state());
        self::assertSame('commit-failed', $job->failure());
        self::assertCount(1, $this->snapshotFiles(), 'The import snapshot stays.');
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        // The project is still usable.
        ProjectFile::touch($this->db, self::USER_KEY, $this->clock);
    }

    public function testAValidationFailureInsideTheCommitFailsTheJob(): void
    {
        $importer = $this->importer();
        $job = $this->readyJob($importer);
        // A prepared value the new field refuses (longer than its maximum): the records layer stops the commit.
        $job->db()->write(static fn (Database $db) => $db->run('UPDATE prepared SET record = json_set(record, \'$."col-0"\', ?) WHERE n = 3', [str_repeat('x', 600)]));
        $before = $this->dump();
        try {
            $this->commit($job);
            self::fail('A bad prepared value was written.');
        } catch (ValidationFailed $failed) {
            self::assertNotSame([['table', 'problem.import.target_changed']], self::flat($failed));
        }
        self::assertEquals($before, $this->dump());
        self::assertSame('commit-failed', $job->failure());
    }
}
