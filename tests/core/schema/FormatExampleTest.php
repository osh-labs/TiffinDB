<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/ProjectFixture.php';

/**
 * T-SCH-15: the example query of `docs/formats/tiffin.md` section 7, with
 * its keys replaced by those of a generated fixture, runs on a file made by
 * SchemaEditor and returns the expected rows.
 */
#[CoversNothing]
final class FormatExampleTest extends TestCase
{
    use ProjectFixture;

    /** The synthetic keys the document uses in the example. */
    private const DOC_ITEMS = 'h3kq7vn2xbma';
    private const DOC_ITEM_NAME = 'm4pz7k2qsw3n';
    private const DOC_RELATION = 'c2vb6nxq4tkm';
    private const DOC_ROOMS = 'p6wz2kq7nm3v';
    private const DOC_ROOM_NAME = 'k7n2qz4mxp3w';

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-format-example-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private static function exampleQuery(): string
    {
        $doc = RepoFiles::read('docs/formats/tiffin.md');
        $section = substr($doc, (int) strpos($doc, '## 7. Relations'), (int) strpos($doc, '## 8. Attachments') - (int) strpos($doc, '## 7. Relations'));
        self::assertSame(2, preg_match_all('/```sql\n(.*?)```/s', $section, $blocks));
        $query = trim($blocks[1][1]);
        self::assertStringStartsWith('SELECT', $query);

        return rtrim($query, ';');
    }

    public function testExampleQueryReturnsItemsWithTheirRooms(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $link = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $schema = $this->schema();
        $itemName = $schema->fields($items->key)[0]->key;
        $roomName = $schema->fields($rooms->key)[0]->key;
        $relation = $link->options['relation'];

        $kitchen = $this->insert($rooms->key, [$roomName => 'Kitchen']);
        $shed = $this->insert($rooms->key, [$roomName => 'Shed'], true);
        $toaster = $this->insert($items->key, [$itemName => 'Toaster']);
        $mower = $this->insert($items->key, [$itemName => 'Mower']);
        $this->insert($items->key, [$itemName => 'Lamp']);
        $old = $this->insert($items->key, [$itemName => 'Old kettle'], true);
        $this->link($relation, $toaster, $kitchen);
        $this->link($relation, $mower, $shed);
        $this->link($relation, $old, $kitchen);

        $query = strtr(self::exampleQuery(), [
            self::DOC_ITEMS => $items->key,
            self::DOC_ITEM_NAME => $itemName,
            self::DOC_RELATION => $relation,
            self::DOC_ROOMS => $rooms->key,
            self::DOC_ROOM_NAME => $roomName,
        ]);
        $rows = $this->db->all($query . ' ORDER BY i."id"');

        self::assertSame([
            ['item' => 'Toaster', 'room' => 'Kitchen'],
            ['item' => 'Mower', 'room' => null],
            ['item' => 'Lamp', 'room' => null],
        ], $rows);
    }
}
