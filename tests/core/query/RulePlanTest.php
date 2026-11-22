<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\TableDef;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-RUL-11 (`docs/specs/M4-core.md` sections 5.5 and 12.3): query plans of
 * a 10-rule `any` collection with every operator family, on a fixture of
 * 20,000 items and 500 rooms joined by a many relation ("Stored in") and
 * a one relation ("Room"), each item linked once through each. With no
 * statistics at all (no sqlite_stat1, as a project that was never
 * analysed; P181), with the statistics ANALYZE writes, and again with
 * forged statistics that make
 * the trash indexes look selective (the M3 lesson, RecordsJoinOrderTest),
 * every relation subquery finds its join row by its key on r."id" and
 * the other record by its primary key: no SCAN of a join table or of the
 * other table inside a correlated subquery. Timings are measured and not
 * asserted; when TIFFINDB_RUL11_TIMINGS names a file they are written
 * there as JSON. The seed is fixed; all data is synthetic.
 */
#[CoversClass(ListSql::class)]
final class RulePlanTest extends TestCase
{
    use RecordsFixture;

    public const ITEMS = 20000;
    public const ROOMS = 500;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-rule-plan-test-1');
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function seed(): void
    {
        $f = $this->f;
        $labels = ['Red', 'Green', 'Blue'];
        $stored = (string) $f['stored']->options['relation'];
        $room = (string) $f['room']->options['relation'];
        $storedLeft = $this->schema()->relation($stored)->sideOf($f['stored']->key) === 'left';
        $roomLeft = $this->schema()->relation($room)->sideOf($f['room']->key) === 'left';
        $this->db->write(function () use ($f, $labels, $stored, $room, $storedLeft, $roomLeft): void {
            for ($i = 1; $i <= self::ROOMS; $i++) {
                $this->insert($this->rooms->key, [$f['room_name']->key => sprintf('Room %03d', $i)], $i % 50 === 0);
            }
            for ($i = 1; $i <= self::ITEMS; $i++) {
                $this->insert($this->items->key, [
                    $f['name']->key => sprintf('Item %05d', $i),
                    $f['text']->key => $i % 7 === 0 ? null : 'Text ' . ($i % 311),
                    $f['email']->key => $i % 5 === 0 ? 'user' . ($i % 97) . '@example.com' : null,
                    $f['url']->key => $i % 3 === 0 ? 'https://example.com/' . $i : null,
                    $f['number']->key => $i % 11 === 0 ? null : ($i % 1000) / 4,
                    $f['currency']->key => $i % 13 === 0 ? null : $i * 7,
                    $f['date']->key => sprintf('2025-%02d-%02d', 1 + $i % 12, 1 + $i % 28),
                    $f['date_time']->key => sprintf('2026-%02d-%02dT%02d:%02d:00', 1 + $i % 12, 1 + $i % 28, $i % 24, $i % 60),
                    $f['choice']->key => $i % 4 === 0 ? null : $labels[$i % 3],
                    $f['checkbox']->key => $i % 2,
                ], $i % 100 === 0);
                $target = 1 + $i % self::ROOMS;
                if ($i % 9 !== 0) {
                    $this->link($stored, $storedLeft ? $i : $target, $storedLeft ? $target : $i);
                }
                if ($i % 10 !== 0) {
                    $this->link($room, $roomLeft ? $i : $target, $roomLeft ? $target : $i);
                }
            }
        });
        self::assertSame(self::ITEMS, $this->records()->count($this->items->key) + intdiv(self::ITEMS, 100));
        self::assertSame(self::ITEMS - intdiv(self::ITEMS, 9), (int) $this->db->value('SELECT COUNT(*) FROM ' . Identifier::joinTable($stored)));
    }

    /** @return list<Condition> ten conditions on items, one of every operator family */
    private function itemRules(): array
    {
        $c = fn (string $short, Operator $op, array $operands = []): Condition => new Condition($this->f[$short]->key, $op, $operands);

        return [
            $c('text', Operator::Contains, ['Text 31']),
            $c('email', Operator::Is, ['USER7@example.com']),
            $c('url', Operator::NotEmpty),
            $c('number', Operator::Less, ['1.5']),
            $c('currency', Operator::More, ['1000.00']),
            $c('date_time', Operator::On, ['2026-03-04']),
            $c('choice', Operator::Is, ['Blue']),
            $c('checkbox', Operator::Is, ['1']),
            $c('stored', Operator::LinksTo, ['17']),
            $c('room', Operator::Empty),
        ];
    }

    /** @return list<Condition> relation rules from the other side of both relations, and a text rule */
    private function roomRules(): array
    {
        return [
            new Condition($this->f['stores']->key, Operator::LinksTo, ['42']),
            new Condition($this->f['stores']->key, Operator::Empty, []),
            new Condition($this->f['room_items']->key, Operator::Empty, []),
            new Condition($this->f['room_name']->key, Operator::Contains, ['Room 1']),
        ];
    }

    public function testTenRuleAnyCollectionPlans(): void
    {
        $times = ['items' => self::ITEMS, 'rooms' => self::ROOMS];
        self::assertNull($this->db->value("SELECT name FROM sqlite_master WHERE name = 'sqlite_stat1'"), 'The fixture starts without statistics.');
        $this->checkPlans($times, 'no statistics');
        $this->db->run('ANALYZE');
        $this->checkPlans($times, 'analyzed');
        $this->forgeStatistics();
        $this->checkPlans($times, 'forged');

        $file = getenv('TIFFINDB_RUL11_TIMINGS');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, json_encode($times, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }

    /** @param array<string, int|float> $times */
    private function checkPlans(array &$times, string $label): void
    {
        $cases = [
            'items any' => [$this->items, new RuleSet(MatchMode::Any, $this->itemRules()), 2],
            'items all' => [$this->items, new RuleSet(MatchMode::All, $this->itemRules()), 2],
            'rooms any' => [$this->rooms, new RuleSet(MatchMode::Any, $this->roomRules()), 3],
        ];
        foreach ($cases as $name => [$table, $rules, $joins]) {
            $name = $label . ' ' . $name;
            foreach ([new Sort('added', false), new Sort($this->titleOf($table)->key, true)] as $sort) {
                $query = new ListQuery($sort, null, null, 1, $rules);
                $this->assertPlan($name . ' ' . $sort->token(), $this->plan($table, $query), $joins, $label === 'forged');
                $start = hrtime(true);
                $page = $this->records()->page($table->key, $query);
                $times[$name . ' ' . $sort->token() . ' page seconds'] = round((hrtime(true) - $start) / 1e9, 3);
                $times[$name . ' ' . $sort->token() . ' matches'] = $page->total;
                if ($rules->match === MatchMode::Any) {
                    self::assertGreaterThan(50, $page->total, $name);
                    $last = $page->records[count($page->records) - 1]->id;
                    $start = hrtime(true);
                    self::assertSame(count($page->records), $this->records()->position($table->key, $query, $last)?->index);
                    $times[$name . ' ' . $sort->token() . ' position seconds'] = round((hrtime(true) - $start) / 1e9, 3);
                }
            }
        }
        // The rule results are those of the conditions one by one (OR), so the plans answer the same question.
        $union = [];
        foreach ($this->itemRules() as $condition) {
            $union += array_flip($this->allIds(new RuleSet(MatchMode::All, [$condition])));
        }
        self::assertSame(count($union), $this->records()->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1, new RuleSet(MatchMode::Any, $this->itemRules())))->total);
    }

    /** @return list<int> */
    private function allIds(RuleSet $rules): array
    {
        $lists = new ListSql($this->schema(), $this->types);
        $where = $lists->where($this->items, new ListQuery(new Sort('added', false), null, null, 1, $rules));

        return array_map('intval', array_column($this->db->all('SELECT r."id" FROM ' . $this->items->sql() . ' AS "r" WHERE ' . $where->sql(), $where->params()), 'id'));
    }

    private function titleOf(TableDef $table): FieldDef
    {
        return $table->key === $this->items->key ? $this->f['name'] : $this->f['room_name'];
    }

    /** @return list<string> EXPLAIN QUERY PLAN lines of the page query */
    private function plan(TableDef $table, ListQuery $query): array
    {
        $lists = new ListSql($this->schema(), $this->types);
        $where = $lists->where($table, $query);
        $order = $lists->order($table, $query->sort);

        return array_map(
            static fn (array $row): string => (string) $row['detail'],
            $this->db->all(
                'EXPLAIN QUERY PLAN SELECT r.* FROM ' . $table->sql() . ' AS "r" WHERE ' . $where->sql() . ' ORDER BY ' . $order->sql() . ' LIMIT 50',
                [...$where->params(), ...$order->params()],
            ),
        );
    }

    /**
     * Every join row is found by its key on r."id"; with forged statistics
     * that say a join table holds only two distinct ids per column, SQLite
     * may reach that key through a skip-scan of the primary key
     * (`ANY(left_id) AND right_id=?`), which those statistics make as cheap
     * as the right_id index; with real statistics only a direct key lookup
     * is accepted.
     *
     * @param list<string> $plan
     */
    private function assertPlan(string $name, array $plan, int $joins, bool $forged): void
    {
        $text = $name . ":\n" . implode("\n", $plan);
        $found = 0;
        foreach ($plan as $i => $line) {
            self::assertStringNotContainsString('SCAN jj', $line, $text);
            self::assertStringNotContainsString('SCAN o', $line, $text);
            self::assertDoesNotMatchRegularExpression('/^(SEARCH|SCAN) o USING (COVERING )?INDEX/', $line, $text);
            if (str_starts_with($line, 'SEARCH jj ')) {
                $found++;
                $key = $forged ? '(?:ANY\(left_id\) AND )?(left_id|right_id)=\?' : '(left_id|right_id)=\?';
                self::assertMatchesRegularExpression('/^SEARCH jj USING (COVERING )?(INDEX \S+|PRIMARY KEY) \(' . $key . '/', $line, $text);
                $next = $plan[$i + 1] ?? '';
                if (str_starts_with($next, 'SEARCH o ')) {
                    self::assertSame('SEARCH o USING INTEGER PRIMARY KEY (rowid=?)', $next, $text);
                }
            }
        }
        self::assertGreaterThanOrEqual($joins, $found, $text);
    }

    /** Statistics as for tables almost all in the trash and unselective join keys: the planner's worst case. */
    private function forgeStatistics(): void
    {
        $this->db->run('DELETE FROM "sqlite_stat1"');
        foreach ([$this->items, $this->rooms] as $table) {
            $name = Identifier::tableName($table->key);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$name, trim(Identifier::index($name, 'trash'), '"'), '20000 1']);
        }
        foreach ([$this->f['stored'], $this->f['room'], $this->f['parts']] as $field) {
            $join = Identifier::joinName((string) $field->options['relation']);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$join, $join, '40000 20000 1']);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$join, trim(Identifier::index($join, 'r'), '"'), '40000 20000']);
        }
        $this->db->run('ANALYZE "sqlite_schema"');
    }
}
