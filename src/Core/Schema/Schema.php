<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;

/**
 * The tables, fields, and relations of one project file, read once from
 * the `_tiffin_*` tables and immutable afterwards (M1 spec section 7.3).
 * After any change, build a new Schema.
 *
 * Everything read is validated: keys must match the key pattern, stored SQL
 * names must equal the names built from the keys, types must be known,
 * options must be JSON objects, and relations must point at existing tables
 * and fields of the right type and side. A file that fails any check is
 * Unavailable('not-project'). Stored SQL names are only compared, never
 * used: every SQL name comes from Identifier and the key.
 *
 * Keys given to the lookup methods (for example from a URL) are checked
 * with Keys::isValid first; an invalid or unknown key is NotFound.
 */
final class Schema
{
    /** @var array<string, TableDef> by key, in position order */
    private array $tables = [];
    /** @var array<string, FieldDef> by key */
    private array $fields = [];
    /** @var array<string, list<string>> field keys by table key, in position order */
    private array $fieldsByTable = [];
    /** @var array<string, RelationDef> by key */
    private array $relations = [];
    /** @var array<string, string> */
    private array $meta = [];

    public function __construct(Database $db, FieldTypes $types)
    {
        try {
            $metaRows = $db->all('SELECT "key", "value" FROM "_tiffin_meta"');
            $tableRows = $db->all('SELECT "key", "sql_name", "name", "item_name", "position", "title_field" FROM "_tiffin_tables" ORDER BY "position", "key"');
            $fieldRows = $db->all('SELECT "key", "table_key", "sql_name", "name", "type", "options", "required", "help", "position" FROM "_tiffin_fields" ORDER BY "table_key", "position", "key"');
            $relationRows = $db->all('SELECT "key", "join_table", "left_table", "left_field", "left_max", "right_table", "right_field", "right_max" FROM "_tiffin_relations" ORDER BY "key"');
        } catch (\PDOException) {
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }

        foreach ($metaRows as $row) {
            self::require(is_string($row['key']) && is_string($row['value']));
            $this->meta[$row['key']] = $row['value'];
        }
        foreach ($tableRows as $row) {
            $this->readTable($row);
        }
        foreach ($fieldRows as $row) {
            $this->readField($row, $types);
        }
        foreach ($relationRows as $row) {
            $this->readRelation($row);
        }
        foreach ($this->fields as $field) {
            if ($field->type === 'relation') {
                $relation = $this->relations[$field->options['relation']] ?? null;
                self::require($relation !== null);
                self::require(($field->options['side'] === 'left' ? $relation->leftField : $relation->rightField) === $field->key);
            }
        }
        foreach ($this->tables as $table) {
            if ($table->titleFieldKey !== null) {
                $title = $this->fields[$table->titleFieldKey] ?? null;
                self::require($title !== null && $title->tableKey === $table->key);
            }
        }
    }

    /** @return list<TableDef> by position */
    public function tables(): array
    {
        return array_values($this->tables);
    }

    public function table(string $key): TableDef
    {
        if (!Keys::isValid($key) || !isset($this->tables[$key])) {
            throw new NotFound('No such table.');
        }

        return $this->tables[$key];
    }

    /** @return list<FieldDef> by position; NotFound for an unknown table */
    public function fields(string $tableKey): array
    {
        $this->table($tableKey);

        return array_map(fn (string $key): FieldDef => $this->fields[$key], $this->fieldsByTable[$tableKey] ?? []);
    }

    public function field(string $key): FieldDef
    {
        if (!Keys::isValid($key) || !isset($this->fields[$key])) {
            throw new NotFound('No such field.');
        }

        return $this->fields[$key];
    }

    public function relation(string $key): RelationDef
    {
        if (!Keys::isValid($key) || !isset($this->relations[$key])) {
            throw new NotFound('No such relation.');
        }

        return $this->relations[$key];
    }

    /** The relation a relation field is a side of, or null for any other field. */
    public function relationOf(FieldDef $field): ?RelationDef
    {
        if ($field->type !== 'relation') {
            return null;
        }

        return $this->relations[$field->options['relation']] ?? null;
    }

    /** @return list<RelationDef> relations with either side in the table */
    public function relationsTouching(string $tableKey): array
    {
        return array_values(array_filter(
            $this->relations,
            static fn (RelationDef $r): bool => $r->leftTable === $tableKey || $r->rightTable === $tableKey,
        ));
    }

    public function projectName(): string
    {
        return $this->meta['project_name'] ?? '';
    }

    public function meta(string $key): ?string
    {
        return $this->meta[$key] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function readTable(array $row): void
    {
        $key = $row['key'];
        self::require(Keys::isValid($key) && !isset($this->tables[$key]));
        self::require($row['sql_name'] === Identifier::tableName($key));
        self::require(is_string($row['name']) && is_string($row['item_name']) && is_int($row['position']));
        $title = $row['title_field'];
        self::require($title === null || Keys::isValid($title));
        $this->tables[$key] = new TableDef($key, $row['name'], $row['item_name'], $row['position'], $title);
    }

    /** @param array<string, mixed> $row */
    private function readField(array $row, FieldTypes $types): void
    {
        $key = $row['key'];
        $tableKey = $row['table_key'];
        self::require(Keys::isValid($key) && !isset($this->fields[$key]) && !isset($this->tables[$key]));
        self::require(is_string($tableKey) && isset($this->tables[$tableKey]));
        self::require(is_string($row['type']) && $types->has($row['type']));
        $hasColumn = $types->get($row['type'])->columnDefinition() !== null;
        self::require($row['sql_name'] === ($hasColumn ? Identifier::columnName($key) : null));
        self::require(is_string($row['name']) && is_string($row['help']) && is_int($row['position']));
        self::require($row['required'] === 0 || $row['required'] === 1);
        $options = self::decodeOptions($row['options']);
        if ($row['type'] === 'relation') {
            self::require(Keys::isValid($options['relation'] ?? null));
            self::require(in_array($options['side'] ?? null, ['left', 'right'], true));
        }
        $this->fields[$key] = new FieldDef(
            $key,
            $tableKey,
            $row['name'],
            $row['type'],
            $options,
            $row['required'] === 1,
            $row['help'],
            $row['position'],
            $hasColumn,
        );
        $this->fieldsByTable[$tableKey][] = $key;
    }

    /** @param array<string, mixed> $row */
    private function readRelation(array $row): void
    {
        $key = $row['key'];
        self::require(Keys::isValid($key) && !isset($this->relations[$key]));
        self::require(!isset($this->tables[$key]) && !isset($this->fields[$key]));
        self::require($row['join_table'] === Identifier::joinName($key));
        $leftMax = is_string($row['left_max']) ? Cardinality::tryFrom($row['left_max']) : null;
        $rightMax = is_string($row['right_max']) ? Cardinality::tryFrom($row['right_max']) : null;
        self::require($leftMax !== null && $rightMax !== null);
        foreach (['left', 'right'] as $side) {
            $field = $this->fields[$row[$side . '_field']] ?? null;
            self::require($field !== null && $field->type === 'relation');
            self::require($field->tableKey === $row[$side . '_table']);
            self::require($field->options['relation'] === $key && $field->options['side'] === $side);
        }
        self::require($row['left_field'] !== $row['right_field']);
        $this->relations[$key] = new RelationDef(
            $key,
            $row['left_table'],
            $row['left_field'],
            $leftMax,
            $row['right_table'],
            $row['right_field'],
            $rightMax,
        );
    }

    /** @return array<string, mixed> */
    private static function decodeOptions(mixed $text): array
    {
        self::require(is_string($text));
        try {
            $object = json_decode($text, false, 64, JSON_THROW_ON_ERROR);
            self::require($object instanceof \stdClass);
            $options = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }
        self::require(is_array($options));

        return $options;
    }

    /** @phpstan-assert true $condition */
    private static function require(bool $condition): void
    {
        if (!$condition) {
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }
    }
}
