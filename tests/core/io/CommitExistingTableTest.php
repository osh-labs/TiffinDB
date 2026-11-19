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
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-9: commit into an existing table. */
#[CoversClass(Committer::class)]
final class CommitExistingTableTest extends TestCase
{
    use ImportFixture;

    private TableDef $items;
    private TableDef $rooms;
    private TableDef $tags;
    /** @var array<string, FieldDef> */
    private array $f = [];

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-commit-existing-test-1');
        $editor = $this->hookedEditor();
        $this->rooms = $editor->createTable('Rooms', 'room', false);
        $roomName = $editor->addField($this->rooms->key, 'Name', 'text', ['max_length' => 10], false, '');
        $editor->updateTable($this->rooms->key, 'Rooms', 'room', $roomName->key);
        $this->tags = $editor->createTable('Tags', 'tag', false);
        $tagName = $editor->addField($this->tags->key, 'Label', 'choice', ['choices' => ['Red', 'Green', 'Blue']], false, '');
        $editor->updateTable($this->tags->key, 'Tags', 'tag', $tagName->key);
        $this->items = $editor->createTable('Items', 'item', true);
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $this->f['serial'] = $editor->addField($this->items->key, 'Serial', 'text', [], true, '');
        $this->f['weight'] = $editor->addField($this->items->key, 'Weight', 'number', ['decimals' => 1], true, '');
        $this->f['colour'] = $editor->addField($this->items->key, 'Colour', 'choice', ['choices' => ['Red', 'Green']], false, '');
        $this->f['auto'] = $editor->addField($this->items->key, 'Code', 'auto_number', [], false, '');
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::One, 'Item', false, '');
        $this->f['tags'] = $editor->addRelation($this->items->key, 'Tags', $this->tags->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $this->f['many'] = $editor->addRelation($this->items->key, 'Stored in', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Stores', false, '');
        $records = $this->records();
        $kitchen = $records->create($this->rooms->key, [$roomName->key => 'Kitchen'], InputMode::Form, self::USER_KEY);
        $old = $records->create($this->items->key, [$this->f['name']->key => 'Old', $this->f['serial']->key => 'S-0', $this->f['weight']->key => '1'], InputMode::Form, self::USER_KEY);
        $records->setLinks($this->f['room']->key, $old, [$kitchen], self::USER_KEY);
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    private function records(): Records
    {
        return new Records($this->db, $this->schema(), $this->types, $this->clock);
    }

    public function testCommitIntoAnExistingTable(): void
    {
        $titles = [];
        for ($i = 1; $i <= 1005; $i++) {
            $titles[] = 'Shelf ' . $i;
        }
        $importer = new FakeImporter(['Name', 'Weight', 'Colour', 'Room', 'Tags', 'Stored in', 'Ignored'], [
            ['Lamp', '2.5', 'red', 'Kitchen', 'Red; Purple', implode(';', $titles), 'x'],
            ['Kettle', 'heavy', 'Purple', 'Hall', 'green', 'Shelf 1', 'y'],
            ['Rug', '', 'Green', 'Hall', 'Blue', 'Much too long a room name', 'z'],
            ['Fan', '3', '', 'Garden shed', '', '', ''],
        ]);
        $job = $this->readyJob($importer, fn ($job): Mapping => new Mapping(Mapping::EXISTING, null, $this->items->key, [
            new ColumnMapping(0, true, null, null, $this->f['name']->key),
            new ColumnMapping(1, true, null, null, $this->f['weight']->key),
            new ColumnMapping(2, true, null, null, $this->f['colour']->key),
            new ColumnMapping(3, true, null, null, $this->f['room']->key),
            new ColumnMapping(4, true, null, null, $this->f['tags']->key),
            new ColumnMapping(5, true, null, null, $this->f['many']->key),
            new ColumnMapping(6, false, null, null, null),
        ]));
        $report = $this->commit($job);
        $records = $this->records();
        $ids = [2, 3, 4, 5];
        $got = [];
        foreach ($ids as $id) {
            $record = $records->get($this->items->key, $id);
            self::assertNotNull($record);
            $got[] = [
                $record->values[$this->f['name']->key],
                $record->values[$this->f['serial']->key],
                $record->values[$this->f['weight']->key],
                $record->values[$this->f['colour']->key],
                $record->values[$this->f['auto']->key],
                array_map(static fn ($l): ?string => $l->title, $record->links[$this->f['room']->key]),
                array_map(static fn ($l): ?string => $l->title, $record->links[$this->f['tags']->key]),
                count($record->links[$this->f['many']->key]),
            ];
        }
        self::assertSame([
            ['Lamp', null, 2.5, 'Red', 2, [], ['Red'], 1000],
            ['Kettle', null, null, null, 3, ['Hall'], ['Green'], 1],
            ['Rug', null, null, 'Green', 4, [], ['Blue'], 0],
            ['Fan', null, 3.0, null, 5, [], [], 0],
        ], $got, 'Unmapped fields empty; required fields not enforced; Kitchen is taken (one-to-one); Hall is taken by row 2.');

        // Garden shed is longer than the room title allows (10): refused, no record. Purple is not a tag label.
        $roomTitles = array_map(static fn ($l): ?string => $l->title, $records->pickList($this->rooms->key, 3));
        self::assertSame(['Hall', 'Kitchen', 'Shelf 1'], $roomTitles);
        self::assertSame(2 + 1005, $records->count($this->rooms->key), 'Kitchen, Hall, and Shelf 1 to 1005; the refused titles made no record.');
        $tagTitles = array_map(static fn ($l): ?string => $l->title, $records->pickList($this->tags->key, 500));
        sort($tagTitles);
        self::assertSame(['Blue', 'Green', 'Red'], $tagTitles);
        self::assertSame(3, $records->count($this->tags->key));

        self::assertSame(4, $report->rowsImported);
        self::assertFalse($report->newTable);
        self::assertSame($this->items->key, $report->tableKey);
        self::assertSame(3 + 1 + 1005, $report->linksAdded);
        $columns = [];
        foreach ($report->report['columns'] as $column) {
            $columns[$column['name']] = [$column['emptied'], $column['rows']];
        }
        self::assertSame([
            'Weight' => [1, [2]],
            'Colour' => [1, [2]],
            'Room' => [3, [1, 3, 4]],
            'Tags' => [1, [1]],
            'Stored in' => [2, [1, 3]],
        ], $columns);
        self::assertSame(8, $report->valuesEmptied);
        self::assertSame([
            ['field' => $this->f['serial']->key, 'count' => 4],
            ['field' => $this->f['weight']->key, 'count' => 2],
        ], $report->report['required_empty']);
        $links = array_column($report->report['links'], 'added', 'field');
        self::assertSame([$this->f['room']->key => 1, $this->f['tags']->key => 3, $this->f['many']->key => 1005], $links);
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
    }

    public function testDuplicateTitlesInTheTargetUseTheFirstRecord(): void
    {
        $records = $this->records();
        $name = $this->schema()->table($this->rooms->key)->titleFieldKey;
        self::assertNotNull($name);
        $records->create($this->rooms->key, [$name => 'kitchen'], InputMode::Form, self::USER_KEY);
        $importer = new FakeImporter(['Name', 'Stored in'], [['Lamp', 'KITCHEN']]);
        $job = $this->readyJob($importer, fn ($job): Mapping => new Mapping(Mapping::EXISTING, null, $this->items->key, [
            new ColumnMapping(0, true, null, null, $this->f['name']->key),
            new ColumnMapping(1, true, null, null, $this->f['many']->key),
        ]));
        $report = $this->commit($job);
        self::assertSame(1, $records->get($this->items->key, 2)?->links[$this->f['many']->key][0]->id);
        self::assertSame([['field' => $this->f['many']->key, 'table' => $this->rooms->key, 'added' => 0, 'duplicate_titles' => 1]], $report->report['links']);
    }
}
