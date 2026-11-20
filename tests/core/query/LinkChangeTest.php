<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\LinkDetail;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-LNK-1 to T-LNK-5, T-LNK-7, T-LNK-9: addLink, removeLink, joined
 * mode, linkDetails, and version tokens. T-LNK-6 is RecordsWriteTest
 * (setLinks unchanged). All data is synthetic.
 */
#[CoversClass(Records::class)]
#[CoversClass(LinkDetail::class)]
final class LinkChangeTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-link-change-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return list<int> every linked id (live or trashed) through the field, in link order */
    private function linked(FieldDef $field, int $id): array
    {
        $relation = (string) $field->options['relation'];
        $sides = $this->schema()->relation($relation)->sideOf($field->key) === 'left' ? ['left_id', 'right_id'] : ['right_id', 'left_id'];
        $rows = $this->db->all(
            'SELECT ' . Identifier::system($sides[1]) . ' AS "id" FROM ' . Identifier::joinTable($relation) . ' WHERE ' . Identifier::system($sides[0])
            . ' = ? ORDER BY "position", ' . Identifier::system($sides[1]),
            [$id],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    // ------------------------------------------------------------ T-LNK-1

    public function testAddLinkAppendsToAManyField(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $cellar = $this->room('Cellar');
        $records = $this->records();
        $records->setLinks($this->f['stored']->key, $item, [$hall, $attic], self::USER_KEY);
        $this->clock->advance(60);

        $records->addLink($this->f['stored']->key, $item, $cellar, self::OTHER_USER_KEY);

        self::assertSame([$hall, $attic, $cellar], $this->linked($this->f['stored'], $item));
        self::assertSame([1, 2, 3], array_map('intval', array_column($this->db->all('SELECT "position" FROM ' . Identifier::joinTable($this->relationKey($this->f['stored'])) . ' ORDER BY "position"'), 'position')));
        self::assertSame('2026-11-12T03:16:00Z', $this->rowOf($this->items, $item)['_updated_at']);
        self::assertSame(self::OTHER_USER_KEY, $this->rowOf($this->items, $item)['_updated_by']);
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));

        // Already linked: nothing changes, not even the times.
        $before = $this->dump();
        $this->clock->advance(60);
        $records->addLink($this->f['stored']->key, $item, $attic, self::USER_KEY);
        self::assertSame($before, $this->dump());
    }

    public function testAddLinkFromTheRightSideAndOnASelfRelation(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $bulb = $this->item(['name' => 'Bulb']);
        $hall = $this->room('Hall');
        $records = $this->records();
        $records->addLink($this->f['stores']->key, $hall, $item, self::USER_KEY);
        self::assertSame([$hall], $this->linked($this->f['stored'], $item));
        $records->addLink($this->f['parts']->key, $item, $bulb, self::USER_KEY);
        $records->addLink($this->f['parts']->key, $item, $item, self::USER_KEY);
        self::assertSame([$bulb, $item], $this->linked($this->f['parts'], $item));
        self::assertSame([$item], $this->linked($this->f['part_of'], $bulb));
    }

    // ------------------------------------------------------------ T-LNK-2

    public function testAddLinkReplacesTheLinkOfAOneField(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $records = $this->records();
        $records->addLink($this->f['room']->key, $item, $hall, self::USER_KEY);
        self::assertSame([$hall], $this->linked($this->f['room'], $item));
        $records->addLink($this->f['room']->key, $item, $attic, self::USER_KEY);
        self::assertSame([$attic], $this->linked($this->f['room'], $item));
        $before = $this->dump();
        $records->addLink($this->f['room']->key, $item, $attic, self::USER_KEY);
        self::assertSame($before, $this->dump());
    }

    public function testATrashedLinkHoldsAOneField(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $this->records()->addLink($this->f['room']->key, $item, $hall, self::USER_KEY);
        $this->trashRecord($this->rooms, $hall);
        $before = $this->dump();

        self::assertSame(
            [[$this->f['room']->key, 'problem.link.trashed_holds', ['item' => 'item']]],
            self::problems(fn () => $this->records()->addLink($this->f['room']->key, $item, $attic, self::USER_KEY)),
        );
        self::assertSame($before, $this->dump());

        // setLinks keeps its M2 text for the same state.
        self::assertSame(
            [[$this->f['room']->key, 'problem.link.one_only', []]],
            self::problems(fn () => $this->records()->setLinks($this->f['room']->key, $item, [$attic], self::USER_KEY)),
        );
    }

    // ------------------------------------------------------------ T-LNK-3

    public function testAddLinkChecksTakenAndMissingTargets(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $records = $this->records();
        $records->addLink($this->f['room']->key, $lamp, $hall, self::USER_KEY);

        // From the room's side, an item is in one room: the lamp is taken by the hall.
        self::assertSame(
            [[$this->f['room_items']->key, 'problem.link.target_taken', ['title' => 'Lamp']]],
            self::problems(fn () => $this->records()->addLink($this->f['room_items']->key, $attic, $lamp, self::USER_KEY)),
        );

        $trashedRoom = $this->room('Shed');
        $this->trashRecord($this->rooms, $trashedRoom);
        $itemsOnly = 0;
        for ($n = 0; $n < 6; $n++) {
            $itemsOnly = $this->item(['name' => 'Spare ' . (string) $n]);
        }
        self::assertNull($this->records()->get($this->rooms->key, $itemsOnly), 'The id exists only among items.');
        foreach (['trashed target' => $trashedRoom, 'absent target' => 999, "another table's id" => $itemsOnly] as $name => $target) {
            self::assertSame(
                [[$this->f['stored']->key, 'problem.link.target_missing', []]],
                self::problems(fn () => $this->records()->addLink($this->f['stored']->key, $lamp, $target, self::USER_KEY)),
                $name,
            );
        }
        self::assertSame([], $this->linked($this->f['stored'], $lamp));
    }

    public function testLinkChangesCheckTheirKeysAndTheRecord(): void
    {
        $item = $this->item();
        $room = $this->room('Hall');
        $trashed = $this->item();
        $this->trashRecord($this->items, $trashed);
        $records = $this->records();
        $cases = [
            'add, text field' => fn () => $records->addLink($this->f['text']->key, $item, $room, self::USER_KEY),
            'add, attachment field' => fn () => $records->addLink($this->f['files']->key, $item, $room, self::USER_KEY),
            'add, unknown field' => fn () => $records->addLink('zzzzzzzzzzzz', $item, $room, self::USER_KEY),
            'add, absent record' => fn () => $records->addLink($this->f['stored']->key, 999, $room, self::USER_KEY),
            'add, trashed record' => fn () => $records->addLink($this->f['stored']->key, $trashed, $room, self::USER_KEY),
            'remove, text field' => fn () => $records->removeLink($this->f['text']->key, $item, $room, self::USER_KEY),
            'remove, trashed record' => fn () => $records->removeLink($this->f['stored']->key, $trashed, $room, self::USER_KEY),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ' was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([
            fn () => $records->addLink($this->f['stored']->key, 0, $room, self::USER_KEY),
            fn () => $records->addLink($this->f['stored']->key, $item, -1, self::USER_KEY),
            fn () => $records->removeLink($this->f['stored']->key, $item, 0, self::USER_KEY),
            fn () => $records->addLink($this->f['stored']->key, $item, $room, 'Example Person'),
        ] as $case) {
            try {
                $case();
                self::fail('A bad argument was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------ T-LNK-4

    public function testRemoveLinkRemovesOnlyALiveLink(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $shed = $this->room('Shed');
        $records = $this->records();
        $records->setLinks($this->f['stored']->key, $item, [$hall, $attic, $shed], self::USER_KEY);
        $this->trashRecord($this->rooms, $shed);
        $this->clock->advance(60);

        self::assertTrue($records->removeLink($this->f['stored']->key, $item, $hall, self::OTHER_USER_KEY));
        self::assertSame([$attic, $shed], $this->linked($this->f['stored'], $item));
        self::assertSame('2026-11-12T03:16:00Z', $this->rowOf($this->items, $item)['_updated_at']);

        $before = $this->dump();
        $this->clock->advance(60);
        self::assertFalse($records->removeLink($this->f['stored']->key, $item, $hall, self::USER_KEY), 'Not linked.');
        self::assertFalse($records->removeLink($this->f['stored']->key, $item, $shed, self::USER_KEY), 'A trashed link is never touched.');
        self::assertFalse($records->removeLink($this->f['stored']->key, $item, 999, self::USER_KEY));
        self::assertSame($before, $this->dump());

        self::assertTrue($records->removeLink($this->f['stored']->key, $item, $attic, self::USER_KEY));
        self::assertSame([$shed], $this->linked($this->f['stored'], $item));
    }

    public function testRemoveLinkOnAOneFieldWithATrashedLink(): void
    {
        $item = $this->item();
        $hall = $this->room('Hall');
        $this->records()->addLink($this->f['room']->key, $item, $hall, self::USER_KEY);
        self::assertTrue($this->records()->removeLink($this->f['room']->key, $item, $hall, self::USER_KEY));
        self::assertSame([], $this->linked($this->f['room'], $item));
    }

    // ------------------------------------------------------------ T-LNK-5

    public function testJoinedModeCreatesAndLinksInOneWrite(): void
    {
        $hall = $this->room('Hall');
        $id = $this->db->write(function (Database $db) use ($hall): int {
            $records = $this->records()->within();
            $id = $records->create($this->items->key, [$this->f['name']->key => 'Lamp'], InputMode::Form, self::USER_KEY);
            $records->addLink($this->f['room']->key, $id, $hall, self::USER_KEY);

            return $id;
        });
        self::assertSame([$hall], $this->linked($this->f['room'], $id));

        $taken = $this->item(['name' => 'Taken']);
        $attic = $this->room('Attic');
        $this->records()->addLink($this->f['room']->key, $taken, $attic, self::USER_KEY);
        $before = $this->dump();
        try {
            $this->db->write(function () use ($taken): void {
                $records = $this->records()->within();
                $room = $records->create($this->rooms->key, [$this->f['room_name']->key => 'Porch'], InputMode::Form, self::USER_KEY);
                $records->addLink($this->f['room_items']->key, $room, $taken, self::USER_KEY);
            });
            self::fail('The taken target was linked.');
        } catch (ValidationFailed $failed) {
            self::assertSame('problem.link.target_taken', $failed->problems[0]->key);
        }
        self::assertSame($before, $this->dump(), 'The create rolled back with the link.');

        $this->expectException(\LogicException::class);
        $this->records()->within();
    }

    public function testRemoveLinkInJoinedMode(): void
    {
        $item = $this->item();
        $hall = $this->room('Hall');
        $this->records()->addLink($this->f['stored']->key, $item, $hall, self::USER_KEY);
        $removed = $this->db->write(fn (): bool => $this->records()->within()->removeLink($this->f['stored']->key, $item, $hall, self::USER_KEY));
        self::assertTrue($removed);
        self::assertSame([], $this->linked($this->f['stored'], $item));
    }

    // ------------------------------------------------------------ T-LNK-7

    public function testLinkDetailsShowTheFirstListColumnField(): void
    {
        $lamp = $this->item(['name' => 'Lamp', 'text' => 'Brass', 'number' => '12']);
        $blank = $this->item(['name' => 'Blank', 'number' => '3']);
        $details = $this->records()->linkDetails($this->items->key, [$blank, $lamp, 999]);
        self::assertSame([$blank, $lamp, 999], array_keys($details));
        self::assertNull($details[$blank], 'No value in the detail field.');
        self::assertNull($details[999]);
        self::assertInstanceOf(LinkDetail::class, $details[$lamp]);
        self::assertSame($this->f['text']->key, $details[$lamp]->field->key);
        self::assertSame('Brass', $details[$lamp]->value);

        // Rooms have only their name and relation fields: no detail field.
        $hall = $this->room('Hall');
        self::assertSame([$hall => null], $this->records()->linkDetails($this->rooms->key, [$hall]));
        self::assertSame([], $this->records()->linkDetails($this->rooms->key, []));
    }

    public function testLinkDetailsSkipLongTextRelationsAndFiles(): void
    {
        $editor = $this->editor();
        $boxes = $editor->createTable('Boxes', 'box', true);
        $editor->addField($boxes->key, 'Notes', 'long_text', [], false, '');
        $editor->addField($boxes->key, 'Photos', 'attachment', [], false, '');
        $editor->addRelation($boxes->key, 'Room', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Boxes', false, '');
        $size = $editor->addField($boxes->key, 'Size', 'number', [], false, '');
        $box = $this->records()->create($boxes->key, [$this->schema()->fields($boxes->key)[0]->key => 'Box', $size->key => '4'], InputMode::Form, self::USER_KEY);
        $detail = $this->records()->linkDetails($boxes->key, [$box])[$box];
        self::assertSame($size->key, $detail?->field->key);
        self::assertSame('4', (string) $detail->value);
    }

    // ------------------------------------------------------------ T-LNK-9

    public function testLinkChangesChangeTheVersion(): void
    {
        $item = $this->item(['name' => 'Lamp']);
        $hall = $this->room('Hall');
        $records = $this->records();
        $v1 = $records->get($this->items->key, $item)?->version;
        self::assertIsString($v1);

        $records->addLink($this->f['stored']->key, $item, $hall, self::OTHER_USER_KEY);
        $v2 = $records->get($this->items->key, $item)?->version;
        self::assertNotSame($v1, $v2);
        try {
            $records->update($this->items->key, $item, [$this->f['text']->key => 'Brass'], InputMode::Form, $v1, self::USER_KEY);
            self::fail('A save with the old version was accepted.');
        } catch (Conflict $conflict) {
            self::assertSame($v2, $conflict->currentVersion);
        }

        self::assertTrue($records->removeLink($this->f['stored']->key, $item, $hall, self::USER_KEY));
        $v3 = $records->get($this->items->key, $item)?->version;
        self::assertNotSame($v2, $v3);
        $this->expectException(Conflict::class);
        $records->update($this->items->key, $item, [$this->f['text']->key => 'Brass'], InputMode::Form, (string) $v2, self::USER_KEY);
    }

    public function testNormaliseSearchIsPublic(): void
    {
        self::assertNull(Records::normaliseSearch('   '));
        self::assertSame('lamp', Records::normaliseSearch(' lamp '));
        $problem = Records::normaliseSearch("a\x00b");
        self::assertSame('problem.text.control', is_string($problem) || $problem === null ? null : $problem->key);
    }
}
