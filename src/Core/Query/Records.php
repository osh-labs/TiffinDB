<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Schema\Type\AutoNumberType;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Record reading and writing (`docs/specs/M2-records.md` section 3, ADR
 * 0013). Takes no actor: Core\App checks permissions first. One instance
 * per opened project and request; the Schema must be current.
 *
 * Every key argument is checked: an unknown table or field key, a field of
 * another table, or a relation operation on a non-relation field is
 * NotFound. Record ids must be positive (\InvalidArgumentException).
 *
 * SQL: names come only from Identifier and from definition objects, and
 * statements whose shape depends on the schema are composed with Sql
 * (ListSql builds the list fragments). Every value is bound.
 */
final class Records
{
    public const PAGE_SIZE = 50;
    public const MAX_SEARCH_LENGTH = 200;
    public const SEARCH_HITS_PER_TABLE = 10;
    public const MAX_LINK_IDS = 1000;
    public const PICKER_LIMIT = 500;
    /** Ids per IN (...) list when reading titles. */
    private const CHUNK = 500;

    private readonly ListSql $lists;

    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        private readonly FieldTypes $types,
        private readonly Clock $clock,
    ) {
        $this->lists = new ListSql($schema, $types);
    }

    // ------------------------------------------------------------------ reading

    /** The record, live or in the trash; null when absent. */
    public function get(string $tableKey, int $id): ?RecordData
    {
        self::id($id);
        $table = $this->schema->table($tableKey);
        $row = $this->db->one('SELECT * FROM ' . $table->sql() . ' WHERE "id" = ?', [$id]);

        return $row === null ? null : $this->build($table, [$row])[0];
    }

    /** Live records of the table. */
    public function count(string $tableKey): int
    {
        $table = $this->schema->table($tableKey);

        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NULL');
    }

    /**
     * @param list<int> $ids
     * @return array<int, ?string> id => title text, for the given ids that exist (live or trashed), in the given order
     */
    public function titles(string $tableKey, array $ids): array
    {
        $table = $this->schema->table($tableKey);
        $ids = self::idList($ids, PHP_INT_MAX);
        $title = $this->lists->titleField($table);
        $found = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if ($title === null) {
                $rows = $this->db->all('SELECT "id", NULL AS "title" FROM ' . $table->sql() . ' WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')', $chunk);
            } else {
                $rows = $this->db->all(
                    'SELECT "id", ' . $title->sql() . ' AS "title" FROM ' . $table->sql() . ' WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')',
                    $chunk,
                );
            }
            foreach ($rows as $row) {
                $found[(int) $row['id']] = $title === null ? null : $this->lists->text($title, $row['title']);
            }
        }
        $titles = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $found)) {
                $titles[$id] = $found[$id];
            }
        }

        return $titles;
    }

    /**
     * Live records of the table in its default order (title ascending,
     * ASCII case-insensitive for text, then id; by id without a title field).
     *
     * @return list<LinkedRecord> at most $limit (1 to PICKER_LIMIT)
     */
    public function pickList(string $tableKey, int $limit): array
    {
        if ($limit < 1 || $limit > self::PICKER_LIMIT) {
            throw new \InvalidArgumentException('The pick list limit is out of range.');
        }
        $table = $this->schema->table($tableKey);
        $title = $this->lists->titleField($table);
        $order = $this->lists->order($table, $this->defaultSort($table));
        if ($title === null) {
            $select = Sql::of('SELECT r."id" AS "id", NULL AS "title" FROM ' . $table->sql() . ' AS "r" WHERE r."_trashed_at" IS NULL ORDER BY');
        } else {
            $select = Sql::of('SELECT r."id" AS "id", r.' . $title->sql() . ' AS "title" FROM ' . $table->sql() . ' AS "r" WHERE r."_trashed_at" IS NULL ORDER BY');
        }
        $statement = Sql::join(' ', [$select, $order, Sql::of('LIMIT ?', [$limit])]);
        $list = [];
        foreach ($this->db->all($statement->sql(), $statement->params()) as $row) {
            $list[] = new LinkedRecord($table->key, (int) $row['id'], $title === null ? null : $this->lists->text($title, $row['title']));
        }

        return $list;
    }

    // ------------------------------------------------------------------ create and update

    /**
     * Creates a record; returns its id (section 3.3). Auto-number fields
     * not given a value get the next number.
     *
     * @param array<string, mixed> $values field key => value as $mode expects
     * @throws ValidationFailed with every problem found; nothing is written
     */
    public function create(string $tableKey, array $values, InputMode $mode, string $userKey): int
    {
        $table = $this->schema->table($tableKey);
        self::person($userKey);
        $storage = $this->validate($table, $values, $mode, true);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($table, $storage, $userKey, $now): int {
            foreach ($this->schema->fields($table->key) as $candidate) {
                if ($candidate->type === 'auto_number' && !array_key_exists($candidate->key, $storage)) {
                    $storage[$candidate->key] = $this->nextNumber($db, $table, $candidate->key);
                }
            }
            $columns = [Sql::of('"_created_at", "_created_by", "_updated_at", "_updated_by"')];
            $params = [$now, $userKey, $now, $userKey];
            foreach ($storage as $fieldKey => $value) {
                $columns[] = Sql::of(Identifier::column((string) $fieldKey));
                $params[] = $value;
            }
            $insert = Sql::join(' ', [
                Sql::of('INSERT INTO ' . $table->sql()),
                Sql::join(', ', $columns)->wrap(),
                Sql::of('VALUES (' . Database::placeholders(count($params)) . ')', $params),
            ]);
            $db->run($insert->sql(), $insert->params());
            $id = $db->lastInsertId();
            ProjectFile::touch($db, $userKey, $this->clock);

            return $id;
        });
    }

    /**
     * Changes the given fields of a live record (section 3.3); fields not
     * given keep their values. When every given value equals the stored
     * one, nothing changes. Returns the record as stored afterwards.
     *
     * @param array<string, mixed> $values field key => value as $mode expects
     * @throws ValidationFailed before anything is read
     * @throws Conflict when $expectedVersion is not the record's current version
     * @throws NotFound when the record is absent or in the trash
     */
    public function update(string $tableKey, int $id, array $values, InputMode $mode, string $expectedVersion, string $userKey): RecordData
    {
        self::id($id);
        $table = $this->schema->table($tableKey);
        self::person($userKey);
        $storage = $this->validate($table, $values, $mode, false);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($table, $id, $storage, $expectedVersion, $userKey, $now): RecordData {
            $record = $this->get($table->key, $id);
            if ($record === null || $record->trashedAt !== null) {
                throw new NotFound('No such live record.');
            }
            if (!hash_equals($record->version, $expectedVersion)) {
                throw new Conflict($record->version);
            }
            $sets = [];
            foreach ($storage as $fieldKey => $value) {
                $field = $this->schema->field((string) $fieldKey);
                $current = $this->types->get($field->type)->toStorage($record->values[$field->key] ?? null, $field->options);
                if ($current !== $value) {
                    $sets[] = Sql::of(Identifier::column($field->key) . ' = ?', [$value]);
                }
            }
            if ($sets === []) {
                return $record;
            }
            $sets[] = Sql::of('"_updated_at" = ?, "_updated_by" = ?', [$now, $userKey]);
            $statement = Sql::join(' ', [
                Sql::of('UPDATE ' . $table->sql() . ' SET'),
                Sql::join(', ', $sets),
                Sql::of('WHERE "id" = ? AND "_trashed_at" IS NULL', [$id]),
            ]);
            $db->run($statement->sql(), $statement->params());
            ProjectFile::touch($db, $userKey, $this->clock);
            $after = $this->get($table->key, $id);
            if ($after === null) {
                throw new \LogicException('The record vanished inside the transaction.');
            }

            return $after;
        });
    }

    // ------------------------------------------------------------------ links

    /**
     * Makes $targetIds exactly the record's live links in the relation
     * field, in that order (section 3.4). Links to records in the trash are
     * kept and are not part of the list.
     *
     * @param list<int> $targetIds 0 to MAX_LINK_IDS positive ids; duplicates dropped, first kept
     * @throws ValidationFailed problem.link.target_missing, one_only, target_taken (field = $fieldKey)
     */
    public function setLinks(string $fieldKey, int $id, array $targetIds, string $userKey): void
    {
        self::id($id);
        $field = $this->schema->field($fieldKey);
        $relation = $this->lists->relation($field);
        $targets = $targetIds === [] ? [] : self::idList($targetIds, self::MAX_LINK_IDS);
        self::person($userKey);
        $table = $this->schema->table($field->tableKey);
        $other = $this->schema->table($relation->otherTable($field->key));
        $thisSide = ListSql::thisSide($relation, $field->key);
        $otherSide = ListSql::otherSide($relation, $field->key);
        $onlyOne = $relation->maxFor($field->key) === Cardinality::One;
        $targetOnce = $relation->maxFor($relation->otherField($field->key)) === Cardinality::One;
        $now = Timestamp::format($this->clock->now());

        $this->db->write(function (Database $db) use (
            $field, $relation, $table, $other, $thisSide, $otherSide, $onlyOne, $targetOnce, $targets, $id, $userKey, $now,
        ): void {
            $live = $db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "id" = ? AND "_trashed_at" IS NULL', [$id]);
            if ((int) $live !== 1) {
                throw new NotFound('No such live record.');
            }
            $existing = $db->all(
                'SELECT "jj".' . Identifier::system($otherSide) . ' AS "id", "o"."_trashed_at" AS "trashed" FROM ' . $relation->sql()
                . ' AS "jj" JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".' . Identifier::system($otherSide)
                . ' WHERE "jj".' . Identifier::system($thisSide) . ' = ?',
                [$id],
            );
            $keptTrashed = 0;
            $liveLinked = [];
            foreach ($existing as $row) {
                if ($row['trashed'] === null) {
                    $liveLinked[] = (int) $row['id'];
                } else {
                    $keptTrashed++;
                }
            }

            $problems = [];
            if ($targets !== []) {
                $liveTargets = array_map(
                    static fn (mixed $v): int => (int) $v,
                    array_column($db->all(
                        'SELECT "id" FROM ' . $other->sql() . ' WHERE "_trashed_at" IS NULL AND "id" IN (' . Database::placeholders(count($targets)) . ')',
                        $targets,
                    ), 'id'),
                );
                if (array_diff($targets, $liveTargets) !== []) {
                    $problems[] = new Problem($field->key, 'problem.link.target_missing');
                }
            }
            if ($onlyOne && count($targets) + $keptTrashed > 1) {
                $problems[] = new Problem($field->key, 'problem.link.one_only');
            }
            if ($targetOnce && $targets !== []) {
                $taken = array_map(
                    static fn (mixed $v): int => (int) $v,
                    array_column($db->all(
                        'SELECT DISTINCT "jj".' . Identifier::system($otherSide) . ' AS "id" FROM ' . $relation->sql() . ' AS "jj" WHERE "jj".'
                        . Identifier::system($thisSide) . ' <> ? AND "jj".' . Identifier::system($otherSide) . ' IN (' . Database::placeholders(count($targets)) . ')',
                        [$id, ...$targets],
                    ), 'id'),
                );
                $titles = $taken === [] ? [] : $this->titles($other->key, $taken);
                foreach ($targets as $target) {
                    if (in_array($target, $taken, true)) {
                        $problems[] = new Problem($field->key, 'problem.link.target_taken', ['title' => $titles[$target] ?? (string) $target]);
                    }
                }
            }
            if ($problems !== []) {
                throw new ValidationFailed($problems);
            }

            $remove = array_values(array_diff($liveLinked, $targets));
            if ($remove !== []) {
                $db->run(
                    'DELETE FROM ' . $relation->sql() . ' WHERE ' . Identifier::system($thisSide) . ' = ? AND '
                    . Identifier::system($otherSide) . ' IN (' . Database::placeholders(count($remove)) . ')',
                    [$id, ...$remove],
                );
            }
            foreach ($targets as $index => $target) {
                if (in_array($target, $liveLinked, true)) {
                    $db->run(
                        'UPDATE ' . $relation->sql() . ' SET "position" = ? WHERE ' . Identifier::system($thisSide) . ' = ? AND '
                        . Identifier::system($otherSide) . ' = ?',
                        [$index + 1, $id, $target],
                    );
                } else {
                    $db->run(
                        'INSERT INTO ' . $relation->sql() . ' (' . Identifier::system($thisSide) . ', ' . Identifier::system($otherSide)
                        . ', "position") VALUES (?, ?, ?)',
                        [$id, $target, $index + 1],
                    );
                }
            }
            $db->run('UPDATE ' . $table->sql() . ' SET "_updated_at" = ?, "_updated_by" = ? WHERE "id" = ?', [$now, $userKey, $id]);
            ProjectFile::touch($db, $userKey, $this->clock);
        });
    }

    // ------------------------------------------------------------------ lists

    /** @return list<Sort> every sort the table offers, in menu order (section 3.6) */
    public function sorts(string $tableKey): array
    {
        $table = $this->schema->table($tableKey);
        $sorts = [];
        foreach ($this->schema->fields($table->key) as $candidate) {
            if ($this->lists->isSortable($candidate)) {
                $sorts[] = new Sort($candidate->key, false);
                $sorts[] = new Sort($candidate->key, true);
            }
        }
        $sorts[] = new Sort('added', true);
        $sorts[] = new Sort('added', false);
        $sorts[] = new Sort('changed', true);

        return $sorts;
    }

    /**
     * Turns raw view state into a valid query; never throws for bad view
     * state (section 3.6). A refused filter or search is dropped and
     * reported; an unknown sort gives the default; pages below 1 become 1.
     *
     * @param array<mixed> $operands
     */
    public function query(
        string $tableKey,
        ?string $sort,
        ?string $filterField,
        ?string $operator,
        array $operands,
        ?string $search,
        int $page,
    ): NormalisedQuery {
        $table = $this->schema->table($tableKey);
        $chosen = $this->defaultSort($table);
        foreach ($this->sorts($table->key) as $offered) {
            if ($sort !== null && $offered->token() === $sort) {
                $chosen = $offered;
            }
        }
        $filter = null;
        $filterProblem = null;
        if ($filterField !== null) {
            $result = $this->normaliseFilter($table, $filterField, $operator, array_values($operands));
            if ($result instanceof Problem) {
                $filterProblem = $result;
            } else {
                $filter = $result;
            }
        }
        $text = null;
        $searchProblem = null;
        if ($search !== null) {
            $result = self::normaliseSearch($search);
            if ($result instanceof Problem) {
                $searchProblem = $result;
            } else {
                $text = $result;
            }
        }

        return new NormalisedQuery(new ListQuery($chosen, $filter, $text, max(1, $page)), $filterProblem, $searchProblem);
    }

    /** One page of live records matching the query, in its order (section 3.7). */
    public function page(string $tableKey, ListQuery $query): RecordPage
    {
        $table = $this->schema->table($tableKey);
        $where = $this->lists->where($table, $query);
        $order = $this->lists->order($table, $query->sort);
        $counting = Sql::join(' ', [Sql::of('SELECT COUNT(*) FROM ' . $table->sql() . ' AS "r" WHERE'), $where]);
        $total = (int) $this->db->value($counting->sql(), $counting->params());
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min($query->page, $pages);
        $select = Sql::join(' ', [
            Sql::of('SELECT r.* FROM ' . $table->sql() . ' AS "r" WHERE'),
            $where,
            Sql::of('ORDER BY'),
            $order,
            Sql::of('LIMIT ? OFFSET ?', [self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]),
        ]);
        $rows = $this->db->all($select->sql(), $select->params());

        return new RecordPage($this->build($table, $rows), $total, $this->count($table->key), $page, $pages, $query->sort);
    }

    /** The record's place in the list the query describes; null when it is not live or does not match. */
    public function position(string $tableKey, ListQuery $query, int $id): ?Position
    {
        self::id($id);
        $table = $this->schema->table($tableKey);
        $where = $this->lists->where($table, $query);
        $order = $this->lists->order($table, $query->sort);
        $statement = Sql::join(' ', [
            Sql::of(
                'SELECT "n", "total", "previous", "next" FROM (SELECT r."id" AS "id", ROW_NUMBER() OVER "w" AS "n", COUNT(*) OVER () AS "total",'
                . ' LAG(r."id") OVER "w" AS "previous", LEAD(r."id") OVER "w" AS "next" FROM ' . $table->sql() . ' AS "r" WHERE',
            ),
            $where,
            Sql::of('WINDOW "w" AS (ORDER BY'),
            $order,
            Sql::of(')) WHERE "id" = ?', [$id]),
        ]);
        $row = $this->db->one($statement->sql(), $statement->params());
        if ($row === null) {
            return null;
        }

        return new Position(
            (int) $row['n'],
            (int) $row['total'],
            $row['previous'] === null ? null : (int) $row['previous'],
            $row['next'] === null ? null : (int) $row['next'],
        );
    }

    /**
     * Searches every table (section 3.8): per table in table order, the
     * count of live matches and the first SEARCH_HITS_PER_TABLE in the
     * table's default order. Tables without a match are left out. Text
     * that normalises to nothing or is refused finds nothing.
     *
     * @return list<SearchGroup>
     */
    public function search(string $text): array
    {
        $text = self::normaliseSearch($text);
        if (!is_string($text)) {
            return [];
        }
        $groups = [];
        foreach ($this->schema->tables() as $candidate) {
            $group = $this->searchTable($candidate->key, $text);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    // ------------------------------------------------------------------ internals

    private function searchTable(string $tableKey, string $text): ?SearchGroup
    {
        $table = $this->schema->table($tableKey);
        $query = new ListQuery($this->defaultSort($table), null, $text, 1);
        $where = $this->lists->where($table, $query);
        $counting = Sql::join(' ', [Sql::of('SELECT COUNT(*) FROM ' . $table->sql() . ' AS "r" WHERE'), $where]);
        $total = (int) $this->db->value($counting->sql(), $counting->params());
        if ($total === 0) {
            return null;
        }
        $select = Sql::join(' ', [
            Sql::of('SELECT r.* FROM ' . $table->sql() . ' AS "r" WHERE'),
            $where,
            Sql::of('ORDER BY'),
            $this->lists->order($table, $query->sort),
            Sql::of('LIMIT ?', [self::SEARCH_HITS_PER_TABLE]),
        ]);
        $title = $this->lists->titleField($table);
        $hits = [];
        foreach ($this->build($table, $this->db->all($select->sql(), $select->params())) as $record) {
            $hits[] = $this->hit($table, $title, $record, $text);
        }

        return new SearchGroup($table->key, $total, $hits);
    }

    private function hit(TableDef $table, ?FieldDef $title, RecordData $record, string $text): SearchHit
    {
        $titleText = null;
        if ($title !== null && $record->values[$title->key] !== null) {
            $titleText = $this->types->get($title->type)->toText($record->values[$title->key], $title->options);
        }
        foreach ($this->schema->fields($table->key) as $candidate) {
            if (($title !== null && $candidate->key === $title->key) || !$this->lists->isSearchable($candidate)) {
                continue;
            }
            if ($candidate->type === 'relation') {
                foreach ($record->links[$candidate->key] ?? [] as $linked) {
                    if ($linked->title !== null && stripos($linked->title, $text) !== false) {
                        return new SearchHit($record->id, $titleText, $candidate->key, $linked->title);
                    }
                }

                continue;
            }
            $value = $record->values[$candidate->key] ?? null;
            if ($value === null) {
                continue;
            }
            $shown = $this->types->get($candidate->type)->toText($value, $candidate->options);
            if (stripos($shown, $text) !== false) {
                return new SearchHit($record->id, $titleText, $candidate->key, $shown);
            }
        }

        return new SearchHit($record->id, $titleText, null, null);
    }

    /** The title field ascending when the table has a sortable one, else the order added. */
    private function defaultSort(TableDef $table): Sort
    {
        $title = $this->lists->titleField($table);
        if ($title !== null && $this->lists->isSortable($title)) {
            return new Sort($title->key, false);
        }

        return new Sort('added', false);
    }

    /**
     * @param list<mixed> $operands
     */
    private function normaliseFilter(TableDef $table, string $fieldKey, ?string $operator, array $operands): Condition|Problem
    {
        $field = null;
        foreach ($this->schema->fields($table->key) as $candidate) {
            if ($candidate->key === $fieldKey) {
                $field = $candidate;
            }
        }
        $op = Operator::tryFrom($operator ?? '');
        if ($field === null || $op === null || !in_array($op->value, ListSql::FILTERS[$field->type] ?? [], true)) {
            return new Problem(null, 'problem.filter.invalid');
        }
        if ($op === Operator::Empty) {
            return new Condition($field->key, $op, []);
        }
        $first = is_string($operands[0] ?? null) ? $operands[0] : '';
        $second = is_string($operands[1] ?? null) ? $operands[1] : '';
        if ($op === Operator::Between) {
            if ($first === '' && $second === '') {
                return new Problem(null, 'problem.filter.value');
            }
            $date = $this->types->get('date');
            $ends = [];
            foreach (array_slice($operands, 0, 2) + ['', ''] as $end) {
                $end = is_string($end) ? $end : '';
                if ($end === '') {
                    $ends[] = '';

                    continue;
                }
                $checked = $date->parse($end, [], false);
                if (!$checked->isOk() || !is_string($checked->value())) {
                    return new Problem(null, 'problem.date.invalid');
                }
                $ends[] = $checked->value();
            }
            if ($ends[0] !== '' && $ends[1] !== '' && strcmp($ends[0], $ends[1]) > 0) {
                return new Problem(null, 'problem.filter.range');
            }

            return new Condition($field->key, $op, $ends);
        }
        if ($first === '') {
            return new Problem(null, 'problem.filter.value');
        }
        if ($field->type === 'checkbox') {
            return $first === '1' || $first === '0' ? new Condition($field->key, $op, [$first]) : new Problem(null, 'problem.filter.value');
        }
        if ($field->type === 'choice') {
            return in_array($first, $this->lists->labels($field), true) ? new Condition($field->key, $op, [$first]) : new Problem(null, 'problem.filter.value');
        }
        // relation, links-to: a live record of the linked table.
        try {
            $target = ListSql::recordId($first);
        } catch (\InvalidArgumentException) {
            return new Problem(null, 'problem.filter.value');
        }
        $other = $this->schema->table($this->lists->relation($field)->otherTable($field->key));
        $live = $this->db->value('SELECT COUNT(*) FROM ' . $other->sql() . ' WHERE "id" = ? AND "_trashed_at" IS NULL', [$target]);

        return (int) $live === 1 ? new Condition($field->key, $op, [(string) $target]) : new Problem(null, 'problem.filter.value');
    }

    /** Trimmed search text, null for none, or the problem that refuses it. */
    private static function normaliseSearch(string $search): string|Problem|null
    {
        if (!Text::isUtf8($search)) {
            return new Problem(null, 'problem.text.control');
        }
        $search = Text::trim($search);
        if ($search === '') {
            return null;
        }
        if (Text::hasControl($search)) {
            return new Problem(null, 'problem.text.control');
        }
        if (Text::length($search) > self::MAX_SEARCH_LENGTH) {
            return new Problem(null, 'problem.text.too_long', ['max' => self::MAX_SEARCH_LENGTH]);
        }

        return $search;
    }

    /**
     * Checks every given value (section 3.3) and returns the stored form of
     * each, by field key. An auto-number given no value is left out, so
     * create numbers it and update keeps it.
     *
     * @param array<mixed> $values
     * @return array<string, string|int|float|null>
     */
    private function validate(TableDef $table, array $values, InputMode $mode, bool $create): array
    {
        $fields = [];
        foreach ($this->schema->fields($table->key) as $candidate) {
            $fields[$candidate->key] = $candidate;
        }
        $problems = [];
        $storage = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $field = $fields[$key] ?? null;
            if ($field === null) {
                $problems[] = new Problem($key, 'problem.value.unknown_field');

                continue;
            }
            if (!$field->hasColumn) {
                $problems[] = new Problem($key, 'problem.value.unsupported');

                continue;
            }
            $type = $this->types->get($field->type);
            if ($mode === InputMode::Canonical) {
                $checked = $type->check($value, $field->options);
            } elseif (is_string($value)) {
                $checked = $type->parse($value, $field->options, $mode === InputMode::Import);
            } else {
                throw new \InvalidArgumentException('Form and import values are text.');
            }
            if (!$checked->isOk()) {
                $problems[] = new Problem($key, (string) $checked->problemKey(), $checked->problemParams());

                continue;
            }
            $canonical = $checked->value();
            if ($canonical === null && $field->required) {
                $problems[] = new Problem($key, 'problem.value.required');

                continue;
            }
            if ($canonical === null && $field->type === 'auto_number') {
                continue;
            }
            $storage[$key] = $type->toStorage($canonical, $field->options);
        }
        if ($create) {
            foreach ($fields as $key => $field) {
                if ($field->required && $field->hasColumn && !array_key_exists($key, $values)) {
                    $problems[] = new Problem($key, 'problem.value.required');
                }
            }
        }
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }

        return $storage;
    }

    /**
     * The next number of an auto-number field: the larger of its `next`
     * option and one more than the highest stored number; advances `next`.
     * Runs inside the create transaction.
     */
    private function nextNumber(Database $db, TableDef $table, string $fieldKey): int
    {
        $text = $db->value('SELECT "options" FROM "_tiffin_fields" WHERE "key" = ?', [$fieldKey]);
        $options = json_decode(is_string($text) ? $text : '', true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($options)) {
            throw new \UnexpectedValueException('Field options are not an object.');
        }
        $next = is_int($options['next'] ?? null) ? $options['next'] : 1;
        $highest = $db->value('SELECT MAX(' . Identifier::column($fieldKey) . ') FROM ' . $table->sql());
        $number = $highest === null ? $next : max($next, (int) $highest + 1);
        if ($number > AutoNumberType::MAX) {
            throw new \OverflowException('The auto-number is at its largest value.');
        }
        $options['next'] = $number + 1;
        $db->run(
            'UPDATE "_tiffin_fields" SET "options" = ? WHERE "key" = ?',
            [json_encode((object) $options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $fieldKey],
        );

        return $number;
    }

    /**
     * RecordData for rows of the table, with links and attachments read
     * with one query per relation or attachment field.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<RecordData>
     */
    private function build(TableDef $table, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $fields = $this->schema->fields($table->key);
        $links = [];
        $attachments = [];
        foreach ($fields as $candidate) {
            if ($candidate->type === 'relation') {
                $links[$candidate->key] = $this->linksOf($candidate->key, $ids);
            } elseif ($candidate->type === 'attachment') {
                $attachments[$candidate->key] = $this->attachmentsOf($candidate->key, $ids);
            }
        }
        $records = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $values = [];
            foreach ($fields as $candidate) {
                if ($candidate->hasColumn) {
                    $values[$candidate->key] = $this->types->get($candidate->type)
                        ->fromStorage($row[Identifier::columnName($candidate->key)] ?? null, $candidate->options);
                }
            }
            $recordLinks = [];
            $linkIds = [];
            foreach ($links as $fieldKey => $byRecord) {
                $recordLinks[$fieldKey] = $byRecord[$id]['live'] ?? [];
                $linkIds[$fieldKey] = $byRecord[$id]['all'] ?? [];
            }
            $recordFiles = [];
            foreach ($attachments as $fieldKey => $byRecord) {
                $recordFiles[$fieldKey] = $byRecord[$id] ?? [];
            }
            $records[] = new RecordData(
                $id,
                $values,
                $recordLinks,
                $recordFiles,
                (string) $row['_created_at'],
                is_string($row['_created_by']) ? $row['_created_by'] : null,
                (string) $row['_updated_at'],
                is_string($row['_updated_by']) ? $row['_updated_by'] : null,
                is_string($row['_trashed_at']) ? $row['_trashed_at'] : null,
                is_string($row['_trashed_by']) ? $row['_trashed_by'] : null,
                RecordVersion::of($row, $linkIds),
            );
        }

        return $records;
    }

    /**
     * Links of the records in one relation field: per record id, every
     * linked id (live or trashed) and the live targets in link order
     * (position, then id).
     *
     * @param list<int> $ids
     * @return array<int, array{all: list<int>, live: list<LinkedRecord>}>
     */
    private function linksOf(string $fieldKey, array $ids): array
    {
        $field = $this->schema->field($fieldKey);
        $relation = $this->lists->relation($field);
        $other = $this->schema->table($relation->otherTable($field->key));
        $title = $this->lists->titleField($other);
        $thisSide = ListSql::thisSide($relation, $field->key);
        $otherSide = ListSql::otherSide($relation, $field->key);
        if ($title === null) {
            $titleColumn = Sql::of('NULL AS "title"');
        } else {
            $titleColumn = Sql::of('"o".' . $title->sql() . ' AS "title"');
        }
        $statement = Sql::join(' ', [
            Sql::of('SELECT "jj".' . Identifier::system($thisSide) . ' AS "rid", "jj".' . Identifier::system($otherSide) . ' AS "oid", "o"."_trashed_at" AS "trashed",'),
            $titleColumn,
            Sql::of(
                'FROM ' . $relation->sql() . ' AS "jj" JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".' . Identifier::system($otherSide)
                . ' WHERE "jj".' . Identifier::system($thisSide) . ' IN (' . Database::placeholders(count($ids)) . ')'
                . ' ORDER BY "jj".' . Identifier::system($thisSide) . ', "jj"."position", "jj".' . Identifier::system($otherSide),
                $ids,
            ),
        ]);
        $byRecord = [];
        foreach ($this->db->all($statement->sql(), $statement->params()) as $row) {
            $recordId = (int) $row['rid'];
            $linkedId = (int) $row['oid'];
            $byRecord[$recordId] ??= ['all' => [], 'live' => []];
            $byRecord[$recordId]['all'][] = $linkedId;
            if ($row['trashed'] === null) {
                $byRecord[$recordId]['live'][] = new LinkedRecord($other->key, $linkedId, $title === null ? null : $this->lists->text($title, $row['title']));
            }
        }

        return $byRecord;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<AttachmentSummary>> by record id, in link order
     */
    private function attachmentsOf(string $fieldKey, array $ids): array
    {
        $rows = $this->db->all(
            'SELECT "l"."record_id" AS "rid", "a"."id" AS "id", "a"."original_name" AS "name", "a"."media_type" AS "type", "a"."size" AS "size"'
            . ' FROM "_tiffin_attachment_links" AS "l" JOIN "_tiffin_attachments" AS "a" ON "a"."id" = "l"."attachment_id"'
            . ' WHERE "l"."field_key" = ? AND "l"."record_id" IN (' . Database::placeholders(count($ids)) . ')'
            . ' ORDER BY "l"."record_id", "l"."position", "a"."id"',
            [$fieldKey, ...$ids],
        );
        $byRecord = [];
        foreach ($rows as $row) {
            $byRecord[(int) $row['rid']][] = new AttachmentSummary((int) $row['id'], (string) $row['name'], (string) $row['type'], (int) $row['size']);
        }

        return $byRecord;
    }

    private static function id(int $id): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids must be positive integers.');
        }
    }

    /**
     * @param array<mixed> $ids
     * @return list<int> positive ids without duplicates, first kept
     */
    private static function idList(array $ids, int $max): array
    {
        if (count($ids) > $max) {
            throw new \InvalidArgumentException('Too many record ids.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Record ids must be positive integers.');
            }
        }

        return array_values(array_unique($ids));
    }

    private static function person(string $userKey): void
    {
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
    }
}
