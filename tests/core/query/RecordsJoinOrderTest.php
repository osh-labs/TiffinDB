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
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\TableDef;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * Join order of the list subqueries (relation sort, relation empty filter,
 * linked-title search). With statistics that make the other table's trash
 * index look selective, a plain JOIN lets SQLite scan that index for every
 * listed record (quadratic: 9 s for one page of 2,000 records in the
 * follow-up measurement). Each subquery must find the join row first, by
 * its key on the listed record, and the other record by its primary key.
 */
#[CoversClass(ListSql::class)]
final class RecordsJoinOrderTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-records-join-order-test-1');
        $this->forgeStatistics([$this->items, $this->rooms], [$this->f['stored'], $this->f['parts'], $this->f['room']]);
    }

    /**
     * @param list<TableDef> $tables
     * @param list<FieldDef> $relationFields
     */
    private function forgeStatistics(array $tables, array $relationFields): void
    {
        // Statistics as ANALYZE would write them for tables whose records are almost all in the
        // trash, with join tables whose keys look unselective: the planner's worst case.
        $this->db->run('ANALYZE');
        $this->db->run('DELETE FROM "sqlite_stat1"');
        foreach ($tables as $table) {
            $name = Identifier::tableName($table->key);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$name, trim(Identifier::index($name, 'trash'), '"'), '20000 1']);
        }
        foreach ($relationFields as $relationField) {
            $join = Identifier::joinName((string) $relationField->options['relation']);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$join, $join, '40000 20000 1']);
            $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$join, trim(Identifier::index($join, 'r'), '"'), '40000 20000']);
        }
        $this->db->run('ANALYZE "sqlite_schema"');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
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

    public function testTheStatisticsWouldMisleadAPlainJoin(): void
    {
        // The setup really tempts the planner: the same subquery as a plain JOIN starts from the trash index.
        $relation = Identifier::joinTable((string) $this->f['stored']->options['relation']);
        $plan = array_column($this->db->all(
            'EXPLAIN QUERY PLAN SELECT 1 FROM ' . $this->items->sql() . ' AS "r" WHERE NOT EXISTS (SELECT 1 FROM ' . $relation . ' AS "jj" JOIN '
            . $this->rooms->sql() . ' AS "o" ON "o"."id" = "jj"."right_id" WHERE "jj"."left_id" = r."id" AND "o"."_trashed_at" IS NULL)',
        ), 'detail');
        self::assertMatchesRegularExpression('/^SEARCH o USING (COVERING )?INDEX x_t_[a-z2-7]{12}_trash/', implode("\n", array_slice($plan, 2)));
    }

    public function testEverySubqueryFindsTheJoinRowFirst(): void
    {
        $field = static fn (FieldDef $f): string => $f->key;
        $cases = [
            'sort by text title, left side' => [$this->items, new ListQuery(new Sort($field($this->f['stored']), false), null, null, 1), 2],
            'sort descending, self-relation' => [$this->items, new ListQuery(new Sort($field($this->f['parts']), true), null, null, 1), 2],
            'sort, right side' => [$this->rooms, new ListQuery(new Sort($field($this->f['stores']), false), null, null, 1), 2],
            'empty filter, left side' => [$this->items, new ListQuery(new Sort('added', false), new Condition($field($this->f['stored']), Operator::Empty, []), null, 1), 1],
            'empty filter, self-relation' => [$this->items, new ListQuery(new Sort('added', false), new Condition($field($this->f['part_of']), Operator::Empty, []), null, 1), 1],
            'empty filter, right side' => [$this->rooms, new ListQuery(new Sort('added', false), new Condition($field($this->f['room_items']), Operator::Empty, []), null, 1), 1],
            'search of linked titles' => [$this->items, new ListQuery(new Sort('added', false), null, 'lamp', 1), 4],
            'search from the right side' => [$this->rooms, new ListQuery(new Sort('added', false), null, 'lamp', 1), 2],
        ];
        // A number title takes the sort's other branch.
        $editor = $this->editor();
        $boxes = $editor->createTable('Boxes', 'box', false);
        $size = $editor->addField($boxes->key, 'Size', 'number', [], false, '');
        $editor->updateTable($boxes->key, 'Boxes', 'box', $size->key);
        $inBox = $editor->addRelation($this->items->key, 'In box', $boxes->key, Cardinality::Many, Cardinality::Many, 'Holds', false, '');
        $this->forgeStatistics([$this->items, $this->rooms, $boxes], [$this->f['stored'], $this->f['parts'], $this->f['room'], $inBox]);
        $cases['sort by number title'] = [$this->items, new ListQuery(new Sort($inBox->key, false), null, null, 1), 2];

        foreach ($cases as $name => [$table, $query, $subqueries]) {
            $plan = $this->plan($table, $query);
            $text = implode("\n", $plan);
            self::assertStringNotContainsString('_trash (_trashed_at=?)' . "\n" . 'SEARCH jj', $text, $name);
            $joins = 0;
            foreach ($plan as $i => $line) {
                if (str_starts_with($line, 'SEARCH jj ')) {
                    $joins++;
                    if (($plan[$i + 1] ?? '') !== '' && str_starts_with($plan[$i + 1], 'SEARCH o ')) {
                        self::assertSame('SEARCH o USING INTEGER PRIMARY KEY (rowid=?)', $plan[$i + 1], $name);
                    }
                }
                self::assertDoesNotMatchRegularExpression('/^(SEARCH|SCAN) o USING (COVERING )?INDEX/', $line, $name . ': ' . $text);
                self::assertStringNotContainsString('SCAN jj', $line, $name);
            }
            self::assertGreaterThanOrEqual($subqueries, $joins, $name . ': ' . $text);
        }
    }
}
