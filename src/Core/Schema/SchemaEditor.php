<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Type\ChoiceType;
use TiffinDB\Core\Schema\Type\FieldType;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Every structural change to a project file (M1 spec section 7.4). It
 * makes no permission decision (that is Core\App) but takes the snapshots
 * itself, before the transaction, so that no caller can skip them.
 *
 * Every public method that changes something runs in one Database::write()
 * (BEGIN IMMEDIATE), re-reads the schema inside it, validates, changes the
 * SQL schema and its metadata together, and ends with ProjectFile::touch().
 * Validation collects every problem before throwing ValidationFailed.
 *
 * SQL names come only from Identifier (built from keys) and column
 * definitions only from ColumnDefinition; every value is a bound parameter.
 * After any change the caller must build a new Schema.
 */
final class SchemaEditor
{
    public const MAX_TABLE_NAME = 60;
    public const MAX_ITEM_NAME = 40;
    public const MAX_FIELD_NAME = 60;
    public const MAX_HELP = 200;
    public const MAX_FIELDS = 500;
    public const MAX_CONVERT_RECORDS = 50000;

    /** The field core adds to a new table (P45): data in English, renameable (section 16 item 6). */
    public const NAME_FIELD = 'Name';

    private const ELLIPSIS = "\u{2026}";
    private const NO_REQUIRED = ['checkbox', 'auto_number'];

    private readonly \Closure $onSnapshot;

    /** @param callable(SnapshotInfo): void $onSnapshot */
    public function __construct(
        private readonly Database $db,
        private readonly string $projectFolder,
        private readonly FieldTypes $types,
        private readonly Keys $keys,
        private readonly Clock $clock,
        private readonly Snapshots $snapshots,
        private readonly string $userKey,
        callable $onSnapshot,
    ) {
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
        $this->onSnapshot = $onSnapshot(...);
    }

    // ------------------------------------------------------------------ tables

    public function createTable(string $name, string $itemName, bool $withNameField): TableDef
    {
        return $this->change(function (Schema $schema) use ($name, $itemName, $withNameField): TableDef {
            self::fail([
                ...$this->tableNameProblems($schema, $name, null),
                ...Text::nameProblems($itemName, 'item_name', self::MAX_ITEM_NAME),
            ]);
            $name = Text::trim($name);
            $itemName = Text::trim($itemName);
            $key = $this->newKey();
            $now = $this->now();

            $this->db->run('CREATE TABLE ' . Identifier::table($key) . ' (
    "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
    "_created_at" TEXT NOT NULL,
    "_created_by" TEXT,
    "_updated_at" TEXT NOT NULL,
    "_updated_by" TEXT,
    "_trashed_at" TEXT,
    "_trashed_by" TEXT
)');
            $this->db->run('CREATE INDEX ' . Identifier::index(Identifier::tableName($key), 'trash')
                . ' ON ' . Identifier::table($key) . ' ("_trashed_at")');
            $position = (int) $this->db->value('SELECT COALESCE(MAX("position"), 0) + 1 FROM "_tiffin_tables"');
            $this->db->run(
                'INSERT INTO "_tiffin_tables" ("key", "sql_name", "name", "item_name", "position", "title_field", "created_at", "updated_at")'
                . ' VALUES (?, ?, ?, ?, ?, NULL, ?, ?)',
                [$key, Identifier::tableName($key), $name, $itemName, $position, $now, $now],
            );
            $table = new TableDef($key, $name, $itemName, $position, null);
            if (!$withNameField) {
                return $table;
            }
            $text = $this->types->get('text');
            $field = $this->insertField($table, self::NAME_FIELD, $text, $text->normaliseOptions([]), false, '');
            $this->db->run('UPDATE "_tiffin_tables" SET "title_field" = ? WHERE "key" = ?', [$field->key, $key]);

            return new TableDef($key, $name, $itemName, $position, $field->key);
        });
    }

    public function updateTable(string $tableKey, string $name, string $itemName, ?string $titleFieldKey): TableDef
    {
        return $this->change(function (Schema $schema) use ($tableKey, $name, $itemName, $titleFieldKey): TableDef {
            $table = $schema->table($tableKey);
            $problems = [
                ...$this->tableNameProblems($schema, $name, $tableKey),
                ...Text::nameProblems($itemName, 'item_name', self::MAX_ITEM_NAME),
            ];
            if ($titleFieldKey !== null && !$this->canBeTitle($schema, $tableKey, $titleFieldKey)) {
                $problems[] = new Problem('title_field', 'problem.title_field.invalid');
            }
            self::fail($problems);
            $name = Text::trim($name);
            $itemName = Text::trim($itemName);
            $this->db->run(
                'UPDATE "_tiffin_tables" SET "name" = ?, "item_name" = ?, "title_field" = ?, "updated_at" = ? WHERE "key" = ?',
                [$name, $itemName, $titleFieldKey, $this->now(), $tableKey],
            );

            return new TableDef($tableKey, $name, $itemName, $table->position, $titleFieldKey);
        });
    }

    /** Deletes a table, its records, and every relation that touches it. Returns the number of records it had. */
    public function deleteTable(string $tableKey): int
    {
        $this->schema()->table($tableKey);
        $this->snapshot(SnapshotReason::TableDelete);

        return $this->change(function (Schema $schema) use ($tableKey): int {
            $table = $schema->table($tableKey);
            $records = $this->recordCountOf($table, true);
            $touched = [];
            foreach ($schema->relationsTouching($tableKey) as $relation) {
                $touched = [...$touched, ...$this->dropRelation($relation)];
            }
            foreach ($schema->fields($tableKey) as $field) {
                $this->db->run('DELETE FROM "_tiffin_attachment_links" WHERE "field_key" = ?', [$field->key]);
            }
            $this->db->run('DROP TABLE ' . $table->sql());
            $this->db->run('DELETE FROM "_tiffin_fields" WHERE "table_key" = ?', [$tableKey]);
            $this->db->run('DELETE FROM "_tiffin_tables" WHERE "key" = ?', [$tableKey]);
            $this->renumberTables();
            foreach (array_unique($touched) as $other) {
                if ($other !== $tableKey) {
                    $this->renumberFields($other);
                }
            }

            return $records;
        });
    }

    // ------------------------------------------------------------------ adding fields

    /** @param array<string, mixed> $options */
    public function addField(string $tableKey, string $name, string $type, array $options, bool $required, string $help): FieldDef
    {
        return $this->change(function (Schema $schema) use ($tableKey, $name, $type, $options, $required, $help): FieldDef {
            $table = $schema->table($tableKey);
            $problems = [
                ...$this->fieldNameProblems($schema, $tableKey, $name, null, 'name', 'problem.field_name.taken', []),
                ...self::helpProblems($help),
            ];
            $normalised = [];
            if (!$this->types->has($type) || $type === 'relation') {
                $problems[] = new Problem('type', 'problem.type.unknown');
            } else {
                try {
                    $normalised = $this->types->get($type)->normaliseOptions($options);
                } catch (ValidationFailed $failed) {
                    $problems = [...$problems, ...$failed->problems];
                }
            }
            if (count($schema->fields($tableKey)) >= self::MAX_FIELDS) {
                $problems[] = new Problem(null, 'problem.table.too_many_fields', ['max' => self::MAX_FIELDS]);
            }
            self::fail($problems);

            return $this->insertField($table, Text::trim($name), $this->types->get($type), $normalised, $required, Text::trim($help));
        });
    }

    /**
     * Adds a relation: the new field in $tableKey is the left side, the
     * reverse field in the target table the right side. Returns the new field.
     */
    public function addRelation(
        string $tableKey,
        string $name,
        string $targetTableKey,
        Cardinality $thisMax,
        Cardinality $otherMax,
        string $reverseName,
        bool $required,
        string $help,
    ): FieldDef {
        return $this->change(function (Schema $schema) use (
            $tableKey, $name, $targetTableKey, $thisMax, $otherMax, $reverseName, $required, $help,
        ): FieldDef {
            $table = $schema->table($tableKey);
            $problems = [
                ...$this->fieldNameProblems($schema, $tableKey, $name, null, 'name', 'problem.field_name.taken', []),
                ...self::helpProblems($help),
            ];
            $target = null;
            foreach ($schema->tables() as $candidate) {
                if ($candidate->key === $targetTableKey) {
                    $target = $candidate;
                }
            }
            if ($target === null) {
                $problems[] = new Problem('target', 'problem.relation.target_missing');
                $problems = [...$problems, ...Text::nameProblems($reverseName, 'reverse_name', self::MAX_FIELD_NAME)];
            } else {
                $reverseProblems = $this->fieldNameProblems(
                    $schema,
                    $target->key,
                    $reverseName,
                    null,
                    'reverse_name',
                    'problem.relation.reverse_name_taken',
                    ['table' => $target->name],
                );
                if ($reverseProblems === [] && $target->key === $tableKey
                    && strcasecmp(Text::trim($reverseName), Text::trim($name)) === 0) {
                    $reverseProblems[] = new Problem('reverse_name', 'problem.relation.reverse_name_taken', ['table' => $target->name]);
                }
                $problems = [...$problems, ...$reverseProblems];
            }
            $count = count($schema->fields($tableKey));
            $tooMany = $target !== null && $target->key === $tableKey
                ? $count + 2 > self::MAX_FIELDS
                : $count >= self::MAX_FIELDS || ($target !== null && count($schema->fields($target->key)) >= self::MAX_FIELDS);
            if ($tooMany) {
                $problems[] = new Problem(null, 'problem.table.too_many_fields', ['max' => self::MAX_FIELDS]);
            }
            self::fail($problems);
            assert($target !== null);

            $relationKey = $this->newKey();
            $leftKey = $this->newKey([$relationKey]);
            $rightKey = $this->newKey([$relationKey, $leftKey]);
            $join = Identifier::joinTable($relationKey);
            $joinName = Identifier::joinName($relationKey);

            $this->db->run('CREATE TABLE ' . $join . ' (
    "left_id"  INTEGER NOT NULL REFERENCES ' . Identifier::table($table->key) . ' ("id") ON DELETE CASCADE,
    "right_id" INTEGER NOT NULL REFERENCES ' . Identifier::table($target->key) . ' ("id") ON DELETE CASCADE,
    "position" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("left_id", "right_id")
) WITHOUT ROWID');
            $this->db->run('CREATE INDEX ' . Identifier::index($joinName, 'r') . ' ON ' . $join . ' ("right_id")');
            if ($thisMax === Cardinality::One) {
                $this->db->run('CREATE UNIQUE INDEX ' . Identifier::index($joinName, 'l1') . ' ON ' . $join . ' ("left_id")');
            }
            if ($otherMax === Cardinality::One) {
                $this->db->run('CREATE UNIQUE INDEX ' . Identifier::index($joinName, 'r1') . ' ON ' . $join . ' ("right_id")');
            }

            $left = $this->insertRelationField($leftKey, $table->key, Text::trim($name), $relationKey, 'left', $required, Text::trim($help));
            $this->insertRelationField($rightKey, $target->key, Text::trim($reverseName), $relationKey, 'right', false, '');
            $this->db->run(
                'INSERT INTO "_tiffin_relations" ("key", "join_table", "left_table", "left_field", "left_max",'
                . ' "right_table", "right_field", "right_max", "created_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$relationKey, $joinName, $table->key, $leftKey, $thisMax->value, $target->key, $rightKey, $otherMax->value, $this->now()],
            );

            return $left;
        });
    }

    // ------------------------------------------------------------------ changing fields

    /**
     * What updateField() would do with these options: records and values
     * cleared. Renamed choice labels are rewritten, not cleared, and are not
     * counted. Changes nothing.
     *
     * @param array<string, mixed> $options
     */
    public function previewUpdate(string $fieldKey, array $options): Impact
    {
        $schema = $this->schema();
        $plan = $this->planUpdate($schema, $schema->field($fieldKey), null, $options, false, null);

        return new Impact($plan->cleared, $plan->cleared);
    }

    /**
     * Changes a field's name, options, requiredness, and help. Choice
     * renames rewrite stored values; removed choices and values above a
     * lowered rating maximum are cleared, which needs $confirmLoss
     * (ConfirmationRequired before anything changes). A `field-options`
     * snapshot is taken when any stored value changes.
     *
     * For relation fields the options are `{"links": "one"|"many"}`, this
     * side's maximum; the stored `relation` and `side` are kept. For
     * auto-number fields the stored `next` is kept.
     *
     * @param array<string, mixed> $options
     */
    public function updateField(string $fieldKey, string $name, array $options, bool $required, string $help, bool $confirmLoss): FieldDef
    {
        $snapshotTaken = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $schema = $this->schema();
            $plan = $this->planUpdate($schema, $schema->field($fieldKey), $name, $options, $required, $help);
            if ($plan->cleared > 0 && !$confirmLoss) {
                throw new ConfirmationRequired($plan->cleared, $plan->cleared);
            }
            if ($plan->changes !== [] && !$snapshotTaken) {
                $this->snapshot(SnapshotReason::FieldOptions);
                $snapshotTaken = true;
            }
            $result = $this->db->write(function () use ($fieldKey, $name, $options, $required, $help, $confirmLoss, $snapshotTaken): ?FieldDef {
                $schema = $this->schema();
                $plan = $this->planUpdate($schema, $schema->field($fieldKey), $name, $options, $required, $help);
                if ($plan->cleared > 0 && !$confirmLoss) {
                    throw new ConfirmationRequired($plan->cleared, $plan->cleared);
                }
                if ($plan->changes !== [] && !$snapshotTaken) {
                    // Values changed since the check outside the transaction: nothing has been
                    // changed yet; take the snapshot and try again.
                    return null;
                }
                $field = $this->applyUpdate($schema, $plan);
                ProjectFile::touch($this->db, $this->userKey, $this->clock);

                return $field;
            });
            if ($result !== null) {
                return $result;
            }
        }

        throw new \LogicException('The field update did not settle.');
    }

    /**
     * What changeType() would do: records and values that cannot be
     * converted and would be cleared. Changes nothing.
     *
     * @param array<string, mixed> $options
     */
    public function previewTypeChange(string $fieldKey, string $newType, array $options): Impact
    {
        $schema = $this->schema();
        $plan = $this->planConversion($schema, $schema->field($fieldKey), $newType, $options);

        return new Impact($plan->failures, $plan->failures);
    }

    /**
     * Changes a field's type (M1 spec section 7.4, P53, P54). Values are
     * converted through text with lenient parsing; values that fail are
     * cleared, which needs $confirmLoss. A `field-type-change` snapshot is
     * taken first; the values are then read and converted inside one
     * BEGIN IMMEDIATE transaction, so a record changed after the check is
     * converted from its current value.
     *
     * @param array<string, mixed> $options
     */
    public function changeType(string $fieldKey, string $newType, array $options, bool $confirmLoss): Impact
    {
        $schema = $this->schema();
        $plan = $this->planConversion($schema, $schema->field($fieldKey), $newType, $options);
        if ($plan->failures > 0 && !$confirmLoss) {
            throw new ConfirmationRequired($plan->failures, $plan->failures);
        }
        $this->snapshot(SnapshotReason::FieldTypeChange);

        return $this->change(function (Schema $schema) use ($fieldKey, $newType, $options, $confirmLoss): Impact {
            $field = $schema->field($fieldKey);
            $table = $schema->table($field->tableKey);
            $plan = $this->planConversion($schema, $field, $newType, $options);
            if ($plan->failures > 0 && !$confirmLoss) {
                throw new ConfirmationRequired($plan->failures, $plan->failures);
            }
            $to = $this->types->get($newType);

            $this->db->run('DROP INDEX IF EXISTS ' . Identifier::index(Identifier::columnName($field->key), 'u'));
            $this->db->run('ALTER TABLE ' . $table->sql() . ' DROP COLUMN ' . $field->sql());
            $this->db->run('ALTER TABLE ' . $table->sql() . ' ADD COLUMN ' . $field->sql() . ' ' . ColumnDefinition::of($to));
            foreach ($plan->values as $id => $value) {
                $this->db->run('UPDATE ' . $table->sql() . ' SET ' . $field->sql() . ' = ? WHERE "id" = ?', [$value, $id]);
            }
            $required = $field->required && !in_array($newType, self::NO_REQUIRED, true);
            $this->db->run(
                'UPDATE "_tiffin_fields" SET "type" = ?, "options" = ?, "required" = ?, "updated_at" = ? WHERE "key" = ?',
                [$newType, self::encodeOptions($plan->options), $required ? 1 : 0, $this->now(), $field->key],
            );
            if ($table->titleFieldKey === $field->key && !$to->canBeTitle()) {
                $this->db->run('UPDATE "_tiffin_tables" SET "title_field" = NULL WHERE "key" = ?', [$table->key]);
            }

            return new Impact($plan->failures, $plan->failures);
        });
    }

    /** Swaps the field with its neighbour: -1 earlier, +1 later. No change at either end. */
    public function moveField(string $fieldKey, int $direction): void
    {
        if ($direction !== -1 && $direction !== 1) {
            throw new \InvalidArgumentException('The direction must be -1 or +1.');
        }
        $this->db->write(function () use ($fieldKey, $direction): void {
            $schema = $this->schema();
            $field = $schema->field($fieldKey);
            $keys = array_map(static fn (FieldDef $f): string => $f->key, $schema->fields($field->tableKey));
            $index = array_search($fieldKey, $keys, true);
            assert(is_int($index));
            $other = $index + $direction;
            if ($other < 0 || $other >= count($keys)) {
                return;
            }
            [$keys[$index], $keys[$other]] = [$keys[$other], $keys[$index]];
            foreach ($keys as $i => $key) {
                $this->db->run('UPDATE "_tiffin_fields" SET "position" = ? WHERE "key" = ?', [$i + 1, $key]);
            }
            ProjectFile::touch($this->db, $this->userKey, $this->clock);
        });
    }

    /** Deletes a field (both fields for a relation) and its values, after a `field-delete` snapshot. */
    public function deleteField(string $fieldKey): void
    {
        $this->schema()->field($fieldKey);
        $this->snapshot(SnapshotReason::FieldDelete);

        $this->change(function (Schema $schema) use ($fieldKey): void {
            $field = $schema->field($fieldKey);
            $relation = $schema->relationOf($field);
            if ($relation !== null) {
                $touched = $this->dropRelation($relation);
            } else {
                if ($field->type === 'attachment') {
                    $this->db->run('DELETE FROM "_tiffin_attachment_links" WHERE "field_key" = ?', [$field->key]);
                } else {
                    $table = $schema->table($field->tableKey);
                    $this->db->run('DROP INDEX IF EXISTS ' . Identifier::index(Identifier::columnName($field->key), 'u'));
                    $this->db->run('ALTER TABLE ' . $table->sql() . ' DROP COLUMN ' . $field->sql());
                }
                $this->db->run('UPDATE "_tiffin_tables" SET "title_field" = NULL WHERE "title_field" = ?', [$field->key]);
                $this->db->run('DELETE FROM "_tiffin_fields" WHERE "key" = ?', [$field->key]);
                $touched = [$field->tableKey];
            }
            foreach (array_unique($touched) as $tableKey) {
                $this->renumberFields($tableKey);
            }
        });
    }

    // ------------------------------------------------------------------ counts

    /**
     * Non-empty values of a field, live and trashed records: non-null
     * column values (ticked boxes for a checkbox), records with at least one
     * link on this side (relation), or records with at least one file
     * (attachment).
     */
    public function valueCount(string $fieldKey): int
    {
        $schema = $this->schema();
        $field = $schema->field($fieldKey);
        $relation = $schema->relationOf($field);
        if ($relation !== null) {
            $sql = $relation->sideOf($field->key) === 'left'
                ? 'SELECT COUNT(DISTINCT "left_id") FROM ' . $relation->sql()
                : 'SELECT COUNT(DISTINCT "right_id") FROM ' . $relation->sql();

            return (int) $this->db->value($sql);
        }
        if ($field->type === 'attachment') {
            return (int) $this->db->value('SELECT COUNT(DISTINCT "record_id") FROM "_tiffin_attachment_links" WHERE "field_key" = ?', [$field->key]);
        }
        $table = $schema->table($field->tableKey);
        if ($field->type === 'checkbox') {
            return (int) $this->db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE ' . $field->sql() . ' = 1');
        }

        return (int) $this->db->value('SELECT COUNT(' . $field->sql() . ') FROM ' . $table->sql());
    }

    public function recordCount(string $tableKey, bool $includeTrashed = true): int
    {
        return $this->recordCountOf($this->schema()->table($tableKey), $includeTrashed);
    }

    // ------------------------------------------------------------------ private: plans

    /**
     * Validates an update and works out its effect on stored values.
     * $name and $help are null for a preview (options only).
     *
     * @param array<string, mixed> $options
     */
    private function planUpdate(Schema $schema, FieldDef $field, ?string $name, array $options, bool $required, ?string $help): FieldUpdate
    {
        $problems = [];
        if ($name !== null) {
            $problems = $this->fieldNameProblems($schema, $field->tableKey, $name, $field->key, 'name', 'problem.field_name.taken', []);
        }
        if ($help !== null) {
            $problems = [...$problems, ...self::helpProblems($help)];
        }
        $type = $this->types->get($field->type);
        $table = $schema->table($field->tableKey);
        $newOptions = $field->options;
        $changes = [];
        $cleared = 0;
        $links = null;

        $relation = $schema->relationOf($field);
        if ($relation !== null) {
            $current = $relation->maxFor($field->key);
            $given = $options['links'] ?? $current->value;
            $links = is_string($given) ? Cardinality::tryFrom($given) : null;
            if ($links === null) {
                $problems[] = new Problem('options.links', 'problem.options.invalid');
            } elseif ($links === Cardinality::One && $current === Cardinality::Many) {
                $sql = $relation->sideOf($field->key) === 'left'
                    ? 'SELECT COUNT(*) FROM (SELECT "left_id" FROM ' . $relation->sql() . ' GROUP BY "left_id" HAVING COUNT(*) > 1)'
                    : 'SELECT COUNT(*) FROM (SELECT "right_id" FROM ' . $relation->sql() . ' GROUP BY "right_id" HAVING COUNT(*) > 1)';
                $conflicts = (int) $this->db->value($sql);
                if ($conflicts > 0) {
                    $problems[] = new Problem('options.links', 'problem.relation.cardinality_conflict', ['count' => $conflicts]);
                }
            }
            if ($links === $current) {
                $links = null;
            }
        } else {
            try {
                $newOptions = $type->normaliseOptions($options);
            } catch (ValidationFailed $failed) {
                $problems = [...$problems, ...$failed->problems];
                $newOptions = null;
            }
            if ($newOptions !== null) {
                $defaults = $type->defaultOptions();
                if ($field->type === 'currency'
                    && $newOptions['minor_digits'] !== ($field->options['minor_digits'] ?? $defaults['minor_digits'])) {
                    $problems[] = new Problem('options.minor_digits', 'problem.options.minor_digits_fixed');
                }
                if ($field->type === 'auto_number') {
                    if ($newOptions['start'] !== ($field->options['start'] ?? $defaults['start'])) {
                        $problems[] = new Problem('options.start', 'problem.options.start_fixed');
                    }
                    $newOptions['next'] = $field->options['next'] ?? $newOptions['next'];
                }
                if ($field->type === 'choice') {
                    [$changes, $cleared] = $this->choiceChanges($table, $field, $newOptions);
                }
                if ($field->type === 'rating') {
                    $rows = $this->db->all(
                        'SELECT "id" FROM ' . $table->sql() . ' WHERE ' . $field->sql() . ' > ? ORDER BY "id"',
                        [$newOptions['max']],
                    );
                    foreach ($rows as $row) {
                        $changes[$row['id']] = null;
                    }
                    $cleared = count($rows);
                }
            }
        }
        self::fail($problems);
        assert($newOptions !== null);

        return new FieldUpdate(
            $field,
            $name === null ? $field->name : Text::trim($name),
            $newOptions,
            $required && !in_array($field->type, self::NO_REQUIRED, true),
            $help === null ? $field->help : Text::trim($help),
            $changes,
            $cleared,
            $links,
        );
    }

    /**
     * Stored values to rewrite after a choice edit: a label renamed under
     * the same key is rewritten; a value whose choice is gone is rewritten to
     * a remaining label equal ignoring ASCII case, or else cleared.
     *
     * @param array<string, mixed> $newOptions
     * @return array{0: array<int, string|null>, 1: int}
     */
    private function choiceChanges(TableDef $table, FieldDef $field, array $newOptions): array
    {
        $oldByLabel = [];
        foreach ($field->options['choices'] ?? [] as $choice) {
            $oldByLabel[$choice['label']] = $choice['key'];
        }
        $newByKey = [];
        $newLabels = [];
        foreach ($newOptions['choices'] as $choice) {
            $newByKey[$choice['key']] = $choice['label'];
            $newLabels[] = $choice['label'];
        }
        $changes = [];
        $cleared = 0;
        $rows = $this->db->all('SELECT "id", ' . $field->sql() . ' AS "v" FROM ' . $table->sql() . ' WHERE ' . $field->sql() . ' IS NOT NULL ORDER BY "id"');
        foreach ($rows as $row) {
            $value = $row['v'];
            if (!is_string($value) || !isset($oldByLabel[$value])) {
                continue;
            }
            $target = $newByKey[$oldByLabel[$value]] ?? null;
            if ($target === null) {
                foreach ($newLabels as $label) {
                    if (strcasecmp($label, $value) === 0) {
                        $target = $label;

                        break;
                    }
                }
            }
            if ($target === $value) {
                continue;
            }
            $changes[$row['id']] = $target;
            if ($target === null) {
                $cleared++;
            }
        }

        return [$changes, $cleared];
    }

    private function applyUpdate(Schema $schema, FieldUpdate $plan): FieldDef
    {
        $field = $plan->field;
        if ($plan->changes !== []) {
            $table = $schema->table($field->tableKey);
            foreach ($plan->changes as $id => $value) {
                $this->db->run('UPDATE ' . $table->sql() . ' SET ' . $field->sql() . ' = ? WHERE "id" = ?', [$value, $id]);
            }
        }
        $relation = $schema->relationOf($field);
        if ($relation !== null && $plan->links !== null) {
            $left = $relation->sideOf($field->key) === 'left';
            $joinName = Identifier::joinName($relation->key);
            if ($plan->links === Cardinality::One) {
                if ($left) {
                    $this->db->run('CREATE UNIQUE INDEX ' . Identifier::index($joinName, 'l1') . ' ON ' . $relation->sql() . ' ("left_id")');
                } else {
                    $this->db->run('CREATE UNIQUE INDEX ' . Identifier::index($joinName, 'r1') . ' ON ' . $relation->sql() . ' ("right_id")');
                }
            } else {
                $this->db->run('DROP INDEX IF EXISTS ' . Identifier::index($joinName, $left ? 'l1' : 'r1'));
            }
            if ($left) {
                $this->db->run('UPDATE "_tiffin_relations" SET "left_max" = ? WHERE "key" = ?', [$plan->links->value, $relation->key]);
            } else {
                $this->db->run('UPDATE "_tiffin_relations" SET "right_max" = ? WHERE "key" = ?', [$plan->links->value, $relation->key]);
            }
        }
        $this->db->run(
            'UPDATE "_tiffin_fields" SET "name" = ?, "options" = ?, "required" = ?, "help" = ?, "updated_at" = ? WHERE "key" = ?',
            [$plan->name, self::encodeOptions($plan->options), $plan->required ? 1 : 0, $plan->help, $this->now(), $field->key],
        );

        return new FieldDef(
            $field->key,
            $field->tableKey,
            $plan->name,
            $field->type,
            $plan->options,
            $plan->required,
            $plan->help,
            $field->position,
            $field->hasColumn,
        );
    }

    /**
     * Validates a type change and converts every stored value (dry run when
     * called outside a transaction; the real values when inside).
     *
     * @param array<string, mixed> $options
     */
    private function planConversion(Schema $schema, FieldDef $field, string $newType, array $options): Conversion
    {
        if (!$this->types->has($newType)) {
            self::fail([new Problem('type', 'problem.type.unknown')]);
        }
        if (!$this->types->canConvert($field->type, $newType)) {
            self::fail([new Problem('type', 'problem.type.not_convertible')]);
        }
        $table = $schema->table($field->tableKey);
        if ($this->recordCountOf($table, true) > self::MAX_CONVERT_RECORDS) {
            self::fail([new Problem(null, 'problem.table.too_large_to_convert', ['max' => self::MAX_CONVERT_RECORDS])]);
        }
        $from = $this->types->get($field->type);
        $to = $this->types->get($newType);

        $texts = [];
        $rows = $this->db->all('SELECT "id", ' . $field->sql() . ' AS "v" FROM ' . $table->sql() . ' ORDER BY "id"');
        foreach ($rows as $row) {
            $value = $from->fromStorage($row['v'], $field->options);
            if ($value === null || ($field->type === 'checkbox' && $value === false)) {
                continue;
            }
            $texts[$row['id']] = $from->toText($value, $field->options);
        }

        if ($newType === 'choice' && self::noChoicesGiven($options['choices'] ?? null)) {
            [$toOptions, $texts] = $this->deriveChoices($to, $texts);
        } else {
            $toOptions = $to->normaliseOptions($options);
        }

        $values = [];
        $failures = 0;
        foreach ($texts as $id => $text) {
            $checked = $to->parse($text, $toOptions, true);
            $stored = $checked->isOk() && $checked->value() !== null ? $to->toStorage($checked->value(), $toOptions) : null;
            if ($stored === null) {
                $failures++;

                continue;
            }
            $values[$id] = $stored;
        }

        return new Conversion($toOptions, $values, $failures);
    }

    /**
     * Derives the choices of a `choice` target from the source texts (P54)
     * and returns the options and the texts to parse (trimmed and cut).
     *
     * @param array<int, string> $texts by record id, in id order
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function deriveChoices(FieldType $to, array $texts): array
    {
        $labels = [];
        $cutTexts = [];
        foreach ($texts as $id => $text) {
            $label = Text::trim($text);
            if (Text::isUtf8($label) && Text::length($label) > ChoiceType::MAX_LABEL) {
                // The first MAX_LABEL - 1 (99) code points, then an ellipsis.
                preg_match('/^.{99}/su', $label, $match);
                $label = $match[0] . self::ELLIPSIS;
            }
            $cutTexts[$id] = $label;
            if ($label !== '' && !isset($labels[strtolower($label)])) {
                $labels[strtolower($label)] = $label;
            }
        }
        $labels = array_values($labels);
        usort($labels, static fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));
        if (count($labels) > ChoiceType::MAX_CHOICES) {
            self::fail([new Problem('options.choices', 'problem.options.choices_too_many', ['max' => ChoiceType::MAX_CHOICES])]);
        }
        try {
            $options = $to->normaliseOptions(['choices' => $labels]);
        } catch (ValidationFailed) {
            self::fail([new Problem('options.choices', 'problem.options.choices_unconvertible')]);
        }
        if (count($options['choices']) !== count($labels)) {
            // A label the type dropped (blank after its own trimming) cannot hold its values.
            self::fail([new Problem('options.choices', 'problem.options.choices_unconvertible')]);
        }

        return [$options, $cutTexts];
    }

    private static function noChoicesGiven(mixed $given): bool
    {
        if ($given === null || $given === '' || $given === []) {
            return true;
        }
        if (!is_array($given)) {
            return false;
        }
        foreach ($given as $entry) {
            $label = is_array($entry) ? ($entry['label'] ?? null) : $entry;
            if (!is_string($label) || Text::trim($label) !== '') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ private: SQL helpers

    /**
     * Adds a field that has passed validation (every type but relation).
     *
     * @param array<string, mixed> $options normalised
     */
    private function insertField(TableDef $table, string $name, FieldType $type, array $options, bool $required, string $help): FieldDef
    {
        $key = $this->newKey();
        $hasColumn = $type->columnDefinition() !== null;
        if ($hasColumn) {
            $this->db->run('ALTER TABLE ' . $table->sql() . ' ADD COLUMN ' . Identifier::column($key) . ' ' . ColumnDefinition::of($type));
        }
        if ($type->id() === 'auto_number') {
            $next = $options['start'];
            foreach ($this->db->all('SELECT "id" FROM ' . $table->sql() . ' ORDER BY "id"') as $row) {
                $this->db->run('UPDATE ' . $table->sql() . ' SET ' . Identifier::column($key) . ' = ? WHERE "id" = ?', [$next, $row['id']]);
                $next++;
            }
            $options['next'] = $next;
            $this->db->run('CREATE UNIQUE INDEX ' . Identifier::index(Identifier::columnName($key), 'u')
                . ' ON ' . $table->sql() . ' (' . Identifier::column($key) . ')');
        }
        $required = $required && !in_array($type->id(), self::NO_REQUIRED, true);
        $position = $this->nextFieldPosition($table->key);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "required", "help", "position", "created_at", "updated_at")'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $key, $table->key, $hasColumn ? Identifier::columnName($key) : null, $name, $type->id(),
                self::encodeOptions($options), $required ? 1 : 0, $help, $position, $now, $now,
            ],
        );

        return new FieldDef($key, $table->key, $name, $type->id(), $options, $required, $help, $position, $hasColumn);
    }

    private function insertRelationField(string $key, string $tableKey, string $name, string $relationKey, string $side, bool $required, string $help): FieldDef
    {
        $options = ['relation' => $relationKey, 'side' => $side];
        $position = $this->nextFieldPosition($tableKey);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "required", "help", "position", "created_at", "updated_at")'
            . ' VALUES (?, ?, NULL, ?, \'relation\', ?, ?, ?, ?, ?, ?)',
            [$key, $tableKey, $name, self::encodeOptions($options), $required ? 1 : 0, $help, $position, $now, $now],
        );

        return new FieldDef($key, $tableKey, $name, 'relation', $options, $required, $help, $position, false);
    }

    /**
     * Drops a relation's join table (its indexes go with it), then its row,
     * then both field rows: `_tiffin_relations` references both fields
     * without ON DELETE, so the relation row goes first.
     *
     * @return list<string> the tables whose fields changed
     */
    private function dropRelation(RelationDef $relation): array
    {
        $this->db->run('DROP TABLE ' . $relation->sql());
        $this->db->run('DELETE FROM "_tiffin_relations" WHERE "key" = ?', [$relation->key]);
        $this->db->run('DELETE FROM "_tiffin_fields" WHERE "key" = ?', [$relation->leftField]);
        $this->db->run('DELETE FROM "_tiffin_fields" WHERE "key" = ?', [$relation->rightField]);

        return [$relation->leftTable, $relation->rightTable];
    }

    private function recordCountOf(TableDef $table, bool $includeTrashed): int
    {
        $sql = $includeTrashed
            ? 'SELECT COUNT(*) FROM ' . $table->sql()
            : 'SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NULL';

        return (int) $this->db->value($sql);
    }

    private function nextFieldPosition(string $tableKey): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX("position"), 0) + 1 FROM "_tiffin_fields" WHERE "table_key" = ?', [$tableKey]);
    }

    private function renumberFields(string $tableKey): void
    {
        $rows = $this->db->all('SELECT "key", "position" FROM "_tiffin_fields" WHERE "table_key" = ? ORDER BY "position", "key"', [$tableKey]);
        foreach ($rows as $i => $row) {
            if ($row['position'] !== $i + 1) {
                $this->db->run('UPDATE "_tiffin_fields" SET "position" = ? WHERE "key" = ?', [$i + 1, $row['key']]);
            }
        }
    }

    private function renumberTables(): void
    {
        $rows = $this->db->all('SELECT "key", "position" FROM "_tiffin_tables" ORDER BY "position", "key"');
        foreach ($rows as $i => $row) {
            if ($row['position'] !== $i + 1) {
                $this->db->run('UPDATE "_tiffin_tables" SET "position" = ? WHERE "key" = ?', [$i + 1, $row['key']]);
            }
        }
    }

    /**
     * A key not used by any table, field, or relation of this project (one
     * scope, ADR 0010 decision 1), nor by any SQL object, nor in $also.
     *
     * @param list<string> $also
     */
    private function newKey(array $also = []): string
    {
        return $this->keys->generateUnique(function (string $key) use ($also): bool {
            if (in_array($key, $also, true)) {
                return true;
            }
            $used = $this->db->value(
                'SELECT 1 FROM "_tiffin_tables" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_fields" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_relations" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "sqlite_master" WHERE "name" IN (?, ?)',
                [$key, $key, $key, Identifier::tableName($key), Identifier::joinName($key)],
            );

            return $used !== null;
        });
    }

    // ------------------------------------------------------------------ private: validation and plumbing

    /** @return list<Problem> */
    private function tableNameProblems(Schema $schema, string $name, ?string $exceptKey): array
    {
        $problems = Text::nameProblems($name, 'name', self::MAX_TABLE_NAME);
        if ($problems !== []) {
            return $problems;
        }
        $name = Text::trim($name);
        foreach ($schema->tables() as $table) {
            if ($table->key !== $exceptKey && strcasecmp($table->name, $name) === 0) {
                return [new Problem('name', 'problem.table_name.taken')];
            }
        }

        return [];
    }

    /**
     * @param array<string, string|int> $takenParams
     * @return list<Problem>
     */
    private function fieldNameProblems(
        Schema $schema,
        string $tableKey,
        string $name,
        ?string $exceptKey,
        string $input,
        string $takenKey,
        array $takenParams,
    ): array {
        $problems = Text::nameProblems($name, $input, self::MAX_FIELD_NAME);
        if ($problems !== []) {
            return $problems;
        }
        $name = Text::trim($name);
        foreach ($schema->fields($tableKey) as $field) {
            if ($field->key !== $exceptKey && strcasecmp($field->name, $name) === 0) {
                return [new Problem($input, $takenKey, $takenParams)];
            }
        }

        return [];
    }

    /**
     * Help: 0 to 200 characters, no control characters. Section 7.4 names
     * `problem.name.too_long` for a help text that is too long, so the shared
     * helper's `problem.text.too_long` (same wording) is reported under it.
     *
     * @return list<Problem>
     */
    private static function helpProblems(string $help): array
    {
        return array_map(
            static fn (Problem $p): Problem => $p->key === 'problem.text.too_long' ? new Problem($p->field, 'problem.name.too_long', $p->params) : $p,
            Text::optionalTextProblems($help, 'help', self::MAX_HELP),
        );
    }

    private function canBeTitle(Schema $schema, string $tableKey, string $fieldKey): bool
    {
        if (!Keys::isValid($fieldKey)) {
            return false;
        }
        foreach ($schema->fields($tableKey) as $field) {
            if ($field->key === $fieldKey) {
                return $this->types->get($field->type)->canBeTitle();
            }
        }

        return false;
    }

    /** @param list<Problem> $problems */
    private static function fail(array $problems): void
    {
        if ($problems !== []) {
            throw new ValidationFailed(array_values($problems));
        }
    }

    /**
     * One transaction: builds the schema inside it, runs $work, then
     * records the change in the project meta.
     *
     * @template T
     * @param callable(Schema): T $work
     * @return T
     */
    private function change(callable $work): mixed
    {
        return $this->db->write(function () use ($work): mixed {
            $result = $work($this->schema());
            ProjectFile::touch($this->db, $this->userKey, $this->clock);

            return $result;
        });
    }

    private function schema(): Schema
    {
        return new Schema($this->db, $this->types);
    }

    private function snapshot(SnapshotReason $reason): void
    {
        $info = $this->snapshots->take($this->db, $this->projectFolder, $reason);
        ($this->onSnapshot)($info);
    }

    private function now(): string
    {
        return Timestamp::format($this->clock->now());
    }

    /** @param array<string, mixed> $options */
    private static function encodeOptions(array $options): string
    {
        return json_encode((object) $options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
