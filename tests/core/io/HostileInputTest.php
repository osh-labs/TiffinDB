<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\Committer;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\MappingCheck;
use TiffinDB\Core\Io\Preparer;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/ImportFixture.php';

/**
 * Hostile input through the pipeline: headings, cells, titles, and
 * mappings written to look like SQL or paths, invalid text, and a job
 * file changed between requests. Values reach SQLite only as bound
 * parameters; SQL names come only from keys; nothing outside the mapping
 * is written.
 */
#[CoversClass(Committer::class)]
#[CoversClass(MappingCheck::class)]
#[CoversClass(Preparer::class)]
final class HostileInputTest extends TestCase
{
    use ImportFixture;

    private const SQL = '"; DROP TABLE "_tiffin_meta"; --';

    private TableDef $items;
    /** @var array<string, FieldDef> */
    private array $f = [];

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-hostile-input-test-1');
        $editor = $this->hookedEditor();
        $this->items = $editor->createTable('Items', 'item', true);
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $this->f['auto'] = $editor->addField($this->items->key, 'Code', 'auto_number', [], false, '');
        $this->f['weight'] = $editor->addField($this->items->key, 'Weight', 'number', [], false, '');
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    private function metaTables(): array
    {
        return $this->db->all('SELECT "name" FROM "sqlite_master" WHERE "type" = \'table\' ORDER BY "name"');
    }

    public function testHeadingsAndCellsThatLookLikeSqlAreData(): void
    {
        $headings = [self::SQL, "../../etc/passwd", "Tab\there\x00nul", "\xC3\x28 bad", 'Room ' . self::SQL];
        $rows = [
            [self::SQL, "'); DELETE FROM t; --", "x\x00y", "\xFF\xFE", 'Kitchen' . self::SQL],
            ['Robert\'); DROP TABLE items;--', '%s %n %x', '${7*7}', '<script>alert(1)</script>', ''],
        ];
        $importer = new FakeImporter($headings, $rows);
        $job = $this->readyJob($importer, fn (ImportJob $job): Mapping => $this->defaultMapping($job, $importer, 'Imported ' . substr(self::SQL, 0, 20)));
        $tablesBefore = count($this->metaTables());
        $report = $this->commit($job);

        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame($tablesBefore + 1, count($this->metaTables()), 'One new user table, nothing dropped.');
        self::assertSame('tiffindb-project', $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format\''));
        $table = $this->schema()->table((string) $report->tableKey);
        self::assertSame('Imported "; DROP TABLE "_tiff', $table->name);
        $fields = $this->schema()->fields($table->key);
        self::assertSame(['"; DROP TABLE " tiffin meta";', '../../etc/passwd', 'Tab here nul', 'Column 4', 'Room "; DROP TABLE " tiffin meta";'], array_map(static fn (FieldDef $f): string => $f->name, $fields));
        foreach ($fields as $field) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{12}\z/', $field->key);
        }
        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $first = $records->get($table->key, 1);
        self::assertNotNull($first);
        self::assertSame(self::SQL, $first->values[$fields[0]->key]);
        self::assertSame("'); DELETE FROM t; --", $first->values[$fields[1]->key]);
        self::assertSame('Robert\'); DROP TABLE items;--', $records->get($table->key, 2)?->values[$fields[0]->key]);
        self::assertSame('<script>alert(1)</script>', $records->get($table->key, 2)?->values[$fields[3]->key]);
        self::assertSame(2, $report->rowsImported);
    }

    public function testHostileMappingsAreRefused(): void
    {
        $stats = [];
        $check = fn (Mapping $m): array => array_map(static fn (Problem $p): string => $p->key, MappingCheck::check($m, $this->schema(), $this->types, $stats));
        self::assertSame(['problem.import.type_invalid'], $check(new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'link:' . self::SQL, null)])));
        self::assertSame(['problem.import.type_invalid'], $check(new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'link:' . $this->items->key . '"', null)])));
        self::assertSame(['problem.import.type_invalid'], $check(new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'TEXT', null)])));
        self::assertSame(['problem.import.type_invalid'], $check(new Mapping(Mapping::EXISTING, null, $this->items->key, [new ColumnMapping(0, true, null, null, self::SQL)])));
        self::assertSame(['problem.import.type_invalid'], $check(new Mapping(Mapping::EXISTING, null, $this->items->key, [new ColumnMapping(0, true, null, null, $this->f['auto']->key)])));
    }

    public function testAJobFileChangedBetweenRequestsCannotWriteOutsideTheMapping(): void
    {
        $importer = new FakeImporter(['Name', 'Weight'], [['Lamp', '2'], ['Rug', '3']]);
        $mapping = new Mapping(Mapping::EXISTING, null, $this->items->key, [
            new ColumnMapping(0, true, null, null, $this->f['name']->key),
            new ColumnMapping(1, true, null, null, $this->f['weight']->key),
        ]);
        $job = $this->readyJob($importer, static fn (): Mapping => $mapping);
        // Someone writes extra keys into a prepared record: the auto-number field, an unknown column, and a system column.
        $job->db()->write(fn (Database $db) => $db->run(
            'UPDATE prepared SET record = json_set(record, ?, 999, \'$."_trashed_at"\', \'2026-01-01T00:00:00Z\', \'$."f_zzzz"\', 1) WHERE n = 1',
            ['$."' . $this->f['auto']->key . '"'],
        ));
        $report = $this->commit($job);
        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $lamp = $records->get($this->items->key, 1);
        self::assertNotNull($lamp);
        self::assertSame(1, $lamp->values[$this->f['auto']->key], 'The auto-number is the next number, not the injected one.');
        self::assertNull($lamp->trashedAt);
        self::assertSame(2, $report->rowsImported);
    }

    public function testAMappingChangedInTheJobFileIsNotCommitted(): void
    {
        $importer = new FakeImporter(['Name'], [['Lamp']]);
        $job = $this->readyJob($importer, fn (): Mapping => new Mapping(Mapping::EXISTING, null, $this->items->key, [new ColumnMapping(0, true, null, null, $this->f['name']->key)]));
        $accepted = $job->mapping();
        self::assertNotNull($accepted);
        // The stored mapping now names the auto-number field.
        $forged = new Mapping(Mapping::EXISTING, null, $this->items->key, [new ColumnMapping(0, true, null, null, $this->f['auto']->key)]);
        $job->setMetaJson('mapping', $forged->toArray());
        $before = $this->dump();
        try {
            (new Committer())->commit($job, $accepted, $this->db, $this->types, $this->clock, $this->hookedEditor(), $this->snapshots, $this->folder, self::USER_KEY);
            self::fail('A mapping other than the accepted one was committed.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->commit($job);
            self::fail('A mapping that MappingCheck refuses was committed.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
        self::assertEquals($before, $this->dump());
        self::assertNotSame(JobState::Done, $job->state());
    }

    public function testAForgedReadyStateWithoutAMappingDoesNothing(): void
    {
        $job = $this->newJob();
        $job->db()->write(static fn (Database $db) => $db->run('UPDATE meta SET value = \'ready\' WHERE key = \'state\''));
        $before = $this->dump();
        try {
            (new Committer())->commit($job, new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'text', null)]), $this->db, $this->types, $this->clock, $this->hookedEditor(), $this->snapshots, $this->folder, self::USER_KEY);
            self::fail('A job without a mapping was committed.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        self::assertEquals($before, $this->dump());
        self::assertSame([], $this->snapshotFiles());
    }

    public function testAnImportCannotSetAutoNumbersOrTrashThroughRecords(): void
    {
        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $before = $this->dump();
        foreach ([[$this->f['auto']->key => '5'], ['_trashed_at' => 'x'], ['id' => '1'], [self::SQL => 'x']] as $values) {
            try {
                $records->create($this->items->key, $values, InputMode::Import, self::USER_KEY, []);
                self::fail('A value outside the fields was written.');
            } catch (\TiffinDB\Core\Error\ValidationFailed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertEquals($before, $this->dump());
    }
}
