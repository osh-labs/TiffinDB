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
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Tests\Core\Schema\SqlStringsTest;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/RecordsFixture.php';
require_once __DIR__ . '/../schema/SqlStringsTest.php';

/**
 * T-RUL-1 to T-RUL-8 and T-RUL-10 (`docs/specs/M4-core.md` sections 5
 * and 12.3): every rule operator on every type, all and any, composition
 * with the list filter and search, hostile operands, and refused
 * conditions. All data is synthetic.
 */
#[CoversClass(ListSql::class)]
#[CoversClass(ListQuery::class)]
#[CoversClass(RuleSet::class)]
#[CoversClass(Operator::class)]
#[CoversClass(MatchMode::class)]
final class RuleSqlTest extends TestCase
{
    use RecordsFixture;

    /** @var array<string, int> short name => record id */
    private array $r = [];

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-rule-sql-test-1');
        $records = $this->records();
        $hall = $this->room('Hall');
        $attic = $this->room('Attic');
        $this->r['hall'] = $hall;
        $this->r['attic'] = $attic;
        $this->r['a'] = $this->item([
            'name' => 'a', 'text' => 'Blue Lamp', 'long_text' => 'Long note', 'url' => 'https://example.com/a', 'number' => '10', 'currency' => '3.50',
            'rating' => '2', 'duration' => '1:30', 'date' => '2026-01-15', 'date_time' => '2026-01-15 08:00', 'checkbox' => '1', 'choice' => 'Red',
        ]);
        $this->r['b'] = $this->item([
            'name' => 'b', 'text' => 'lampshade', 'email' => 'user@example.com', 'number' => '2.5', 'currency' => '12.00', 'rating' => '5',
            'duration' => '0:45', 'date' => '2026-02-01', 'date_time' => '2026-02-01 23:59', 'checkbox' => '0', 'choice' => 'Green',
        ]);
        $this->r['c'] = $this->item([
            'name' => 'c', 'text' => 'Chair', 'long_text' => 'another NOTE', 'phone' => '555-0101', 'number' => '-1', 'currency' => '0.10',
            'rating' => '1', 'duration' => '2:00', 'date' => '2025-12-31', 'date_time' => '2025-12-31 00:00', 'choice' => 'Blue',
        ]);
        $this->r['d'] = $this->item(['name' => 'd']);
        // A trashed record that would match almost every rule: never listed.
        $trashed = $this->item([
            'name' => 'e', 'text' => 'Blue Lamp', 'long_text' => 'Long note', 'number' => '10', 'currency' => '3.50', 'rating' => '2', 'duration' => '1:30',
            'date' => '2026-01-15', 'date_time' => '2026-01-15 08:00', 'checkbox' => '1', 'choice' => 'Red',
        ]);
        $records->setLinks($this->f['stored']->key, $this->r['a'], [$hall], self::USER_KEY);
        $records->setLinks($this->f['stored']->key, $this->r['b'], [$attic, $hall], self::USER_KEY);
        $records->setLinks($this->f['stored']->key, $trashed, [$hall], self::USER_KEY);
        $records->setLinks($this->f['room']->key, $this->r['a'], [$hall], self::USER_KEY);
        $records->setLinks($this->f['room']->key, $this->r['c'], [$attic], self::USER_KEY);
        $this->trashRecord($this->items, $trashed);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function cond(string $short, string $op, array $operands = []): Condition
    {
        return new Condition($this->f[$short]->key, Operator::from($op), $operands);
    }

    /**
     * @param list<Condition> $conditions
     * @return list<string> short names of the listed records, in the order added
     */
    private function listed(MatchMode $match, array $conditions, ?Condition $filter = null, ?string $search = null): array
    {
        $query = new ListQuery(new Sort('added', false), $filter, $search, 1, $conditions === [] ? null : new RuleSet($match, $conditions));
        $byId = array_flip($this->r);

        return array_map(static fn (RecordData $record): string => $byId[$record->id], $this->records()->page($this->items->key, $query)->records);
    }

    // ------------------------------------------------------------ T-RUL-1, T-RUL-4

    public function testEveryOperatorOnEveryType(): void
    {
        $hall = (string) $this->r['hall'];
        $attic = (string) $this->r['attic'];
        $cases = [
            ['text', 'contains', ['lamp'], ['a', 'b']],
            ['text', 'contains', ['LAMP'], ['a', 'b']],
            ['text', 'is', ['blue lamp'], ['a']],
            ['text', 'is', ['Blue'], []],
            ['text', 'empty', [], ['d']],
            ['text', 'not-empty', [], ['a', 'b', 'c']],
            ['long_text', 'contains', ['note'], ['a', 'c']],
            ['long_text', 'is', ['LONG NOTE'], ['a']],
            ['long_text', 'empty', [], ['b', 'd']],
            ['long_text', 'not-empty', [], ['a', 'c']],
            ['url', 'contains', ['example.com'], ['a']],
            ['url', 'is', ['HTTPS://EXAMPLE.COM/A'], ['a']],
            ['url', 'empty', [], ['b', 'c', 'd']],
            ['url', 'not-empty', [], ['a']],
            ['email', 'contains', ['@example'], ['b']],
            ['email', 'is', ['USER@example.com'], ['b']],
            ['email', 'empty', [], ['a', 'c', 'd']],
            ['email', 'not-empty', [], ['b']],
            ['phone', 'contains', ['0101'], ['c']],
            ['phone', 'is', ['555-0101'], ['c']],
            ['phone', 'empty', [], ['a', 'b', 'd']],
            ['phone', 'not-empty', [], ['c']],
            ['number', 'is', ['10'], ['a']],
            ['number', 'is', ['10.00'], ['a']],
            ['number', 'is', ['2.5'], ['b']],
            ['number', 'less', ['3'], ['b', 'c']],
            ['number', 'less', ['-1'], []],
            ['number', 'more', ['2.5'], ['a']],
            ['number', 'more', ['-100'], ['a', 'b', 'c']],
            ['number', 'empty', [], ['d']],
            // Currency compares minor units: 3.50 is 350.
            ['currency', 'is', ['3.50'], ['a']],
            ['currency', 'is', ['3.5'], ['a']],
            ['currency', 'more', ['3.50'], ['b']],
            ['currency', 'less', ['1'], ['c']],
            ['currency', 'less', ['12.01'], ['a', 'b', 'c']],
            ['currency', 'empty', [], ['d']],
            ['rating', 'is', ['5'], ['b']],
            ['rating', 'less', ['2'], ['c']],
            ['rating', 'more', ['1'], ['a', 'b']],
            ['rating', 'empty', [], ['d']],
            // Duration compares seconds: 1:30:00 is 5400, as is 90 (minutes).
            ['duration', 'is', ['1:30:00'], ['a']],
            ['duration', 'is', ['90'], ['a']],
            ['duration', 'less', ['1:00'], ['b']],
            ['duration', 'more', ['1:30'], ['c']],
            ['duration', 'empty', [], ['d']],
            ['date', 'on', ['2026-01-15'], ['a']],
            ['date', 'before', ['2026-01-15'], ['c']],
            ['date', 'after', ['2026-01-15'], ['b']],
            ['date', 'after', ['2026-02-01'], []],
            ['date', 'empty', [], ['d']],
            // A date-time is on a day from 00:00:00 to 23:59:59; before and after exclude the day.
            ['date_time', 'on', ['2026-02-01'], ['b']],
            ['date_time', 'on', ['2025-12-31'], ['c']],
            ['date_time', 'on', ['2026-01-15'], ['a']],
            ['date_time', 'before', ['2026-01-15'], ['c']],
            ['date_time', 'before', ['2025-12-31'], []],
            ['date_time', 'after', ['2026-01-15'], ['b']],
            ['date_time', 'after', ['2026-02-01'], []],
            ['date_time', 'empty', [], ['d']],
            ['choice', 'is', ['Red'], ['a']],
            ['choice', 'empty', [], ['d']],
            ['checkbox', 'is', ['1'], ['a']],
            ['checkbox', 'is', ['0'], ['b', 'c', 'd']],
            ['stored', 'links-to', [$hall], ['a', 'b']],
            ['stored', 'links-to', [$attic], ['b']],
            ['stored', 'empty', [], ['c', 'd']],
            ['room', 'links-to', [$attic], ['c']],
            ['room', 'empty', [], ['b', 'd']],
            // M2 filter operators stay usable through the same method.
            ['date', 'between', ['2026-01-01', '2026-01-31'], ['a']],
        ];
        $seen = [];
        foreach ($cases as [$short, $op, $operands, $expected]) {
            $seen[$this->f[$short]->type][$op] = true;
            foreach ([MatchMode::All, MatchMode::Any] as $match) {
                self::assertSame($expected, $this->listed($match, [$this->cond($short, $op, $operands)]), $short . ' ' . $op . ' ' . implode(',', $operands));
            }
        }
        // Every operator of section 5.2 on every type was tried.
        foreach (ListSql::RULES as $type => $operators) {
            foreach ($operators as $operator) {
                self::assertArrayHasKey($operator, $seen[$type] ?? [], $type . ' ' . $operator);
            }
        }
    }

    // ------------------------------------------------------------ T-RUL-2

    public function testAllAndAny(): void
    {
        $lamp = $this->cond('text', 'contains', ['lamp']);
        $ticked = $this->cond('checkbox', 'is', ['1']);
        $negative = $this->cond('number', 'less', ['0']);
        $onFirst = $this->cond('date_time', 'on', ['2026-02-01']);
        self::assertSame(['a', 'b', 'c', 'd'], $this->listed(MatchMode::All, []));
        self::assertSame(['a', 'b'], $this->listed(MatchMode::All, [$lamp]));
        self::assertSame(['a'], $this->listed(MatchMode::All, [$lamp, $ticked]));
        self::assertSame(['a', 'b', 'c'], $this->listed(MatchMode::Any, [$lamp, $negative]));
        self::assertSame([], $this->listed(MatchMode::All, [$lamp, $negative]));
        // A fragment holding its own AND (date-time on) stays one condition under any and under all.
        self::assertSame(['a', 'b'], $this->listed(MatchMode::Any, [$onFirst, $ticked]));
        self::assertSame(['b'], $this->listed(MatchMode::All, [$this->cond('text', 'contains', ['shade']), $onFirst]));
        self::assertSame([], $this->listed(MatchMode::All, [$ticked, $onFirst]));

        $ten = [
            $lamp, $ticked, $this->cond('number', 'more', ['5']), $this->cond('currency', 'is', ['3.50']), $this->cond('rating', 'less', ['3']),
            $this->cond('duration', 'is', ['1:30']), $this->cond('date', 'on', ['2026-01-15']), $this->cond('choice', 'is', ['Red']),
            $this->cond('stored', 'links-to', [(string) $this->r['hall']]), $this->cond('url', 'not-empty'),
        ];
        self::assertSame(['a'], $this->listed(MatchMode::All, $ten));
        self::assertSame(['a', 'b', 'c'], $this->listed(MatchMode::Any, $ten));
        $nothing = array_fill(0, 9, $this->cond('text', 'is', ['nothing like this']));
        self::assertSame(['d'], $this->listed(MatchMode::Any, [...$nothing, $this->cond('choice', 'empty')]));
        self::assertSame([], $this->listed(MatchMode::All, [...$nothing, $this->cond('choice', 'empty')]));

        // The SQL: each condition in parentheses, joined by the match word, the group in parentheses.
        $lists = new ListSql($this->schema(), $this->types);
        $col = fn (string $short): string => 'r.' . $this->f[$short]->sql();
        $where = $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::Any, [$onFirst, $ticked])));
        self::assertSame(
            'r."_trashed_at" IS NULL AND ((' . $col('date_time') . ' >= ? AND ' . $col('date_time') . ' <= ?) OR ('
            . $col('checkbox') . ' = ?))',
            $where->sql(),
        );
        self::assertSame(['2026-02-01T00:00:00', '2026-02-01T23:59:59', 1], $where->params());
        $where = $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::All, [$ticked])));
        self::assertSame('r."_trashed_at" IS NULL AND ((' . $col('checkbox') . ' = ?))', $where->sql());
    }

    // ------------------------------------------------------------ T-RUL-3

    public function testRulesFilterAndSearchAreAllRequired(): void
    {
        $any = [$this->cond('text', 'contains', ['lamp']), $this->cond('number', 'less', ['0'])];   // a, b, c
        $filter = $this->cond('choice', 'is', ['Green']);                                             // b
        self::assertSame(['a', 'b', 'c'], $this->listed(MatchMode::Any, $any));
        self::assertSame(['b'], $this->listed(MatchMode::Any, $any, $filter));
        self::assertSame(['c'], $this->listed(MatchMode::Any, $any, null, 'chair'));
        self::assertSame([], $this->listed(MatchMode::Any, $any, $filter, 'chair'));
        self::assertSame(['b'], $this->listed(MatchMode::Any, $any, $filter, 'shade'));
        self::assertSame(['b'], $this->listed(MatchMode::Any, [$this->cond('text', 'is', ['nothing']), $this->cond('stored', 'links-to', [(string) $this->r['attic']])], $filter, 'lamp'));

        $lists = new ListSql($this->schema(), $this->types);
        $where = $lists->where($this->items, new ListQuery(new Sort('added', false), $filter, 'x', 1, new RuleSet(MatchMode::Any, $any)));
        self::assertMatchesRegularExpression('/^r\."_trashed_at" IS NULL AND \(\(.+\) OR \(.+\)\) AND r\.\S+ = \? AND \(.+\)$/', $where->sql());
    }

    // ------------------------------------------------------------ T-RUL-5

    public function testTextIsIgnoresAsciiCaseAndContainsMatchesWildcardsLiterally(): void
    {
        $ids = [];
        foreach (['100%', '1_0', 'a\\b', '1000', 'abb', 'Éclair', 'éclair'] as $text) {
            $ids[$this->item(['name' => 'w', 'text' => $text])] = $text;
        }
        $named = function (array $conditions) use ($ids): array {
            $page = $this->records()->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::All, $conditions)));
            $named = [];
            foreach ($page->records as $record) {
                if (isset($ids[$record->id])) {
                    $named[] = $ids[$record->id];
                }
            }

            return $named;
        };
        self::assertSame(['100%'], $named([$this->cond('text', 'contains', ['%'])]));
        self::assertSame(['100%'], $named([$this->cond('text', 'contains', ['0%'])]));
        self::assertSame(['1_0'], $named([$this->cond('text', 'contains', ['_'])]));
        self::assertSame(['1_0'], $named([$this->cond('text', 'contains', ['1_0'])]));
        self::assertSame(['a\\b'], $named([$this->cond('text', 'contains', ['\\'])]));
        self::assertSame(['a\\b'], $named([$this->cond('text', 'contains', ['a\\b'])]));
        self::assertSame(['1000'], $named([$this->cond('text', 'is', ['1000'])]));
        self::assertSame(['abb'], $named([$this->cond('text', 'is', ['ABB'])]));
        // Only ASCII letters are folded (SQLite NOCASE), for is and for contains.
        self::assertSame(['Éclair'], $named([$this->cond('text', 'is', ['Éclair'])]));
        self::assertSame(['éclair'], $named([$this->cond('text', 'is', ['éCLAIR'])]));
        self::assertSame(['Éclair'], $named([$this->cond('text', 'contains', ['ÉC'])]));
    }

    // ------------------------------------------------------------ T-RUL-6

    public function testHostileOperandsAreBoundNeverWritten(): void
    {
        $lists = new ListSql($this->schema(), $this->types);
        $plain = [];
        foreach (['contains', 'is'] as $op) {
            $plain[$op] = $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::All, [$this->cond('text', $op, ['plain'])])))->sql();
        }
        $hostile = ["O'Brien \"x\"", "'; DROP TABLE x; --", '1 OR 1=1', ') OR (1', "/* c */", '?', str_repeat('é', 200)];
        foreach ($hostile as $text) {
            $id = $this->item(['name' => 'h', 'text' => $text]);
            foreach (['contains', 'is'] as $op) {
                foreach ([MatchMode::All, MatchMode::Any] as $match) {
                    $query = new ListQuery(new Sort('added', false), null, null, 1, new RuleSet($match, [$this->cond('text', $op, [$text])]));
                    $where = $lists->where($this->items, $query);
                    if ($match === MatchMode::All) {
                        // The text is the same whatever the operand.
                        self::assertSame($plain[$op], $where->sql());
                    }
                    self::assertCount(1, $where->params());
                    self::assertStringStartsWith('r."_trashed_at" IS NULL AND ((r.' . $this->f['text']->sql() . ' ', $where->sql());
                    self::assertContains($op === 'contains' ? '%' . ListSql::escapeLike($text) . '%' : $text, $where->params());
                    $page = $this->records()->page($this->items->key, $query);
                    self::assertSame([$id], array_map(static fn (RecordData $r): int => $r->id, $page->records), $op . ' ' . $text);
                }
            }
            $this->trashRecord($this->items, $id);
        }
        // Refused, never sent: NUL, other control characters, invalid UTF-8, empty text, 201 characters, the wrong operand count.
        foreach ([["a\0b"], ["a\u{0007}"], ["tab\there"], ["\xC3"], [''], [str_repeat('é', 201)], [], ['a', 'b']] as $operands) {
            foreach (['contains', 'is'] as $op) {
                $this->assertRefused($lists, $this->cond('text', $op, $operands), $op . ' ' . json_encode($operands));
            }
        }
        foreach ([
            $this->cond('number', 'is', ["1\0"]),
            $this->cond('number', 'less', ['1 OR 1']),
            $this->cond('number', 'more', ['']),
            $this->cond('number', 'is', []),
            $this->cond('currency', 'is', ['1.234']),
            $this->cond('rating', 'is', ['6']),
            $this->cond('duration', 'is', ['-1:00']),
            $this->cond('date', 'on', ['2026-02-30']),
            $this->cond('date', 'on', ['2026-1-5']),
            $this->cond('date', 'before', ["2026-01-01'"]),
            $this->cond('date', 'after', ['2026-01-01', '2026-01-02']),
            $this->cond('date_time', 'on', ['2026-01-01 10:00']),
            $this->cond('date_time', 'on', []),
            $this->cond('stored', 'links-to', ['1 OR 1']),
            $this->cond('checkbox', 'is', ['yes']),
        ] as $condition) {
            $this->assertRefused($lists, $condition, $condition->operator->value . ' ' . json_encode($condition->operands));
        }
    }

    private function assertRefused(ListSql $lists, Condition $condition, string $message): void
    {
        try {
            $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::Any, [$condition])));
            self::fail('Accepted: ' . $message);
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------ T-RUL-7

    public function testConditionRefusesOperatorsOutsideTheUnionAndOtherTablesFields(): void
    {
        $lists = new ListSql($this->schema(), $this->types);
        foreach ([
            ['text', 'between'], ['text', 'links-to'], ['text', 'less'], ['text', 'on'],
            ['number', 'contains'], ['number', 'not-empty'], ['number', 'on'], ['number', 'between'],
            ['date', 'less'], ['date', 'contains'], ['date', 'not-empty'], ['date_time', 'is'],
            ['choice', 'contains'], ['choice', 'not-empty'], ['choice', 'less'],
            ['checkbox', 'empty'], ['checkbox', 'not-empty'], ['checkbox', 'contains'],
            ['stored', 'is'], ['stored', 'contains'], ['stored', 'not-empty'],
            ['time', 'is'], ['time', 'empty'], ['time', 'before'], ['auto', 'is'], ['auto', 'empty'], ['files', 'empty'], ['files', 'not-empty'],
        ] as [$short, $op]) {
            $this->assertRefused($lists, $this->cond($short, $op, ['1']), $short . ' ' . $op);
            $this->assertRefused($lists, $this->cond($short, $op, []), $short . ' ' . $op);
        }
        self::assertSame(['between', 'empty', 'on', 'before', 'after'], ListSql::operators('date'));
        self::assertSame(['contains', 'is', 'empty', 'not-empty'], ListSql::operators('text'));
        self::assertSame([], ListSql::operators('time'));
        self::assertSame([], ListSql::operators('attachment'));

        // The list filter keeps to its own operators (FILTERS unchanged).
        foreach ([$this->cond('text', 'contains', ['a']), $this->cond('number', 'less', ['1']), $this->cond('date', 'on', ['2026-01-01'])] as $condition) {
            try {
                $lists->where($this->items, new ListQuery(new Sort('added', false), $condition, null, 1));
                self::fail('Filter accepted ' . $condition->operator->value);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // A field of another table, as a rule or through condition().
        try {
            $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::All, [$this->cond('room_name', 'contains', ['Hall'])])));
            self::fail('Another table\'s field accepted.');
        } catch (NotFound) {
            self::addToAssertionCount(1);
        }
        $this->expectException(NotFound::class);
        $lists->condition($this->rooms, $this->cond('text', 'empty'));
    }

    // ------------------------------------------------------------ T-RUL-8

    public function testRuleSetHoldsOneToTenConditions(): void
    {
        $one = $this->cond('text', 'empty');
        self::assertCount(10, (new RuleSet(MatchMode::Any, array_fill(0, 10, $one)))->conditions);
        self::assertSame(MatchMode::All, (new RuleSet(MatchMode::All, [$one]))->match);
        foreach ([[], array_fill(0, 11, $one), ['text'], [$one, null], [1 => $one], ['a' => $one]] as $i => $conditions) {
            try {
                new RuleSet(MatchMode::All, $conditions);
                self::fail('Case ' . $i . ' accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([MatchMode::All, MatchMode::Any], [MatchMode::from('all'), MatchMode::from('any')]);
        self::assertNull(MatchMode::tryFrom('ALL'));
        self::assertSame(
            ['is', 'empty', 'between', 'links-to', 'contains', 'not-empty', 'less', 'more', 'on', 'before', 'after'],
            array_map(static fn (Operator $o): string => $o->value, Operator::cases()),
        );
    }

    // ------------------------------------------------------------ T-RUL-10

    public function testTheSqlScannerCoversTheNewFilesWithNoExemption(): void
    {
        $scanned = SqlStringsTest::scannedFiles();
        foreach (['ListQuery', 'ListSql', 'Operator', 'MatchMode', 'RuleSet', 'Collection', 'CollectionRule', 'CollectionStore', 'CollectionRules', 'ResolvedRules', 'RuleState'] as $class) {
            $path = 'src/Core/Query/' . $class . '.php';
            self::assertFileExists(RepoFiles::path($path));
            self::assertContains($path, $scanned);
            self::assertArrayNotHasKey($path, SqlStringsTest::ALLOWED_VARIABLES);
            self::assertArrayNotHasKey($path, SqlStringsTest::RULE_EXEMPTIONS);
            self::assertNotSame($path, SqlStringsTest::EXEMPT_FILE);
        }
        self::assertSame('src/Core/Query/Sql.php', SqlStringsTest::EXEMPT_FILE);
    }
}
