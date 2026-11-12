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
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Schema\Conversion;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\Impact;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotReason;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-10: changeType and previewTypeChange (P53, P54). */
#[CoversClass(SchemaEditor::class)]
#[CoversClass(Conversion::class)]
final class ChangeTypeTest extends TestCase
{
    use ProjectFixture;

    private TableDef $items;
    private FieldDef $notes;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-change-type-test-1');
        $this->items = $this->editor()->createTable('Items', 'item', true);
        $this->notes = $this->editor()->addField($this->items->key, 'Notes', 'text', [], true, '');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @param list<string|null> $values */
    private function fill(array $values): void
    {
        foreach ($values as $i => $value) {
            $this->insert($this->items->key, [$this->notes->key => $value], $i % 3 === 2);
        }
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

    private function declaredType(): string
    {
        foreach ($this->db->all('SELECT "name", "type" FROM "pragma_table_info"(?)', [Identifier::tableName($this->items->key)]) as $column) {
            if ($column['name'] === Identifier::columnName($this->notes->key)) {
                return $column['type'];
            }
        }
        self::fail('The column is missing.');
    }

    /** @return list<string> */
    private function labels(): array
    {
        return array_column($this->schema()->field($this->notes->key)->options['choices'], 'label');
    }

    public function testUnconvertibleValuesNeedConfirmationAndChangeNothing(): void
    {
        $this->fill(['12', 'twelve', '3.5', null, '1,234', 'n/a']);
        $before = (string) file_get_contents($this->path());

        self::assertEquals(new Impact(2, 2), $this->editor()->previewTypeChange($this->notes->key, 'number', ['decimals' => 1]));
        $confirmation = $this->confirmationOf(fn () => $this->editor()->changeType($this->notes->key, 'number', ['decimals' => 1], false));
        self::assertSame([2, 2], [$confirmation->records, $confirmation->values]);
        self::assertSame($before, (string) file_get_contents($this->path()));
        self::assertSame([], $this->snapshotFiles());
        self::assertSame('TEXT', $this->declaredType());

        $impact = $this->editor()->changeType($this->notes->key, 'number', ['decimals' => 1], true);
        self::assertEquals(new Impact(2, 2), $impact);
        self::assertSame([12, null, 3.5, null, 1234, null], $this->column($this->items->key, $this->notes->key));
        self::assertSame('NUMERIC', $this->declaredType());
        $field = $this->schema()->field($this->notes->key);
        self::assertSame('number', $field->type);
        self::assertSame(['decimals' => 1, 'min' => null, 'max' => null], $field->options);
        self::assertTrue($field->required);
        self::assertCount(1, $this->taken);
        self::assertSame(SnapshotReason::FieldTypeChange, $this->taken[0]->reason);
        self::assertSame([$this->taken[0]->file], $this->snapshotFiles());
    }

    public function testCleanConversionNeedsNoConfirmation(): void
    {
        $this->fill(['2026-03-14', '2026-03-15T10:00', null]);
        self::assertEquals(new Impact(0, 0), $this->editor()->changeType($this->notes->key, 'date', [], false));
        self::assertSame(['2026-03-14', '2026-03-15', null], $this->column($this->items->key, $this->notes->key));
        self::assertCount(1, $this->taken);
    }

    public function testTextToChoiceDerivesSortedChoices(): void
    {
        $this->fill(['Tool', ' Appliance ', 'tool', null, 'Book', 'TOOL', 'appliance']);
        self::assertEquals(new Impact(0, 0), $this->editor()->changeType($this->notes->key, 'choice', [], false));
        self::assertSame(['Appliance', 'Book', 'Tool'], $this->labels());
        self::assertSame(['Tool', 'Appliance', 'Tool', null, 'Book', 'Tool', 'Appliance'], $this->column($this->items->key, $this->notes->key));
        foreach ($this->schema()->field($this->notes->key)->options['choices'] as $choice) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{12}\z/', $choice['key']);
        }
        self::assertSame('TEXT', $this->declaredType());
    }

    public function testGivenChoicesAreUsedInsteadOfDerived(): void
    {
        $this->fill(['Tool', 'Plant']);
        $confirmation = $this->confirmationOf(fn () => $this->editor()->changeType($this->notes->key, 'choice', ['choices' => ['Tool', 'Book']], false));
        self::assertSame([1, 1], [$confirmation->records, $confirmation->values]);
        $this->editor()->changeType($this->notes->key, 'choice', ['choices' => ['Tool', 'Book']], true);
        self::assertSame(['Tool', 'Book'], $this->labels());
        self::assertSame(['Tool', null], $this->column($this->items->key, $this->notes->key));
    }

    public function testLongValuesAreCutAndMerged(): void
    {
        $editor = $this->editor();
        $long = $editor->addField($this->items->key, 'Long', 'long_text', [], false, '');
        $base = str_repeat('a', 99);
        $first = $base . str_repeat('b', 51);   // 150 characters
        $second = $base . str_repeat('c', 60);  // cuts to the same label
        $this->insert($this->items->key, [$long->key => $first]);
        $this->insert($this->items->key, [$long->key => $second]);
        $this->insert($this->items->key, [$long->key => 'short']);

        $editor->changeType($long->key, 'choice', [], false);
        $label = $base . "\u{2026}";
        self::assertSame([$label, 'short'], array_column($this->schema()->field($long->key)->options['choices'], 'label'));
        self::assertSame([$label, $label, 'short'], $this->column($this->items->key, $long->key));
        self::assertSame(100, preg_match_all('/./su', $label));
    }

    public function testTooManyDerivedChoicesAreRefused(): void
    {
        $values = [];
        for ($i = 1; $i <= 201; $i++) {
            $values[] = 'Value ' . $i;
        }
        $this->fill($values);
        self::assertSame([['options.choices', 'problem.options.choices_too_many', ['max' => 200]]], self::problems(fn () => $this->editor()->changeType($this->notes->key, 'choice', [], true)));
        self::assertSame('text', $this->schema()->field($this->notes->key)->type);
        self::assertSame([], $this->snapshotFiles());
    }

    public function testDerivedOptionsThatFailValidationAreRefused(): void
    {
        $this->fill(['Tool', "\x01\x02"]);
        $before = (string) file_get_contents($this->path());
        self::assertSame([['options.choices', 'problem.options.choices_unconvertible', []]], self::problems(fn () => $this->editor()->changeType($this->notes->key, 'choice', [], true)));
        self::assertSame([['options.choices', 'problem.options.choices_unconvertible', []]], self::problems(fn () => $this->editor()->previewTypeChange($this->notes->key, 'choice', [])));
        self::assertSame($before, (string) file_get_contents($this->path()));
        self::assertSame([], $this->snapshotFiles());
        self::assertSame(['Tool', "\x01\x02"], $this->column($this->items->key, $this->notes->key));
    }

    public function testARecordEditedAfterTheDryRunIsConvertedFromItsCurrentValue(): void
    {
        $this->fill(['1', '2']);
        $editor = $this->editor(null, function (): void {
            $this->secondConnection()->write(fn (Database $db) => $db->run(
                'UPDATE ' . Identifier::table($this->items->key) . ' SET ' . Identifier::column($this->notes->key) . ' = ? WHERE "id" = 2',
                ['42'],
            ));
        });
        self::assertEquals(new Impact(0, 0), $editor->changeType($this->notes->key, 'number', [], false));
        self::assertSame([1, 42], $this->column($this->items->key, $this->notes->key));
    }

    public function testANewlyFailingValueWithoutConfirmationChangesNothing(): void
    {
        $this->fill(['1', '2']);
        $editor = $this->editor(null, function (): void {
            $this->secondConnection()->write(fn (Database $db) => $db->run(
                'UPDATE ' . Identifier::table($this->items->key) . ' SET ' . Identifier::column($this->notes->key) . ' = ? WHERE "id" = 2',
                ['two'],
            ));
        });
        $confirmation = $this->confirmationOf(fn () => $editor->changeType($this->notes->key, 'number', [], false));
        self::assertSame([1, 1], [$confirmation->records, $confirmation->values]);
        self::assertSame('TEXT', $this->declaredType());
        self::assertSame('text', $this->schema()->field($this->notes->key)->type);
        self::assertSame(['1', 'two'], $this->column($this->items->key, $this->notes->key));
        // The snapshot stays and is harmless.
        self::assertCount(1, $this->snapshotFiles());
    }

    public function testTitleFieldIsClearedWhenTheNewTypeCannotBeATitle(): void
    {
        $name = $this->schema()->fields($this->items->key)[0];
        self::assertSame($name->key, $this->schema()->table($this->items->key)->titleFieldKey);
        $this->insert($this->items->key, [$name->key => 'yes']);
        $this->insert($this->items->key, [$name->key => 'no']);
        $this->insert($this->items->key);

        $this->editor()->changeType($name->key, 'checkbox', [], false);
        self::assertNull($this->schema()->table($this->items->key)->titleFieldKey);
        self::assertSame([1, 0, 0], $this->column($this->items->key, $name->key));

        // A title-capable target keeps the title field.
        $this->editor()->changeType($this->notes->key, 'long_text', [], false);
        $this->editor()->updateTable($this->items->key, 'Items', 'item', $this->notes->key);
        $this->editor()->changeType($this->notes->key, 'email', [], true);
        self::assertSame($this->notes->key, $this->schema()->table($this->items->key)->titleFieldKey);
    }

    public function testCheckboxSourceTreatsUntickedAsEmptyAndCheckboxTargetIsNotRequired(): void
    {
        $this->insert($this->items->key, [$this->notes->key => 'true']);
        $this->insert($this->items->key, [$this->notes->key => 'off']);
        $this->editor()->changeType($this->notes->key, 'checkbox', [], false);
        $field = $this->schema()->field($this->notes->key);
        self::assertFalse($field->required);
        self::assertSame([1, 0], $this->column($this->items->key, $this->notes->key));

        $this->editor()->changeType($this->notes->key, 'text', [], false);
        self::assertSame(['1', null], $this->column($this->items->key, $this->notes->key));
    }

    public function testAutoNumberToNumberDropsTheUniqueIndex(): void
    {
        $this->insert($this->items->key);
        $this->insert($this->items->key);
        $number = $this->editor()->addField($this->items->key, 'Number', 'auto_number', [], false, '');
        self::assertArrayHasKey('x_f_' . $number->key . '_u', $this->masterNames());
        $this->editor()->changeType($number->key, 'number', [], false);
        self::assertArrayNotHasKey('x_f_' . $number->key . '_u', $this->masterNames());
        self::assertSame([1, 2], $this->column($this->items->key, $number->key));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusedPairs(): iterable
    {
        yield 'same type' => ['text', 'text', 'problem.type.not_convertible'];
        yield 'to auto-number' => ['text', 'auto_number', 'problem.type.not_convertible'];
        yield 'to relation' => ['text', 'relation', 'problem.type.not_convertible'];
        yield 'to attachment' => ['text', 'attachment', 'problem.type.not_convertible'];
        yield 'attachment to text' => ['attachment', 'text', 'problem.type.not_convertible'];
        yield 'auto-number to date' => ['auto_number', 'date', 'problem.type.not_convertible'];
        yield 'unknown type' => ['text', 'colour', 'problem.type.unknown'];
    }

    #[DataProvider('refusedPairs')]
    public function testRefusedPairs(string $from, string $to, string $problem): void
    {
        $field = $from === 'text' ? $this->notes : $this->editor()->addField($this->items->key, 'Other', $from, [], false, '');
        self::assertSame([['type', $problem, []]], self::problems(fn () => $this->editor()->changeType($field->key, $to, [], true)));
        self::assertSame([], $this->snapshotFiles());
    }

    public function testRelationFieldsCannotChangeType(): void
    {
        $rooms = $this->editor()->createTable('Rooms', 'room', true);
        $field = $this->editor()->addRelation($this->items->key, 'Room', $rooms->key, \TiffinDB\Core\Schema\Cardinality::Many, \TiffinDB\Core\Schema\Cardinality::Many, 'Items', false, '');
        self::assertSame([['type', 'problem.type.not_convertible', []]], self::problems(fn () => $this->editor()->changeType($field->key, 'text', [], true)));
    }

    public function testTargetOptionsAreValidated(): void
    {
        self::assertSame(
            [['options.max', 'problem.options.range', ['min' => 3, 'max' => 10]]],
            self::problems(fn () => $this->editor()->changeType($this->notes->key, 'rating', ['max' => 11], true)),
        );
    }

    public function testTablesWithMoreThan50000RecordsAreRefused(): void
    {
        $this->db->run(
            'WITH RECURSIVE "n"("i") AS (SELECT 1 UNION ALL SELECT "i" + 1 FROM "n" WHERE "i" < 50001)'
            . ' INSERT INTO ' . Identifier::table($this->items->key) . ' ("_created_at", "_updated_at") SELECT ?, ? FROM "n"',
            [self::START, self::START],
        );
        self::assertSame(
            [[null, 'problem.table.too_large_to_convert', ['max' => 50000]]],
            self::problems(fn () => $this->editor()->changeType($this->notes->key, 'long_text', [], true)),
        );
        self::assertSame([], $this->snapshotFiles());
    }
}
