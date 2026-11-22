<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Io\ImportLog;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionStore;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-10 and T-ARC-11 (M4 spec sections 8.2 and 12.4): collections of a
 * version 4 archive are copied with their keys, names, rules, sort, and
 * times, positions renumbered; hostile collection rows are repaired or
 * dropped and counted, and never make the rebuild fail. Rules that do not
 * fit the rebuilt tables are kept, as in a live project. Every name and
 * value is synthetic.
 */
#[CoversClass(UntrustedProject::class)]
#[CoversClass(ProjectRebuilder::class)]
#[CoversClass(RebuildReport::class)]
final class RebuildCollectionsTest extends TestCase
{
    use ArchiveFixture;

    /** A key of the right shape that names no field of the project. */
    private const GONE_FIELD = 'z7zz7zz7zz7z';
    private const SELECT = 'SELECT "key", "table_key", "name", "match_mode", "rules", "sort", "position", "created_at", "created_by", "updated_at", "updated_by"'
        . ' FROM "_tiffin_collections" ORDER BY "position"';

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-collections-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    private function store(): CollectionStore
    {
        return new CollectionStore($this->db, $this->schema(), $this->keys, $this->clock);
    }

    /** A valid key made from a number, distinct for each $prefix and $i. */
    private static function key(string $prefix, int $i): string
    {
        return $prefix . strtr(sprintf('%011d', $i), '0123456789', 'abcdefghij');
    }

    /** @return array{0: string, 1: string, 2: string} the keys of three collections made through the store */
    private function threeCollections(): array
    {
        $store = $this->store();
        $tools = $store->create($this->items->key, 'Tools', MatchMode::All, [
            new CollectionRule($this->f['Done']->key, 'is', ['1']),
            new CollectionRule($this->f['Bought']->key, 'after', ['2023-01-01']),
            new CollectionRule($this->f['Name']->key, 'contains', ['Hamm "é" /x\\']),
        ], $this->f['Bought']->key . '.desc', self::USER_KEY);
        $this->clock->advance(60);
        $broken = $store->create($this->items->key, 'Heavy things', MatchMode::Any, [
            new CollectionRule(self::GONE_FIELD, 'more', ['10']),
            new CollectionRule($this->f['Room']->key, 'links-to', ['999']),
            new CollectionRule($this->f['Notes']->key, 'empty', []),
        ], null, self::OTHER_USER_KEY);
        $this->clock->advance(60);
        $rooms = $store->create($this->rooms->key, 'All rooms', MatchMode::All, [], 'added.asc', self::USER_KEY);

        return [$tools, $broken, $rooms];
    }

    // ------------------------------------------------------------ T-ARC-10

    public function testCollectionsAreCopiedWithKeysNamesRulesSortAndTimes(): void
    {
        [$tools, $broken, $rooms] = $this->threeCollections();
        $source = $this->db->all(self::SELECT);
        self::assertCount(3, $source);

        [$report, $db] = $this->import($this->export());

        self::assertTrue($report->isClean());
        self::assertSame(0, $report->collectionsDropped);
        self::assertSame(0, $report->collectionRulesDropped);
        self::assertSame($source, $db->all(self::SELECT), 'Every column is kept, rules byte for byte, broken rules included.');
        self::assertSame([$tools, $broken, $rooms], array_column($db->all(self::SELECT), 'key'));

        // The rebuilt project reads them as the store does.
        $read = CollectionStore::read($db);
        self::assertSame(['Tools', 'Heavy things', 'All rooms'], array_map(static fn ($c): string => $c->name, $read));
        self::assertSame(self::GONE_FIELD, $read[1]->rules[0]->field);
        self::assertSame(MatchMode::Any, $read[1]->match);
    }

    public function testPositionsAreRenumberedInPositionThenKeyOrder(): void
    {
        [$tools, $broken, $rooms] = $this->threeCollections();
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            "UPDATE _tiffin_collections SET position = 40 WHERE key = '" . $tools . "'",
            "UPDATE _tiffin_collections SET position = 7 WHERE key = '" . $broken . "'",
            "UPDATE _tiffin_collections SET position = 40 WHERE key = '" . $rooms . "'",
        );
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));

        self::assertSame(0, $report->collectionsDropped);
        $expected = [$broken];
        $sameNumber = [$tools, $rooms];
        sort($sameNumber, SORT_STRING);
        $expected = [...$expected, ...$sameNumber];
        self::assertSame($expected, array_column($db->all(self::SELECT), 'key'));
        self::assertSame([1, 2, 3], array_column($db->all(self::SELECT), 'position'));
    }

    /** @return array<string, string> the attachment entries of the source, for an archive built by zipFor */
    private function attachmentEntries(): array
    {
        $entries = [];
        foreach ([self::FILE_ONE, self::FILE_TWO] as $content) {
            $sha256 = hash('sha256', $content);
            $entries['attachments/' . substr($sha256, 0, 2) . '/' . $sha256] = $content;
        }

        return $entries;
    }

    // ------------------------------------------------------------ T-ARC-11

    /** One collection row as plain SQL, values given as SQL literals. */
    private function row(string $key, string $table, string $name, string $match = "'all'", string $rules = "'[]'", string $sort = 'NULL', string $position = '1', string $times = "'2026-11-12T03:15:00Z'", string $by = "'m2kq7vn3xbpa'"): string
    {
        return 'INSERT INTO _tiffin_collections (key, table_key, name, match_mode, rules, sort, position, created_at, created_by, updated_at, updated_by)'
            . " VALUES ('" . $key . "', '" . $table . "', " . $name . ', ' . $match . ', ' . $rules . ', ' . $sort . ', ' . $position . ', ' . $times . ', ' . $by . ', ' . $times . ', ' . $by . ')';
    }

    private static function lit(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function testHostileCollectionRowsAreRepairedOrDropped(): void
    {
        $items = $this->items->key;
        $done = $this->f['Done']->key;
        $good = ['field' => $done, 'op' => 'is', 'values' => ['1']];
        $rules = static fn (array $list): string => self::lit(json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $eleven = array_fill(0, 11, $good);
        $mixed = [
            $good,
            ['field' => $done, 'op' => 'is', 'values' => ['1', '0', '1']],
            ['field' => $done, 'op' => 'contains', 'values' => [str_repeat('x', 201)]],
            ['field' => $done, 'op' => 'resembles', 'values' => ['1']],
            ['field' => 'NOT A KEY', 'op' => 'is', 'values' => ['1']],
            ['field' => $done, 'op' => 'is', 'values' => ['a' => '1']],
            ['field' => $done, 'op' => 'is', 'values' => [1]],
            'not an object',
            ['field' => $done, 'op' => 'is', 'values' => ['1'], 'note' => 'unknown members are ignored'],
            ['field' => $done, 'op' => 'contains', 'values' => [str_repeat('x', 200)]],
        ];
        $statements = [
            // The duplicate-name case needs the unique index gone, as a hostile file may have it.
            'DROP INDEX _tiffin_collections_name',
            $this->row('BADKEY', $items, "'Bad key'", position: '1'),
            $this->row(self::key('t', 1), self::key('q', 1), "'Unknown table'", position: '2'),
            $this->row(self::key('c', 1), $items, "'Example'", position: '3'),
            $this->row(self::key('c', 2), $items, "'EXAMPLE'", position: '4'),
            $this->row(self::key('c', 3), $items, "'   '", position: '5'),
            $this->row(self::key('c', 4), $items, self::lit(str_repeat('n', 61)), position: '6'),
            $this->row(self::key('c', 5), $items, "x'07'", position: '7'),
            $this->row(self::key('c', 6), $items, "'Damaged rules'", rules: "'[{\"field\":'", position: '8'),
            $this->row(self::key('c', 7), $items, "'Object rules'", rules: "'{\"field\":\"x\"}'", position: '9'),
            $this->row(self::key('c', 8), $items, "'Eleven rules'", rules: $rules($eleven), position: '10'),
            $this->row(self::key('c', 9), $items, "'Mixed rules'", rules: $rules($mixed), position: '11'),
            $this->row(self::key('c', 10), $items, "'Odd match and sort'", match: "'none'", sort: "'x.y'", position: '12'),
            $this->row(self::key('c', 11), $items, "'Odd times'", position: "'first'", times: "'yesterday'", by: "'Example Person'"),
            $this->row(self::key('c', 12), $items, "'Blob rules'", rules: 'x\'5b5d\'', position: '13'),
            $this->row(self::key('c', 13), $items, "'Good sort'", sort: self::lit($this->f['Name']->key . '.asc'), position: '14'),
        ];
        // Then 45 more valid rows: 8 are kept before them, so 42 of them fill the 50 and the last 3 are dropped.
        for ($i = 0; $i < 45; $i++) {
            $statements[] = $this->row(self::key('m', $i), $items, "'Many " . $i . "'", position: (string) (100 + $i));
        }
        $copy = $this->copyOfSource();
        self::rawEdit($copy, ...$statements);
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));

        $kept = $db->all(self::SELECT);
        $byKey = array_column($kept, null, 'key');
        self::assertCount(50, $kept);
        self::assertSame(range(1, 50), array_column($kept, 'position'));
        foreach (['BADKEY', self::key('t', 1), self::key('c', 2), self::key('c', 3), self::key('c', 4), self::key('c', 5)] as $key) {
            self::assertArrayNotHasKey($key, $byKey);
        }
        // Rows sort by position with text after numbers: "first" comes after every whole number.
        self::assertArrayNotHasKey(self::key('c', 11), $byKey, 'Positions as text sort last and fall beyond the 50 kept.');
        self::assertSame('Example', $byKey[self::key('c', 1)]['name']);
        self::assertSame('[]', $byKey[self::key('c', 6)]['rules']);
        self::assertSame('[]', $byKey[self::key('c', 7)]['rules']);
        self::assertSame('[]', $byKey[self::key('c', 12)]['rules']);
        self::assertSame(json_encode(array_fill(0, 10, $good), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $byKey[self::key('c', 8)]['rules']);
        self::assertSame(
            json_encode([$good, $good, ['field' => $done, 'op' => 'contains', 'values' => [str_repeat('x', 200)]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $byKey[self::key('c', 9)]['rules'],
        );
        self::assertSame(['all', null], [$byKey[self::key('c', 10)]['match_mode'], $byKey[self::key('c', 10)]['sort']]);
        self::assertSame($this->f['Name']->key . '.asc', $byKey[self::key('c', 13)]['sort']);
        self::assertArrayHasKey(self::key('m', 41), $byKey);
        self::assertArrayNotHasKey(self::key('m', 42), $byKey);

        // Dropped: bad key, unknown table, taken name, empty name, long name, a control character as the name, 3 over the limit, the text position.
        self::assertSame(6 + 3 + 1, $report->collectionsDropped);
        // Rules: damaged JSON 1, object 1, eleventh 1, mixed 7 (the blob holds the text of an empty list).
        self::assertSame(1 + 1 + 1 + 7, $report->collectionRulesDropped);
        self::assertFalse($report->isClean());
        $log = ImportLog::get($db, $report->importLogKey);
        self::assertNotNull($log);
        self::assertSame(10, $log->report['archive']['collections_dropped']);
        self::assertSame(10, $log->report['archive']['collection_rules_dropped']);
        self::assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'));
        self::assertSame([], $db->all('PRAGMA foreign_key_check'));
    }

    public function testBadTimesAndPersonKeysAreReplaced(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit($copy, $this->row(self::key('c', 1), $this->items->key, "'Odd times'", times: "'yesterday'", by: "'Example Person'"));
        $this->clock->advance(3600);
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));

        $row = $db->one(self::SELECT);
        self::assertNotNull($row);
        self::assertSame(['2026-11-12T04:15:00Z', null, '2026-11-12T04:15:00Z', null], [$row['created_at'], $row['created_by'], $row['updated_at'], $row['updated_by']]);
        self::assertSame(2, $report->timesReplaced);
        self::assertSame(2, $report->personKeysCleared);
        self::assertSame(0, $report->collectionsDropped);
    }

    public function testNoHostileCollectionRowMakesTheRebuildFail(): void
    {
        $items = $this->items->key;
        $cases = [
            'rules as an integer' => [$this->row(self::key('c', 1), $items, "'A'", rules: '42')],
            'deeply nested rules' => [$this->row(self::key('c', 1), $items, "'A'", rules: self::lit(str_repeat('[', 200) . str_repeat(']', 200)))],
            'invalid UTF-8 name' => [$this->row(self::key('c', 1), $items, "x'ff41'")],
            'invalid UTF-8 operand' => [$this->row(self::key('c', 1), $items, "'A'", rules: "'[{\"field\":\"" . $this->f['Name']->key . "\",\"op\":\"is\",\"values\":[\"' || x'ff' || '\"]}]'")],
            'huge name' => [$this->row(self::key('c', 1), $items, 'hex(zeroblob(5000))')],
            'huge sort' => [$this->row(self::key('c', 1), $items, "'A'", sort: 'hex(zeroblob(5000))')],
            'name with a control character' => [$this->row(self::key('c', 1), $items, "'A' || char(7)")],
            'real position' => [$this->row(self::key('c', 1), $items, "'A'", position: '1.5')],
        ];
        foreach ($cases as $label => $statements) {
            $copy = $this->copyOfSource();
            self::rawEdit($copy, ...$statements);
            try {
                [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));
            } catch (ArchiveRefused $refused) {
                self::fail($label . ': refused as ' . $refused->reason);
            }
            self::assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'), $label);
            self::assertLessThanOrEqual(1, (int) $db->value('SELECT COUNT(*) FROM "_tiffin_collections"'), $label);
            self::assertInstanceOf(RebuildReport::class, $report);
            self::assertInstanceOf(Database::class, $db);
        }
    }
}
