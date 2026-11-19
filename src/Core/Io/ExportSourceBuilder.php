<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportProject;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Builds the ExportSource of an open project (M3 spec section 10.1): one
 * table or every table, fields by position, live records by id in pages of
 * 500. Reads only; no transaction (a long export may see records added
 * while it runs). Project facts come from the file's own meta rows.
 */
final class ExportSourceBuilder
{
    private function __construct()
    {
    }

    /** @param ?string $tableKey one table, or null for every table (unknown key: NotFound) */
    public static function build(Database $db, Schema $schema, FieldTypes $types, ?string $tableKey, Clock $clock, string $appVersion): ExportSource
    {
        $tables = $tableKey === null ? $schema->tables() : [$schema->table($tableKey)];
        $exportTables = [];
        foreach ($tables as $table) {
            $exportTables[] = self::table($db, $schema, $table);
        }
        $project = new ExportProject(
            (string) $schema->meta('project_key'),
            (string) $schema->meta('project_name'),
            (string) $schema->meta('created_at'),
            (string) $schema->meta('updated_at'),
            (int) $schema->meta('format_version'),
            Timestamp::format($clock->now()),
            $appVersion,
        );

        return new ProjectExportSource($db, $schema, $types, $project, $exportTables);
    }

    private static function table(Database $db, Schema $schema, TableDef $table): ExportTable
    {
        $fields = [];
        foreach ($schema->fields($table->key) as $field) {
            $relation = $schema->relationOf($field);
            $fields[] = new ExportField(
                $field->key,
                $field->name,
                $field->type,
                $field->options,
                $field->required,
                $field->help,
                $relation === null ? null : $relation->otherTable($field->key),
            );
        }
        $count = (int) $db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NULL');

        return new ExportTable($table->key, $table->name, $table->itemName, $table->titleFieldKey, $fields, $count);
    }
}
