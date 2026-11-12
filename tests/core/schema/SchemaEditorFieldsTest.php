<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\ColumnDefinition;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\SnapshotReason;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-7, T-SCH-8, T-SCH-11 (fields), T-SCH-12: adding, moving, and deleting fields. */
#[CoversClass(SchemaEditor::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(FieldDef::class)]
#[CoversClass(RelationDef::class)]
final class SchemaEditorFieldsTest extends TestCase
{
    use ProjectFixture;

    private TableDef $items;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-schema-fields-test-1');
        $this->items = $this->editor()->createTable('Items', 'item', true);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function columnTypes(): array
    {
        return [
            'text' => ['text', 'TEXT', []],
            'long_text' => ['long_text', 'TEXT', []],
            'number' => ['number', 'NUMERIC', []],
            'currency' => ['currency', 'INTEGER', []],
            'date' => ['date', 'TEXT', []],
            'time' => ['time', 'TEXT', []],
            'date_time' => ['date_time', 'TEXT', []],
            'duration' => ['duration', 'INTEGER', []],
            'checkbox' => ['checkbox', 'INTEGER', []],
            'choice' => ['choice', 'TEXT', ['choices' => ['Red', 'Green']]],
            'rating' => ['rating', 'INTEGER', []],
            'url' => ['url', 'TEXT', []],
            'email' => ['email', 'TEXT', []],
            'phone' => ['phone', 'TEXT', []],
            'auto_number' => ['auto_number', 'INTEGER', []],
        ];
    }

    // ------------------------------------------------------------ T-SCH-7

    /** @param array<string, mixed> $options */
    #[DataProvider('columnTypes')]
    public function testDeclaredColumnTypeMatchesTheFormat(string $type, string $declared, array $options): void
    {
        $field = $this->editor()->addField($this->items->key, 'Field', $type, $options, true, 'A hint');

        $columns = array_column($this->db->all('SELECT "name", "type", "notnull", "dflt_value" FROM "pragma_table_xinfo"(?)', [Identifier::tableName($this->items->key)]), null, 'name');
        $column = $columns[Identifier::columnName($field->key)];
        self::assertSame($declared, $column['type']);
        self::assertSame($type === 'checkbox' ? 1 : 0, $column['notnull']);
        self::assertSame($type === 'checkbox' ? '0' : null, $column['dflt_value']);
        self::assertTrue($field->hasColumn);
        self::assertSame(Identifier::columnName($field->key), $this->fieldRow($field->key)['sql_name']);
        self::assertSame(2, $field->position);
        self::assertSame(!in_array($type, ['checkbox', 'auto_number'], true), $field->required);
        self::assertEquals($field, $this->schema()->field($field->key));
    }

    public function testAutoNumberNumbersExistingRecordsAndIsUnique(): void
    {
        $ids = [$this->insert($this->items->key), $this->insert($this->items->key, [], true), $this->insert($this->items->key)];
        $field = $this->editor()->addField($this->items->key, 'Number', 'auto_number', [], false, '');

        self::assertSame([1, 2, 3], $this->column($this->items->key, $field->key));
        self::assertSame(['start' => 1, 'next' => 4], $field->options);
        self::assertSame('{"start":1,"next":4}', $this->fieldRow($field->key)['options']);
        $index = $this->masterNames()['x_f_' . $field->key . '_u'] ?? null;
        self::assertNotNull($index);
        self::assertStringContainsString('UNIQUE', $index);
        $this->expectException(\PDOException::class);
        $this->db->run('UPDATE ' . Identifier::table($this->items->key) . ' SET ' . Identifier::column($field->key) . ' = 1 WHERE "id" = ?', [$ids[2]]);
    }

    public function testAutoNumberStartsAtTheGivenStart(): void
    {
        $this->insert($this->items->key);
        $this->insert($this->items->key);
        $field = $this->editor()->addField($this->items->key, 'Number', 'auto_number', ['start' => 100, 'next' => 5], false, '');
        self::assertSame([100, 101], $this->column($this->items->key, $field->key));
        self::assertSame(['start' => 100, 'next' => 102], $this->schema()->field($field->key)->options);
    }

    public function testAttachmentFieldHasNoColumn(): void
    {
        $field = $this->editor()->addField($this->items->key, 'Files', 'attachment', ['images_only' => true], false, '');
        self::assertFalse($field->hasColumn);
        self::assertNull($this->fieldRow($field->key)['sql_name']);
        self::assertCount(8, $this->db->all('SELECT "name" FROM "pragma_table_info"(?)', [Identifier::tableName($this->items->key)]));
        $this->expectException(\LogicException::class);
        $field->sql();
    }

    public function testAddFieldValidation(): void
    {
        $editor = $this->editor();
        self::assertSame(
            [['name', 'problem.field_name.taken', []], ['help', 'problem.name.too_long', ['max' => 200]], ['type', 'problem.type.unknown', []]],
            self::problems(fn () => $editor->addField($this->items->key, 'NAME', 'colour', [], false, str_repeat('h', 201))),
        );
        self::assertSame([['type', 'problem.type.unknown', []]], self::problems(fn () => $editor->addField($this->items->key, 'Link', 'relation', [], false, '')));
        self::assertSame(
            [['name', 'problem.name.empty', []], ['help', 'problem.text.control', []], ['options.max_length', 'problem.options.range', ['min' => 1, 'max' => 10000]]],
            self::problems(fn () => $editor->addField($this->items->key, ' ', 'text', ['max_length' => 0], false, "line\nbreak")),
        );
        self::assertSame([['options.choices', 'problem.options.choices_empty', []]], self::problems(fn () => $editor->addField($this->items->key, 'Colour', 'choice', [], false, '')));
        self::assertCount(1, $this->schema()->fields($this->items->key));

        $this->expectException(NotFound::class);
        $editor->addField('aaaaaaaaaaaa', 'X', 'text', [], false, '');
    }

    public function testFieldLimitPerTable(): void
    {
        $editor = $this->editor();
        for ($i = 2; $i <= SchemaEditor::MAX_FIELDS; $i++) {
            $editor->addField($this->items->key, 'Field ' . $i, 'checkbox', [], false, '');
        }
        self::assertSame([[null, 'problem.table.too_many_fields', ['max' => 500]]], self::problems(fn () => $editor->addField($this->items->key, 'One more', 'text', [], false, '')));
    }

    // ------------------------------------------------------------ T-SCH-8

    /** @return iterable<string, array{Cardinality, Cardinality, list<string>}> */
    public static function cardinalities(): iterable
    {
        yield 'one to many' => [Cardinality::One, Cardinality::Many, ['r', 'l1']];
        yield 'many to one' => [Cardinality::Many, Cardinality::One, ['r', 'r1']];
        yield 'one to one' => [Cardinality::One, Cardinality::One, ['r', 'l1', 'r1']];
        yield 'many to many' => [Cardinality::Many, Cardinality::Many, ['r']];
    }

    /** @param list<string> $indexes */
    #[DataProvider('cardinalities')]
    public function testAddRelationCreatesTheJoinTableAndIndexes(Cardinality $thisMax, Cardinality $otherMax, array $indexes): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        $field = $editor->addRelation($this->items->key, 'Room', $rooms->key, $thisMax, $otherMax, 'Items', true, 'Where it is');

        $schema = $this->schema();
        $relation = $schema->relationOf($schema->field($field->key));
        self::assertInstanceOf(RelationDef::class, $relation);
        $r = $relation->key;
        $join = 'j_' . $r;
        $names = $this->masterNames();
        self::assertSame(
            'CREATE TABLE "' . $join . '" ( "left_id" INTEGER NOT NULL REFERENCES "t_' . $this->items->key . '" ("id") ON DELETE CASCADE,'
            . ' "right_id" INTEGER NOT NULL REFERENCES "t_' . $rooms->key . '" ("id") ON DELETE CASCADE,'
            . ' "position" INTEGER NOT NULL DEFAULT 0, PRIMARY KEY ("left_id", "right_id") ) WITHOUT ROWID',
            trim((string) preg_replace('/\s+/', ' ', $names[$join])),
        );
        $expected = [];
        foreach ($indexes as $purpose) {
            $expected[] = 'x_' . $join . '_' . $purpose;
        }
        $actual = array_values(array_filter(array_keys($names), static fn (string $n): bool => str_starts_with($n, 'x_' . $join)));
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame('CREATE INDEX "x_' . $join . '_r" ON "' . $join . '" ("right_id")', $names['x_' . $join . '_r']);
        if (in_array('l1', $indexes, true)) {
            self::assertSame('CREATE UNIQUE INDEX "x_' . $join . '_l1" ON "' . $join . '" ("left_id")', $names['x_' . $join . '_l1']);
        }
        if (in_array('r1', $indexes, true)) {
            self::assertSame('CREATE UNIQUE INDEX "x_' . $join . '_r1" ON "' . $join . '" ("right_id")', $names['x_' . $join . '_r1']);
        }

        self::assertSame($thisMax, $relation->leftMax);
        self::assertSame($otherMax, $relation->rightMax);
        self::assertSame($this->items->key, $relation->leftTable);
        self::assertSame($rooms->key, $relation->rightTable);
        self::assertSame($field->key, $relation->leftField);
        $reverse = $schema->field($relation->rightField);
        self::assertSame('Items', $reverse->name);
        self::assertSame($rooms->key, $reverse->tableKey);
        self::assertSame(2, $reverse->position);
        self::assertFalse($reverse->required);
        self::assertSame('', $reverse->help);
        self::assertSame(['relation' => $r, 'side' => 'right'], $reverse->options);
        self::assertSame(['relation' => $r, 'side' => 'left'], $field->options);
        self::assertTrue($field->required);
        self::assertSame('Where it is', $field->help);
        self::assertNull($this->fieldRow($field->key)['sql_name']);
        self::assertSame('{"relation":"' . $r . '","side":"left"}', $this->fieldRow($field->key)['options']);
        self::assertSame($join, $this->db->value('SELECT "join_table" FROM "_tiffin_relations" WHERE "key" = ?', [$r]));
        self::assertSame('left', $relation->sideOf($field->key));
        self::assertSame($relation->rightField, $relation->otherField($field->key));
        self::assertSame($rooms->key, $relation->otherTable($field->key));
        self::assertSame($thisMax, $relation->maxFor($field->key));
        self::assertSame($otherMax, $relation->maxFor($reverse->key));
        self::assertCount(1, $schema->relationsTouching($rooms->key));
    }

    public function testDeletingARecordRemovesItsJoinRows(): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        $field = $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $relation = $this->schema()->relationOf($field);
        $item = $this->insert($this->items->key);
        $other = $this->insert($this->items->key);
        $room = $this->insert($rooms->key);
        $this->link($relation->key, $item, $room);
        $this->link($relation->key, $other, $room);

        $this->db->run('DELETE FROM ' . Identifier::table($this->items->key) . ' WHERE "id" = ?', [$item]);
        self::assertSame([[ 'left_id' => $other, 'right_id' => $room]], $this->db->all('SELECT "left_id", "right_id" FROM ' . Identifier::joinTable($relation->key)));
        $this->db->run('DELETE FROM ' . Identifier::table($rooms->key) . ' WHERE "id" = ?', [$room]);
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM ' . Identifier::joinTable($relation->key)));
    }

    public function testOneSideIsEnforcedByTheUniqueIndex(): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        $field = $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $relation = $this->schema()->relationOf($field);
        $item = $this->insert($this->items->key);
        $this->link($relation->key, $item, $this->insert($rooms->key));
        $this->expectException(\PDOException::class);
        $this->link($relation->key, $item, $this->insert($rooms->key));
    }

    public function testSelfRelation(): void
    {
        $editor = $this->editor();
        $field = $editor->addRelation($this->items->key, 'Parts', $this->items->key, Cardinality::Many, Cardinality::One, 'Part of', false, '');
        $schema = $this->schema();
        $relation = $schema->relationOf($field);
        self::assertSame($this->items->key, $relation->leftTable);
        self::assertSame($this->items->key, $relation->rightTable);
        self::assertSame(['Name', 'Parts', 'Part of'], array_map(static fn (FieldDef $f): string => $f->name, $schema->fields($this->items->key)));
        self::assertSame([1, 2, 3], array_map(static fn (FieldDef $f): int => $f->position, $schema->fields($this->items->key)));
        self::assertSame('right', $relation->sideOf($relation->rightField));
        self::assertSame($this->items->key, $relation->otherTable($field->key));

        self::assertSame(
            [['reverse_name', 'problem.relation.reverse_name_taken', ['table' => 'Items']]],
            self::problems(fn () => $editor->addRelation($this->items->key, 'Twin', $this->items->key, Cardinality::Many, Cardinality::Many, 'twin', false, '')),
        );
    }

    public function testRelationValidation(): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        self::assertSame(
            [['reverse_name', 'problem.relation.reverse_name_taken', ['table' => 'Rooms']]],
            self::problems(fn () => $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::Many, Cardinality::Many, 'name', false, '')),
        );
        self::assertSame(
            [['name', 'problem.field_name.taken', []], ['target', 'problem.relation.target_missing', []], ['reverse_name', 'problem.name.empty', []]],
            self::problems(fn () => $editor->addRelation($this->items->key, 'Name', 'aaaaaaaaaaaa', Cardinality::Many, Cardinality::Many, '', false, '')),
        );
        self::assertSame(
            [['target', 'problem.relation.target_missing', []]],
            self::problems(fn () => $editor->addRelation($this->items->key, 'Room', 'NOT A KEY', Cardinality::Many, Cardinality::Many, 'Items', false, '')),
        );
        self::assertSame(
            [['help', 'problem.text.control', []], ['reverse_name', 'problem.name.too_long', ['max' => 60]]],
            self::problems(fn () => $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::Many, Cardinality::Many, str_repeat('r', 61), false, "\x07")),
        );
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_relations"'));
    }

    // ------------------------------------------------------------ T-SCH-11 (fields)

    public function testDeleteColumnField(): void
    {
        $editor = $this->editor();
        $number = $editor->addField($this->items->key, 'Number', 'auto_number', [], false, '');
        $notes = $editor->addField($this->items->key, 'Notes', 'text', [], false, '');
        $name = $this->schema()->fields($this->items->key)[0];
        $this->insert($this->items->key, [$notes->key => 'Example']);

        $editor->deleteField($number->key);
        self::assertCount(1, $this->taken);
        self::assertSame(SnapshotReason::FieldDelete, $this->taken[0]->reason);
        $names = $this->masterNames();
        self::assertArrayNotHasKey('x_f_' . $number->key . '_u', $names);
        self::assertStringNotContainsString('f_' . $number->key, $names['t_' . $this->items->key]);
        self::assertSame(['Name', 'Notes'], array_map(static fn (FieldDef $f): string => $f->name, $this->schema()->fields($this->items->key)));
        self::assertSame([1, 2], array_map(static fn (FieldDef $f): int => $f->position, $this->schema()->fields($this->items->key)));
        self::assertSame(['Example'], $this->column($this->items->key, $notes->key));

        $editor->deleteField($name->key);
        self::assertNull($this->schema()->table($this->items->key)->titleFieldKey);
        self::assertCount(2, $this->snapshotFiles());
    }

    public function testDeleteBothSidesOfARelation(): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        $editor->addField($rooms->key, 'Size', 'number', [], false, '');
        $field = $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $editor->addField($rooms->key, 'Floor', 'number', [], false, '');
        $relation = $this->schema()->relationOf($field);
        $this->link($relation->key, $this->insert($this->items->key), $this->insert($rooms->key));

        // Deleting from the right side deletes both fields.
        $editor->deleteField($relation->rightField);
        $schema = $this->schema();
        self::assertSame(['Name'], array_map(static fn (FieldDef $f): string => $f->name, $schema->fields($this->items->key)));
        self::assertSame(['Name', 'Size', 'Floor'], array_map(static fn (FieldDef $f): string => $f->name, $schema->fields($rooms->key)));
        self::assertSame([1, 2, 3], array_map(static fn (FieldDef $f): int => $f->position, $schema->fields($rooms->key)));
        self::assertSame([], $schema->relationsTouching($rooms->key));
        $names = $this->masterNames();
        self::assertArrayNotHasKey('j_' . $relation->key, $names);
        self::assertArrayNotHasKey('x_j_' . $relation->key . '_r', $names);
        self::assertArrayNotHasKey('x_j_' . $relation->key . '_l1', $names);
        self::assertSame(SnapshotReason::FieldDelete, $this->taken[0]->reason);
        self::assertSame([], $this->db->all('PRAGMA foreign_key_check'));
    }

    public function testDeleteAttachmentFieldRemovesItsLinks(): void
    {
        $editor = $this->editor();
        $files = $editor->addField($this->items->key, 'Files', 'attachment', [], false, '');
        $photos = $editor->addField($this->items->key, 'Photos', 'attachment', [], false, '');
        $id = $this->insert($this->items->key);
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [str_repeat('b', 64), 'text/plain', 'example.txt', self::START]);
        foreach ([$files, $photos] as $field) {
            $this->db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, 1, 1)', [$field->key, $id]);
        }
        self::assertSame(1, $editor->valueCount($files->key));

        $editor->deleteField($files->key);
        self::assertSame([$photos->key], array_column($this->db->all('SELECT "field_key" FROM "_tiffin_attachment_links"'), 'field_key'));
        self::assertSame(1, $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
        self::assertSame(['Name', 'Photos'], array_map(static fn (FieldDef $f): string => $f->name, $this->schema()->fields($this->items->key)));
    }

    public function testDeleteUnknownFieldTakesNoSnapshot(): void
    {
        try {
            $this->editor()->deleteField('aaaaaaaaaaaa');
            self::fail('Deleted an unknown field.');
        } catch (NotFound) {
            self::assertSame([], $this->snapshotFiles());
        }
    }

    // ------------------------------------------------------------ T-SCH-12

    public function testMoveField(): void
    {
        $editor = $this->editor();
        $b = $editor->addField($this->items->key, 'B', 'text', [], false, '');
        $c = $editor->addField($this->items->key, 'C', 'text', [], false, '');
        $order = fn (): array => array_map(static fn (FieldDef $f): string => $f->name . $f->position, $this->schema()->fields($this->items->key));

        $editor->moveField($c->key, -1);
        self::assertSame(['Name1', 'C2', 'B3'], $order());
        $editor->moveField($c->key, -1);
        self::assertSame(['C1', 'Name2', 'B3'], $order());
        $editor->moveField($c->key, -1);
        self::assertSame(['C1', 'Name2', 'B3'], $order());
        $editor->moveField($b->key, 1);
        self::assertSame(['C1', 'Name2', 'B3'], $order());
        $editor->moveField($c->key, 1);
        self::assertSame(['Name1', 'C2', 'B3'], $order());

        $this->expectException(\InvalidArgumentException::class);
        $editor->moveField($c->key, 2);
    }

    public function testValueCount(): void
    {
        $editor = $this->editor();
        $done = $editor->addField($this->items->key, 'Done', 'checkbox', [], false, '');
        $notes = $editor->addField($this->items->key, 'Notes', 'text', [], false, '');
        $rooms = $editor->createTable('Rooms', 'room', true);
        $toRoom = $editor->addRelation($this->items->key, 'Room', $rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $relation = $this->schema()->relationOf($toRoom);
        $a = $this->insert($this->items->key, [$done->key => 1, $notes->key => 'x']);
        $b = $this->insert($this->items->key, [$done->key => 0], true);
        $this->insert($this->items->key, [$notes->key => 'y'], true);
        $room1 = $this->insert($rooms->key);
        $room2 = $this->insert($rooms->key);
        $this->link($relation->key, $a, $room1);
        $this->link($relation->key, $a, $room2);
        $this->link($relation->key, $b, $room2);

        self::assertSame(1, $editor->valueCount($done->key));
        self::assertSame(2, $editor->valueCount($notes->key));
        self::assertSame(2, $editor->valueCount($toRoom->key));
        self::assertSame(2, $editor->valueCount($relation->rightField));
    }
}
