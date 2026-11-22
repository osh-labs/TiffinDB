<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;

/**
 * Collection rules between their stored form and query form
 * (`docs/specs/M4-core.md` section 6.3, ADR 0019 decisions 2 to 4):
 * resolve() checks stored rules against the table as it is now and turns
 * the working ones into query-form conditions; fromInput() turns a posted
 * editor row into a stored rule or the problems that refuse it;
 * testsFor() lists the tests the editor offers for a field.
 */
final class CollectionRules
{
    /** Editor test tokens of a checkbox field, and the stored operand of each (`is` with 1 or 0). */
    private const CHECKBOX_TESTS = ['ticked' => '1', 'not-ticked' => '0'];
    private const NO_OPERAND = ['empty', 'not-empty'];

    private readonly ListSql $lists;

    public function __construct(private readonly Database $db, private readonly Schema $schema, private readonly FieldTypes $types)
    {
        $this->lists = new ListSql($schema, $types);
    }

    /**
     * Rules as stored, checked against the table as it is now (ADR 0019
     * decision 4). Broken rules are kept in the states with their reason
     * and left out of the set; the set is null when no rule works.
     *
     * @param list<CollectionRule> $rules
     * @throws NotFound when the table does not exist
     */
    public function resolve(string $tableKey, MatchMode $match, array $rules, ?string $sort): ResolvedRules
    {
        $table = $this->schema->table($tableKey);
        if (!array_is_list($rules) || count($rules) > CollectionStore::MAX_RULES) {
            throw new \InvalidArgumentException('A collection holds a list of at most 10 rules.');
        }
        $states = [];
        $conditions = [];
        $broken = 0;
        foreach ($rules as $rule) {
            if (!$rule instanceof CollectionRule) {
                throw new \InvalidArgumentException('Rules must be CollectionRule values.');
            }
            $result = $this->queryForm($table, $rule);
            if (is_string($result)) {
                $states[] = new RuleState($rule, $result);
                $broken++;
            } else {
                $states[] = new RuleState($rule, null);
                $conditions[] = $result;
            }
        }

        return new ResolvedRules($conditions === [] ? null : new RuleSet($match, $conditions), $states, $broken, $this->sort($table, $sort));
    }

    /**
     * A posted draft rule (`field`, `test`, `value`, and for a relation
     * `record`) to its stored form, or the problems that refuse it, each
     * with field `rule-<n>` where n is $number, the row's index from 1.
     *
     * @param array<mixed> $row
     * @return CollectionRule|non-empty-list<Problem>
     */
    public function fromInput(string $tableKey, array $row, int $number = 1): CollectionRule|array
    {
        $table = $this->schema->table($tableKey);
        $input = 'rule-' . $number;
        $field = $this->fieldOf($table, self::posted($row, 'field'));
        $tests = $field === null ? [] : array_column($this->testsFor($field), 'op');
        if ($field === null || $tests === []) {
            return [new Problem($input, 'problem.collection.field')];
        }
        $test = self::posted($row, 'test');
        if (!in_array($test, $tests, true)) {
            return [new Problem($input, 'problem.collection.test')];
        }
        if ($field->type === 'checkbox') {
            return new CollectionRule($field->key, 'is', [self::CHECKBOX_TESTS[$test]]);
        }
        if (in_array($test, self::NO_OPERAND, true)) {
            return new CollectionRule($field->key, $test, []);
        }
        if ($field->type === 'relation') {
            $id = $this->liveRecord($field, self::posted($row, 'record'));

            return $id === null ? [new Problem($input, 'problem.collection.record')] : new CollectionRule($field->key, $test, [(string) $id]);
        }

        $value = is_string($row['value'] ?? null) ? $row['value'] : '';
        $problem = $this->valueProblem($field);
        if (!Text::isUtf8($value)) {
            return [new Problem($input, $problem[0], $problem[1])];
        }
        $value = Text::trim($value);
        if ($value === '') {
            return [new Problem($input, 'problem.collection.value')];
        }
        if ($field->type === 'choice') {
            $stored = is_array($this->queryOperand($field, $value)) ? $value : null;
        } else {
            $stored = $this->storedOperand($field, $value);
        }

        return $stored === null ? [new Problem($input, $problem[0], $problem[1])] : new CollectionRule($field->key, $test, [$stored]);
    }

    /** @return list<array{op: string, label: string}> the tests the editor offers for a field, by type (section 5.2); label is a message key */
    public function testsFor(FieldDef $field): array
    {
        $tokens = $field->type === 'checkbox' ? array_keys(self::CHECKBOX_TESTS) : (ListSql::RULES[$field->type] ?? []);
        $tests = [];
        foreach ($tokens as $token) {
            $tests[] = ['op' => $token, 'label' => 'collections.test.' . $token];
        }

        return $tests;
    }

    // ------------------------------------------------------------------ internals

    /** The rule in query form, or the reason it is broken (the first that applies, section 6.3). */
    private function queryForm(TableDef $table, CollectionRule $rule): Condition|string
    {
        $field = $this->fieldOf($table, $rule->field);
        if ($field === null) {
            return 'collection.broken.field';
        }
        if (!in_array($rule->op, ListSql::RULES[$field->type] ?? [], true)) {
            return 'collection.broken.type';
        }
        $operator = Operator::from($rule->op);
        $count = in_array($rule->op, self::NO_OPERAND, true) ? 0 : 1;
        if (count($rule->values) !== $count) {
            return 'collection.broken.value';
        }
        if ($count === 0) {
            $condition = new Condition($field->key, $operator, []);
        } else {
            $operand = $this->queryOperand($field, $rule->values[0]);
            if (is_string($operand)) {
                return $operand;
            }
            $condition = new Condition($field->key, $operator, $operand);
        }
        try {
            // The same checks the query makes, so a resolved set can never fail when listed.
            $this->lists->condition($table, $condition);
        } catch (\InvalidArgumentException) {
            return 'collection.broken.value';
        }

        return $condition;
    }

    /** @return list<string>|string the query-form operand as a one-element list, or a broken reason key */
    private function queryOperand(FieldDef $field, string $value): array|string
    {
        if ($field->type === 'choice') {
            foreach ($field->options['choices'] ?? [] as $choice) {
                if (is_array($choice) && ($choice['key'] ?? null) === $value && is_string($choice['label'] ?? null)) {
                    return [$choice['label']];
                }
            }

            return 'collection.broken.choice';
        }
        if ($field->type === 'checkbox') {
            return $value === '1' || $value === '0' ? [$value] : 'collection.broken.value';
        }
        if ($field->type === 'relation') {
            $id = $this->liveRecord($field, $value);

            return $id === null ? 'collection.broken.record' : [(string) $id];
        }
        $operand = Text::isUtf8($value) ? $this->storedOperand($field, Text::trim($value)) : null;

        return $operand === null ? 'collection.broken.value' : [$operand];
    }

    /**
     * The canonical operand text of a text, number-like, or date value, or
     * null when it does not fit the field with its current options.
     */
    private function storedOperand(FieldDef $field, string $value): ?string
    {
        if ($value === '' || Text::hasControl($value)) {
            return null;
        }
        if (in_array($field->type, ListSql::TEXT_LIKE, true)) {
            return Text::length($value) <= ListSql::MAX_OPERAND ? $value : null;
        }
        if (in_array($field->type, ListSql::NUMBER_LIKE, true)) {
            $type = $this->types->get($field->type);
            $checked = $type->parse($value, $field->options, false);

            return $checked->isOk() && $checked->value() !== null ? $type->toText($checked->value(), $field->options) : null;
        }
        if ($field->type === 'date' || $field->type === 'date_time') {
            $checked = $this->types->get('date')->parse($value, [], false);

            return $checked->isOk() && is_string($checked->value()) ? $checked->value() : null;
        }

        return null;
    }

    /** @return array{0: string, 1: array<string, int>} the problem key (and parameters) for a value that does not fit the field */
    private function valueProblem(FieldDef $field): array
    {
        if (in_array($field->type, ListSql::NUMBER_LIKE, true)) {
            return ['problem.collection.number', []];
        }
        if ($field->type === 'date' || $field->type === 'date_time') {
            return ['problem.collection.date', []];
        }
        if ($field->type === 'choice') {
            return ['problem.collection.choice', []];
        }

        return ['problem.collection.text', ['max' => ListSql::MAX_OPERAND]];
    }

    /** The id when $text is the canonical id of a live record of the relation's other table, else null. */
    private function liveRecord(FieldDef $field, string $text): ?int
    {
        try {
            $id = ListSql::recordId($text);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $other = $this->schema->table($this->lists->relation($field)->otherTable($field->key));
        $live = $this->db->value('SELECT COUNT(*) FROM ' . $other->sql() . ' WHERE "id" = ? AND "_trashed_at" IS NULL', [$id]);

        return (int) $live === 1 ? $id : null;
    }

    /** The resolved sort: a token the table offers now, else null (the default order). */
    private function sort(TableDef $table, ?string $token): ?Sort
    {
        if ($token === 'added.asc' || $token === 'added.desc') {
            return new Sort('added', $token === 'added.desc');
        }
        if ($token === 'changed.desc') {
            return new Sort('changed', true);
        }
        if ($token === null || preg_match('/^([a-z2-7]{12})\.(asc|desc)\z/', $token, $m) !== 1) {
            return null;
        }
        $field = $this->fieldOf($table, $m[1]);
        if ($field === null || !$this->lists->isSortable($field)) {
            return null;
        }

        return new Sort($field->key, $m[2] === 'desc');
    }

    /** A field of the table by key, or null. */
    private function fieldOf(TableDef $table, string $key): ?FieldDef
    {
        foreach ($this->schema->fields($table->key) as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<mixed> $row */
    private static function posted(array $row, string $name): string
    {
        $value = $row[$name] ?? '';

        return is_string($value) ? $value : '';
    }
}
