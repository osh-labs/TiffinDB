<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;

/**
 * The relation picker's candidate query (M4 spec section 4.4, ADR 0019
 * decision 9, P174). Read only; takes no actor (Core\App checks
 * permissions first). Candidates are live records of the other table that
 * the record does not link (live or in the trash), in that table's
 * default list order, PAGE_SIZE per page; a self-relation offers the
 * record itself.
 *
 * SQL: as Records (ListSql builds the order and search fragments). The
 * exclusion is a NOT EXISTS on the join table by its key, with the record
 * id bound; joins of the join table with a user table are CROSS JOINs
 * with the join row first.
 */
final class Picker
{
    public const PAGE_SIZE = 50;
    /** Ids per IN (...) list when reading titles. */
    private const CHUNK = 500;

    private readonly ListSql $lists;

    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        FieldTypes $types,
    ) {
        $this->lists = new ListSql($schema, $types);
    }

    /**
     * The candidates for a relation field of a live record, ordered as the
     * other table's default list, PAGE_SIZE per page; pages below 1 become
     * 1 and pages past the end the last page (P84). A refused search gives
     * searchProblem and an unfiltered list.
     *
     * @throws NotFound for a field that is not a relation field, or a record that is absent or in the trash
     */
    public function page(string $fieldKey, int $recordId, ?string $search, int $page): CandidatePage
    {
        if ($recordId < 1) {
            throw new \InvalidArgumentException('Record ids must be positive integers.');
        }
        $field = $this->schema->field($fieldKey);
        $relation = $this->lists->relation($field);
        $table = $this->schema->table($field->tableKey);
        $other = $this->schema->table($relation->otherTable($field->key));
        $live = $this->db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "id" = ? AND "_trashed_at" IS NULL', [$recordId]);
        if ((int) $live !== 1) {
            throw new NotFound('No such live record.');
        }

        $text = null;
        $searchProblem = null;
        if ($search !== null) {
            $normalised = Records::normaliseSearch($search);
            if ($normalised instanceof Problem) {
                $searchProblem = $normalised;
            } else {
                $text = $normalised;
            }
        }
        [$linked, $trashedLinks] = $this->links($relation, $field, $other, $recordId);
        $where = $this->where($relation, $field, $other, $recordId, $text);
        $counting = Sql::join(' ', [Sql::of('SELECT COUNT(*) FROM ' . $other->sql() . ' AS "r" WHERE'), $where]);
        $total = (int) $this->db->value($counting->sql(), $counting->params());
        $pages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        $title = $this->lists->titleField($other);
        if ($title === null) {
            $columns = Sql::of('SELECT r."id" AS "id", NULL AS "title" FROM ' . $other->sql() . ' AS "r" WHERE');
        } else {
            $columns = Sql::of('SELECT r."id" AS "id", r.' . $title->sql() . ' AS "title" FROM ' . $other->sql() . ' AS "r" WHERE');
        }
        $select = Sql::join(' ', [
            $columns,
            $where,
            Sql::of('ORDER BY'),
            $this->lists->order($other, $this->defaultSort($other)),
            Sql::of('LIMIT ? OFFSET ?', [self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]),
        ]);
        $rows = $this->db->all($select->sql(), $select->params());
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $takenBy = $this->takenBy($relation, $field, $table, $recordId, $ids);
        $candidates = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $candidates[] = new Candidate($id, $title === null ? null : $this->lists->text($title, $row['title']), $takenBy[$id] ?? null);
        }

        return new CandidatePage(
            $linked,
            $candidates,
            $total,
            $page,
            $pages,
            $trashedLinks,
            $relation->maxFor($field->key) === Cardinality::One,
            $text,
            $searchProblem,
        );
    }

    /**
     * Live records of the other table that the record does not link, live
     * or trashed, matching the search when given.
     */
    private function where(RelationDef $relation, FieldDef $field, TableDef $other, int $recordId, ?string $text): Sql
    {
        $parts = [
            Sql::of('r."_trashed_at" IS NULL'),
            Sql::of(
                'NOT EXISTS (SELECT 1 FROM ' . $relation->sql() . ' AS "jj" WHERE "jj".' . Identifier::system(ListSql::thisSide($relation, $field->key))
                . ' = ? AND "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key)) . ' = r."id")',
                [$recordId],
            ),
        ];
        if ($text !== null) {
            $parts[] = $this->lists->search($other, $text);
        }

        return Sql::join(' AND ', $parts);
    }

    /**
     * The record's live links in link order (position, then id), and how
     * many of its links point to records in the trash.
     *
     * @return array{0: list<LinkedRecord>, 1: int}
     */
    private function links(RelationDef $relation, FieldDef $field, TableDef $other, int $recordId): array
    {
        $title = $this->lists->titleField($other);
        if ($title === null) {
            $titleColumn = Sql::of('NULL AS "title"');
        } else {
            $titleColumn = Sql::of('"o".' . $title->sql() . ' AS "title"');
        }
        $statement = Sql::join(' ', [
            Sql::of('SELECT "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key)) . ' AS "id", "o"."_trashed_at" AS "trashed",'),
            $titleColumn,
            Sql::of(
                'FROM ' . $relation->sql() . ' AS "jj" CROSS JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key))
                . ' WHERE "jj".' . Identifier::system(ListSql::thisSide($relation, $field->key)) . ' = ? ORDER BY "jj"."position", "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key)),
                [$recordId],
            ),
        ]);
        $linked = [];
        $trashed = 0;
        foreach ($this->db->all($statement->sql(), $statement->params()) as $row) {
            if ($row['trashed'] !== null) {
                $trashed++;

                continue;
            }
            $linked[] = new LinkedRecord($other->key, (int) $row['id'], $title === null ? null : $this->lists->text($title, $row['title']));
        }

        return [$linked, $trashed];
    }

    /**
     * When the other side allows one link: the record of this table that
     * holds each candidate, by candidate id (other records only).
     *
     * @param list<int> $ids
     * @return array<int, LinkedRecord>
     */
    private function takenBy(RelationDef $relation, FieldDef $field, TableDef $table, int $recordId, array $ids): array
    {
        if ($ids === [] || $relation->maxFor($relation->otherField($field->key)) !== Cardinality::One) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key)) . ' AS "oid", "jj".' . Identifier::system(ListSql::thisSide($relation, $field->key)) . ' AS "hid" FROM ' . $relation->sql() . ' AS "jj" WHERE "jj".' . Identifier::system(ListSql::otherSide($relation, $field->key))
            . ' IN (' . Database::placeholders(count($ids)) . ') AND "jj".' . Identifier::system(ListSql::thisSide($relation, $field->key)) . ' <> ?',
            [...$ids, $recordId],
        );
        $holders = [];
        foreach ($rows as $row) {
            $holders[(int) $row['oid']] = (int) $row['hid'];
        }
        $titles = $this->titles($table, array_values(array_unique($holders)));
        $taken = [];
        foreach ($holders as $candidate => $holder) {
            $taken[$candidate] = new LinkedRecord($table->key, $holder, $titles[$holder] ?? null);
        }

        return $taken;
    }

    /**
     * @param list<int> $ids
     * @return array<int, ?string> id => title text
     */
    private function titles(TableDef $table, array $ids): array
    {
        $title = $this->lists->titleField($table);
        if ($title === null || $ids === []) {
            return [];
        }
        $titles = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->all(
                'SELECT "id", ' . $title->sql() . ' AS "title" FROM ' . $table->sql() . ' WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')',
                $chunk,
            );
            foreach ($rows as $row) {
                $titles[(int) $row['id']] = $this->lists->text($title, $row['title']);
            }
        }

        return $titles;
    }

    /** The other table's default list order (as Records): its title ascending when sortable, else the order added. */
    private function defaultSort(TableDef $table): Sort
    {
        $title = $this->lists->titleField($table);
        if ($title !== null && $this->lists->isSortable($title)) {
            return new Sort($title->key, false);
        }

        return new Sort('added', false);
    }
}
