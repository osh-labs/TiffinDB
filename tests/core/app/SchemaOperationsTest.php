<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\App\SchemaOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldTypes;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-20 to T-APP-22: tables and fields through the core operations. */
#[CoversClass(SchemaOperations::class)]
final class SchemaOperationsTest extends AppTestCase
{
    private string $project;
    private string $table;
    private string $field;
    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants')->key;
        $this->table = $this->ops->schema()->tables($this->admin, $this->project)[0]->table->key;
        $this->field = $this->ops->schema()->design($this->admin, $this->project, $this->table)->fields[0]->field->key;
        $this->other = $this->ops->schema()->createTable($this->admin, $this->project, 'Beds', 'bed', true)->key;
        $this->ops->projects()->setSharing($this->admin, $this->project, [$this->member->userKey]);
    }

    public function testAdminAddsOneFieldOfEveryType(): void
    {
        $schema = $this->ops->schema();
        $options = ['choice' => ['choices' => ['Red', 'Green']]];
        $added = [];
        foreach (FieldTypes::standard()->ids() as $type) {
            if ($type === 'relation') {
                $field = $schema->addRelationField($this->admin, $this->project, $this->table, 'Bed', $this->other, Cardinality::One, Cardinality::Many, 'Plants', false, '');
            } else {
                $field = $schema->addField($this->admin, $this->project, $this->table, 'F ' . $type, $type, $options[$type] ?? [], false, 'Help for ' . $type);
            }
            self::assertSame($type, $field->type);
            $added[$type] = $field->key;
        }
        $this->fx->insertRecord($this->project, $this->table);
        $this->fx->insertRecord($this->project, $this->table, [], true);

        $design = $schema->design($this->admin, $this->project, $this->table);
        self::assertCount(18, $design->fields);
        self::assertSame(['Name', ...array_map(static fn (string $t): string => $t === 'relation' ? 'Bed' : 'F ' . $t, FieldTypes::standard()->ids())], array_map(static fn ($s) => $s->field->name, $design->fields));
        self::assertSame([1, 1], [$design->recordCount, $design->trashedCount]);
        $relation = $design->fields[16];
        self::assertSame(['Beds', Cardinality::One], [$relation->linkedTableName, $relation->links]);
        self::assertSame([null, null], [$design->fields[1]->linkedTableName, $design->fields[1]->links]);

        $reverse = $schema->design($this->admin, $this->project, $this->other)->fields[1];
        self::assertSame(['Plants', 'Plants', Cardinality::Many], [$reverse->field->name, $reverse->linkedTableName, $reverse->links]);

        $field = $schema->field($this->admin, $this->project, $this->table, $added['text']);
        self::assertContains('number', $field->convertibleTo);
        self::assertNotContains('text', $field->convertibleTo);
        self::assertNotContains('relation', $field->convertibleTo);
        self::assertSame(0, $field->valueCount);
        $link = $schema->field($this->admin, $this->project, $this->table, $added['relation']);
        self::assertSame(['Beds', []], [$link->linkedTableName, $link->convertibleTo]);
        self::assertNotNull($link->relation);

        // A granted Member may read.
        self::assertCount(2, $schema->tables($this->member, $this->project));
        self::assertSame(['Plants', 18, 1], [$schema->tables($this->member, $this->project)[0]->table->name, $schema->tables($this->member, $this->project)[0]->fieldCount, $schema->tables($this->member, $this->project)[0]->recordCount]);
        self::assertCount(18, $schema->design($this->member, $this->project, $this->table)->fields);
    }

    public function testChangesGoThroughTheEditor(): void
    {
        $schema = $this->ops->schema();
        $number = $schema->addField($this->admin, $this->project, $this->table, 'Height', 'text', [], false, '');
        $this->fx->insertRecord($this->project, $this->table, [$number->key => 'tall']);
        $this->fx->insertRecord($this->project, $this->table, [$number->key => '12']);

        self::assertSame([1, 1], [$schema->previewTypeChange($this->admin, $this->project, $this->table, $number->key, 'number', [])->records, $schema->previewTypeChange($this->admin, $this->project, $this->table, $number->key, 'number', [])->values]);
        $impact = $schema->changeFieldType($this->admin, $this->project, $this->table, $number->key, 'number', [], 1);
        self::assertSame(1, $impact->values);
        self::assertSame(['type' => 'number', 'type_from' => 'text', 'values_cleared' => 1], $this->lastAudit('schema.field_changed')->detail);

        $updated = $schema->updateField($this->admin, $this->project, $this->table, $number->key, 'Height (cm)', ['decimals' => 1], true, 'In centimetres', 0);
        self::assertSame(['Height (cm)', true, 'In centimetres'], [$updated->name, $updated->required, $updated->help]);
        self::assertSame(0, $schema->previewFieldUpdate($this->admin, $this->project, $this->table, $number->key, [])->values);

        $schema->moveField($this->admin, $this->project, $this->table, $number->key, -1);
        self::assertSame($number->key, $schema->design($this->admin, $this->project, $this->table)->fields[0]->field->key);
        $table = $schema->updateTable($this->admin, $this->project, $this->table, 'Plants and herbs', 'plant', $number->key);
        self::assertSame(['Plants and herbs', 'plant', $number->key], [$table->name, $table->itemName, $table->titleFieldKey]);

        self::assertSame(2, $schema->tableImpact($this->admin, $this->project, $this->table)->records);
        $schema->deleteTable($this->admin, $this->project, $this->table);
        self::assertSame(['Beds'], array_map(static fn ($t) => $t->table->name, $schema->tables($this->admin, $this->project)));
        self::assertSame(['records' => 2], $this->lastAudit('schema.table_deleted')->detail);
    }

    /** @return iterable<string, array{\Closure(SchemaOperations, Actor, string, string, string, string): mixed}> */
    public static function changingMethods(): iterable
    {
        yield 'field' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->field($a, $p, $t, $f)];
        yield 'createTable' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->createTable($a, $p, 'Tools', 'tool', true)];
        yield 'updateTable' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->updateTable($a, $p, $t, 'Renamed', 'item', null)];
        yield 'tableImpact' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->tableImpact($a, $p, $t)];
        yield 'deleteTable' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->deleteTable($a, $p, $t)];
        yield 'addField' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->addField($a, $p, $t, 'Notes', 'long_text', [], false, '')];
        yield 'addRelationField' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->addRelationField($a, $p, $t, 'Bed', $o, Cardinality::Many, Cardinality::Many, 'Plants', false, '')];
        yield 'updateField' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->updateField($a, $p, $t, $f, 'Title', [], false, '', 0)];
        yield 'previewFieldUpdate' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->previewFieldUpdate($a, $p, $t, $f, [])];
        yield 'changeFieldType' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->changeFieldType($a, $p, $t, $f, 'long_text', [], 0)];
        yield 'previewTypeChange' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->previewTypeChange($a, $p, $t, $f, 'long_text', [])];
        yield 'moveField' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->moveField($a, $p, $t, $f, 1)];
        yield 'deleteField' => [static fn (SchemaOperations $s, Actor $a, string $p, string $t, string $f, string $o) => $s->deleteField($a, $p, $t, $f)];
    }

    #[DataProvider('changingMethods')]
    public function testChangingMethodsRefuseMembers(\Closure $call): void
    {
        $before = $this->dump();
        $schema = $this->ops->schema();
        $this->assertThrows(AccessDenied::class, fn () => $call($schema, $this->member, $this->project, $this->table, $this->field, $this->other));
        $this->assertThrows(NotFound::class, fn () => $call($schema, $this->member2, $this->project, $this->table, $this->field, $this->other));
        self::assertSame($before, $this->dump());
        // The same call succeeds for the Admin, so the refusal is about the actor.
        $call($schema, $this->admin, $this->project, $this->table, $this->field, $this->other);
    }

    public function testReadingMethodsRefuseUngrantedMembers(): void
    {
        $schema = $this->ops->schema();
        $this->assertThrows(NotFound::class, fn () => $schema->tables($this->member2, $this->project));
        $this->assertThrows(NotFound::class, fn () => $schema->design($this->member2, $this->project, $this->table));
    }

    public function testKeysThatDoNotBelongTogetherAreNotFound(): void
    {
        $schema = $this->ops->schema();
        $otherField = $schema->design($this->admin, $this->project, $this->other)->fields[0]->field->key;
        $elsewhere = $this->ops->projects()->create($this->admin, 'Example Books', 'Books')->key;
        $elsewhereTable = $schema->tables($this->admin, $elsewhere)[0]->table->key;

        $cases = [
            'field of another table' => fn () => $schema->field($this->admin, $this->project, $this->table, $otherField),
            'field of another table, update' => fn () => $schema->updateField($this->admin, $this->project, $this->table, $otherField, 'X', [], false, '', 0),
            'field of another table, delete' => fn () => $schema->deleteField($this->admin, $this->project, $this->table, $otherField),
            'field of another table, move' => fn () => $schema->moveField($this->admin, $this->project, $this->table, $otherField, 1),
            'table of another project' => fn () => $schema->design($this->admin, $this->project, $elsewhereTable),
            'table of another project, delete' => fn () => $schema->deleteTable($this->admin, $this->project, $elsewhereTable),
            'table of another project, add field' => fn () => $schema->addField($this->admin, $this->project, $elsewhereTable, 'X', 'text', [], false, ''),
            'invalid table key' => fn () => $schema->design($this->admin, $this->project, 'NOT-A-KEY'),
            'invalid field key' => fn () => $schema->field($this->admin, $this->project, $this->table, '"; DROP TABLE x; --'),
            'empty key' => fn () => $schema->design($this->admin, $this->project, ''),
            'invalid project key' => fn () => $schema->tables($this->admin, 'zz'),
            'unknown project key' => fn () => $schema->tables($this->admin, 'zzzzzzzzzzzz'),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ': NotFound was expected.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertCount(1, $schema->design($this->admin, $this->project, $this->other)->fields);
    }

    /** @return array<string, mixed> the project file's schema rows and the audit log */
    private function dump(): array
    {
        $db = $this->fx->projectDb($this->project);

        return [
            $db->all('SELECT * FROM "_tiffin_tables" ORDER BY "key"'),
            $db->all('SELECT * FROM "_tiffin_fields" ORDER BY "key"'),
            $db->all('SELECT * FROM "_tiffin_meta" ORDER BY "key"'),
            $this->fx->systemRows()['audit_log'],
            glob($this->fx->projectFolder($this->project) . '/snapshots/*') ?: [],
        ];
    }
}
