<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\LinkedRecord;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\Position;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Schema\Cardinality;

require_once __DIR__ . '/RecordsFixture.php';

/** T-REC-8, T-REC-9, T-REC-11, T-REC-12: sorting, filtering, pages, and positions. */
#[CoversClass(Records::class)]
#[CoversClass(ListSql::class)]
#[CoversClass(Sort::class)]
#[CoversClass(Condition::class)]
#[CoversClass(ListQuery::class)]
#[CoversClass(RuleSet::class)]
final class RecordsListTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-records-list-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return list<int> ids of every live record in the query's order */
    private function ids(ListQuery $query, ?string $tableKey = null): array
    {
        $page = $this->records()->page($tableKey ?? $this->items->key, $query);

        return array_map(static fn (RecordData $r): int => $r->id, $page->records);
    }

    private function sorted(string $by, bool $descending): ListQuery
    {
        return new ListQuery(new Sort($by, $descending), null, null, 1);
    }

    // ------------------------------------------------------------ T-REC-8

    /** @return iterable<string, array{string, list<string>, string}> short field name, form texts, comparison */
    public static function sortCases(): iterable
    {
        $words = ['banana', 'Apple', '', 'cherry', 'apple', 'Banana', '', 'Zed', 'zed'];
        yield 'text' => ['text', $words, 'nocase'];
        yield 'long_text' => ['long_text', $words, 'nocase'];
        yield 'url' => ['url', ['https://b.example/', 'https://A.example/', '', 'https://a.example/', 'https://c.example/'], 'nocase'];
        yield 'email' => ['email', ['b@example.com', 'A@example.com', '', 'a@example.com'], 'nocase'];
        yield 'phone' => ['phone', ['555-0102', '555-0100', '', '555-0101', '555-0100'], 'nocase'];
        yield 'number' => ['number', ['10', '2', '', '-3', '2', '0.5', '2.25'], 'number'];
        yield 'currency' => ['currency', ['1.00', '0.10', '', '-2', '1'], 'number'];
        yield 'rating' => ['rating', ['3', '1', '', '5', '3'], 'number'];
        yield 'duration' => ['duration', ['1:00', '0:30', '', '2:00', '0:30'], 'number'];
        yield 'date' => ['date', ['2026-03-01', '2025-12-31', '', '2026-01-15', '2025-12-31'], 'string'];
        yield 'time' => ['time', ['09:30', '23:00', '', '00:15', '09:30'], 'string'];
        yield 'date_time' => ['date_time', ['2026-03-01 09:00', '2026-03-01 08:59', '', '2025-01-01 00:00', '2026-03-01 09:00'], 'string'];
        yield 'checkbox' => ['checkbox', ['1', '', '0', '1', ''], 'bool'];
        yield 'choice' => ['choice', ['Blue', 'Red', '', 'Green', 'Red', 'Blue'], 'choice'];
    }

    /** @param list<string> $texts */
    #[DataProvider('sortCases')]
    public function testSortOrdersEveryTypeWithEmptiesLastAndTiesById(string $short, array $texts, string $comparison): void
    {
        $field = $this->f[$short];
        $values = [];
        foreach ($texts as $text) {
            $id = $this->item([$short => $text]);
            $values[$id] = $this->records()->get($this->items->key, $id)?->values[$field->key];
        }
        $this->assertOrders($field->key, $values, $comparison);
    }

    public function testSortByAutoNumber(): void
    {
        $values = [];
        // Auto-numbers are unique (index x_..._u), so there are no ties to break.
        foreach ([5, null, 3, 7, null, 12] as $number) {
            $id = $this->insert($this->items->key, $number === null ? [] : [$this->f['auto']->key => $number]);
            $values[$id] = $number;
        }
        $this->assertOrders($this->f['auto']->key, $values, 'number');
    }

    /** @param array<int, mixed> $values id => canonical value */
    private function assertOrders(string $fieldKey, array $values, string $comparison): void
    {
        $labels = ['Red', 'Green', 'Blue'];
        $compare = static function (mixed $a, mixed $b) use ($comparison, $labels): int {
            return match ($comparison) {
                'nocase' => strcmp(strtolower((string) $a), strtolower((string) $b)),
                'number' => $a <=> $b,
                'string' => strcmp((string) $a, (string) $b),
                'bool' => (int) $a <=> (int) $b,
                'choice' => array_search($a, $labels, true) <=> array_search($b, $labels, true),
            };
        };
        foreach ([false, true] as $descending) {
            $filled = array_filter($values, static fn (mixed $v): bool => $v !== null);
            $empty = array_keys(array_filter($values, static fn (mixed $v): bool => $v === null));
            $ids = array_keys($filled);
            usort($ids, static function (int $x, int $y) use ($filled, $compare, $descending): int {
                $c = $compare($filled[$x], $filled[$y]);

                return ($descending ? -$c : $c) ?: $x <=> $y;
            });
            sort($empty);
            self::assertSame([...$ids, ...$empty], $this->ids($this->sorted($fieldKey, $descending)), $descending ? 'descending' : 'ascending');
        }
    }

    public function testSortByRelationUsesTheLowestLiveLinkedTitle(): void
    {
        $records = $this->records();
        $b = $this->room('beta');
        $a = $this->room('Alpha');
        $c = $this->room('alpha');
        $z = $this->room('Zulu');
        $gone = $this->room('Aardvark');
        $one = $this->item(['name' => 'one']);
        $two = $this->item(['name' => 'two']);
        $three = $this->item(['name' => 'three']);
        $four = $this->item(['name' => 'four']);
        $five = $this->item(['name' => 'five']);
        $records->setLinks($this->f['stored']->key, $one, [$z, $b], self::USER_KEY);   // lowest: beta
        $records->setLinks($this->f['stored']->key, $two, [$a], self::USER_KEY);       // Alpha
        $records->setLinks($this->f['stored']->key, $three, [$gone], self::USER_KEY);  // only a trashed room: empty
        $records->setLinks($this->f['stored']->key, $five, [$c, $z], self::USER_KEY);  // alpha (ties Alpha ignoring case)
        $this->trashRecord($this->rooms, $gone);

        self::assertSame([$two, $five, $one, $three, $four], $this->ids($this->sorted($this->f['stored']->key, false)));
        self::assertSame([$one, $two, $five, $three, $four], $this->ids($this->sorted($this->f['stored']->key, true)));

        // A linked table with a number title sorts numerically.
        $editor = $this->editor();
        $boxes = $editor->createTable('Boxes', 'box', false);
        $size = $editor->addField($boxes->key, 'Size', 'number', [], false, '');
        $editor->updateTable($boxes->key, 'Boxes', 'box', $size->key);
        $inBox = $editor->addRelation($this->items->key, 'In box', $boxes->key, Cardinality::One, Cardinality::Many, 'Holds', false, '');
        $records = $this->records();
        $small = $records->create($boxes->key, [$size->key => '9'], InputMode::Form, self::USER_KEY);
        $large = $records->create($boxes->key, [$size->key => '10'], InputMode::Form, self::USER_KEY);
        $records->setLinks($inBox->key, $one, [$large], self::USER_KEY);
        $records->setLinks($inBox->key, $two, [$small], self::USER_KEY);
        self::assertSame([$two, $one, $three, $four, $five], $this->ids($this->sorted($inBox->key, false)));
    }

    public function testAddedChangedDefaultAndMenu(): void
    {
        $records = $this->records();
        $first = $this->item(['name' => 'b']);
        $this->clock->advance(10);
        $second = $this->item(['name' => 'A']);
        $this->clock->advance(10);
        $third = $this->item(['name' => 'c']);
        $this->clock->advance(10);
        $version = $records->get($this->items->key, $first)?->version;
        $records->update($this->items->key, $first, [$this->f['text']->key => 'x'], InputMode::Form, (string) $version, self::USER_KEY);

        self::assertSame([$first, $second, $third], $this->ids($this->sorted('added', false)));
        self::assertSame([$third, $second, $first], $this->ids($this->sorted('added', true)));
        self::assertSame([$first, $third, $second], $this->ids($this->sorted('changed', true)));

        // The default: title A to Z; an unknown or unoffered token gives it too.
        foreach ([null, 'nonsense', 'changed.asc', $this->f['files']->key . '.asc', $this->f['room_name']->key . '.asc', 'added.ASC'] as $token) {
            $query = $records->query($this->items->key, $token, null, null, [], null, 1)->query;
            self::assertSame($this->f['name']->key . '.asc', $query->sort->token(), (string) $token);
        }
        self::assertSame([$second, $first, $third], $this->ids($records->query($this->items->key, null, null, null, [], null, 1)->query));
        self::assertSame('added.desc', $records->query($this->items->key, 'added.desc', null, null, [], null, 1)->query->sort->token());
        // Without a title field: the order added.
        $editor = $this->editor();
        $plain = $editor->createTable('Plain', 'plain', false);
        self::assertSame('added.asc', $this->records()->query($plain->key, null, null, null, [], null, 1)->query->sort->token());

        // Menu order: each sortable field in field order (attachment left out), then added and changed.
        $expected = [];
        foreach (['name', 'text', 'long_text', 'url', 'email', 'phone', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice', 'auto', 'room', 'stored', 'parts', 'part_of'] as $short) {
            $expected[] = $this->f[$short]->key . '.asc';
            $expected[] = $this->f[$short]->key . '.desc';
        }
        $expected = [...$expected, 'added.desc', 'added.asc', 'changed.desc'];
        self::assertSame($expected, array_map(static fn (Sort $s): string => $s->token(), $records->sorts($this->items->key)));
        // A relation to a table without a title field does not sort.
        $editor->addRelation($this->rooms->key, 'Plain thing', $plain->key, Cardinality::Many, Cardinality::Many, 'Rooms of', false, '');
        $roomTokens = array_map(static fn (Sort $s): string => $s->token(), $this->records()->sorts($this->rooms->key));
        self::assertSame(
            [
                $this->f['room_name']->key . '.asc', $this->f['room_name']->key . '.desc',
                $this->f['room_items']->key . '.asc', $this->f['room_items']->key . '.desc',
                $this->f['stores']->key . '.asc', $this->f['stores']->key . '.desc',
                'added.desc', 'added.asc', 'changed.desc',
            ],
            $roomTokens,
        );
    }

    public function testPickListAndTitles(): void
    {
        $records = $this->records();
        $b = $this->room('beta');
        $a = $this->room('Alpha');
        $gone = $this->room('Aardvark');
        $c = $this->room('alpha');
        $empty = $records->create($this->rooms->key, [], InputMode::Form, self::USER_KEY);
        $this->trashRecord($this->rooms, $gone);

        $list = $records->pickList($this->rooms->key, Records::PICKER_LIMIT);
        self::assertSame([$a, $c, $b, $empty], array_map(static fn (LinkedRecord $l): int => $l->id, $list), 'Live records only, by title ignoring case, empties last.');
        self::assertEquals(new LinkedRecord($this->rooms->key, $a, 'Alpha'), $list[0]);
        self::assertNull($list[3]->title);
        self::assertSame([$a, $c], array_map(static fn (LinkedRecord $l): int => $l->id, $records->pickList($this->rooms->key, 2)));

        // Without a title field: live records by id, titles null.
        $plain = $this->editor()->createTable('Plain', 'plain', false);
        $records = $this->records();
        $p1 = $records->create($plain->key, [], InputMode::Form, self::USER_KEY);
        $p2 = $records->create($plain->key, [], InputMode::Form, self::USER_KEY);
        $p3 = $records->create($plain->key, [], InputMode::Form, self::USER_KEY);
        $this->trashRecord($plain, $p2);
        self::assertEquals([new LinkedRecord($plain->key, $p1, null), new LinkedRecord($plain->key, $p3, null)], $records->pickList($plain->key, 10));
        foreach ([0, Records::PICKER_LIMIT + 1] as $bad) {
            try {
                $records->pickList($plain->key, $bad);
                self::fail((string) $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Titles of live and trashed records, in the given order; absent ids left out.
        self::assertSame([$gone => 'Aardvark', $b => 'beta', $empty => null], $records->titles($this->rooms->key, [$gone, 999, $b, $empty, $b]));
        self::assertSame([$p2 => null], $records->titles($plain->key, [$p2]));
    }

    public function testRecordIdsFromTextStayWithinRange(): void
    {
        self::assertSame(PHP_INT_MAX, ListSql::recordId('9223372036854775807'));
        self::assertSame(1, ListSql::recordId('1'));
        // One past the largest integer would saturate to PHP_INT_MAX in a cast; it is refused instead.
        foreach (['9223372036854775808', '9999999999999999999', '10000000000000000000', '0', '01', '-1', '1e3', ' 1', ''] as $bad) {
            try {
                ListSql::recordId($bad);
                self::fail($bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSortValueObjects(): void
    {
        self::assertSame('added.desc', (new Sort('added', true))->token());
        foreach ([['changed', false], ['Name', false], ['', true]] as [$by, $descending]) {
            try {
                new Sort($by, $descending);
                self::fail($by);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new ListQuery(new Sort('added', false), null, null, 0);
    }

    // ------------------------------------------------------------ T-REC-9

    public function testEveryFilterReturnsExactlyTheExpectedRecords(): void
    {
        $records = $this->records();
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $gone = $this->room('Gone');
        $a = $this->item(['name' => 'a', 'choice' => 'Green', 'checkbox' => '1', 'date' => '2026-01-01', 'date_time' => '2026-01-01 00:00']);
        $b = $this->item(['name' => 'b', 'choice' => 'Red', 'date' => '2026-01-31', 'date_time' => '2026-01-31 23:59']);
        $c = $this->item(['name' => 'c', 'checkbox' => '1', 'date' => '2026-02-01', 'date_time' => '2026-02-01 00:00']);
        $d = $this->item(['name' => 'd', 'choice' => 'Green']);
        $records->setLinks($this->f['stored']->key, $a, [$hall, $attic], self::USER_KEY);
        $records->setLinks($this->f['stored']->key, $b, [$attic], self::USER_KEY);
        $records->setLinks($this->f['stored']->key, $c, [$gone], self::USER_KEY);
        $this->trashRecord($this->rooms, $gone);
        $trashed = $this->item(['name' => 'e', 'choice' => 'Green', 'checkbox' => '1']);
        $this->trashRecord($this->items, $trashed);

        $cases = [
            ['choice', 'is', ['Green'], [$a, $d]],
            ['choice', 'empty', [], [$c]],
            ['date', 'empty', [], [$d]],
            ['date_time', 'empty', [], [$d]],
            ['checkbox', 'is', ['1'], [$a, $c]],
            ['checkbox', 'is', ['0'], [$b, $d]],
            ['stored', 'links-to', [(string) $attic], [$a, $b]],
            ['stored', 'links-to', [(string) $hall], [$a]],
            ['stored', 'empty', [], [$c, $d]],
            ['date', 'between', ['2026-01-01', '2026-01-31'], [$a, $b]],
            ['date', 'between', ['2026-01-31', ''], [$b, $c]],
            ['date', 'between', ['', '2026-01-01'], [$a]],
            ['date_time', 'between', ['2026-01-01', '2026-01-31'], [$a, $b]],
            ['date_time', 'between', ['2026-02-01', ''], [$c]],
            ['date_time', 'between', ['', '2026-01-31'], [$a, $b]],
            ['date', 'between', [' 2026-01-31 ', '2026-01-31'], [$b]],
        ];
        foreach ($cases as [$short, $operator, $operands, $expected]) {
            $normalised = $records->query($this->items->key, 'added.asc', $this->f[$short]->key, $operator, $operands, null, 1);
            self::assertNull($normalised->filterProblem, $short . ' ' . $operator);
            self::assertNotNull($normalised->query->filter);
            self::assertSame($this->f[$short]->key, $normalised->query->filter->fieldKey);
            self::assertSame($operator, $normalised->query->filter->operator->value);
            self::assertSame($expected, $this->ids($normalised->query), $short . ' ' . $operator . ' ' . implode(',', $operands));
        }
        // Operands are normalised: dates canonical, the other end empty.
        $normalised = $records->query($this->items->key, null, $this->f['date']->key, 'between', [' 2026-01-05 '], null, 1);
        self::assertSame(['2026-01-05', ''], $normalised->query->filter?->operands);
    }

    public function testRefusedFiltersAreReportedAndDropped(): void
    {
        $records = $this->records();
        $hall = $this->room('Hall');
        $gone = $this->room('Gone');
        $this->trashRecord($this->rooms, $gone);
        $a = $this->item(['name' => 'a']);
        $cases = [
            ['zzzzzzzzzzzz', 'is', ['1'], 'problem.filter.invalid'],
            ['"; DROP TABLE x; --', 'is', ['1'], 'problem.filter.invalid'],
            [$this->f['room_name']->key, 'is', ['x'], 'problem.filter.invalid'],
            [$this->f['text']->key, 'is', ['x'], 'problem.filter.invalid'],
            [$this->f['files']->key, 'empty', [], 'problem.filter.invalid'],
            [$this->f['checkbox']->key, 'empty', [], 'problem.filter.invalid'],
            [$this->f['choice']->key, 'between', ['a', 'b'], 'problem.filter.invalid'],
            [$this->f['choice']->key, 'IS', ['Red'], 'problem.filter.invalid'],
            [$this->f['choice']->key, null, ['Red'], 'problem.filter.invalid'],
            [$this->f['choice']->key, 'is', [], 'problem.filter.value'],
            [$this->f['choice']->key, 'is', [''], 'problem.filter.value'],
            [$this->f['choice']->key, 'is', [['Red']], 'problem.filter.value'],
            [$this->f['choice']->key, 'is', ['Purple'], 'problem.filter.value'],
            [$this->f['choice']->key, 'is', ['red'], 'problem.filter.value'],
            [$this->f['choice']->key, 'is', [' Red'], 'problem.filter.value'],
            [$this->f['checkbox']->key, 'is', ['yes'], 'problem.filter.value'],
            [$this->f['checkbox']->key, 'is', [''], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', [], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', ['999'], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', [(string) $gone], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', ['0'], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', ['1e3'], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', ['99999999999999999999'], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', ['9999999999999999999'], 'problem.filter.value'],
            [$this->f['stored']->key, 'links-to', [(string) $hall . ' '], 'problem.filter.value'],
            [$this->f['date']->key, 'between', [], 'problem.filter.value'],
            [$this->f['date']->key, 'between', ['', ''], 'problem.filter.value'],
            [$this->f['date']->key, 'between', ['2026-02-30', ''], 'problem.date.invalid'],
            [$this->f['date_time']->key, 'between', ['', 'tomorrow'], 'problem.date.invalid'],
            [$this->f['date_time']->key, 'between', ['2026-01-01 10:00', ''], 'problem.date.invalid'],
            [$this->f['date']->key, 'between', ['2026-02-01', '2026-01-31'], 'problem.filter.range'],
        ];
        foreach ($cases as [$fieldKey, $operator, $operands, $key]) {
            $normalised = $records->query($this->items->key, null, $fieldKey, $operator, $operands, null, 1);
            self::assertEquals(new Problem(null, $key), $normalised->filterProblem, $fieldKey . ' ' . $operator . ' ' . json_encode($operands));
            self::assertNull($normalised->query->filter);
            self::assertSame([$a], $this->ids($normalised->query));
        }
        // No filter field: no filter and no problem, whatever else is given.
        $normalised = $records->query($this->items->key, null, null, 'is', ['x'], null, 1);
        self::assertNull($normalised->filterProblem);
        self::assertNull($normalised->query->filter);
    }

    public function testInvalidConditionsAreRefusedWhenListed(): void
    {
        $records = $this->records();
        foreach ([
            new Condition($this->f['text']->key, Operator::Is, ['x']),
            new Condition($this->f['checkbox']->key, Operator::Is, ['2']),
            new Condition($this->f['date']->key, Operator::Between, ['', '']),
            new Condition($this->f['date']->key, Operator::Between, ['2026-01-01\'', '']),
            new Condition($this->f['stored']->key, Operator::LinksTo, ['1 OR 1']),
            new Condition($this->f['choice']->key, Operator::Is, []),
        ] as $condition) {
            try {
                $records->page($this->items->key, new ListQuery(new Sort('added', false), $condition, null, 1));
                self::fail(json_encode($condition->operands) ?: '');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            $records->page($this->items->key, new ListQuery(new Sort($this->f['files']->key, false), null, null, 1));
            self::fail('Attachment sort accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new Condition('x', Operator::Is, []);
    }

    // ------------------------------------------------------------ T-REC-11

    public function testPagesOfFifty(): void
    {
        $records = $this->records();
        $hall = $this->room('Hall');
        for ($i = 1; $i <= 120; $i++) {
            $this->insert($this->items->key, [$this->f['name']->key => sprintf('Item %03d', $i), $this->f['checkbox']->key => $i % 3 === 0 ? 1 : 0]);
        }
        $records->setLinks($this->f['stored']->key, 2, [$hall], self::USER_KEY);
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 5, ?, ?, ?)', [str_repeat('a', 64), 'text/plain', 'note.txt', self::START]);
        $this->db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, 3, 1, 1)', [$this->f['files']->key]);

        $sizes = [];
        foreach ([1, 2, 3] as $number) {
            $page = $records->page($this->items->key, new ListQuery(new Sort('added', false), null, null, $number));
            $sizes[] = count($page->records);
            self::assertSame([120, 120, 3, $number], [$page->total, $page->tableTotal, $page->pages, $page->page]);
        }
        self::assertSame([50, 50, 20], $sizes);
        $page = $records->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 9));
        self::assertSame(3, $page->page);
        self::assertSame(101, $page->records[0]->id);
        self::assertSame('added.asc', $page->sort->token());

        $first = $records->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1));
        self::assertSame('Hall', $first->records[1]->links[$this->f['stored']->key][0]->title);
        self::assertSame('note.txt', $first->records[2]->attachments[$this->f['files']->key][0]->originalName);
        self::assertSame([], $first->records[0]->links[$this->f['stored']->key]);

        // A filter: total counts matches, tableTotal the table.
        $filtered = $records->page($this->items->key, new ListQuery(new Sort('added', true), new Condition($this->f['checkbox']->key, Operator::Is, ['1']), null, 1));
        self::assertSame([40, 120, 1, 40], [$filtered->total, $filtered->tableTotal, $filtered->pages, count($filtered->records)]);
        self::assertSame(120, $filtered->records[0]->id);

        // Nothing matches: one empty page.
        $none = $records->page($this->items->key, new ListQuery(new Sort('added', false), null, 'no such text', 4));
        self::assertSame([0, 120, 1, 1, []], [$none->total, $none->tableTotal, $none->pages, $none->page, $none->records]);
        self::assertSame(1, $records->query($this->items->key, null, null, null, [], null, -5)->query->page);
    }

    // ------------------------------------------------------------ T-REC-12

    public function testPositionInTheList(): void
    {
        $records = $this->records();
        $ids = [];
        foreach (['Lamp b', 'lamp a', 'Kettle', 'LAMP C', 'Lamp d', 'lamp e'] as $i => $name) {
            $ids[$name] = $this->item(['name' => $name, 'checkbox' => $i === 4 ? '0' : '1']);
        }
        $query = new ListQuery(new Sort($this->f['name']->key, true), new Condition($this->f['checkbox']->key, Operator::Is, ['1']), 'lamp', 1);
        // Matching: lamp e, LAMP C, Lamp b, lamp a (descending by name ignoring case; Lamp d unticked; Kettle no match).
        self::assertEquals(new Position(1, 4, null, $ids['LAMP C']), $records->position($this->items->key, $query, $ids['lamp e']));
        self::assertEquals(new Position(2, 4, $ids['lamp e'], $ids['Lamp b']), $records->position($this->items->key, $query, $ids['LAMP C']));
        self::assertEquals(new Position(4, 4, $ids['Lamp b'], null), $records->position($this->items->key, $query, $ids['lamp a']));
        self::assertNull($records->position($this->items->key, $query, $ids['Kettle']));
        self::assertNull($records->position($this->items->key, $query, $ids['Lamp d']));
        self::assertNull($records->position($this->items->key, $query, 999));
        $this->trashRecord($this->items, $ids['LAMP C']);
        self::assertNull($records->position($this->items->key, $query, $ids['LAMP C']));
        self::assertEquals(new Position(2, 3, $ids['lamp e'], $ids['lamp a']), $records->position($this->items->key, $query, $ids['Lamp b']));

        // A choice sort binds its labels; positions follow the same order as the page.
        $order = $this->ids(new ListQuery(new Sort($this->f['choice']->key, false), null, null, 1));
        foreach ($order as $index => $id) {
            $position = $records->position($this->items->key, new ListQuery(new Sort($this->f['choice']->key, false), null, null, 1), $id);
            self::assertSame($index + 1, $position?->index);
        }
    }

    // ------------------------------------------------------------ T-RUL-12 (M4 spec section 12.3)

    public function testPageAndPositionHonourCollectionRules(): void
    {
        $records = $this->records();
        $ids = [];
        foreach (['Lamp b' => '1', 'lamp a' => '0', 'Kettle' => '1', 'Mug' => '0', 'Lamp d' => '0', 'Clock' => '1'] as $name => $ticked) {
            $ids[$name] = $this->item(['name' => $name, 'checkbox' => $ticked, 'number' => (string) strlen($name)]);
        }
        $rules = new RuleSet(MatchMode::Any, [
            new Condition($this->f['checkbox']->key, Operator::Is, ['1']),
            new Condition($this->f['name']->key, Operator::Contains, ['lamp']),
        ]);
        // In the collection: Lamp b, lamp a, Kettle, Lamp d, Clock; by name descending: Lamp d, Lamp b, lamp a, Kettle, Clock.
        $query = new ListQuery(new Sort($this->f['name']->key, true), null, null, 1, $rules);
        $page = $records->page($this->items->key, $query);
        self::assertSame([5, 6, 1, 1], [$page->total, $page->tableTotal, $page->pages, $page->page]);
        self::assertSame([$ids['Lamp d'], $ids['Lamp b'], $ids['lamp a'], $ids['Kettle'], $ids['Clock']], $this->ids($query));
        self::assertEquals(new Position(1, 5, null, $ids['Lamp b']), $records->position($this->items->key, $query, $ids['Lamp d']));
        self::assertEquals(new Position(3, 5, $ids['Lamp b'], $ids['Kettle']), $records->position($this->items->key, $query, $ids['lamp a']));
        self::assertEquals(new Position(5, 5, $ids['Kettle'], null), $records->position($this->items->key, $query, $ids['Clock']));
        self::assertNull($records->position($this->items->key, $query, $ids['Mug']));

        // All of the rules, with the list's search on top.
        $all = new ListQuery(new Sort('added', false), null, 'lamp', 1, new RuleSet(MatchMode::All, [
            new Condition($this->f['number']->key, Operator::More, ['5']),
            new Condition($this->f['checkbox']->key, Operator::Is, ['0']),
        ]));
        self::assertSame([$ids['lamp a'], $ids['Lamp d']], $this->ids($all));
        self::assertEquals(new Position(2, 2, $ids['lamp a'], null), $records->position($this->items->key, $all, $ids['Lamp d']));
        self::assertNull($records->position($this->items->key, $all, $ids['Lamp b']));
        $this->trashRecord($this->items, $ids['lamp a']);
        self::assertEquals(new Position(1, 1, null, null), $records->position($this->items->key, $all, $ids['Lamp d']));
        self::assertNull($records->position($this->items->key, $all, $ids['lamp a']));
    }
}
