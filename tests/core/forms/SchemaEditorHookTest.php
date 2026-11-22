<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Tests\Core\Schema\ProjectFixture;

require_once __DIR__ . '/../schema/ProjectFixture.php';

/**
 * T-FORM-8 and T-EDT-11: every structural SchemaEditor method calls the
 * after-change hook once, inside its transaction, after its own changes
 * and before the project meta is touched, with exactly the table keys of
 * M2 spec section 5.6 and the added field keys of M5 spec section 7.2
 * (addField: the new field; addRelation: both sides; otherwise none); a
 * closure taking two arguments still works; an exception from the hook
 * rolls the change back.
 */
#[CoversClass(SchemaEditor::class)]
final class SchemaEditorHookTest extends TestCase
{
    use ProjectFixture;

    /** @var list<array{keys: list<string>, added: list<string>, inTransaction: bool, touched: bool}> */
    private array $calls = [];
    private bool $fail = false;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-schema-editor-hook-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function hooked(): SchemaEditor
    {
        return new SchemaEditor(
            $this->db,
            $this->folder,
            $this->types,
            $this->keys,
            $this->clock,
            $this->snapshots,
            self::USER_KEY,
            function (SnapshotInfo $info): void {
                $this->taken[] = $info;
            },
            function (Database $db, array $tableKeys, array $addedFieldKeys): void {
                $this->calls[] = [
                    'keys' => $tableKeys,
                    'added' => $addedFieldKeys,
                    'inTransaction' => $db->inTransaction(),
                    // The meta is touched after the hook: updated_at still holds the earlier time.
                    'touched' => $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\'') !== self::START,
                ];
                if ($this->fail) {
                    throw new RuntimeException('Synthetic hook failure.');
                }
            },
        );
    }

    /**
     * Runs $change and checks that the hook ran exactly once inside the
     * transaction, before the meta touch, with $expected (in order unless
     * $anyOrder) and no added field keys unless $added gives them (a closure
     * returning them, as they are known only after the change).
     *
     * @param list<string> $expected
     * @param (Closure(): list<string>)|null $added
     */
    private function assertHook(array $expected, Closure $change, bool $anyOrder = false, ?Closure $added = null): void
    {
        $this->calls = [];
        $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'updated_at\'', [self::START]));
        $this->clock->advance(60);
        $change();
        self::assertCount(1, $this->calls);
        if ($expected === ['(the new table)']) {
            $tables = $this->schema()->tables();
            $expected = [$tables[count($tables) - 1]->key];
        }
        $actual = $this->calls[0]['keys'];
        if ($anyOrder) {
            sort($expected);
            sort($actual);
        }
        self::assertSame($expected, $actual);
        self::assertSame($added === null ? [] : $added(), $this->calls[0]['added']);
        self::assertTrue($this->calls[0]['inTransaction']);
        self::assertFalse($this->calls[0]['touched']);
        self::assertNotSame(self::START, $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\''));
    }

    public function testEachMethodPassesItsTables(): void
    {
        $editor = $this->hooked();
        $items = null;
        $this->assertHook(['(the new table)'], static function () use ($editor, &$items): void {
            $items = $editor->createTable('Items', 'item', true);
        });
        $rooms = $editor->createTable('Rooms', 'room', false);
        $tags = $editor->createTable('Tags', 'tag', false);
        $notes = null;

        $this->assertHook([$items->key], static function () use ($editor, $items, &$notes): void {
            $notes = $editor->addField($items->key, 'Notes', 'text', [], false, '');
        }, false, static function () use (&$notes): array {
            return [$notes->key];
        });
        $relation = null;
        $sides = fn (?FieldDef $field): array => [$field->key, $this->schema()->relationOf($field)->otherField($field->key)];
        $this->assertHook([$items->key, $rooms->key], static function () use ($editor, $items, $rooms, &$relation): void {
            $relation = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        }, false, static function () use (&$relation, $sides): array {
            return $sides($relation);
        });
        $self = null;
        $this->assertHook([$tags->key], static function () use ($editor, $tags, &$self): void {
            $self = $editor->addRelation($tags->key, 'Parent', $tags->key, Cardinality::One, Cardinality::Many, 'Children', false, '');
        }, false, static function () use (&$self, $sides): array {
            return $sides($self);
        });
        $this->assertHook([$items->key], static fn () => $editor->changeType($notes->key, 'long_text', [], 0));
        $this->assertHook([$items->key], static fn () => $editor->moveField($notes->key, -1));
        $this->assertHook([$items->key, $rooms->key], static fn () => $editor->moveField($relation->key, -1));
        $this->assertHook([$items->key], static fn () => $editor->updateField($notes->key, 'Remarks', [], false, '', 0));
        $this->assertHook([$items->key, $rooms->key], static fn () => $editor->updateField($relation->key, 'Place', ['links' => 'one'], false, '', 0));
        $reverse = $this->schema()->relationOf($relation)->otherField($relation->key);
        $this->assertHook([$rooms->key, $items->key], static fn () => $editor->updateField($reverse, 'Things', ['links' => 'many'], false, '', 0));
        $this->assertHook([$tags->key], static fn () => $editor->updateField($self->key, 'Up', ['links' => 'one'], false, '', 0));
        $this->assertHook([$items->key], static fn () => $editor->deleteField($notes->key));
        $this->assertHook([$tags->key], static fn () => $editor->deleteField($self->key));
        $this->assertHook([$items->key, $rooms->key], static fn () => $editor->deleteField($relation->key));

        // deleteTable passes the other tables that had a relation to the deleted one.
        $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $editor->addRelation($tags->key, 'Rooms', $rooms->key, Cardinality::Many, Cardinality::Many, 'Tags', false, '');
        $this->assertHook([$items->key, $tags->key], static fn () => $editor->deleteTable($rooms->key), true);
        $this->assertHook([], static fn () => $editor->deleteTable($tags->key));

        // updateTable changes no form: no call.
        $this->calls = [];
        $editor->updateTable($items->key, 'Things', 'thing', null);
        self::assertSame([], $this->calls);
        // moveField at either end changes nothing: no call.
        $only = $this->schema()->fields($items->key)[0];
        $editor->moveField($only->key, -1);
        self::assertSame([], $this->calls);
    }

    public function testAnExceptionFromTheHookRollsTheChangeBack(): void
    {
        $editor = $this->hooked();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $notes = $editor->addField($items->key, 'Notes', 'text', [], false, '');
        $relation = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->insert($items->key, [$notes->key => 'Synthetic note']);
        $this->fail = true;

        $changes = [
            'createTable' => static fn () => $editor->createTable('Tags', 'tag', true),
            'deleteTable' => static fn () => $editor->deleteTable($rooms->key),
            'addField' => static fn () => $editor->addField($items->key, 'More', 'number', [], false, ''),
            'addRelation' => static fn () => $editor->addRelation($rooms->key, 'Owner', $items->key, Cardinality::Many, Cardinality::Many, 'Owned', false, ''),
            'changeType' => static fn () => $editor->changeType($notes->key, 'long_text', [], 0),
            'moveField' => static fn () => $editor->moveField($notes->key, -1),
            'updateField' => static fn () => $editor->updateField($notes->key, 'Remarks', [], true, 'Help', 0),
            'updateField of a relation' => static fn () => $editor->updateField($relation->key, 'Place', ['links' => 'many'], false, '', 0),
            'deleteField' => static fn () => $editor->deleteField($notes->key),
            'deleteField of a relation' => static fn () => $editor->deleteField($relation->key),
        ];
        foreach ($changes as $name => $change) {
            $before = $this->dump();
            try {
                $change();
                self::fail($name . ' did not report the hook failure.');
            } catch (RuntimeException $error) {
                self::assertSame('Synthetic hook failure.', $error->getMessage(), $name);
            }
            self::assertSame($before, $this->dump(), $name . ' changed the file.');
            self::assertFalse($this->db->inTransaction(), $name);
        }
    }

    public function testAHookTakingTwoArgumentsStillWorks(): void
    {
        $calls = [];
        $editor = new SchemaEditor(
            $this->db,
            $this->folder,
            $this->types,
            $this->keys,
            $this->clock,
            $this->snapshots,
            self::USER_KEY,
            static function (SnapshotInfo $info): void {
            },
            static function (Database $db, array $tableKeys) use (&$calls): void {
                $calls[] = $tableKeys;
            },
        );
        $items = $editor->createTable('Items', 'item', true);
        $editor->addField($items->key, 'Notes', 'text', [], false, '');
        $editor->addRelation($items->key, 'Parent', $items->key, Cardinality::One, Cardinality::Many, 'Children', false, '');
        self::assertSame([[$items->key], [$items->key], [$items->key]], $calls);
        self::assertCount(4, $this->schema()->fields($items->key));
    }

    public function testWithoutAHookNothingIsCalled(): void
    {
        $table = $this->editor()->createTable('Items', 'item', true);
        self::assertSame([], $this->calls);
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM "_tiffin_forms"'));
        self::assertSame('Items', $this->schema()->table($table->key)->name);
    }
}
