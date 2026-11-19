<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\Type\ChoiceType;

/**
 * Validates a mapping against the project (M3 spec section 6.4) and builds
 * the default mappings. Problems name the field `table` or `col-<n>` (n
 * from 0). Name keys reuse M1's `problem.table_name.taken` and
 * `problem.field_name.taken` (the spec's `problem.name.taken` is not an M1
 * key; reported to the orchestrator).
 */
final class MappingCheck
{
    public const DEFAULT_TABLE_NAME = 'Imported rows';

    private function __construct()
    {
    }

    /**
     * Every problem of the mapping, all at once.
     *
     * @param list<ColumnStats> $stats one per source column
     * @return list<Problem>
     * @throws NotFound when an existing target table does not exist
     */
    public static function check(Mapping $mapping, Schema $schema, FieldTypes $types, array $stats): array
    {
        $problems = [];
        if ($mapping->target === Mapping::NEW) {
            $name = $mapping->newTableName ?? '';
            $nameProblems = Text::nameProblems($name, 'table', SchemaEditor::MAX_TABLE_NAME);
            if ($nameProblems === []) {
                foreach ($schema->tables() as $table) {
                    if (strcasecmp($table->name, Text::trim($name)) === 0) {
                        $nameProblems[] = new Problem('table', 'problem.table_name.taken');
                    }
                }
            }
            $problems = [...$problems, ...$nameProblems];
            $included = $mapping->included();
            if ($included === []) {
                $problems[] = new Problem('table', 'problem.import.nothing_included');
            }
            if (count($included) > SchemaEditor::MAX_FIELDS) {
                $problems[] = new Problem('table', 'problem.table.too_many_fields', ['max' => SchemaEditor::MAX_FIELDS]);
            }
            $seen = [];
            foreach ($included as $column) {
                $field = self::field($column->column);
                $fieldName = $column->fieldName ?? '';
                $fieldProblems = Text::nameProblems($fieldName, $field, SchemaEditor::MAX_FIELD_NAME);
                $folded = strtolower(Text::trim($fieldName));
                if ($fieldProblems === [] && isset($seen[$folded])) {
                    $fieldProblems[] = new Problem($field, 'problem.field_name.taken');
                }
                $seen[$folded] = true;
                $problems = [...$problems, ...$fieldProblems, ...self::typeProblems($column, $schema, $types, $stats[$column->column] ?? new ColumnStats())];
            }

            return $problems;
        }

        $table = $schema->table((string) $mapping->tableKey);
        $eligible = self::eligibleFields($schema, $table->key);
        $chosen = [];
        $any = false;
        foreach ($mapping->columns as $column) {
            if (!$column->include || $column->fieldKey === null) {
                continue;
            }
            $any = true;
            $field = self::field($column->column);
            if (!isset($eligible[$column->fieldKey])) {
                $problems[] = new Problem($field, 'problem.import.type_invalid');
            } elseif (isset($chosen[$column->fieldKey])) {
                $problems[] = new Problem($field, 'problem.import.field_twice');
            }
            $chosen[$column->fieldKey] = true;
        }
        if (!$any) {
            $problems[] = new Problem('table', 'problem.import.nothing_included');
        }

        return $problems;
    }

    /**
     * The default mapping (section 6.4): a new table named $tableName, or
     * the existing table $tableKey with each column on the eligible field
     * whose name equals the proposed field name (ASCII case-insensitive).
     *
     * @param list<TypeGuess> $guesses
     */
    public static function defaults(string $target, Schema $schema, array $guesses, string $tableName, ?string $tableKey = null): Mapping
    {
        $columns = [];
        if ($target === Mapping::NEW) {
            foreach ($guesses as $index => $guess) {
                $columns[] = new ColumnMapping($index, $guess->include, $guess->fieldName, $guess->type, null);
            }

            return new Mapping(Mapping::NEW, $tableName, null, $columns);
        }
        $table = $schema->table((string) $tableKey);
        $eligible = self::eligibleFields($schema, $table->key);
        foreach ($guesses as $index => $guess) {
            $match = null;
            foreach ($eligible as $field) {
                if ($match === null && strcasecmp($field->name, $guess->fieldName) === 0) {
                    $match = $field->key;
                }
            }
            $columns[] = new ColumnMapping($index, $guess->include && $match !== null, null, null, $match);
        }

        return new Mapping(Mapping::EXISTING, null, $table->key, $columns);
    }

    /** The default new table name: the sheet name when given, else the file name without its extension; cut to 60. */
    public static function defaultTableName(string $fileName, ?string $sheetName = null): string
    {
        $base = $sheetName ?? (string) preg_replace('/\.[^.]*\z/', '', $fileName);
        $base = Text::isUtf8($base) ? $base : '';
        $base = (string) preg_replace('/(?![\x{200C}\x{200D}])[\p{Cc}\p{Zl}\p{Zp}\p{Cf}]/u', ' ', $base);
        $base = Text::trim(ColumnStats::cut(Text::trim((string) preg_replace('/\s+/u', ' ', $base)), SchemaEditor::MAX_TABLE_NAME));

        return $base === '' ? self::DEFAULT_TABLE_NAME : $base;
    }

    /**
     * Fields of an existing table a column may go into: importable types,
     * and relation fields whose linked table has a text-like title field.
     *
     * @return array<string, FieldDef> by key, in position order
     */
    public static function eligibleFields(Schema $schema, string $tableKey): array
    {
        $fields = [];
        foreach ($schema->fields($tableKey) as $field) {
            if (in_array($field->type, Inference::IMPORTABLE, true)) {
                $fields[$field->key] = $field;
            } elseif ($field->type === 'relation') {
                $relation = $schema->relationOf($field);
                if ($relation !== null && LinkTarget::of($schema, $relation->otherTable($field->key)) !== null) {
                    $fields[$field->key] = $field;
                }
            }
        }

        return $fields;
    }

    /** The target of a `link:<table key>` type, or null for any other type or a table that is not a valid target. */
    public static function linkTarget(string $type, Schema $schema): ?LinkTarget
    {
        if (!str_starts_with($type, Inference::LINK_PREFIX)) {
            return null;
        }
        $key = substr($type, strlen(Inference::LINK_PREFIX));
        foreach ($schema->tables() as $table) {
            if ($table->key === $key) {
                return LinkTarget::of($schema, $key);
            }
        }

        return null;
    }

    /**
     * @param list<ColumnStats> $stats
     * @return list<Problem>
     */
    private static function typeProblems(ColumnMapping $column, Schema $schema, FieldTypes $types, ColumnStats $stats): array
    {
        $field = self::field($column->column);
        $type = $column->type ?? '';
        if (str_starts_with($type, Inference::LINK_PREFIX)) {
            return self::linkTarget($type, $schema) === null ? [new Problem($field, 'problem.import.type_invalid')] : [];
        }
        if (!in_array($type, Inference::IMPORTABLE, true) || !$types->has($type)) {
            return [new Problem($field, 'problem.import.type_invalid')];
        }
        if ($type === 'choice' && !Inference::choicesFit($stats)) {
            return [new Problem($field, 'problem.import.too_many_choices', ['max' => ChoiceType::MAX_CHOICES])];
        }
        try {
            $types->get($type)->normaliseOptions(Inference::optionsFor($type, $stats));
        } catch (ValidationFailed $failed) {
            return array_map(static fn (Problem $p): Problem => new Problem($field, $p->key, $p->params), $failed->problems);
        }

        return [];
    }

    private static function field(int $column): string
    {
        return 'col-' . $column;
    }
}
