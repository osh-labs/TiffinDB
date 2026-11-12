<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldUpdate;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\Impact;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-9: updateField and previewUpdate. */
#[CoversClass(SchemaEditor::class)]
#[CoversClass(FieldUpdate::class)]
#[CoversClass(Impact::class)]
final class UpdateFieldTest extends TestCase
{
    use ProjectFixture;

    private TableDef $items;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-update-field-test-1');
        $this->items = $this->editor()->createTable('Items', 'item', true);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function colourField(): FieldDef
    {
        return $this->editor()->addField($this->items->key, 'Colour', 'choice', ['choices' => ['Red', 'Green', 'Blue']], false, '');
    }

    /** @return list<array{key: string, label: string}> */
    private static function choices(FieldDef $field): array
    {
        return $field->options['choices'];
    }

    private function confirmationOf(callable $work): ConfirmationRequired
    {
        try {
            $work();
        } catch (ConfirmationRequired $e) {
            return $e;
        }
        self::fail('ConfirmationRequired was not thrown.');
    }

    public function testNameHelpAndRequired(): void
    {
        $editor = $this->editor();
        $field = $editor->addField($this->items->key, 'Notes', 'text', [], false, '');
        $this->clock->advance(10);
        $updated = $editor->updateField($field->key, '  Remarks ', ['max_length' => 80], true, ' Short notes ', false);
        self::assertSame('Remarks', $updated->name);
        self::assertSame('Short notes', $updated->help);
        self::assertTrue($updated->required);
        self::assertSame(['max_length' => 80], $updated->options);
        self::assertEquals($updated, $this->schema()->field($field->key));
        self::assertSame('2026-11-12T03:15:10Z', $this->fieldRow($field->key)['updated_at']);
        self::assertSame([], $this->taken);

        self::assertSame(
            [['name', 'problem.field_name.taken', []], ['help', 'problem.name.too_long', ['max' => 200]], ['options.max_length', 'problem.options.range', ['min' => 1, 'max' => 10000]]],
            self::problems(fn () => $editor->updateField($field->key, 'name', ['max_length' => 'many'], false, str_repeat('h', 201), false)),
        );
        // Keeping its own name in another case is allowed.
        self::assertSame('REMARKS', $editor->updateField($field->key, 'REMARKS', [], false, '', false)->name);
    }

    public function testChoiceRenameRewritesRowsWithoutConfirmation(): void
    {
        $field = $this->colourField();
        [$red, $green, $blue] = self::choices($field);
        $ids = [
            $this->insert($this->items->key, [$field->key => 'Red']),
            $this->insert($this->items->key, [$field->key => 'Green'], true),
            $this->insert($this->items->key, [$field->key => 'Blue']),
            $this->insert($this->items->key, [$field->key => 'Red']),
            $this->insert($this->items->key),
        ];
        $options = ['choices' => [
            ['key' => $red['key'], 'label' => 'Crimson'],
            ['key' => $green['key'], 'label' => 'Green'],
            ['key' => $blue['key'], 'label' => 'Navy'],
        ]];
        self::assertEquals(new Impact(0, 0), $this->editor()->previewUpdate($field->key, $options));

        $updated = $this->editor()->updateField($field->key, 'Colour', $options, false, '', false);
        self::assertSame(['Crimson', 'Green', 'Navy', 'Crimson', null], $this->column($this->items->key, $field->key));
        self::assertSame([$red['key'], $green['key'], $blue['key']], array_column(self::choices($updated), 'key'));
        self::assertCount(1, $this->taken);
        self::assertSame(SnapshotReason::FieldOptions, $this->taken[0]->reason);
        self::assertCount(5, $ids);
    }

    public function testChoiceLabelsCanBeSwapped(): void
    {
        $field = $this->colourField();
        [$red, $green, $blue] = self::choices($field);
        $this->insert($this->items->key, [$field->key => 'Red']);
        $this->insert($this->items->key, [$field->key => 'Green']);
        $this->editor()->updateField($field->key, 'Colour', ['choices' => [
            ['key' => $red['key'], 'label' => 'Green'],
            ['key' => $green['key'], 'label' => 'Red'],
            ['key' => $blue['key'], 'label' => 'Blue'],
        ]], false, '', false);
        self::assertSame(['Green', 'Red'], $this->column($this->items->key, $field->key));
    }

    public function testRemovingAUsedChoiceNeedsConfirmationThenClears(): void
    {
        $field = $this->colourField();
        [$red, $green] = self::choices($field);
        $this->insert($this->items->key, [$field->key => 'Red']);
        $this->insert($this->items->key, [$field->key => 'Blue'], true);
        $this->insert($this->items->key, [$field->key => 'Blue']);
        $this->insert($this->items->key, [$field->key => 'Green']);
        $options = ['choices' => [['key' => $red['key'], 'label' => 'Red'], ['key' => $green['key'], 'label' => 'Lime']]];

        self::assertEquals(new Impact(2, 2), $this->editor()->previewUpdate($field->key, $options));
        $before = (string) file_get_contents($this->path());
        $confirmation = $this->confirmationOf(fn () => $this->editor()->updateField($field->key, 'Paint', $options, false, '', false));
        self::assertSame([2, 2], [$confirmation->records, $confirmation->values]);
        self::assertSame(['Red', 'Blue', 'Blue', 'Green'], $this->column($this->items->key, $field->key));
        self::assertSame('Colour', $this->schema()->field($field->key)->name);
        self::assertSame([], $this->snapshotFiles());
        self::assertSame($before, (string) file_get_contents($this->path()));

        $updated = $this->editor()->updateField($field->key, 'Paint', $options, false, '', true);
        self::assertSame(['Red', null, null, 'Lime'], $this->column($this->items->key, $field->key));
        self::assertSame(['Red', 'Lime'], array_column(self::choices($updated), 'label'));
        self::assertCount(1, $this->taken);
        self::assertSame(SnapshotReason::FieldOptions, $this->taken[0]->reason);
        self::assertSame([$this->taken[0]->file], $this->snapshotFiles());
    }

    public function testRemovingAnUnusedChoiceNeedsNoConfirmationOrSnapshot(): void
    {
        $field = $this->colourField();
        [$red] = self::choices($field);
        $this->insert($this->items->key, [$field->key => 'Red']);
        $this->editor()->updateField($field->key, 'Colour', ['choices' => [['key' => $red['key'], 'label' => 'Red'], 'Purple']], false, '', false);
        self::assertSame(['Red', 'Purple'], array_column(self::choices($this->schema()->field($field->key)), 'label'));
        self::assertSame([], $this->taken);
    }

    public function testARemovedChoiceReplacedByTheSameLabelKeepsItsValues(): void
    {
        $field = $this->colourField();
        $this->insert($this->items->key, [$field->key => 'Red']);
        $this->editor()->updateField($field->key, 'Colour', ['choices' => ['red', 'Green']], false, '', false);
        self::assertSame(['red'], $this->column($this->items->key, $field->key));
    }

    public function testLoweringARatingMaximumClearsValuesAbove(): void
    {
        $editor = $this->editor();
        $field = $editor->addField($this->items->key, 'Stars', 'rating', ['max' => 10], false, '');
        foreach ([1, 4, 5, 9, 10] as $stars) {
            $this->insert($this->items->key, [$field->key => $stars]);
        }
        $this->insert($this->items->key);

        self::assertEquals(new Impact(0, 0), $editor->previewUpdate($field->key, ['max' => 10]));
        self::assertEquals(new Impact(3, 3), $editor->previewUpdate($field->key, ['max' => 4]));
        $confirmation = $this->confirmationOf(fn () => $editor->updateField($field->key, 'Stars', ['max' => 4], false, '', false));
        self::assertSame([3, 3], [$confirmation->records, $confirmation->values]);
        self::assertSame([1, 4, 5, 9, 10, null], $this->column($this->items->key, $field->key));

        $editor->updateField($field->key, 'Stars', ['max' => 4], false, '', true);
        self::assertSame([1, 4, null, null, null, null], $this->column($this->items->key, $field->key));
        self::assertSame(['max' => 4], $this->schema()->field($field->key)->options);
        self::assertSame([SnapshotReason::FieldOptions], array_map(static fn (SnapshotInfo $i): SnapshotReason => $i->reason, $this->taken));

        // Raising it again changes no values and takes no snapshot.
        $editor->updateField($field->key, 'Stars', ['max' => 8], false, '', false);
        self::assertCount(1, $this->taken);
    }

    public function testRelationManyToOneIsRefusedWhileRecordsHaveSeveralLinks(): void
    {
        $editor = $this->editor();
        $rooms = $editor->createTable('Rooms', 'room', true);
        $field = $editor->addRelation($this->items->key, 'Rooms', $rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $relation = $this->schema()->relationOf($field);
        $a = $this->insert($this->items->key);
        $b = $this->insert($this->items->key);
        $c = $this->insert($this->items->key);
        [$r1, $r2, $r3] = [$this->insert($rooms->key), $this->insert($rooms->key), $this->insert($rooms->key)];
        $this->link($relation->key, $a, $r1);
        $this->link($relation->key, $a, $r2);
        $this->link($relation->key, $b, $r1);
        $this->link($relation->key, $b, $r3);
        $this->link($relation->key, $c, $r3);

        self::assertSame(
            [['options.links', 'problem.relation.cardinality_conflict', ['count' => 2]]],
            self::problems(fn () => $editor->updateField($field->key, 'Rooms', ['links' => 'one'], false, '', true)),
        );
        self::assertSame(
            [['options.links', 'problem.options.invalid', []]],
            self::problems(fn () => $editor->updateField($field->key, 'Rooms', ['links' => 'several'], false, '', true)),
        );

        $this->db->run('DELETE FROM ' . Identifier::joinTable($relation->key) . ' WHERE "left_id" = ? AND "right_id" = ?', [$a, $r2]);
        self::assertSame(
            [['options.links', 'problem.relation.cardinality_conflict', ['count' => 1]]],
            self::problems(fn () => $editor->updateField($field->key, 'Rooms', ['links' => 'one'], false, '', true)),
        );
        $this->db->run('DELETE FROM ' . Identifier::joinTable($relation->key) . ' WHERE "left_id" = ? AND "right_id" = ?', [$b, $r3]);

        $updated = $editor->updateField($field->key, 'Room', ['links' => 'one', 'relation' => 'aaaaaaaaaaaa', 'side' => 'right'], true, 'Where', false);
        self::assertSame(['relation' => $relation->key, 'side' => 'left'], $updated->options);
        self::assertSame('Room', $updated->name);
        self::assertTrue($updated->required);
        $after = $this->schema()->relation($relation->key);
        self::assertSame(Cardinality::One, $after->leftMax);
        self::assertSame(Cardinality::Many, $after->rightMax);
        self::assertArrayHasKey('x_j_' . $relation->key . '_l1', $this->masterNames());
        self::assertSame([], $this->taken);

        // Back to many drops the index; the other side is changed through its own field.
        $editor->updateField($field->key, 'Room', ['links' => 'many'], false, '', false);
        self::assertArrayNotHasKey('x_j_' . $relation->key . '_l1', $this->masterNames());
        // Room r1 is still linked by two items.
        self::assertSame(
            [['options.links', 'problem.relation.cardinality_conflict', ['count' => 1]]],
            self::problems(fn () => $editor->updateField($relation->rightField, 'Items', ['links' => 'one'], false, '', false)),
        );
        $this->db->run('DELETE FROM ' . Identifier::joinTable($relation->key) . ' WHERE "left_id" = ? AND "right_id" = ?', [$b, $r1]);
        $editor->updateField($relation->rightField, 'Items', ['links' => 'one'], false, '', false);
        self::assertArrayHasKey('x_j_' . $relation->key . '_r1', $this->masterNames());
        self::assertSame(Cardinality::One, $this->schema()->relation($relation->key)->rightMax);
        $editor->updateField($relation->rightField, 'Items', [], false, '', false);
        self::assertSame(Cardinality::One, $this->schema()->relation($relation->key)->rightMax);
        $editor->updateField($relation->rightField, 'Items', ['links' => 'many'], false, '', false);
        self::assertArrayNotHasKey('x_j_' . $relation->key . '_r1', $this->masterNames());
    }

    public function testCurrencyMinorDigitsAndAutoNumberStartAreFixed(): void
    {
        $editor = $this->editor();
        $price = $editor->addField($this->items->key, 'Price', 'currency', ['code' => 'usd'], false, '');
        $number = $editor->addField($this->items->key, 'Number', 'auto_number', ['start' => 5], false, '');
        $this->insert($this->items->key);

        self::assertSame(
            [['options.minor_digits', 'problem.options.minor_digits_fixed', []]],
            self::problems(fn () => $editor->updateField($price->key, 'Price', ['code' => 'EUR', 'minor_digits' => 3], false, '', true)),
        );
        self::assertSame('EUR', $editor->updateField($price->key, 'Price', ['code' => 'EUR', 'minor_digits' => 2], false, '', false)->options['code']);

        self::assertSame(
            [['options.start', 'problem.options.start_fixed', []]],
            self::problems(fn () => $editor->updateField($number->key, 'Number', ['start' => 1], false, '', true)),
        );
        $updated = $editor->updateField($number->key, 'No.', ['start' => 5, 'next' => 1], true, '', false);
        self::assertSame(['start' => 5, 'next' => 5], $updated->options);
        self::assertFalse($updated->required);
        self::assertSame(['start' => 5, 'next' => 5], $this->schema()->field($number->key)->options);
    }
}
