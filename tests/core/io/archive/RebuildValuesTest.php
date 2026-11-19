<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Timestamp;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-6: values that do not fit their field type are emptied and
 * counted per field (a text value with a control character, a number over
 * its maximum, an invalid date, a currency real, a choice label not in
 * the list, an auto-number duplicate, a checkbox that is not 0 or 1); bad
 * system times are replaced and bad person keys cleared, both counted; a
 * trash time without its person is cleared as a pair; records with an id
 * below 1 are left out; links to missing records, with ids that are not
 * whole numbers, or beyond a side limited to one link are dropped.
 */
#[CoversClass(ProjectRebuilder::class)]
#[CoversClass(RebuildReport::class)]
final class RebuildValuesTest extends TestCase
{
    use ArchiveFixture;

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-values-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testInvalidValuesAreEmptiedAndCounted(): void
    {
        $items = '"' . Identifier::tableName($this->items->key) . '"';
        $col = fn (string $name): string => '"' . Identifier::columnName($this->f[$name]->key) . '"';
        [$one, $two, $three] = $this->itemIds;
        $join = '"' . Identifier::joinName($this->room->key) . '"';
        [$kitchen, $garage] = $this->roomIds;
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'DROP INDEX "x_' . Identifier::columnName($this->f['Code']->key) . '_u"',
            'DROP INDEX "x_' . Identifier::joinName($this->room->key) . '_l1"',
            'UPDATE ' . $items . ' SET ' . $col('Name') . " = 'Ham' || char(1) || 'mer', " . $col('Weight') . ' = 5000, ' . $col('Kind') . " = 'Gadget', \"_created_at\" = 'yesterday' WHERE id = " . $one,
            'UPDATE ' . $items . ' SET ' . $col('Bought') . " = '2026-02-30', " . $col('Price') . ' = 1.5, ' . $col('Code') . ' = 1, ' . $col('Done') . " = 'yes', \"_updated_by\" = 'Robert''); DROP' WHERE id = " . $two,
            'UPDATE ' . $items . ' SET "_trashed_by" = NULL WHERE id = ' . $three,
            'INSERT INTO ' . $items . ' ("id", "_created_at", "_updated_at", ' . $col('Name') . ") VALUES (-5, '" . self::START . "', '" . self::START . "', 'Negative')",
            'INSERT INTO ' . $join . ' VALUES (99, ' . $kitchen . ', 0)',
            'INSERT INTO ' . $join . ' VALUES (' . $two . ', ' . $kitchen . ", 'x')",
            'INSERT INTO ' . $join . " VALUES ('abc', " . $garage . ', 0)',
        );
        [$report, $db] = $this->import($this->zipFor($copy));

        $counts = array_map(static fn (array $entry): int => $entry['count'], $report->valuesEmptied);
        $expected = [];
        foreach (['Name', 'Price', 'Done', 'Kind', 'Bought', 'Weight'] as $name) {
            $expected[$this->f[$name]->key] = 1;
        }
        $expected[$this->f['Code']->key] = 1;
        ksort($expected);
        ksort($counts);
        self::assertSame($expected, $counts);
        self::assertSame(['table' => 'Items', 'field' => 'Weight', 'count' => 1], $report->valuesEmptied[$this->f['Weight']->key]);
        self::assertSame(7, $report->valuesEmptiedTotal());
        self::assertSame(1, $report->recordsSkipped);
        self::assertSame(5, $report->records, 'Three items and two rooms.');
        self::assertSame(1, $report->personKeysCleared);
        self::assertSame(2, $report->timesReplaced, 'One creation time and one trash pair.');
        self::assertSame(3, $report->linksDropped);

        $row = fn (int $id): array => $db->one('SELECT * FROM ' . $items . ' WHERE "id" = ?', [$id]) ?? [];
        $first = $row($one);
        self::assertNull($first[Identifier::columnName($this->f['Name']->key)]);
        self::assertNull($first[Identifier::columnName($this->f['Weight']->key)]);
        self::assertNull($first[Identifier::columnName($this->f['Kind']->key)]);
        self::assertSame(1, $first[Identifier::columnName($this->f['Code']->key)]);
        self::assertSame(1299, $first[Identifier::columnName($this->f['Price']->key)]);
        self::assertSame(Timestamp::format($this->clock->now()), $first['_created_at']);
        $second = $row($two);
        self::assertNull($second[Identifier::columnName($this->f['Bought']->key)]);
        self::assertNull($second[Identifier::columnName($this->f['Price']->key)]);
        self::assertNull($second[Identifier::columnName($this->f['Code']->key)]);
        self::assertSame(0, $second[Identifier::columnName($this->f['Done']->key)]);
        self::assertNull($second['_updated_by']);
        self::assertSame('Kite', $second[Identifier::columnName($this->f['Name']->key)]);
        $third = $row($three);
        self::assertNull($third['_trashed_at']);
        self::assertNull($third['_trashed_by']);
        self::assertNull($db->one('SELECT * FROM ' . $items . ' WHERE "id" = -5'));

        $links = $db->all('SELECT "left_id", "right_id", "position" FROM ' . $join . ' ORDER BY "left_id"');
        self::assertSame([
            ['left_id' => $one, 'right_id' => $kitchen, 'position' => 0],
            ['left_id' => $two, 'right_id' => $kitchen, 'position' => 0],
            ['left_id' => $three, 'right_id' => $kitchen, 'position' => 0],
        ], $links, 'The second room of an item is dropped: lowest position, then lowest room id, wins.');

        $log = json_decode((string) $db->value('SELECT "report" FROM "_tiffin_import_log"'), true);
        self::assertSame(7, $log['archive']['values_emptied']);
        self::assertSame(3, $log['archive']['links_dropped']);
        self::assertCount(7, $log['columns']);
        self::assertSame(7, $db->value('SELECT "values_emptied" FROM "_tiffin_import_log"'));
        self::assertSame(1, $db->value('SELECT "rows_skipped" FROM "_tiffin_import_log"'));
        $this->assertNoValueInLog($db);
    }

    public function testAutoNumberNextContinuesAfterTheLargestValue(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'UPDATE "' . Identifier::tableName($this->items->key) . '" SET "' . Identifier::columnName($this->f['Code']->key) . '" = 40 WHERE id = ' . $this->itemIds[1],
        );
        [, $db] = $this->import($this->zipFor($copy));
        $options = json_decode((string) $db->value('SELECT "options" FROM "_tiffin_fields" WHERE "key" = ?', [$this->f['Code']->key]), true);
        self::assertSame(['start' => 1, 'next' => 41], $options);
    }

    public function testMetadataTimesAndNamesAreNormalised(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            "UPDATE _tiffin_tables SET created_at = 'long ago', name = '  Rooms  ' WHERE key = '" . $this->rooms->key . "'",
            "UPDATE _tiffin_meta SET value = 'not a key' WHERE key = 'created_by'",
            "UPDATE _tiffin_meta SET value = '<b>' WHERE key = 'created_with'",
        );
        [$report, $db] = $this->import($this->zipFor($copy));
        self::assertSame(1, $report->timesReplaced);
        $table = $db->one('SELECT "name", "created_at" FROM "_tiffin_tables" WHERE "key" = ?', [$this->rooms->key]);
        self::assertSame(['name' => 'Rooms', 'created_at' => Timestamp::format($this->clock->now())], $table);
        $meta = array_column($db->all('SELECT "key", "value" FROM "_tiffin_meta"'), 'value', 'key');
        self::assertSame(self::IMPORTER_KEY, $meta['created_by']);
        self::assertSame(self::VERSION_TEXT, $meta['created_with']);
    }

    /** The import log holds counts and keys, never a record value. */
    private function assertNoValueInLog(Database $db): void
    {
        $text = json_encode($db->all('SELECT * FROM "_tiffin_import_log"'), JSON_THROW_ON_ERROR);
        foreach (['Ham', 'Gadget', 'Robert', '2026-02-30', 'Negative'] as $value) {
            self::assertStringNotContainsString($value, $text);
        }
    }
}
