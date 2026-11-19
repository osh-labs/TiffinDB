<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\SearchGroup;
use TiffinDB\Core\Query\SearchHit;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;

require_once __DIR__ . '/RecordsFixture.php';

/** T-REC-10, T-REC-13, T-REC-15: searching within a table and across tables; names from people. */
#[CoversClass(Records::class)]
#[CoversClass(ListSql::class)]
final class RecordsSearchTest extends TestCase
{
    use RecordsFixture;

    /** Every name in sqlite_master of a project file (M1 T-SCH-5). */
    private const MASTER_NAME = '/^(_tiffin_\w+|sqlite_\w+|[tfj]_[a-z2-7]{12}|x_[tfj]_[a-z2-7]{12}_(trash|u|r|l1|r1))$/';

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-records-search-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return list<int> */
    private function found(string $text, ?string $tableKey = null): array
    {
        $normalised = $this->records()->query($tableKey ?? $this->items->key, 'added.asc', null, null, [], $text, 1);
        self::assertNull($normalised->searchProblem, $text);

        return array_map(static fn (RecordData $r): int => $r->id, $this->records()->page($tableKey ?? $this->items->key, $normalised->query)->records);
    }

    // ------------------------------------------------------------ T-REC-10

    public function testSearchLooksAtEverySearchableTypeAndLinkedTitles(): void
    {
        $records = $this->records();
        $ids = [];
        foreach ([
            'text' => 'Blue shelf',
            'long_text' => "Second line\nmentions Quartz",
            'url' => 'https://example.com/zebra',
            'email' => 'walrus@example.com',
            'phone' => '555-0199',
            'choice' => 'Green',
        ] as $short => $value) {
            $ids[$short] = $this->item(['name' => 'Item ' . $short, $short => $value]);
        }
        $numbers = $this->item(['name' => 'Numbers', 'number' => '4242', 'date' => '2026-07-07', 'rating' => '3']);
        $linked = $this->item(['name' => 'Linked']);
        $attic = $this->room('Dusty attic');
        $gone = $this->room('Dusty cellar');
        $records->setLinks($this->f['stored']->key, $linked, [$attic], self::USER_KEY);
        $other = $this->item(['name' => 'Other']);
        $records->setLinks($this->f['stored']->key, $other, [$gone], self::USER_KEY);
        $this->trashRecord($this->rooms, $gone);

        self::assertSame([$ids['text']], $this->found('SHELF'));
        self::assertSame([$ids['long_text']], $this->found('quartz'));
        self::assertSame([$ids['url']], $this->found('ZEBRA'));
        self::assertSame([$ids['email']], $this->found('Walrus@'));
        self::assertSame([$ids['phone']], $this->found('0199'));
        self::assertSame([$ids['choice']], $this->found('green'));
        self::assertSame([$linked], $this->found('dusty'), 'Linked live titles match; a trashed room does not.');
        self::assertSame([$ids['text'], $ids['long_text'], $ids['url'], $ids['email'], $ids['phone'], $ids['choice']], $this->found('item '));
        self::assertSame([], $this->found('4242'), 'Numbers are not searched.');
        self::assertSame([], $this->found('2026-07'), 'Dates are not searched.');
        self::assertSame([$numbers], $this->found('  numbers  '));

        // Searching from the room side finds rooms by their linked items' titles.
        self::assertSame([$attic], $this->found('linked', $this->rooms->key));
    }

    public function testLikeWildcardsMatchLiterallyAndOnlyAsciiFolds(): void
    {
        $percent = $this->item(['name' => '100% cotton']);
        $plain = $this->item(['name' => '1000 cotton']);
        $under = $this->item(['name' => 'snake_case']);
        $other = $this->item(['name' => 'snakeXcase']);
        $slash = $this->item(['name' => 'C:\\temp']);
        $slashOther = $this->item(['name' => 'C:temp']);
        $accent = $this->item(['name' => 'Écrin']);
        self::assertSame([$percent], $this->found('%'));
        self::assertSame([$percent], $this->found('0% c'));
        self::assertSame([$under], $this->found('_'));
        self::assertSame([$under], $this->found('e_c'));
        self::assertSame([$slash], $this->found('\\'));
        self::assertSame([$slash], $this->found(':\\t'));
        self::assertSame([$plain, $percent], array_values(array_intersect([$plain, $percent], $this->found('cotton'))));
        self::assertNotContains($other, $this->found('_'));
        self::assertNotContains($slashOther, $this->found('\\'));
        self::assertSame([$accent], $this->found('Écrin'));
        self::assertSame([], $this->found('écrin'), 'LIKE folds ASCII letters only (ADR 0013 decision 5).');
        self::assertSame('a\\%b\\_c\\\\d', ListSql::escapeLike('a%b_c\\d'));
    }

    public function testTrashedRecordsAreNeverFoundAndTablesWithoutSearchableFieldsMatchNothing(): void
    {
        $kept = $this->item(['name' => 'Garden hose']);
        $gone = $this->item(['name' => 'Garden rake']);
        $this->trashRecord($this->items, $gone);
        self::assertSame([$kept], $this->found('garden'));
        self::assertSame([], array_map(static fn (SearchGroup $g): array => $g->hits, array_filter(
            $this->records()->search('rake'),
            static fn (SearchGroup $g): bool => $g->tableKey === $this->items->key,
        )));

        $editor = $this->editor();
        $counts = $editor->createTable('Counts', 'count', false);
        $amount = $editor->addField($counts->key, 'Amount', 'number', [], false, '');
        $this->records()->create($counts->key, [$amount->key => '7'], InputMode::Form, self::USER_KEY);
        self::assertSame([], $this->found('7', $counts->key));
        self::assertSame(1, $this->records()->count($counts->key));
    }

    public function testSearchTextProblems(): void
    {
        $records = $this->records();
        $a = $this->item(['name' => 'a']);
        foreach ([
            [str_repeat('x', 201), new Problem(null, 'problem.text.too_long', ['max' => 200])],
            ["bad\x01text", new Problem(null, 'problem.text.control')],
            ["tab\there", new Problem(null, 'problem.text.control')],
            ["new\nline", new Problem(null, 'problem.text.control')],
            ["\xff\xfe", new Problem(null, 'problem.text.control')],
        ] as [$text, $problem]) {
            $normalised = $records->query($this->items->key, null, null, null, [], $text, 1);
            self::assertEquals($problem, $normalised->searchProblem);
            self::assertNull($normalised->query->search);
            self::assertSame([$a], array_map(static fn (RecordData $r): int => $r->id, $records->page($this->items->key, $normalised->query)->records));
            self::assertSame([], $records->search($text));
        }
        $exact = $records->query($this->items->key, null, null, null, [], ' ' . str_repeat('é', 200) . ' ', 1);
        self::assertNull($exact->searchProblem);
        self::assertSame(str_repeat('é', 200), $exact->query->search);
        foreach (['', '   ', null] as $none) {
            $normalised = $records->query($this->items->key, null, null, null, [], $none, 1);
            self::assertNull($normalised->searchProblem);
            self::assertNull($normalised->query->search);
        }
        self::assertSame([], $records->search('   '));
    }

    // ------------------------------------------------------------ T-REC-13

    public function testSearchAcrossTables(): void
    {
        $records = $this->records();
        $editor = $this->editor();
        $notes = $editor->createTable('Notes', 'note', false);
        $body = $editor->addField($notes->key, 'Body', 'long_text', [], false, '');
        $records = $this->records();

        for ($i = 1; $i <= 12; $i++) {
            $this->item(['name' => sprintf('Tool %02d', 13 - $i), 'text' => $i === 3 ? 'has a red handle' : '']);
        }
        $hall = $this->room('Red hall');
        $records->setLinks($this->f['stored']->key, 1, [$hall], self::USER_KEY);
        $redOnly = $this->item(['name' => 'Redwood box']);
        $note = $records->create($notes->key, [$body->key => 'Paint it RED'], InputMode::Form, self::USER_KEY);
        $this->room('Garage');

        $groups = $records->search('red');
        self::assertSame([$this->items->key, $this->rooms->key, $notes->key], array_map(static fn (SearchGroup $g): string => $g->tableKey, $groups));
        [$items, $rooms, $noteGroup] = $groups;
        self::assertSame(3, $items->total);
        // Default order: title A to Z ("Redwood box", "Tool 11", "Tool 12").
        self::assertEquals([
            new SearchHit($redOnly, 'Redwood box', null, null),
            new SearchHit(3, 'Tool 10', $this->f['text']->key, 'has a red handle'),
            new SearchHit(1, 'Tool 12', $this->f['stored']->key, 'Red hall'),
        ], $items->hits);
        self::assertEquals(new SearchGroup($this->rooms->key, 1, [new SearchHit($hall, 'Red hall', null, null)]), $rooms);
        self::assertEquals(new SearchGroup($notes->key, 1, [new SearchHit($note, null, $body->key, 'Paint it RED')]), $noteGroup);

        // At most ten hits per table, with the full total.
        $all = $records->search('tool');
        // The hall is found too, through the title of the item it stores.
        self::assertSame([$this->items->key, $this->rooms->key], array_map(static fn (SearchGroup $g): string => $g->tableKey, $all));
        self::assertEquals(new SearchHit($hall, 'Red hall', $this->f['stores']->key, 'Tool 12'), $all[1]->hits[0]);
        self::assertSame(12, $all[0]->total);
        self::assertCount(Records::SEARCH_HITS_PER_TABLE, $all[0]->hits);
        self::assertSame('Tool 01', $all[0]->hits[0]->title);
        self::assertSame('Tool 10', $all[0]->hits[9]->title);
        self::assertSame([], $records->search('nothing matches this'));
    }

    // ------------------------------------------------------------ T-REC-15

    public function testNamesFromPeopleNeverReachSql(): void
    {
        $editor = $this->editor();
        $hostile = '"; DROP TABLE x; --';
        $table = $editor->createTable($hostile, '%_\\', true);
        $wild = $editor->addField($table->key, '%_\\', 'text', [], false, '');
        $quote = $editor->addField($table->key, "it's \"quoted\"", 'choice', ['choices' => [$hostile, "Robert'); DROP TABLE _tiffin_meta;--", '%_\\']], false, '');
        $flag = $editor->addField($table->key, '?', 'checkbox', [], false, '');
        $link = $editor->addRelation($table->key, '?) OR 1=1 --', $this->items->key, Cardinality::Many, Cardinality::Many, ':name', false, '');
        $title = $this->schema()->table($table->key)->titleFieldKey;
        self::assertNotNull($title);
        $records = $this->records();

        $target = $this->item(['name' => "Robert'); DROP TABLE _tiffin_meta;--"]);
        $a = $records->create($table->key, [$title => $hostile, $wild->key => '%_\\', $quote->key => $hostile, $flag->key => '1'], InputMode::Form, self::USER_KEY);
        $b = $records->create($table->key, [$title => '?', $quote->key => '%_\\'], InputMode::Form, self::USER_KEY);
        $records->setLinks($link->key, $a, [$target], self::USER_KEY);
        $version = $records->get($table->key, $b)?->version;
        $records->update($table->key, $b, [$wild->key => '?, ?'], InputMode::Form, (string) $version, self::USER_KEY);

        foreach ($records->sorts($table->key) as $sort) {
            self::assertCount(2, $records->page($table->key, new ListQuery($sort, null, null, 1))->records, $sort->token());
        }
        $query = $records->query($table->key, $quote->key . '.desc', $quote->key, 'is', [$hostile], 'DROP TABLE', 1);
        self::assertNull($query->filterProblem);
        self::assertSame([$a], array_map(static fn (RecordData $r): int => $r->id, $records->page($table->key, $query->query)->records));
        self::assertSame(1, $records->position($table->key, $query->query, $a)?->index);
        $byLink = $records->query($table->key, null, $link->key, 'links-to', [(string) $target], '_tiffin_meta', 1);
        self::assertSame([$a], array_map(static fn (RecordData $r): int => $r->id, $records->page($table->key, $byLink->query)->records));
        self::assertSame([$b], array_map(static fn (RecordData $r): int => $r->id, $records->page($table->key, $records->query($table->key, null, null, null, [], '?, ?', 1)->query)->records));
        $groups = $records->search('%_\\');
        self::assertSame([$table->key], array_map(static fn (SearchGroup $g): string => $g->tableKey, $groups));
        self::assertSame(2, $groups[0]->total);
        self::assertSame(['_tiffin_meta', '"; DROP TABLE x; --'], [$this->db->value('SELECT "name" FROM "sqlite_master" WHERE "name" = \'_tiffin_meta\''), $this->schema()->table($table->key)->name]);

        foreach (array_keys($this->masterNames()) as $object) {
            self::assertMatchesRegularExpression(self::MASTER_NAME, $object);
        }
        self::assertSame('ok', $this->db->value('PRAGMA integrity_check'));
        self::assertSame("Robert'); DROP TABLE _tiffin_meta;--", $this->rowOf($this->items, $target)[Identifier::columnName($this->f['name']->key)]);
    }
}
