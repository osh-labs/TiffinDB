<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Query\Candidate;
use TiffinDB\Core\Query\CandidatePage;
use TiffinDB\Core\Query\LinkedRecord;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\Picker;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-PCK-1 to T-PCK-3: the relation picker's candidate query. All data is
 * synthetic; the 20,000-record fixture is generated in the test.
 */
#[CoversClass(Picker::class)]
#[CoversClass(CandidatePage::class)]
#[CoversClass(Candidate::class)]
final class PickerTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-picker-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function picker(): Picker
    {
        return new Picker($this->db, $this->schema(), $this->types);
    }

    /** @return list<?string> candidate titles of the page */
    private static function titlesOf(CandidatePage $page): array
    {
        return array_map(static fn (Candidate $c): ?string => $c->title, $page->candidates);
    }

    // ------------------------------------------------------------ T-PCK-1

    public function testCandidatesExcludeLinkedRecordsInDefaultOrderFiftyPerPage(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $rooms = [];
        for ($n = 1; $n <= 110; $n++) {
            $rooms[$n] = $this->room(sprintf('Room %03d', $n));
        }
        $hall = $this->room('hall');
        $shed = $this->room('Shed');
        $attic = $this->room('Attic');
        $this->records()->setLinks($this->f['stored']->key, $lamp, [$hall, $shed, $rooms[2]], self::USER_KEY);
        $this->trashRecord($this->rooms, $shed);
        $this->trashRecord($this->rooms, $rooms[5]);

        $first = $this->picker()->page($this->f['stored']->key, $lamp, null, 1);

        self::assertEquals([new LinkedRecord($this->rooms->key, $hall, 'hall'), new LinkedRecord($this->rooms->key, $rooms[2], 'Room 002')], $first->linked, 'Live links in link order.');
        self::assertSame(1, $first->trashedLinks);
        self::assertFalse($first->onlyOne);
        self::assertNull($first->search);
        self::assertNull($first->searchProblem);
        // 113 rooms, less 3 linked (one in the trash) and 1 other in the trash.
        self::assertSame(109, $first->total);
        self::assertSame(3, $first->pages);
        self::assertSame(1, $first->page);
        self::assertCount(50, $first->candidates);
        self::assertSame(['Attic', 'Room 001', 'Room 003', 'Room 004', 'Room 006'], array_slice(self::titlesOf($first), 0, 5), 'Title order, ignoring case.');
        self::assertSame($attic, $first->candidates[0]->id);
        self::assertNull($first->candidates[0]->takenBy, 'Rooms may hold many items through this relation.');

        $last = $this->picker()->page($this->f['stored']->key, $lamp, null, 3);
        self::assertCount(9, $last->candidates);
        self::assertSame('Room 110', self::titlesOf($last)[8]);
        self::assertEquals($last, $this->picker()->page($this->f['stored']->key, $lamp, null, 99), 'Pages past the end clamp to the last.');
        self::assertEquals($first, $this->picker()->page($this->f['stored']->key, $lamp, null, 0));
        self::assertEquals($first, $this->picker()->page($this->f['stored']->key, $lamp, null, -4));

        $all = [];
        foreach ([1, 2, 3] as $n) {
            foreach ($this->picker()->page($this->f['stored']->key, $lamp, null, $n)->candidates as $candidate) {
                $all[] = $candidate->id;
            }
        }
        self::assertCount(109, array_unique($all));
        foreach ([$hall, $shed, $rooms[2], $rooms[5]] as $excluded) {
            self::assertNotContains($excluded, $all);
        }
    }

    public function testSearchMatchesLiterallyAndARefusedSearchIsReported(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        foreach (['50% off', '500 off', 'a_b', 'axb', 'Back hall'] as $name) {
            $this->room($name);
        }
        $percent = $this->picker()->page($this->f['stored']->key, $lamp, ' 50% ', 1);
        self::assertSame('50%', $percent->search);
        self::assertSame(['50% off'], self::titlesOf($percent));
        self::assertSame(1, $percent->total);
        self::assertSame(['a_b'], self::titlesOf($this->picker()->page($this->f['stored']->key, $lamp, '_', 1)));
        self::assertSame(['Back hall'], self::titlesOf($this->picker()->page($this->f['stored']->key, $lamp, 'HALL', 1)));

        $refused = $this->picker()->page($this->f['stored']->key, $lamp, "a\x00b", 1);
        self::assertSame('problem.text.control', $refused->searchProblem?->key);
        self::assertNull($refused->search);
        self::assertSame(5, $refused->total, 'An unfiltered list.');
        $long = $this->picker()->page($this->f['stored']->key, $lamp, str_repeat('a', 201), 1);
        self::assertSame('problem.text.too_long', $long->searchProblem?->key);

        $blank = $this->picker()->page($this->f['stored']->key, $lamp, '   ', 1);
        self::assertNull($blank->search);
        self::assertNull($blank->searchProblem);
        self::assertSame(5, $blank->total);
    }

    public function testATableWithoutATitleIsOrderedAsAdded(): void
    {
        $editor = $this->editor();
        $boxes = $editor->createTable('Boxes', 'box', false);
        $inBox = $editor->addRelation($this->items->key, 'In box', $boxes->key, Cardinality::Many, Cardinality::Many, 'Holds', false, '');
        $item = $this->item();
        $ids = [];
        for ($n = 0; $n < 3; $n++) {
            $ids[] = $this->insert($boxes->key);
        }
        $page = $this->picker()->page($inBox->key, $item, null, 1);
        self::assertSame($ids, array_map(static fn (Candidate $c): int => $c->id, $page->candidates));
        self::assertSame([null, null, null], self::titlesOf($page));
        self::assertSame(0, $this->picker()->page($inBox->key, $item, 'x', 1)->total, 'Nothing to search.');
    }

    public function testPageChecksItsKeysAndTheRecord(): void
    {
        $item = $this->item();
        $trashed = $this->item();
        $this->trashRecord($this->items, $trashed);
        foreach ([
            'text field' => fn () => $this->picker()->page($this->f['text']->key, $item, null, 1),
            'attachment field' => fn () => $this->picker()->page($this->f['files']->key, $item, null, 1),
            'unknown field' => fn () => $this->picker()->page('zzzzzzzzzzzz', $item, null, 1),
            'absent record' => fn () => $this->picker()->page($this->f['stored']->key, 999, null, 1),
            'trashed record' => fn () => $this->picker()->page($this->f['stored']->key, $trashed, null, 1),
        ] as $name => $case) {
            try {
                $case();
                self::fail($name . ' was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->picker()->page($this->f['stored']->key, 0, null, 1);
    }

    // ------------------------------------------------------------ T-PCK-2

    public function testTakenByNamesTheHolderWhenTheOtherSideAllowsOne(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $kettle = $this->item(['name' => 'Kettle']);
        $loose = $this->item(['name' => 'Loose']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $this->records()->addLink($this->f['room']->key, $lamp, $hall, self::USER_KEY);
        $this->records()->addLink($this->f['room']->key, $kettle, $attic, self::USER_KEY);

        // From the attic: items are in one room, so the lamp is taken by the hall; the kettle is already linked.
        $page = $this->picker()->page($this->f['room_items']->key, $attic, null, 1);
        self::assertEquals([new LinkedRecord($this->items->key, $kettle, 'Kettle')], $page->linked);
        self::assertFalse($page->onlyOne);
        self::assertSame([$lamp, $loose], array_map(static fn (Candidate $c): int => $c->id, $page->candidates));
        self::assertEquals(new LinkedRecord($this->rooms->key, $hall, 'Hall'), $page->candidates[0]->takenBy);
        self::assertNull($page->candidates[1]->takenBy);

        // From the item's one-link side: onlyOne, and rooms may hold many items, so nothing is taken.
        $side = $this->picker()->page($this->f['room']->key, $loose, null, 1);
        self::assertTrue($side->onlyOne);
        self::assertSame([null, null], array_map(static fn (Candidate $c): ?LinkedRecord => $c->takenBy, $side->candidates));
    }

    public function testASelfRelationListsTheRecordItself(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $bulb = $this->item(['name' => 'Bulb']);
        $page = $this->picker()->page($this->f['parts']->key, $lamp, null, 1);
        self::assertSame(['Bulb', 'Lamp'], self::titlesOf($page));
        $this->records()->addLink($this->f['parts']->key, $lamp, $lamp, self::USER_KEY);
        self::assertSame(['Bulb'], self::titlesOf($this->picker()->page($this->f['parts']->key, $lamp, null, 1)));
        self::assertSame(['Bulb', 'Lamp'], self::titlesOf($this->picker()->page($this->f['part_of']->key, $bulb, null, 1)));
    }

    // ------------------------------------------------------------ T-PCK-3

    public function testTheExclusionUsesTheJoinTableKeyOnTwentyThousandRecords(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $join = Identifier::joinTable($this->relationKey($this->f['parts']));
        $this->db->write(function (Database $db) use ($lamp, $join): void {
            for ($n = 1; $n <= 20000; $n++) {
                $db->run(
                    'INSERT INTO ' . $this->items->sql() . ' ("_created_at", "_updated_at", "_trashed_at", ' . $this->f['name']->sql() . ') VALUES (?, ?, ?, ?)',
                    [self::START, self::START, $n % 10 === 0 ? self::START : null, sprintf('Part %05d', $n)],
                );
                if ($n % 2 === 0) {
                    $db->run('INSERT INTO ' . $join . ' ("left_id", "right_id", "position") VALUES (?, ?, ?)', [$lamp, $db->lastInsertId(), $n]);
                }
            }
        });
        $this->db->run('ANALYZE');

        $started = microtime(true);
        $page = $this->picker()->page($this->f['parts']->key, $lamp, null, 101);
        $elapsed = microtime(true) - $started;
        self::assertSame(10001, $page->total, 'Odd parts (never trashed) and the lamp itself.');
        self::assertSame(201, $page->pages);
        self::assertSame(101, $page->page);
        self::assertCount(8000, $page->linked);
        self::assertSame(2000, $page->trashedLinks);
        self::assertLessThan(5.0, $elapsed);

        // The plan of the same exclusion and order: the join row by its primary key for each candidate.
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Core/Query/Picker.php');
        self::assertStringContainsString("'NOT EXISTS (SELECT 1 FROM ' . \$relation->sql() . ' AS \"jj\" WHERE \"jj\".'", $source);
        $order = (new ListSql($this->schema(), $this->types))->order($this->items, new Sort($this->f['name']->key, false));
        $plan = array_column($this->db->all(
            'EXPLAIN QUERY PLAN SELECT r."id" FROM ' . $this->items->sql() . ' AS "r" WHERE r."_trashed_at" IS NULL AND NOT EXISTS (SELECT 1 FROM ' . $join
            . ' AS "jj" WHERE "jj"."left_id" = ? AND "jj"."right_id" = r."id") ORDER BY ' . $order->sql() . ' LIMIT 50 OFFSET 5000',
            [$lamp, ...$order->params()],
        ), 'detail');
        $text = implode("\n", $plan);
        self::assertStringContainsString('SEARCH jj USING PRIMARY KEY (left_id=? AND right_id=?)', $text);
        self::assertStringNotContainsString('SCAN jj', $text);
    }
}
