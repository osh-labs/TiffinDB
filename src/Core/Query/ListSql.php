<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;

/**
 * The SQL fragments of list, search, and position queries
 * (`docs/specs/M2-records.md` sections 3.6 to 3.8), for Records. Every
 * fragment is built with Sql::of() from string literals and names that
 * come from Identifier or from definition objects; every value (labels,
 * dates, ids, search patterns, list positions) is a bound parameter. The
 * record row is always aliased "r", a join row "jj", and the record at the
 * other end of a link "o". Every join of a join table with the other
 * table is a CROSS JOIN, which SQLite never reorders: the join row is
 * found first through its key on r."id", then "o" by its primary key.
 * With a plain JOIN, statistics that make the other table's trash index
 * look selective lead SQLite to scan that index for every record.
 *
 * Internal helper of Core\Query (not part of the record layer's API).
 */
final class ListSql
{
    /** Types sorted ignoring ASCII case and searched with LIKE. */
    public const TEXT_LIKE = ['text', 'long_text', 'url', 'email', 'phone'];
    /** Types whose values a search looks at (ADR 0013 decision 5). */
    public const SEARCHABLE = ['text', 'long_text', 'choice', 'url', 'email', 'phone'];
    /** Types sorted by their stored value as SQLite compares it. */
    public const PLAIN_SORT = ['number', 'currency', 'rating', 'duration', 'auto_number', 'date', 'time', 'date_time', 'checkbox'];
    /** Field type => operators a filter takes (ADR 0013 decision 4). */
    public const FILTERS = [
        'choice' => ['is', 'empty'],
        'checkbox' => ['is'],
        'relation' => ['links-to', 'empty'],
        'date' => ['between', 'empty'],
        'date_time' => ['between', 'empty'],
    ];

    public function __construct(private readonly Schema $schema, private readonly FieldTypes $types)
    {
    }

    /** The table's title field, or null when it has none. */
    public function titleField(TableDef $table): ?FieldDef
    {
        if ($table->titleFieldKey === null) {
            return null;
        }
        $field = $this->schema->field($table->titleFieldKey);

        return $field->hasColumn ? $field : null;
    }

    /** Whether the field can order a list: every type but attachment; a relation only when the linked table has a title field. */
    public function isSortable(FieldDef $field): bool
    {
        if ($field->type === 'relation') {
            $relation = $this->relation($field);

            return $this->titleField($this->schema->table($relation->otherTable($field->key))) !== null;
        }

        return in_array($field->type, self::TEXT_LIKE, true) || in_array($field->type, self::PLAIN_SORT, true) || $field->type === 'choice';
    }

    /** Whether a search looks at the field (a column of a searchable type, or a relation whose linked title is one). */
    public function isSearchable(FieldDef $field): bool
    {
        if ($field->type === 'relation') {
            $title = $this->titleField($this->schema->table($this->relation($field)->otherTable($field->key)));

            return $title !== null && in_array($title->type, self::SEARCHABLE, true);
        }

        return $field->hasColumn && in_array($field->type, self::SEARCHABLE, true);
    }

    /** The relation of a relation field. */
    public function relation(FieldDef $field): RelationDef
    {
        $relation = $this->schema->relationOf($field);
        if ($relation === null) {
            throw new NotFound('Not a relation field.');
        }

        return $relation;
    }

    /** 'left_id' or 'right_id': the join column holding the id of the record that has this field. */
    public static function thisSide(RelationDef $relation, string $fieldKey): string
    {
        return $relation->sideOf($fieldKey) === 'left' ? 'left_id' : 'right_id';
    }

    /** The join column holding the id of the record at the other end. */
    public static function otherSide(RelationDef $relation, string $fieldKey): string
    {
        return $relation->sideOf($fieldKey) === 'left' ? 'right_id' : 'left_id';
    }

    /** `r."_trashed_at" IS NULL`, AND the query's condition, AND its search. */
    public function where(TableDef $table, ListQuery $query): Sql
    {
        $parts = [Sql::of('r."_trashed_at" IS NULL')];
        if ($query->filter !== null) {
            $parts[] = $this->filter($table, $query->filter);
        }
        if ($query->search !== null && $query->search !== '') {
            $parts[] = $this->search($table, $query->search);
        }

        return Sql::join(' AND ', $parts);
    }

    /**
     * The ORDER BY list (without the keywords): empties last in both
     * directions, ties by id ascending (section 3.7).
     */
    public function order(TableDef $table, Sort $sort): Sql
    {
        if ($sort->by === 'added') {
            if ($sort->descending) {
                return Sql::of('r."id" DESC');
            }

            return Sql::of('r."id" ASC');
        }
        if ($sort->by === 'changed') {
            return Sql::of('r."_updated_at" DESC, r."id" DESC');
        }
        $field = $this->fieldOf($table, $sort->by);
        if (!$this->isSortable($field)) {
            throw new \InvalidArgumentException('That field cannot order a list.');
        }
        if ($sort->descending) {
            $direction = Sql::of('DESC');
        } else {
            $direction = Sql::of('ASC');
        }

        if ($field->type === 'relation') {
            $relation = $this->relation($field);
            $other = $this->schema->table($relation->otherTable($field->key));
            $title = $this->titleField($other);
            if ($title === null) {
                throw new \InvalidArgumentException('That field cannot order a list.');
            }
            $thisSide = self::thisSide($relation, $field->key);
            $otherSide = self::otherSide($relation, $field->key);
            if (in_array($title->type, self::TEXT_LIKE, true)) {
                $lowest = Sql::of(
                    '(SELECT MIN("o".' . $title->sql() . ' COLLATE NOCASE) FROM ' . $relation->sql() . ' AS "jj" CROSS JOIN ' . $other->sql()
                    . ' AS "o" ON "o"."id" = "jj".' . Identifier::system($otherSide) . ' WHERE "jj".' . Identifier::system($thisSide)
                    . ' = r."id" AND "o"."_trashed_at" IS NULL)',
                );
                $term = Sql::join(' ', [$lowest, Sql::of('COLLATE NOCASE'), $direction]);
            } else {
                $lowest = Sql::of(
                    '(SELECT MIN("o".' . $title->sql() . ') FROM ' . $relation->sql() . ' AS "jj" CROSS JOIN ' . $other->sql()
                    . ' AS "o" ON "o"."id" = "jj".' . Identifier::system($otherSide) . ' WHERE "jj".' . Identifier::system($thisSide)
                    . ' = r."id" AND "o"."_trashed_at" IS NULL)',
                );
                $term = Sql::join(' ', [$lowest, $direction]);
            }

            return Sql::join(', ', [Sql::join(' ', [$lowest, Sql::of('IS NULL')])->wrap(), $term, Sql::of('r."id" ASC')]);
        }

        $isEmpty = Sql::of('(r.' . $field->sql() . ' IS NULL)');
        if ($field->type === 'choice') {
            // Each label's place in the list, all bound: CASE r."f" WHEN ? THEN ? ... ELSE ? END.
            $case = [Sql::of('CASE r.' . $field->sql())];
            $labels = $this->labels($field);
            foreach ($labels as $place => $label) {
                $case[] = Sql::of('WHEN ? THEN ?', [$label, $place]);
            }
            $case[] = Sql::of('ELSE ? END', [count($labels)]);
            $term = Sql::join(' ', [Sql::join(' ', $case)->wrap(), $direction]);
        } elseif (in_array($field->type, self::TEXT_LIKE, true)) {
            $term = Sql::join(' ', [Sql::of('r.' . $field->sql() . ' COLLATE NOCASE'), $direction]);
        } else {
            $term = Sql::join(' ', [Sql::of('r.' . $field->sql()), $direction]);
        }

        return Sql::join(', ', [$isEmpty, $term, Sql::of('r."id" ASC')]);
    }

    /**
     * The condition on the record row "r" (section 3.6 table). The
     * condition must already be valid (Records::query() makes it so);
     * a combination the table does not list is \InvalidArgumentException.
     */
    public function filter(TableDef $table, Condition $condition): Sql
    {
        $field = $this->fieldOf($table, $condition->fieldKey);
        $operator = $condition->operator->value;
        if (!in_array($operator, self::FILTERS[$field->type] ?? [], true)) {
            throw new \InvalidArgumentException('That filter cannot be used with this field.');
        }
        $operands = $condition->operands;

        if ($field->type === 'relation') {
            $relation = $this->relation($field);
            $other = $this->schema->table($relation->otherTable($field->key));
            $thisSide = self::thisSide($relation, $field->key);
            $otherSide = self::otherSide($relation, $field->key);
            if ($operator === 'links-to') {
                return Sql::of(
                    'EXISTS (SELECT 1 FROM ' . $relation->sql() . ' AS "jj" WHERE "jj".' . Identifier::system($thisSide)
                    . ' = r."id" AND "jj".' . Identifier::system($otherSide) . ' = ?)',
                    [self::recordId($operands[0] ?? '')],
                );
            }

            return Sql::of(
                'NOT EXISTS (SELECT 1 FROM ' . $relation->sql() . ' AS "jj" CROSS JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".'
                . Identifier::system($otherSide) . ' WHERE "jj".' . Identifier::system($thisSide) . ' = r."id" AND "o"."_trashed_at" IS NULL)',
            );
        }
        if ($operator === 'empty') {
            return Sql::of('r.' . $field->sql() . ' IS NULL');
        }
        if ($field->type === 'checkbox') {
            $ticked = $operands[0] ?? '';
            if ($ticked !== '1' && $ticked !== '0') {
                throw new \InvalidArgumentException('A checkbox filter is 1 or 0.');
            }

            return Sql::of('r.' . $field->sql() . ' = ?', [$ticked === '1' ? 1 : 0]);
        }
        if ($field->type === 'choice') {
            if (!is_string($operands[0] ?? null)) {
                throw new \InvalidArgumentException('A choice filter needs a label.');
            }

            return Sql::of('r.' . $field->sql() . ' = ?', [$operands[0]]);
        }

        // between: [from, to], each a canonical date or '' (open end).
        $from = $operands[0] ?? '';
        $to = $operands[1] ?? '';
        foreach (array_slice($operands, 0, 2) as $end) {
            if ($end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $end) !== 1) {
                throw new \InvalidArgumentException('A range end is a date or empty.');
            }
        }
        if ($field->type === 'date_time') {
            $from = $from === '' ? '' : $from . 'T00:00:00';
            $to = $to === '' ? '' : $to . 'T23:59:59';
        }
        $parts = [];
        if ($from !== '') {
            $parts[] = Sql::of('r.' . $field->sql() . ' >= ?', [$from]);
        }
        if ($to !== '') {
            $parts[] = Sql::of('r.' . $field->sql() . ' <= ?', [$to]);
        }
        if ($parts === []) {
            throw new \InvalidArgumentException('A range needs at least one end.');
        }

        return Sql::join(' AND ', $parts);
    }

    /**
     * The search condition on "r" for already normalised text: the OR of
     * LIKE over each searchable column and each searchable relation's
     * linked live titles; `0` (matches nothing) when the table has no
     * searchable field. `%`, `_`, and `\` in the text match literally.
     */
    public function search(TableDef $table, string $text): Sql
    {
        $pattern = '%' . self::escapeLike($text) . '%';
        $parts = [];
        foreach ($this->schema->fields($table->key) as $candidate) {
            if (!$this->isSearchable($candidate)) {
                continue;
            }
            $parts[] = $this->searchTerm($candidate->key, $pattern);
        }
        if ($parts === []) {
            return Sql::of('0');
        }

        return Sql::join(' OR ', $parts)->wrap();
    }

    /** `\` before each `\`, `%`, and `_`, for LIKE ... ESCAPE '\'. */
    public static function escapeLike(string $text): string
    {
        return (string) preg_replace('/[\\\\%_]/', '\\\\$0', $text);
    }

    private function searchTerm(string $fieldKey, string $pattern): Sql
    {
        $field = $this->schema->field($fieldKey);
        if ($field->type !== 'relation') {
            return Sql::of('r.' . $field->sql() . ' LIKE ? ESCAPE \'\\\'', [$pattern]);
        }
        $relation = $this->relation($field);
        $other = $this->schema->table($relation->otherTable($field->key));
        $title = $this->titleField($other);
        if ($title === null) {
            throw new \LogicException('A searchable relation has a linked title field.');
        }

        return Sql::of(
            'EXISTS (SELECT 1 FROM ' . $relation->sql() . ' AS "jj" CROSS JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".'
            . Identifier::system(self::otherSide($relation, $field->key)) . ' WHERE "jj".' . Identifier::system(self::thisSide($relation, $field->key))
            . ' = r."id" AND "o"."_trashed_at" IS NULL AND "o".' . $title->sql() . ' LIKE ? ESCAPE \'\\\')',
            [$pattern],
        );
    }

    /** A field of the table; NotFound for an unknown key or a field of another table. */
    public function fieldOf(TableDef $table, string $fieldKey): FieldDef
    {
        $field = $this->schema->field($fieldKey);
        if ($field->tableKey !== $table->key) {
            throw new NotFound('No such field in this table.');
        }

        return $field;
    }

    /** @return list<string> the choice field's current labels, in list order */
    public function labels(FieldDef $field): array
    {
        $labels = [];
        foreach ($field->options['choices'] ?? [] as $choice) {
            if (is_array($choice) && is_string($choice['label'] ?? null)) {
                $labels[] = $choice['label'];
            }
        }

        return $labels;
    }

    /** A positive record id from canonical digits; \InvalidArgumentException otherwise. */
    public static function recordId(string $text): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}\z/', $text) !== 1 || (strlen($text) === 19 && strcmp($text, (string) PHP_INT_MAX) > 0)) {
            throw new \InvalidArgumentException('A record id is a positive whole number.');
        }

        return (int) $text;
    }

    /** The field type's canonical text of a stored value, or null for empty. */
    public function text(FieldDef $field, mixed $stored): ?string
    {
        $type = $this->types->get($field->type);
        $value = $type->fromStorage($stored, $field->options);

        return $value === null ? null : $type->toText($value, $field->options);
    }
}
