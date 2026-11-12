<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\SnapshotReason;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-5, T-SCH-6, and table deletion (T-SCH-11): tables through SchemaEditor. */
#[CoversClass(SchemaEditor::class)]
#[CoversClass(TableDef::class)]
final class SchemaEditorTablesTest extends TestCase
{
    use ProjectFixture;

    /** Every name in sqlite_master of a project file (T-SCH-5). */
    public const MASTER_NAME = '/^(_tiffin_\w+|sqlite_\w+|[tfj]_[a-z2-7]{12}|x_[tfj]_[a-z2-7]{12}_(trash|u|r|l1|r1))$/';

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-schema-tables-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    // ------------------------------------------------------------ T-SCH-5

    public function testNamesFromPeopleNeverBecomeIdentifiers(): void
    {
        $names = ['"; DROP TABLE x; --', 'Name"', 'Ünïcödé', str_repeat('x', 60), "Robert'); DROP TABLE _tiffin_meta;--", '[t_aaaaaaaaaaaa]', '`f`'];
        $editor = $this->editor();
        $tables = [];
        foreach ($names as $name) {
            $tables[$name] = $editor->createTable($name, $name === str_repeat('x', 60) ? str_repeat('y', 40) : 'item', true);
        }
        $first = reset($tables);
        $fields = [];
        foreach ($names as $name) {
            $fields[$name] = $editor->addField($first->key, $name === 'Name"' ? 'Name"2' : $name, 'text', ['max_length' => 100], false, 'Help "; DROP TABLE x; --');
        }
        $editor->addRelation($first->key, '"; DELETE FROM _tiffin_fields; --', $tables['Ünïcödé']->key, Cardinality::One, Cardinality::Many, 'Ünïcödé "reverse"', false, '');
        $editor->createTable('"; DROP TABLE x; -- choice', 'item', false);

        foreach (array_keys($this->masterNames()) as $object) {
            self::assertMatchesRegularExpression(self::MASTER_NAME, $object);
        }
        foreach ($this->db->all('SELECT "name" FROM "pragma_table_info"(?)', [Identifier::tableName($first->key)]) as $column) {
            self::assertMatchesRegularExpression('/^(id|_created_at|_created_by|_updated_at|_updated_by|_trashed_at|_trashed_by|f_[a-z2-7]{12})$/', $column['name']);
        }

        $schema = $this->schema();
        foreach ($tables as $name => $table) {
            self::assertSame($name, $schema->table($table->key)->name);
        }
        foreach ($fields as $field) {
            self::assertSame($field->name, $schema->field($field->key)->name);
            self::assertSame('Help "; DROP TABLE x; --', $schema->field($field->key)->help);
        }
        self::assertSame(str_repeat('x', 60), $schema->table($tables[str_repeat('x', 60)]->key)->name);
        self::assertNotNull($this->db->value('SELECT 1 FROM "_tiffin_meta" WHERE "key" = \'format\''));
    }

    // ------------------------------------------------------------ T-SCH-6

    public function testCreateTableWithTheNameField(): void
    {
        $table = $this->editor()->createTable('Items', 'item', true);

        self::assertMatchesRegularExpression('/^[a-z2-7]{12}\z/', $table->key);
        self::assertSame(1, $table->position);
        self::assertNotNull($table->titleFieldKey);
        $schema = $this->schema();
        $fields = $schema->fields($table->key);
        self::assertCount(1, $fields);
        self::assertSame('Name', $fields[0]->name);
        self::assertSame('text', $fields[0]->type);
        self::assertSame(['max_length' => 500], $fields[0]->options);
        self::assertFalse($fields[0]->required);
        self::assertSame(1, $fields[0]->position);
        self::assertSame($fields[0]->key, $schema->table($table->key)->titleFieldKey);
        self::assertEquals($table, $schema->table($table->key));

        $row = $this->db->one('SELECT * FROM "_tiffin_tables" WHERE "key" = ?', [$table->key]);
        self::assertSame(Identifier::tableName($table->key), $row['sql_name']);
        self::assertSame('item', $row['item_name']);
        self::assertSame(self::START, $row['created_at']);
        self::assertSame(self::START, $row['updated_at']);

        $columns = array_column($this->db->all('SELECT "name", "type", "notnull", "pk" FROM "pragma_table_info"(?)', [Identifier::tableName($table->key)]), null, 'name');
        self::assertSame(['id', '_created_at', '_created_by', '_updated_at', '_updated_by', '_trashed_at', '_trashed_by', Identifier::columnName($fields[0]->key)], array_keys($columns));
        self::assertSame(1, $columns['id']['pk']);
        self::assertSame(1, $columns['_created_at']['notnull']);
        self::assertSame(0, $columns['_trashed_at']['notnull']);
        self::assertArrayHasKey('x_t_' . $table->key . '_trash', $this->masterNames());
        self::assertStringContainsString('AUTOINCREMENT', $this->masterNames()['t_' . $table->key]);
    }

    public function testCreateTableWithoutTheNameFieldAndPositions(): void
    {
        $editor = $this->editor();
        $first = $editor->createTable('Items', 'item', false);
        $second = $editor->createTable('  Rooms  ', '  room ', false);
        self::assertSame([], $this->schema()->fields($first->key));
        self::assertNull($first->titleFieldKey);
        self::assertSame(1, $first->position);
        self::assertSame(2, $second->position);
        self::assertSame('Rooms', $second->name);
        self::assertSame('room', $second->itemName);
        self::assertSame([$first->key, $second->key], array_map(static fn (TableDef $t): string => $t->key, $this->schema()->tables()));
    }

    public function testEveryValidationProblemIsReportedTogether(): void
    {
        $editor = $this->editor();
        $editor->createTable('Items', 'item', true);

        self::assertSame([['name', 'problem.name.empty', []], ['item_name', 'problem.name.empty', []]], self::problems(fn () => $editor->createTable('  ', '', true)));
        self::assertSame(
            [['name', 'problem.name.too_long', ['max' => 60]], ['item_name', 'problem.name.too_long', ['max' => 40]]],
            self::problems(fn () => $editor->createTable(str_repeat('x', 61), str_repeat('y', 41), true)),
        );
        self::assertSame(
            [['name', 'problem.text.control', []], ['item_name', 'problem.text.control', []]],
            self::problems(fn () => $editor->createTable("Ta\tble", "it\x01em", true)),
        );
        self::assertSame([['name', 'problem.table_name.taken', []]], self::problems(fn () => $editor->createTable('ITEMS', 'item', true)));
        self::assertSame([['name', 'problem.text.control', []]], self::problems(fn () => $editor->createTable("Bad\xff", 'item', true)));
        self::assertCount(1, $this->schema()->tables());
    }

    public function testUpdateTableNamesAndTitleField(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $count = $editor->addField($items->key, 'Count', 'number', [], false, '');
        $done = $editor->addField($items->key, 'Done', 'checkbox', [], false, '');
        $roomName = $this->schema()->fields($rooms->key)[0];

        $this->clock->advance(5);
        $updated = $editor->updateTable($items->key, 'Things', 'thing', $count->key);
        self::assertEquals(new TableDef($items->key, 'Things', 'thing', 1, $count->key), $updated);
        self::assertEquals($updated, $this->schema()->table($items->key));
        self::assertSame('2026-11-12T03:15:05Z', $this->db->value('SELECT "updated_at" FROM "_tiffin_tables" WHERE "key" = ?', [$items->key]));

        self::assertNull($editor->updateTable($items->key, 'Things', 'thing', null)->titleFieldKey);
        self::assertSame(
            [['name', 'problem.table_name.taken', []], ['title_field', 'problem.title_field.invalid', []]],
            self::problems(fn () => $editor->updateTable($items->key, 'rooms', 'thing', $done->key)),
        );
        self::assertSame([['title_field', 'problem.title_field.invalid', []]], self::problems(fn () => $editor->updateTable($items->key, 'Things', 'thing', $roomName->key)));
        self::assertSame([['title_field', 'problem.title_field.invalid', []]], self::problems(fn () => $editor->updateTable($items->key, 'Things', 'thing', 'NOT A KEY')));
        // Renaming to its own name in another case is allowed.
        self::assertSame('THINGS', $editor->updateTable($items->key, 'THINGS', 'thing', null)->name);

        $this->expectException(NotFound::class);
        $editor->updateTable('aaaaaaaaaaaa', 'X', 'x', null);
    }

    // ------------------------------------------------------------ T-SCH-11 (tables)

    public function testDeleteTableRemovesItsRelationsAndRenumbers(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $people = $editor->createTable('People', 'person', true);
        $files = $editor->addField($rooms->key, 'Photos', 'attachment', [], false, '');
        $toRoom = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $self = $editor->addRelation($rooms->key, 'Next to', $rooms->key, Cardinality::Many, Cardinality::Many, 'Next to (reverse)', false, '');
        $kept = $editor->addRelation($items->key, 'Owner', $people->key, Cardinality::Many, Cardinality::Many, 'Owns', false, '');
        $afterRoom = $editor->addField($items->key, 'Notes', 'long_text', [], false, '');
        $roomId = $this->insert($rooms->key);
        $this->insert($rooms->key, [], true);
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [str_repeat('a', 64), 'image/png', 'example.png', self::START]);
        $this->db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, 1, 1)', [$files->key, $roomId]);
        $relation = $this->schema()->relationOf($toRoom);
        $selfRelation = $this->schema()->relationOf($self);

        self::assertSame(2, $editor->deleteTable($rooms->key));

        self::assertCount(1, $this->taken);
        self::assertSame(SnapshotReason::TableDelete, $this->taken[0]->reason);
        self::assertSame([$this->taken[0]->file], $this->snapshotFiles());
        $schema = $this->schema();
        self::assertSame([$items->key, $people->key], array_map(static fn (TableDef $t): string => $t->key, $schema->tables()));
        self::assertSame([1, 2], array_map(static fn (TableDef $t): int => $t->position, $schema->tables()));
        $itemFields = $schema->fields($items->key);
        self::assertSame(['Name', 'Owner', 'Notes'], array_map(static fn ($f): string => $f->name, $itemFields));
        self::assertSame([1, 2, 3], array_map(static fn ($f): int => $f->position, $itemFields));
        self::assertSame($afterRoom->key, $itemFields[2]->key);
        self::assertSame($kept->key, $itemFields[1]->key);
        $names = $this->masterNames();
        foreach (['t_' . $rooms->key, 'j_' . $relation->key, 'j_' . $selfRelation->key, 'x_j_' . $relation->key . '_r'] as $gone) {
            self::assertArrayNotHasKey($gone, $names);
        }
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_fields" WHERE "table_key" = ?', [$rooms->key]));
        self::assertSame(1, $this->db->value('SELECT COUNT(*) FROM "_tiffin_relations"'));
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachment_links"'));
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
    }

    public function testDeleteTableOfAnUnknownTableTakesNoSnapshot(): void
    {
        try {
            $this->editor()->deleteTable('aaaaaaaaaaaa');
            self::fail('Deleted an unknown table.');
        } catch (NotFound) {
            self::assertSame([], $this->snapshotFiles());
        }
    }

    public function testRecordCount(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', false);
        $this->insert($items->key);
        $this->insert($items->key, [], true);
        self::assertSame(2, $editor->recordCount($items->key));
        self::assertSame(1, $editor->recordCount($items->key, false));
    }
}
