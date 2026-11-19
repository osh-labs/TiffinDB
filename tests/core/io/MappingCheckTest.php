<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\ColumnStats;
use TiffinDB\Core\Io\Inference;
use TiffinDB\Core\Io\LinkTarget;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\MappingCheck;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-6: MappingCheck reports every problem at once; default mappings for new and existing targets. */
#[CoversClass(MappingCheck::class)]
#[CoversClass(Mapping::class)]
#[CoversClass(ColumnMapping::class)]
final class MappingCheckTest extends TestCase
{
    use ImportFixture;

    private TableDef $rooms;
    private TableDef $bins;
    private TableDef $items;
    /** @var array<string, FieldDef> */
    private array $f = [];

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-mapping-check-test-1');
        $editor = $this->editor();
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->bins = $editor->createTable('Bins', 'bin', false);
        $this->items = $editor->createTable('Items', 'item', true);
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $this->f['weight'] = $editor->addField($this->items->key, 'Weight', 'number', ['decimals' => 1], false, '');
        $this->f['auto'] = $editor->addField($this->items->key, 'Code', 'auto_number', [], false, '');
        $this->f['files'] = $editor->addField($this->items->key, 'Files', 'attachment', [], false, '');
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->f['bin'] = $editor->addRelation($this->items->key, 'Bin', $this->bins->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    /** @param list<Problem> $problems */
    private static function flat(array $problems): array
    {
        return array_map(static fn (Problem $p): array => [$p->field, $p->key, $p->params], $problems);
    }

    /** @param list<?string> $cells */
    private function stats(array $cells): ColumnStats
    {
        $stats = new ColumnStats();
        foreach ($cells as $cell) {
            $stats->add($cell, $this->types);
        }

        return $stats;
    }

    public function testEveryProblemOfANewTargetAtOnce(): void
    {
        $many = $this->stats(array_map(static fn (int $i): string => 'V' . $i, range(1, 201)));
        $long = $this->stats([str_repeat('x', 101), str_repeat('y', 101)]);
        $columns = [
            new ColumnMapping(0, true, '', 'text', null),
            new ColumnMapping(1, true, 'Label', 'text', null),
            new ColumnMapping(2, true, ' label ', 'number', null),
            new ColumnMapping(3, true, str_repeat('n', 61), 'text', null),
            new ColumnMapping(4, true, 'Code', 'auto_number', null),
            new ColumnMapping(5, true, 'Files', 'attachment', null),
            new ColumnMapping(6, true, 'Rel', 'relation', null),
            new ColumnMapping(7, true, 'Ghost', 'link:zzzzzzzzzzzz', null),
            new ColumnMapping(8, true, 'Bin', 'link:' . $this->bins->key, null),
            new ColumnMapping(9, true, 'Untyped', null, null),
            new ColumnMapping(10, true, 'Choices', 'choice', null),
            new ColumnMapping(11, true, 'Labels', 'choice', null),
            new ColumnMapping(12, false, '', 'nonsense', null),
            new ColumnMapping(13, true, "Bad\x07name", 'text', null),
            new ColumnMapping(14, true, 'Room', 'link:' . $this->rooms->key, null),
        ];
        $stats = array_fill(0, 15, $this->stats(['a']));
        $stats[10] = $many;
        $stats[11] = $long;
        self::assertSame([
            ['table', 'problem.table_name.taken', []],
            ['col-0', 'problem.name.empty', []],
            ['col-2', 'problem.field_name.taken', []],
            ['col-3', 'problem.name.too_long', ['max' => 60]],
            ['col-4', 'problem.import.type_invalid', []],
            ['col-5', 'problem.import.type_invalid', []],
            ['col-6', 'problem.import.type_invalid', []],
            ['col-7', 'problem.import.type_invalid', []],
            ['col-8', 'problem.import.type_invalid', []],
            ['col-9', 'problem.import.type_invalid', []],
            ['col-10', 'problem.import.too_many_choices', ['max' => 200]],
            ['col-11', 'problem.options.choice_too_long', ['max' => 100]],
            ['col-13', 'problem.text.control', []],
        ], self::flat(MappingCheck::check(new Mapping(Mapping::NEW, ' items ', null, $columns), $this->schema(), $this->types, $stats)));

        self::assertSame([
            ['table', 'problem.name.empty', []],
            ['table', 'problem.import.nothing_included', []],
        ], self::flat(MappingCheck::check(new Mapping(Mapping::NEW, '  ', null, [new ColumnMapping(0, false, 'A', 'text', null)]), $this->schema(), $this->types, [$this->stats([])])));

        $tooMany = [];
        for ($i = 0; $i < 501; $i++) {
            $tooMany[] = new ColumnMapping($i, true, 'F' . $i, 'text', null);
        }
        self::assertSame([['table', 'problem.table.too_many_fields', ['max' => 500]]], self::flat(MappingCheck::check(new Mapping(Mapping::NEW, 'Wide', null, $tooMany), $this->schema(), $this->types, [])));
    }

    public function testEveryProblemOfAnExistingTargetAtOnce(): void
    {
        $columns = [
            new ColumnMapping(0, true, null, null, $this->f['name']->key),
            new ColumnMapping(1, true, null, null, $this->f['auto']->key),
            new ColumnMapping(2, true, null, null, $this->f['files']->key),
            new ColumnMapping(3, true, null, null, $this->f['bin']->key),
            new ColumnMapping(4, true, null, null, 'zzzzzzzzzzzz'),
            new ColumnMapping(5, true, null, null, $this->f['name']->key),
            new ColumnMapping(6, true, null, null, $this->f['room']->key),
            new ColumnMapping(7, false, null, null, $this->f['name']->key),
            new ColumnMapping(8, true, null, null, null),
            new ColumnMapping(9, true, null, null, $this->schema()->fields($this->rooms->key)[0]->key),
        ];
        self::assertSame([
            ['col-1', 'problem.import.type_invalid', []],
            ['col-2', 'problem.import.type_invalid', []],
            ['col-3', 'problem.import.type_invalid', []],
            ['col-4', 'problem.import.type_invalid', []],
            ['col-5', 'problem.import.field_twice', []],
            ['col-9', 'problem.import.type_invalid', []],
        ], self::flat(MappingCheck::check(new Mapping(Mapping::EXISTING, null, $this->items->key, $columns), $this->schema(), $this->types, [])));

        self::assertSame([['table', 'problem.import.nothing_included', []]], self::flat(MappingCheck::check(
            new Mapping(Mapping::EXISTING, null, $this->items->key, [new ColumnMapping(0, true, null, null, null), new ColumnMapping(1, false, null, null, $this->f['name']->key)]),
            $this->schema(),
            $this->types,
            [],
        )));

        $this->expectException(NotFound::class);
        MappingCheck::check(new Mapping(Mapping::EXISTING, null, 'zzzzzzzzzzzz', []), $this->schema(), $this->types, []);
    }

    public function testDefaults(): void
    {
        $headings = ['name', 'WEIGHT', 'Code', 'Room', 'Notes', 'ID'];
        $stats = [$this->stats(['Lamp']), $this->stats(['1.5']), $this->stats(['7']), $this->stats(['Kitchen']), $this->stats(['x']), $this->stats(['1', '2'])];
        $guesses = Inference::guessAll($headings, $stats, LinkTarget::all($this->schema()));

        $new = MappingCheck::defaults(Mapping::NEW, $this->schema(), $guesses, 'Imported items');
        self::assertSame('Imported items', $new->newTableName);
        self::assertSame(['Name', 'WEIGHT', 'Code', 'Room', 'Notes', 'ID'], array_map(static fn (ColumnMapping $c): ?string => $c->fieldName, $new->columns));
        self::assertSame(['text', 'number', 'number', 'link:' . $this->rooms->key, 'text', 'number'], array_map(static fn (ColumnMapping $c): ?string => $c->type, $new->columns));
        self::assertSame([true, true, true, true, true, false], array_map(static fn (ColumnMapping $c): bool => $c->include, $new->columns));
        self::assertSame([], MappingCheck::check($new, $this->schema(), $this->types, $stats));

        $existing = MappingCheck::defaults(Mapping::EXISTING, $this->schema(), $guesses, 'unused', $this->items->key);
        self::assertSame($this->items->key, $existing->tableKey);
        self::assertSame(
            [$this->f['name']->key, $this->f['weight']->key, null, $this->f['room']->key, null, null],
            array_map(static fn (ColumnMapping $c): ?string => $c->fieldKey, $existing->columns),
            'Code is an auto-number and cannot take a column.',
        );
        self::assertSame([true, true, false, true, false, false], array_map(static fn (ColumnMapping $c): bool => $c->include, $existing->columns));
        self::assertSame([], MappingCheck::check($existing, $this->schema(), $this->types, $stats));

        self::assertSame('inventory', MappingCheck::defaultTableName('inventory.csv'));
        self::assertSame('Sheet 1', MappingCheck::defaultTableName('book.xlsx', ' Sheet 1 '));
        self::assertSame('Imported rows', MappingCheck::defaultTableName('.csv'));
        self::assertSame('Imported rows', MappingCheck::defaultTableName("\xFF.csv"));
        self::assertSame('a b', MappingCheck::defaultTableName("a\x00b.csv"));
        self::assertSame(str_repeat('n', 60), MappingCheck::defaultTableName(str_repeat('n', 80) . '.csv'));
        self::assertSame('archive.tar', MappingCheck::defaultTableName('archive.tar.gz'));
    }

    public function testMappingShapeAndRoundTrip(): void
    {
        $mapping = new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(0, true, 'A', 'text', null), new ColumnMapping(1, false, null, null, null)]);
        self::assertSame($mapping->toArray(), Mapping::fromArray(json_decode(json_encode($mapping->toArray(), JSON_THROW_ON_ERROR), true))->toArray());
        self::assertCount(1, $mapping->included());
        foreach ([
            static fn () => new Mapping('merge', null, null, []),
            static fn () => new Mapping(Mapping::NEW, 'T', null, [new ColumnMapping(1, true, 'A', 'text', null)]),
            static fn () => new Mapping(Mapping::NEW, 'T', null, ['x' => new ColumnMapping(0, true, 'A', 'text', null)]),
            static fn () => new ColumnMapping(-1, true, null, null, null),
        ] as $bad) {
            try {
                $bad();
                self::fail('A bad mapping was built.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['columns' => [['column' => '0', 'include' => true]]], ['columns' => [['column' => 0, 'include' => true, 'type' => 5]]], ['target' => 5]] as $bad) {
            try {
                Mapping::fromArray($bad);
                self::fail('A bad stored mapping was read.');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
