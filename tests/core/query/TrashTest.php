<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Tests\Core\Schema\ProjectFixture;

require_once __DIR__ . '/../schema/ProjectFixture.php';

/** T-TRASH-1, T-TRASH-2, T-TRASH-3: record trash. */
#[CoversClass(Trash::class)]
final class TrashTest extends TestCase
{
    use ProjectFixture;

    private TableDef $items;
    private TableDef $rooms;
    private FieldDef $name;
    private Trash $trash;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-trash-test-1');
        $this->items = $this->editor()->createTable('Items', 'item', true);
        $this->rooms = $this->editor()->createTable('Rooms', 'room', true);
        $this->name = $this->schema()->fields($this->items->key)[0];
        $this->trash = new Trash($this->db, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return array<string, mixed> */
    private function row(TableDef $table, int $id): array
    {
        $row = $this->db->one('SELECT * FROM ' . Identifier::table($table->key) . ' WHERE "id" = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function attach(FieldDef $field, int $recordId, int $attachmentId): void
    {
        $this->db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, 1)', [$field->key, $recordId, $attachmentId]);
    }

    // ------------------------------------------------------------ T-TRASH-1

    public function testMoveToTrashAndRestore(): void
    {
        $a = $this->insert($this->items->key, [$this->name->key => 'Toaster']);
        $b = $this->insert($this->items->key, [$this->name->key => 'Kettle']);
        $c = $this->insert($this->items->key, [$this->name->key => 'Lamp']);

        $this->clock->advance(30);
        self::assertSame(2, $this->trash->moveToTrash($this->items, [$a, $b, $b, 999], self::OTHER_USER_KEY));
        $row = $this->row($this->items, $a);
        self::assertSame('2026-11-12T03:15:30Z', $row['_trashed_at']);
        self::assertSame(self::OTHER_USER_KEY, $row['_trashed_by']);
        self::assertSame('2026-11-12T03:15:30Z', $row['_updated_at']);
        self::assertSame(self::OTHER_USER_KEY, $row['_updated_by']);
        self::assertNull($this->row($this->items, $c)['_trashed_at']);
        self::assertSame(2, $this->trash->count($this->items));

        // Idempotent: records already in the trash are not moved again or touched.
        $this->clock->advance(30);
        self::assertSame(0, $this->trash->moveToTrash($this->items, [$a, $b], self::USER_KEY));
        self::assertSame('2026-11-12T03:15:30Z', $this->row($this->items, $a)['_trashed_at']);
        self::assertSame(self::OTHER_USER_KEY, $this->row($this->items, $a)['_updated_by']);

        self::assertSame(1, $this->trash->restore($this->items, [$a, $c], self::USER_KEY));
        $row = $this->row($this->items, $a);
        self::assertNull($row['_trashed_at']);
        self::assertNull($row['_trashed_by']);
        self::assertSame('2026-11-12T03:16:00Z', $row['_updated_at']);
        self::assertSame(self::USER_KEY, $row['_updated_by']);
        self::assertSame(self::START, $this->row($this->items, $c)['_updated_at']);
        self::assertSame(0, $this->trash->restore($this->items, [$a], self::USER_KEY));
        self::assertSame(1, $this->trash->count($this->items));
        self::assertSame(0, $this->trash->count($this->rooms));
    }

    public function testInvalidIdsAreRefused(): void
    {
        $id = $this->insert($this->items->key);
        foreach ([[], [0], [-1], ['1'], [1.0], [null], [$id, 'x"; DROP TABLE t; --'], [true]] as $ids) {
            foreach (['moveToTrash', 'restore'] as $method) {
                try {
                    $this->trash->$method($this->items, $ids, self::USER_KEY);
                    self::fail($method . ' accepted ' . json_encode($ids));
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame(0, $this->trash->count($this->items));
    }

    public function testAtMost1000IdsPerCall(): void
    {
        $this->insert($this->items->key);
        self::assertSame(1, $this->trash->moveToTrash($this->items, range(1, 1000), self::USER_KEY));
        $this->expectException(InvalidArgumentException::class);
        $this->trash->restore($this->items, range(1, 1001), self::USER_KEY);
    }

    public function testABadPersonKeyIsRefused(): void
    {
        $id = $this->insert($this->items->key);
        $this->expectException(InvalidArgumentException::class);
        $this->trash->moveToTrash($this->items, [$id], 'Example Person');
    }

    // ------------------------------------------------------------ T-TRASH-2

    public function testEmptyDeletesTrashedRowsWithTheirLinks(): void
    {
        $editor = $this->editor();
        $files = $editor->addField($this->items->key, 'Files', 'attachment', [], false, '');
        $photos = $editor->addField($this->rooms->key, 'Photos', 'attachment', [], false, '');
        $link = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, '');
        $relation = $link->options['relation'];
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [str_repeat('c', 64), 'text/plain', 'example.txt', self::START]);

        $liveItem = $this->insert($this->items->key);
        $trashedItem = $this->insert($this->items->key, [], true);
        $liveRoom = $this->insert($this->rooms->key);
        $trashedRoom = $this->insert($this->rooms->key, [], true);
        $this->link($relation, $liveItem, $liveRoom);
        $this->link($relation, $liveItem, $trashedRoom);
        $this->link($relation, $trashedItem, $liveRoom);
        $this->attach($files, $liveItem, 1);
        $this->attach($files, $trashedItem, 1);
        $this->attach($photos, $trashedRoom, 1);
        $this->attach($photos, $liveRoom, 1);

        self::assertSame(2, $this->trash->empty([$this->items, $this->rooms], [$this->items->key => [$files], $this->rooms->key => [$photos]]));

        self::assertSame([$liveItem], array_column($this->db->all('SELECT "id" FROM ' . Identifier::table($this->items->key)), 'id'));
        self::assertSame([$liveRoom], array_column($this->db->all('SELECT "id" FROM ' . Identifier::table($this->rooms->key)), 'id'));
        self::assertSame([['left_id' => $liveItem, 'right_id' => $liveRoom]], $this->db->all('SELECT "left_id", "right_id" FROM ' . Identifier::joinTable($relation)));
        $expected = [$files->key . '/' . $liveItem, $photos->key . '/' . $liveRoom];
        $actual = array_map(static fn (array $r): string => $r['field_key'] . '/' . $r['record_id'], $this->db->all('SELECT "field_key", "record_id" FROM "_tiffin_attachment_links"'));
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame(1, $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
        self::assertSame(0, $this->trash->empty([$this->items, $this->rooms], []));
    }

    public function testEmptyOnlyTouchesTheGivenTables(): void
    {
        $this->insert($this->items->key, [], true);
        $this->insert($this->rooms->key, [], true);
        self::assertSame(1, $this->trash->empty([$this->rooms], []));
        self::assertSame(1, $this->trash->count($this->items));
        self::assertSame(0, $this->trash->count($this->rooms));
    }

    public function testEmptyRefusesFieldsThatAreNotAttachmentsOfTheTable(): void
    {
        $this->insert($this->items->key, [], true);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->trash->empty([$this->items], [$this->items->key => [$this->name]]);
        } finally {
            self::assertSame(1, $this->trash->count($this->items));
        }
    }

    // ------------------------------------------------------------ T-TRASH-3

    public function testListNewestFirstWithTitles(): void
    {
        $price = $this->editor()->addField($this->items->key, 'Price', 'currency', [], false, '');
        $toaster = $this->insert($this->items->key, [$this->name->key => 'Toaster', $price->key => 32900]);
        $kettle = $this->insert($this->items->key, [$this->name->key => 'Kettle', $price->key => 1250]);
        $blank = $this->insert($this->items->key, [$price->key => 5]);
        $this->insert($this->items->key, [$this->name->key => 'Live']);

        $this->trash->moveToTrash($this->items, [$kettle], self::USER_KEY);
        $this->clock->advance(60);
        $this->trash->moveToTrash($this->items, [$toaster, $blank], self::OTHER_USER_KEY);

        $list = $this->trash->list($this->items, $this->name, $this->types, 10, 0);
        self::assertSame([
            ['id' => $blank, 'trashed_at' => '2026-11-12T03:16:00Z', 'trashed_by' => self::OTHER_USER_KEY, 'title' => null],
            ['id' => $toaster, 'trashed_at' => '2026-11-12T03:16:00Z', 'trashed_by' => self::OTHER_USER_KEY, 'title' => 'Toaster'],
            ['id' => $kettle, 'trashed_at' => '2026-11-12T03:15:00Z', 'trashed_by' => self::USER_KEY, 'title' => 'Kettle'],
        ], $list);

        self::assertSame(['329.00', '12.50'], array_column($this->trash->list($this->items, $this->schema()->field($price->key), $this->types, 2, 1), 'title'));
        self::assertSame([null, null, null], array_column($this->trash->list($this->items, null, $this->types, 10, 0), 'title'));
        self::assertSame([$kettle], array_column($this->trash->list($this->items, $this->name, $this->types, 5, 2), 'id'));
        self::assertSame([], $this->trash->list($this->items, $this->name, $this->types, 5, 3));
    }

    public function testListArgumentsAreChecked(): void
    {
        $roomName = $this->schema()->fields($this->rooms->key)[0];
        foreach ([[0, 0, $this->name], [1, -1, $this->name], [1, 0, $roomName]] as [$limit, $offset, $field]) {
            try {
                $this->trash->list($this->items, $field, $this->types, $limit, $offset);
                self::fail('Bad list arguments were accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
