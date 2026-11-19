<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotInfo;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-IO-13: joined mode of SchemaEditor and Records (M3 spec section 6.6),
 * Records::create's $requiredKeys, and the auto-number refusals.
 */
#[CoversClass(Records::class)]
#[CoversClass(SchemaEditor::class)]
final class RecordsJoinedTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-records-joined-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @param list<list<string>> $calls */
    private function hookedEditor(array &$calls): SchemaEditor
    {
        return new SchemaEditor(
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
                self::assertTrue($db->inTransaction());
                $calls[] = $tableKeys;
            },
        );
    }

    private static function assertLogic(callable $work, string $message): void
    {
        try {
            $work();
            self::fail($message);
        } catch (\LogicException) {
            self::assertTrue(true);
        }
    }

    // ------------------------------------------------------------ SchemaEditor

    public function testTheJoinedEditorNeedsATransaction(): void
    {
        $calls = [];
        $editor = $this->hookedEditor($calls);
        self::assertLogic(fn () => $editor->within(), 'within() outside a transaction');
        // A joined copy used after its transaction ended refuses as well.
        $joined = $this->db->write(static fn (Database $db): SchemaEditor => $editor->within());
        $before = $this->dump();
        self::assertLogic(fn () => $joined->createTable('Boxes', 'box', false), 'joined call with no transaction');
        self::assertEquals($before, $this->dump());
        self::assertSame([], $calls);
    }

    public function testJoinedCallsSeeEachOtherAndRunTheHook(): void
    {
        $calls = [];
        $editor = $this->hookedEditor($calls);
        $result = $this->db->write(function (Database $db) use ($editor): array {
            $joined = $editor->within();
            $table = $joined->createTable('Boxes', 'box', false);
            $label = $joined->addField($table->key, 'Label', 'text', [], false, '');
            $joined->addField($table->key, 'Weight', 'number', ['decimals' => 1], false, '');
            $link = $joined->addRelation($table->key, 'Rooms', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Boxes', false, '');
            $joined->updateTable($table->key, 'Boxes', 'box', $label->key);
            $joined->updateField($label->key, 'Box label', ['max_length' => 500], false, 'Synthetic help', 0);
            $joined->moveField($label->key, 1);
            self::assertTrue($db->inTransaction());

            return [$table, $label, $link];
        });
        [$table, $label] = $result;
        $schema = $this->schema();
        self::assertSame($label->key, $schema->table($table->key)->titleFieldKey);
        self::assertSame(['Weight', 'Box label', 'Rooms'], array_map(static fn ($f): string => $f->name, $schema->fields($table->key)));
        self::assertSame([[$table->key], [$table->key], [$table->key], [$table->key, $this->rooms->key], [$table->key], [$table->key]], $calls);
        self::assertSame(self::USER_KEY, $this->meta('updated_by'));
    }

    public function testARollbackUndoesEveryJoinedChange(): void
    {
        $calls = [];
        $editor = $this->hookedEditor($calls);
        $before = $this->dump();
        try {
            $this->db->write(function (Database $db) use ($editor): void {
                $joined = $editor->within();
                $table = $joined->createTable('Boxes', 'box', false);
                $joined->addField($table->key, 'Label', 'text', [], false, '');
                $joined->addRelation($table->key, 'Rooms', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Boxes', false, '');
                throw new \RuntimeException('Injected failure.');
            });
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected failure.', $e->getMessage());
        }
        self::assertEquals($before, $this->dump());
    }

    public function testSnapshotTakingMethodsRefuseInJoinedMode(): void
    {
        $calls = [];
        $editor = $this->hookedEditor($calls);
        $this->item(['choice' => 'Red', 'text' => 'kept']);
        $before = $this->dump();
        $this->db->write(function (Database $db) use ($editor): void {
            $joined = $editor->within();
            self::assertLogic(fn () => $joined->deleteTable($this->rooms->key), 'deleteTable');
            self::assertLogic(fn () => $joined->deleteField($this->f['text']->key), 'deleteField');
            self::assertLogic(fn () => $joined->changeType($this->f['text']->key, 'long_text', [], 0), 'changeType');
            // Removing a choice that a record holds would change stored values.
            self::assertLogic(
                fn () => $joined->updateField($this->f['choice']->key, 'F choice', ['choices' => ['Green', 'Blue']], false, '', 1),
                'updateField that clears values',
            );
        });
        self::assertEquals($before, $this->dump());
        self::assertSame([], $this->snapshotFiles());
        self::assertSame([], $calls);
    }

    // ------------------------------------------------------------ Records

    public function testJoinedRecordsNeedATransactionAndRollBack(): void
    {
        $records = $this->records();
        self::assertLogic(fn () => $records->within(), 'within() outside a transaction');
        $joined = $this->db->write(static fn (Database $db): Records => $records->within());
        self::assertLogic(fn () => $joined->create($this->items->key, [], InputMode::Form, self::USER_KEY), 'joined create with no transaction');

        $room = $this->room('Kitchen');
        $before = $this->dump();
        try {
            $this->db->write(function (Database $db) use ($records, $room): void {
                $joined = $records->within();
                $id = $joined->create($this->items->key, [$this->f['name']->key => 'Lamp'], InputMode::Form, self::USER_KEY);
                $joined->setLinks($this->f['room']->key, $id, [$room], self::USER_KEY);
                $record = $joined->get($this->items->key, $id);
                self::assertNotNull($record);
                $joined->update($this->items->key, $id, [$this->f['text']->key => 'x'], InputMode::Form, $record->version, self::USER_KEY);
                self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM ' . $this->items->sql()));
                throw new \RuntimeException('Injected failure.');
            });
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertEquals($before, $this->dump());

        // Committed when the caller commits.
        $id = $this->db->write(fn (Database $db): int => $records->within()->create($this->items->key, [$this->f['name']->key => 'Lamp'], InputMode::Form, self::USER_KEY));
        self::assertSame('Lamp', $records->get($this->items->key, $id)?->values[$this->f['name']->key]);
    }

    public function testAJoinedRecordsInstanceBuiltAfterASchemaChangeSeesTheNewTable(): void
    {
        $calls = [];
        $editor = $this->hookedEditor($calls);
        $id = $this->db->write(function (Database $db) use ($editor): int {
            $table = $editor->within()->createTable('Boxes', 'box', true);
            $records = (new Records($db, new Schema($db, $this->types), $this->types, $this->clock))->within();
            $name = (new Schema($db, $this->types))->fields($table->key)[0];

            return $records->create($table->key, [$name->key => 'Synthetic box'], InputMode::Canonical, self::USER_KEY);
        });
        self::assertSame(1, $id);
    }

    public function testRequiredKeys(): void
    {
        $editor = $this->editor();
        $serial = $editor->addField($this->items->key, 'Serial', 'text', [], true, '');
        $bought = $editor->addField($this->items->key, 'Bought', 'date', [], true, '');
        $records = $this->records();

        // null: every required field (missing and empty).
        self::assertSame([
            [$serial->key, 'problem.value.required', []],
            [$bought->key, 'problem.value.required', []],
        ], self::problems(fn () => $records->create($this->items->key, [$serial->key => ' '], InputMode::Form, self::USER_KEY)));
        self::assertSame([
            [$serial->key, 'problem.value.required', []],
            [$bought->key, 'problem.value.required', []],
        ], self::problems(fn () => $records->create($this->items->key, [$serial->key => ' '], InputMode::Form, self::USER_KEY, null)));

        // A subset: only those keys, in both checks.
        self::assertSame([[$serial->key, 'problem.value.required', []]], self::problems(
            fn () => $records->create($this->items->key, [$serial->key => ''], InputMode::Form, self::USER_KEY, [$serial->key]),
        ));
        self::assertSame([[$serial->key, 'problem.value.required', []]], self::problems(
            fn () => $records->create($this->items->key, [], InputMode::Import, self::USER_KEY, [$serial->key, $this->f['text']->key]),
        ));
        self::assertSame([[$bought->key, 'problem.value.required', []]], self::problems(
            fn () => $records->create($this->items->key, [$bought->key => null], InputMode::Canonical, self::USER_KEY, [$bought->key]),
        ));
        $id = $records->create($this->items->key, [$serial->key => 'A-1'], InputMode::Form, self::USER_KEY, [$serial->key]);
        self::assertNull($records->get($this->items->key, $id)?->values[$bought->key]);

        // []: none (missing keys and empty values both pass).
        $records->create($this->items->key, [], InputMode::Import, self::USER_KEY, []);
        $records->create($this->items->key, [$serial->key => '', $bought->key => ''], InputMode::Import, self::USER_KEY, []);
        $records->create($this->items->key, [$serial->key => null], InputMode::Canonical, self::USER_KEY, []);

        $this->expectException(\InvalidArgumentException::class);
        $records->create($this->items->key, [], InputMode::Form, self::USER_KEY, [1]);
    }

    public function testAutoNumberValuesAreRefusedWithoutAPdoException(): void
    {
        $records = $this->records();
        $auto = $this->f['auto']->key;
        $first = $records->create($this->items->key, [], InputMode::Form, self::USER_KEY);
        self::assertSame(1, $records->get($this->items->key, $first)?->values[$auto]);
        $before = $this->dump();

        // Import mode: any value is refused.
        foreach (['2', '1', ' 7 ', 'abc'] as $value) {
            self::assertSame([[$auto, 'problem.value.unsupported', []]], self::problems(
                fn () => $records->create($this->items->key, [$auto => $value], InputMode::Import, self::USER_KEY),
            ), $value);
        }
        // Form mode: refused by the type (read only).
        self::assertSame([[$auto, 'problem.auto_number.read_only', []]], self::problems(
            fn () => $records->create($this->items->key, [$auto => '9'], InputMode::Form, self::USER_KEY),
        ));
        // Canonical mode: a number another record holds, live or trashed.
        self::assertSame([[$auto, 'problem.value.unsupported', []]], self::problems(
            fn () => $records->create($this->items->key, [$auto => 1], InputMode::Canonical, self::USER_KEY),
        ));
        self::assertEquals($before, $this->dump());

        $second = $records->create($this->items->key, [$auto => 5], InputMode::Canonical, self::USER_KEY);
        $this->trashRecord($this->items, $second);
        self::assertSame([[$auto, 'problem.value.unsupported', []]], self::problems(
            fn () => $records->create($this->items->key, [$auto => 5], InputMode::Canonical, self::USER_KEY),
        ));
        // Update: another record's number is refused; the record's own number is not.
        $record = $records->get($this->items->key, $first);
        self::assertNotNull($record);
        self::assertSame([[$auto, 'problem.value.unsupported', []]], self::problems(
            fn () => $records->update($this->items->key, $first, [$auto => 5], InputMode::Canonical, $record->version, self::USER_KEY),
        ));
        $records->update($this->items->key, $first, [$auto => 1, $this->f['text']->key => 'same number'], InputMode::Canonical, $record->version, self::USER_KEY);
        // Empty values are not values: Import mode and Form mode take the next number.
        $third = $records->create($this->items->key, [$auto => ''], InputMode::Import, self::USER_KEY);
        self::assertSame(6, $records->get($this->items->key, $third)?->values[$auto]);

        // Joined mode refuses the same way.
        self::assertSame([[$auto, 'problem.value.unsupported', []]], self::problems(
            fn () => $this->db->write(fn (Database $db): int => $records->within()->create($this->items->key, [$auto => 6], InputMode::Canonical, self::USER_KEY)),
        ));
    }
}
