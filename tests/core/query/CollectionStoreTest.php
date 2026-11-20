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
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\Collection;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionStore;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-COL-1 to T-COL-4, T-COL-10 (`docs/specs/M4-core.md` section 12.3):
 * the collection store. Until format version 4 (M4-WP10) makes the table
 * part of every project file, the tests create `_tiffin_collections` from
 * the version 4 block of `docs/formats/tiffin.md` section 4 on the
 * version 3 file ProjectFile::create makes. All data is synthetic.
 */
#[CoversClass(CollectionStore::class)]
#[CoversClass(CollectionRule::class)]
#[CoversClass(Collection::class)]
final class CollectionStoreTest extends TestCase
{
    use RecordsFixture;

    private const LATER = '2026-11-12T04:00:00Z';

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-collection-store-test-1');
        self::createCollectionsTable($this->db);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /**
     * Creates `_tiffin_collections` and its indexes exactly as the format
     * document's version 4 block gives them, unless the file has them.
     */
    public static function createCollectionsTable(Database $db): void
    {
        if ($db->value('SELECT 1 FROM "sqlite_master" WHERE "name" = \'_tiffin_collections\'') !== null) {
            return;
        }
        $format = (string) file_get_contents(RepoFiles::path('docs/formats/tiffin.md'));
        $start = strpos($format, "Version 4 adds one table and its two indexes:\n\n```sql\n");
        self::assertIsInt($start);
        $start = (int) strpos($format, "```sql\n", $start) + 7;
        $block = substr($format, $start, (int) strpos($format, "\n```", $start) - $start);
        $statements = array_values(array_filter(array_map('trim', explode(';', $block)), static fn (string $s): bool => $s !== ''));
        self::assertCount(3, $statements);
        foreach ($statements as $statement) {
            $db->run($statement);
        }
    }

    private function store(): CollectionStore
    {
        return new CollectionStore($this->db, $this->schema(), $this->keys, $this->clock);
    }

    private function rule(string $short, string $op, array $values = []): CollectionRule
    {
        return new CollectionRule($this->f[$short]->key, $op, $values);
    }

    /** @return list<Problem> */
    private function problems(\Closure $work): array
    {
        try {
            $work();
        } catch (ValidationFailed $failed) {
            return $failed->problems;
        }
        self::fail('No ValidationFailed.');
    }

    // ------------------------------------------------------------ T-COL-1

    public function testCreateGetAllUpdateDelete(): void
    {
        $store = $this->store();
        $rules = [$this->rule('checkbox', 'is', ['1']), $this->rule('text', 'contains', ['lamp'])];
        $first = $store->create($this->items->key, '  Lamps to check ', MatchMode::Any, $rules, $this->f['name']->key . '.desc', self::USER_KEY);
        self::assertTrue(Keys::isValid($first));
        self::assertSame(self::START, $this->meta('updated_at'));
        $this->clock->set(self::LATER);
        $second = $store->create($this->rooms->key, 'Rooms', MatchMode::All, [], null, self::OTHER_USER_KEY);
        self::assertSame([self::LATER, self::OTHER_USER_KEY], [$this->meta('updated_at'), $this->meta('updated_by')]);

        $got = $store->get($first);
        self::assertEquals(
            new Collection($first, $this->items->key, 'Lamps to check', MatchMode::Any, $rules, $this->f['name']->key . '.desc', 1, self::START, self::USER_KEY, self::START, self::USER_KEY),
            $got,
        );
        self::assertSame([$first, $second], array_map(static fn (Collection $c): string => $c->key, $store->all()));
        self::assertSame([1, 2], array_map(static fn (Collection $c): int => $c->position, CollectionStore::read($this->db)));
        self::assertNull($store->get('zzzzzzzzzzzz'));
        self::assertNull($store->get('not a key'));

        // Keys share the project scope: never a table, field, relation, or form key.
        foreach (['_tiffin_tables', '_tiffin_fields', '_tiffin_relations', '_tiffin_forms'] as $catalogue) {
            self::assertNull($this->db->value('SELECT 1 FROM "' . $catalogue . '" WHERE "key" IN (?, ?)', [$first, $second]));
        }

        $this->clock->advance(60);
        $store->update($first, 'Lamps', MatchMode::All, [$rules[1]], null, self::OTHER_USER_KEY);
        $updated = $store->get($first);
        self::assertNotNull($updated);
        self::assertEquals(['Lamps', MatchMode::All, [$rules[1]], null, 1, self::START, self::USER_KEY, '2026-11-12T04:01:00Z', self::OTHER_USER_KEY], [
            $updated->name, $updated->match, $updated->rules, $updated->sort, $updated->position, $updated->createdAt, $updated->createdBy, $updated->updatedAt, $updated->updatedBy,
        ]);
        self::assertSame('2026-11-12T04:01:00Z', $this->meta('updated_at'));
        // Renaming to its own name in another case is allowed.
        $store->update($first, 'LAMPS', MatchMode::All, [], null, self::USER_KEY);
        self::assertSame('LAMPS', $store->get($first)?->name);

        $store->delete($first, self::USER_KEY);
        self::assertNull($store->get($first));
        self::assertSame([$second], array_map(static fn (Collection $c): string => $c->key, $store->all()));
        // New collections go after the last position, even after a delete.
        $third = $store->create($this->items->key, 'Third', MatchMode::All, [], 'added.desc', self::USER_KEY);
        self::assertSame(3, $store->get($third)?->position);

        foreach ([
            fn () => $store->delete($first),
            fn () => $store->delete('bad'),
            fn () => $store->update($first, 'X', MatchMode::All, [], null, self::USER_KEY),
            fn () => $store->update('bad', 'X', MatchMode::All, [], null, self::USER_KEY),
            fn () => $store->create('zzzzzzzzzzzz', 'X', MatchMode::All, [], null, self::USER_KEY),
        ] as $i => $work) {
            try {
                $work();
                self::fail('Case ' . $i . ' accepted.');
            } catch (NotFound) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['name.up', 'Added.asc', 'changed.asc', 'added', '', 'zzzzzzzzzzzz.desc '] as $sort) {
            try {
                $store->create($this->items->key, 'Sorted', MatchMode::All, [], $sort, self::USER_KEY);
                self::fail($sort . ' accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $store->create($this->items->key, 'Wrong rules', MatchMode::All, [['field' => $this->f['text']->key, 'op' => 'is', 'values' => []]], null, self::USER_KEY);
    }

    // ------------------------------------------------------------ T-COL-2

    public function testNames(): void
    {
        $store = $this->store();
        $store->create($this->items->key, 'Lamps', MatchMode::All, [], null, self::USER_KEY);
        $cases = [
            ['', 'problem.name.empty'],
            ['   ', 'problem.name.empty'],
            [str_repeat('n', 61), 'problem.name.too_long'],
            ["Bad\u{0007}name", 'problem.text.control'],
            ["Bad\xC3", 'problem.text.control'],
        ];
        foreach ($cases as [$name, $key]) {
            $problems = $this->problems(fn () => $store->create($this->items->key, $name, MatchMode::All, [], null, self::USER_KEY));
            self::assertSame('name', $problems[0]->field, $key);
            self::assertSame($key, $problems[0]->key);
        }
        self::assertNotSame('', $store->create($this->items->key, str_repeat('n', 60), MatchMode::All, [], null, self::USER_KEY));

        // Taken ignoring ASCII case, in any table of the project; found by the check, never by the index.
        $this->db->run('DROP INDEX "_tiffin_collections_name"');
        foreach (['lamps', 'LAMPS', ' Lamps '] as $name) {
            $problems = $this->problems(fn () => $store->create($this->rooms->key, $name, MatchMode::All, [], null, self::USER_KEY));
            self::assertEquals([new Problem('name', 'problem.collection.name_taken')], $problems);
        }
        $other = $store->create($this->rooms->key, 'Rooms', MatchMode::All, [], null, self::USER_KEY);
        $problems = $this->problems(fn () => $store->update($other, 'lAmPs', MatchMode::All, [], null, self::USER_KEY));
        self::assertEquals([new Problem('name', 'problem.collection.name_taken')], $problems);
        // Non-ASCII letters are compared exactly (the format's ASCII-only rule).
        $store->create($this->items->key, 'Éclairs', MatchMode::All, [], null, self::USER_KEY);
        self::assertNotSame('', $store->create($this->items->key, 'éclairs', MatchMode::All, [], null, self::USER_KEY));
        self::assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_collections"'));
    }

    // ------------------------------------------------------------ T-COL-3

    public function testLimits(): void
    {
        $store = $this->store();
        $eleven = array_fill(0, 11, $this->rule('checkbox', 'is', ['1']));
        $problems = $this->problems(fn () => $store->create($this->items->key, 'Many', MatchMode::All, $eleven, null, self::USER_KEY));
        self::assertEquals([new Problem(null, 'problem.collection.too_many_rules', ['max' => 10])], $problems);
        // Every problem at once: the name and the rules.
        $problems = $this->problems(fn () => $store->create($this->items->key, '', MatchMode::All, $eleven, null, self::USER_KEY));
        self::assertSame(['problem.name.empty', 'problem.collection.too_many_rules'], array_map(static fn (Problem $p): string => $p->key, $problems));
        $key = $store->create($this->items->key, 'Ten', MatchMode::All, array_slice($eleven, 0, 10), null, self::USER_KEY);
        $problems = $this->problems(fn () => $store->update($key, 'Ten', MatchMode::All, $eleven, null, self::USER_KEY));
        self::assertSame('problem.collection.too_many_rules', $problems[0]->key);

        for ($i = 2; $i <= 50; $i++) {
            $store->create($this->items->key, 'Collection ' . $i, MatchMode::All, [], null, self::USER_KEY);
        }
        $problems = $this->problems(fn () => $store->create($this->rooms->key, 'Fifty-first', MatchMode::All, [], null, self::USER_KEY));
        self::assertEquals([new Problem(null, 'problem.collection.too_many', ['max' => 50])], $problems);
        self::assertCount(50, $store->all());
        self::assertSame(range(1, 50), array_map(static fn (Collection $c): int => $c->position, $store->all()));
    }

    // ------------------------------------------------------------ T-COL-4

    public function testStoredJsonAndDamagedRows(): void
    {
        $store = $this->store();
        $key = $store->create($this->items->key, 'Shapes', MatchMode::Any, [
            $this->rule('checkbox', 'is', ['1']),
            $this->rule('text', 'contains', ['Café/Ünïcode "quoted"']),
            $this->rule('date', 'empty'),
        ], null, self::USER_KEY);
        $row = $this->db->one('SELECT "match_mode", "rules", "sort" FROM "_tiffin_collections" WHERE "key" = ?', [$key]);
        self::assertSame([
            'match_mode' => 'any',
            'rules' => '[{"field":"' . $this->f['checkbox']->key . '","op":"is","values":["1"]},'
                . '{"field":"' . $this->f['text']->key . '","op":"contains","values":["Café/Ünïcode \"quoted\""]},'
                . '{"field":"' . $this->f['date']->key . '","op":"empty","values":[]}]',
            'sort' => null,
        ], $row);

        // A hand-damaged row: valid entries kept, invalid dropped, match mode all, sort null, logged by key only.
        $valid = '{"field":"' . $this->f['checkbox']->key . '","op":"is","values":["0"],"note":"ignored"}';
        $damaged = '[' . $valid . ',{"field":"bad","op":"is","values":[]},{"field":"' . $this->f['text']->key . '","op":"like","values":[]},'
            . '{"field":"' . $this->f['text']->key . '","op":"is","values":["a","b","c"]},{"field":"' . $this->f['text']->key . '","op":"is","values":[1]},'
            . '{"field":"' . $this->f['text']->key . '","op":"is","values":["' . str_repeat('x', 201) . '"]},"text",null,[],{"op":"empty","values":[]}]';
        $this->db->run('UPDATE "_tiffin_collections" SET "rules" = ?, "match_mode" = ?, "sort" = ? WHERE "key" = ?', [$damaged, 'some', 'name.up', $key]);
        [$read, $log] = $this->logged(fn (): ?Collection => $store->get($key));
        self::assertInstanceOf(Collection::class, $read);
        self::assertEquals([new CollectionRule($this->f['checkbox']->key, 'is', ['0'])], $read->rules);
        self::assertSame([MatchMode::All, null, 'Shapes'], [$read->match, $read->sort, $read->name]);
        self::assertStringContainsString($key, $log);
        self::assertStringNotContainsString($this->f['text']->key, $log);

        foreach (['not json', '{"field":"x"}', '"text"', '', '[' . implode(',', array_fill(0, 11, $valid)) . ']'] as $value) {
            $this->db->run('UPDATE "_tiffin_collections" SET "rules" = ?, "match_mode" = \'all\' WHERE "key" = ?', [$value, $key]);
            [$read, $log] = $this->logged(fn (): array => $store->all());
            self::assertCount(1, $read);
            self::assertCount(str_starts_with($value, '[') ? 10 : 0, $read[0]->rules, $value);
            self::assertStringContainsString($key, $log);
        }
        $this->db->run('UPDATE "_tiffin_collections" SET "rules" = \'[]\' WHERE "key" = ?', [$key]);
        [$read, $log] = $this->logged(fn (): array => $store->all());
        self::assertSame([[], ''], [$read[0]->rules, $log]);
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return array{0: T, 1: string} the result and what was logged
     */
    private function logged(\Closure $work): array
    {
        $log = $this->temp->path('error.log');
        @unlink($log);
        $previous = ini_set('error_log', $log);
        try {
            $result = $work();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        return [$result, is_file($log) ? (string) file_get_contents($log) : ''];
    }

    public function testRuleShape(): void
    {
        $text = $this->f['text']->key;
        self::assertSame(['field' => $text, 'op' => 'not-empty', 'values' => []], (new CollectionRule($text, 'not-empty', []))->toArray());
        self::assertEquals(new CollectionRule($text, 'is', ['a']), CollectionRule::fromArray(['op' => 'is', 'values' => ['a'], 'field' => $text, 'extra' => 1]));
        new CollectionRule($text, 'is', [str_repeat('é', 200), '']);
        foreach ([
            ['bad', 'is', []],
            [$text, 'IS', []],
            [$text, 'like', []],
            [$text, 'is', ['a', 'b', 'c']],
            [$text, 'is', ['v' => 'a']],
            [$text, 'is', [1]],
            [$text, 'is', [str_repeat('é', 201)]],
            [$text, 'is', ["\xC3"]],
        ] as [$field, $op, $values]) {
            try {
                new CollectionRule($field, $op, $values);
                self::fail($op . ' ' . json_encode($values));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['x', null, ['field' => $text, 'op' => 'is'], ['field' => $text, 'op' => 'is', 'values' => 'a'], ['field' => 1, 'op' => 'is', 'values' => []]] as $entry) {
            try {
                CollectionRule::fromArray($entry);
                self::fail(json_encode($entry) ?: '');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------ T-COL-10

    public function testDeletingATableOrAField(): void
    {
        $store = $this->store();
        $onRooms = $store->create($this->rooms->key, 'Rooms', MatchMode::All, [], null, self::USER_KEY);
        $onItems = $store->create($this->items->key, 'Ticked', MatchMode::All, [$this->rule('checkbox', 'is', ['1'])], $this->f['checkbox']->key . '.asc', self::USER_KEY);

        // Deleting a field keeps the collection with its rule as stored (the rule is then broken, CollectionRulesTest).
        $this->editor()->deleteField($this->f['checkbox']->key);
        $kept = $store->get($onItems);
        self::assertNotNull($kept);
        self::assertEquals([$this->rule('checkbox', 'is', ['1'])], $kept->rules);
        self::assertSame($this->f['checkbox']->key . '.asc', $kept->sort);

        // Deleting a table deletes its collections.
        $this->editor()->deleteTable($this->rooms->key);
        self::assertNull($store->get($onRooms));
        self::assertSame([$onItems], array_map(static fn (Collection $c): string => $c->key, $store->all()));
    }
}
