<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\Committer;
use TiffinDB\Core\Io\ImportLog;
use TiffinDB\Core\Io\ImportReport;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-8: commit into a new table. */
#[CoversClass(Committer::class)]
#[CoversClass(ImportLog::class)]
#[CoversClass(ImportReport::class)]
final class CommitNewTableTest extends TestCase
{
    use ImportFixture;

    private TableDef $rooms;
    private FieldDef $roomName;

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-commit-new-test-1');
        $this->rooms = $this->hookedEditor()->createTable('Rooms', 'room', true);
        $this->roomName = $this->schema()->fields($this->rooms->key)[0];
        (new Records($this->db, $this->schema(), $this->types, $this->clock))->create($this->rooms->key, [$this->roomName->key => 'Kitchen'], InputMode::Form, self::USER_KEY);
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    public function testCommitIntoANewTable(): void
    {
        $rows = (new IoFixture())->itemRows(30);
        $rows[4][1] = ' kitchen ';
        $rows[0][1] = 'GARAGE';
        $rows[6][1] = '';
        $rows[7][2] = 'free';
        $rows[] = ['', '', '', '', '', '', ''];
        $importer = new FakeImporter(IoFixture::HEADINGS, $rows);
        // 'free' makes the price column text by inference; the Admin chose currency.
        $job = $this->readyJob($importer, function ($job) use ($importer): Mapping {
            $mapping = $this->defaultMapping($job, $importer);
            self::assertSame('text', $mapping->columns[2]->type);
            $columns = $mapping->columns;
            $columns[2] = new ColumnMapping(2, true, 'Price', 'currency', null);

            return new Mapping(Mapping::NEW, $mapping->newTableName, null, $columns);
        });
        $this->clock->advance(60);
        $before = $this->db->value('SELECT COUNT(*) FROM "_tiffin_tables"');

        $report = $this->commit($job);

        // Snapshot taken before the transaction: it has no new table and no log row.
        self::assertCount(1, $this->importSnapshots);
        self::assertSame(SnapshotReason::Import, $this->importSnapshots[0]->reason);
        $copy = Sqlite::open($this->folder . '/snapshots/' . $this->importSnapshots[0]->file);
        self::assertSame($before, $copy->value('SELECT COUNT(*) FROM "_tiffin_tables"'));
        self::assertSame(0, $copy->value('SELECT COUNT(*) FROM "_tiffin_import_log"'));
        $copy->close();

        // Table, fields in column order, relation, title field.
        $schema = $this->schema();
        $table = null;
        foreach ($schema->tables() as $candidate) {
            if ($candidate->name === 'Imported items') {
                $table = $candidate;
            }
        }
        self::assertNotNull($table);
        self::assertSame('imported items', $table->itemName);
        $fields = $schema->fields($table->key);
        self::assertSame(['Name', 'Room', 'Price', 'Purchased', 'Quantity', 'In use', 'Notes'], array_map(static fn (FieldDef $f): string => $f->name, $fields));
        self::assertSame(['text', 'relation', 'currency', 'date', 'number', 'checkbox', 'text'], array_map(static fn (FieldDef $f): string => $f->type, $fields));
        self::assertSame($fields[0]->key, $table->titleFieldKey);
        self::assertSame(['code' => 'USD', 'minor_digits' => 2], $fields[2]->options);
        $relation = $schema->relationOf($fields[1]);
        self::assertNotNull($relation);
        self::assertSame([Cardinality::Many, Cardinality::Many], [$relation->leftMax, $relation->rightMax]);
        self::assertSame($this->rooms->key, $relation->rightTable);
        $reverse = $schema->field($relation->rightField);
        self::assertSame('Imported items', $reverse->name);
        self::assertFalse($fields[0]->required);

        // Forms regenerated for both tables in the same transaction.
        $forms = $this->db->all('SELECT "table_key", "html" FROM "_tiffin_forms" WHERE "is_default" = 1 ORDER BY "table_key"');
        $byTable = array_column($forms, 'html', 'table_key');
        self::assertArrayHasKey($table->key, $byTable);
        foreach ($fields as $field) {
            self::assertStringContainsString('data-tiffin-field="' . $field->key . '"', $byTable[$table->key]);
        }
        self::assertStringContainsString('data-tiffin-field="' . $reverse->key . '"', $byTable[$this->rooms->key]);

        // Records in file order; the empty row skipped.
        $records = new Records($this->db, $schema, $this->types, $this->clock);
        self::assertSame(30, $records->count($table->key));
        for ($i = 1; $i <= 30; $i++) {
            self::assertSame($rows[$i - 1][0], $records->get($table->key, $i)?->values[$fields[0]->key], 'Record ' . $i);
        }
        $first = $records->get($table->key, 1);
        self::assertNotNull($first);
        self::assertSame(self::USER_KEY, $first->createdBy);

        // Link targets created once per title (ASCII case-insensitive); existing Kitchen matched.
        $roomTitles = array_map(static fn ($l): ?string => $l->title, $records->pickList($this->rooms->key, 500));
        sort($roomTitles);
        self::assertSame(['GARAGE', 'Hall', 'Kitchen', 'Office'], $roomTitles, 'The first spelling seen is used.');
        self::assertSame('Kitchen', $records->get($table->key, 5)?->links[$fields[1]->key][0]->title);
        self::assertSame('GARAGE', $records->get($table->key, 1)?->links[$fields[1]->key][0]->title);
        self::assertSame([], $records->get($table->key, 7)?->links[$fields[1]->key]);
        self::assertNull($records->get($table->key, 8)?->values[$fields[2]->key]);

        // The log row, exactly (format section 4.7).
        $row = $this->db->one('SELECT * FROM "_tiffin_import_log"');
        self::assertSame([
            'key' => $job->key(),
            'imported_at' => '2026-11-12T03:16:00Z',
            'imported_by' => self::USER_KEY,
            'source' => 'file',
            'plugin' => 'import/csv',
            'file_name' => 'inventory.csv',
            'file_size' => 16,
            'file_sha256' => hash('sha256', 'synthetic upload'),
            'table_key' => $table->key,
            'new_table' => 1,
            'rows_read' => 31,
            'rows_imported' => 30,
            'rows_skipped' => 1,
            'values_emptied' => 1,
            'links_added' => 3,
            'snapshot' => $this->importSnapshots[0]->file,
            'report' => json_encode([
                'columns' => [['name' => 'Price', 'field' => $fields[2]->key, 'emptied' => 1, 'rows' => [8]]],
                'skipped' => ['empty' => 1, 'over_limit' => 0],
                'links' => [['field' => $fields[1]->key, 'table' => $this->rooms->key, 'added' => 3]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], $row);
        self::assertSame($job->key(), $report->key);
        self::assertEquals($report, ImportLog::get($this->db, $job->key()));
        self::assertNull(ImportLog::get($this->db, 'not a key'));
        self::assertNull(ImportLog::get($this->db, 'b7xk2mq4nz3p'));

        // The job is done; the project meta records the import.
        self::assertSame(JobState::Done, $job->state());
        self::assertSame($job->key(), $job->meta('report'));
        self::assertSame('2026-11-12T03:16:00Z', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\''));
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
    }

    public function testANewTableWithoutATextColumnHasNoTitleAndUniqueReverseNames(): void
    {
        // The Rooms table already has a field named like the new table.
        $this->hookedEditor()->addField($this->rooms->key, 'Counts', 'number', [], false, '');
        $importer = new FakeImporter(['Count', 'Room'], [['1', 'Kitchen'], ['2', 'Hall']]);
        $job = $this->readyJob($importer, fn ($job) => $this->defaultMapping($job, $importer, 'Counts'));
        $report = $this->commit($job);
        $table = $this->schema()->table((string) $report->tableKey);
        self::assertNull($table->titleFieldKey);
        self::assertSame('counts', $table->itemName);
        $names = array_map(static fn (FieldDef $f): string => $f->name, $this->schema()->fields($this->rooms->key));
        self::assertSame(['Name', 'Counts', 'Counts 2'], $names);
        self::assertSame(1, $report->linksAdded);
    }

    public function testImportReportRefusesBadValues(): void
    {
        foreach ([['source' => 'web'], ['sha' => 'abc'], ['rows' => -1]] as $bad) {
            try {
                new ImportReport('b7xk2mq4nz3p', self::START, null, $bad['source'] ?? 'file', null, 'a.csv', 1, $bad['sha'] ?? str_repeat('0', 64), null, false, $bad['rows'] ?? 0, 0, 0, 0, 0, null, []);
                self::fail('A bad report was built.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $report = new ImportReport('b7xk2mq4nz3p', self::START, null, 'archive', null, 'a.zip', 1, str_repeat('0', 64), null, false, 0, 0, 0, 0, 0, null, []);
        self::assertSame('{}', $report->reportJson());
    }
}
