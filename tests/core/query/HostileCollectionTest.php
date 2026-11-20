<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionRules;
use TiffinDB\Core\Query\CollectionStore;
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\Sort;

require_once __DIR__ . '/RecordsFixture.php';
require_once __DIR__ . '/CollectionStoreTest.php';

/**
 * Design-tier security review of M4-WP3 (docs/build/reviews/M4-sec-wp3-wp5.md):
 * stored collection rows tampered with outside TiffinDB are read, resolved,
 * and listed without an error and without changing the SQL text; hostile
 * operands stay bound. All data is synthetic.
 */
#[CoversClass(CollectionStore::class)]
#[CoversClass(CollectionRules::class)]
#[CoversClass(ListSql::class)]
final class HostileCollectionTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-hostile-collection-test-1');
        CollectionStoreTest::createCollectionsTable($this->db);
        $this->item(['name' => 'a', 'text' => 'x%y_z\\w', 'choice' => 'Red', 'number' => '10']);
        $this->item(['name' => 'b', 'text' => 'xAyBz', 'choice' => 'Green']);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function insertRow(string $key, string $table, string $match, string $rules, ?string $sort): void
    {
        $this->db->run(
            'INSERT INTO "_tiffin_collections" ("key", "table_key", "name", "match_mode", "rules", "sort", "position", "created_at", "created_by", "updated_at", "updated_by")'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$key, $table, 'Tampered ' . $key, $match, $rules, $sort, 1, self::START, self::USER_KEY, self::START, self::USER_KEY],
        );
    }

    /** @return array{int, int} total listed, broken count */
    private function listTampered(string $rulesJson, string $match = 'all', ?string $sort = null): array
    {
        $this->db->run('DELETE FROM "_tiffin_collections"');
        $this->insertRow('aaaaaaaaaaaa', $this->items->key, $match, $rulesJson, $sort);
        $previous = ini_set('error_log', $this->temp->path('error.log'));
        try {
            $collections = CollectionStore::read($this->db);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        self::assertCount(1, $collections);
        $c = $collections[0];
        $resolved = (new CollectionRules($this->db, $this->schema(), $this->types))->resolve($c->tableKey, $c->match, $c->rules, $c->sort);
        $query = new ListQuery($resolved->sort ?? new Sort('added', false), null, null, 1, $resolved->set);

        return [$this->records()->page($this->items->key, $query)->total, $resolved->broken];
    }

    public function testTamperedRuleJsonNeverErrors(): void
    {
        $f = $this->f;
        $valid = json_encode(['field' => $f['text']->key, 'op' => 'not-empty', 'values' => []]);
        $cases = [
            'not json' => 'not json',
            'object' => '{"a":1}',
            'scalars' => '[1, "x", null, true]',
            'deep' => str_repeat('[', 500) . str_repeat(']', 500),
            'deep rule' => '[{"field":"' . $f['text']->key . '","op":"is","values":' . str_repeat('[', 20) . str_repeat(']', 20) . '}]',
            'eleven' => '[' . implode(',', array_fill(0, 11, $valid)) . ']',
            'many' => '[' . implode(',', array_fill(0, 20000, $valid)) . ']',
            'unknown op' => json_encode([['field' => $f['text']->key, 'op' => "is') OR 1=1 --", 'values' => ['x']]]),
            'filter-only op' => json_encode([['field' => $f['date']->key, 'op' => 'between', 'values' => ['2020-01-01', '']]]),
            'op wrong type' => json_encode([['field' => $f['number']->key, 'op' => 'contains', 'values' => ['1']]]),
            'field injection' => json_encode([['field' => 'name" OR 1=1', 'op' => 'empty', 'values' => []]]),
            'field of other table' => json_encode([['field' => $f['room_name']->key, 'op' => 'not-empty', 'values' => []]]),
            'unknown field' => json_encode([['field' => 'abcdefghijkl', 'op' => 'empty', 'values' => []]]),
            'attachment field' => json_encode([['field' => $f['files']->key, 'op' => 'empty', 'values' => []]]),
            'values object' => json_encode([['field' => $f['text']->key, 'op' => 'is', 'values' => ['a' => 'x']]]),
            'values nested' => json_encode([['field' => $f['text']->key, 'op' => 'is', 'values' => [['x']]]]),
            'values int' => json_encode([['field' => $f['number']->key, 'op' => 'is', 'values' => [10]]]),
            'too long' => json_encode([['field' => $f['text']->key, 'op' => 'is', 'values' => [str_repeat('a', 201)]]]),
            'control' => json_encode([['field' => $f['text']->key, 'op' => 'is', 'values' => ["a\0b"]]]),
            'three values' => json_encode([['field' => $f['text']->key, 'op' => 'is', 'values' => ['a', 'b', 'c']]]),
            'extra value on empty' => json_encode([['field' => $f['text']->key, 'op' => 'empty', 'values' => ['x']]]),
            'huge number' => json_encode([['field' => $f['number']->key, 'op' => 'more', 'values' => ['1e400']]]),
            'number text' => json_encode([['field' => $f['number']->key, 'op' => 'more', 'values' => ['1 OR 1=1']]]),
            'bad date' => json_encode([['field' => $f['date']->key, 'op' => 'on', 'values' => ['2026-02-30']]]),
            'removed choice' => json_encode([['field' => $f['choice']->key, 'op' => 'is', 'values' => ['zzzzzzzzzzzz']]]),
            'choice by label' => json_encode([['field' => $f['choice']->key, 'op' => 'is', 'values' => ['Red']]]),
            'checkbox odd' => json_encode([['field' => $f['checkbox']->key, 'op' => 'is', 'values' => ['2']]]),
            'record zero' => json_encode([['field' => $f['stored']->key, 'op' => 'links-to', 'values' => ['0']]]),
            'record huge' => json_encode([['field' => $f['stored']->key, 'op' => 'links-to', 'values' => ['99999999999999999999']]]),
            'record sql' => json_encode([['field' => $f['stored']->key, 'op' => 'links-to', 'values' => ['1 OR 1=1']]]),
            'invalid utf8 escape' => '[{"field":"' . $f['text']->key . '","op":"is","values":["\ud800"]}]',
        ];
        foreach ($cases as $name => $json) {
            [$total, $broken] = $this->listTampered((string) $json);
            self::assertGreaterThanOrEqual(0, $total, $name);
        }
        // Eleven or more: only ten are read. Rules text over MAX_RULES_BYTES (P181, L-5) is
        // not decoded at all: a 20,000-rule row is read with no rules.
        $previous = ini_set('error_log', $this->temp->path('error.log'));
        try {
            $this->listTampered('[' . implode(',', array_fill(0, 11, $valid)) . ']');
            self::assertCount(10, CollectionStore::read($this->db)[0]->rules);
            $many = '[' . implode(',', array_fill(0, 20000, $valid)) . ']';
            self::assertGreaterThan(CollectionStore::MAX_RULES_BYTES, strlen($many));
            $this->listTampered($many);
            self::assertSame([], CollectionStore::read($this->db)[0]->rules);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    public function testTamperedMatchAndSort(): void
    {
        foreach (['', 'ANY', "any' OR 1", 'none'] as $match) {
            self::assertSame(2, $this->listTampered('[]', $match)[0], $match);
        }
        foreach (['x; DROP TABLE t', 'added.asc; --', $this->f['room_name']->key . '.asc', $this->f['files']->key . '.asc', 'abcdefghijkl.desc', 'changed.asc'] as $sort) {
            self::assertSame(2, $this->listTampered('[]', 'all', $sort)[0], $sort);
        }
    }

    public function testLikeWildcardsAreLiteral(): void
    {
        $rule = fn (string $text) => json_encode([['field' => $this->f['text']->key, 'op' => 'contains', 'values' => [$text]]]);
        self::assertSame(1, $this->listTampered((string) $rule('%y_'))[0]);
        self::assertSame(0, $this->listTampered((string) $rule('x_y'))[0]);
        self::assertSame(1, $this->listTampered((string) $rule('\\w'))[0]);
        self::assertSame(0, $this->listTampered((string) $rule('%%'))[0]);
    }

    public function testEveryFragmentKeepsValuesBound(): void
    {
        $lists = new ListSql($this->schema(), $this->types);
        $hostile = "x' OR '1'='1";
        $rules = new RuleSet(MatchMode::Any, [
            new Condition($this->f['text']->key, Operator::Contains, [$hostile]),
            new Condition($this->f['email']->key, Operator::Is, [$hostile]),
            new Condition($this->f['choice']->key, Operator::Is, [$hostile]),
        ]);
        $where = $lists->where($this->items, new ListQuery(new Sort('added', false), null, $hostile, 1, $rules));
        self::assertStringNotContainsString("'1'='1", $where->sql());
        self::assertStringStartsWith('r."_trashed_at" IS NULL AND ((', $where->sql());
    }
}
