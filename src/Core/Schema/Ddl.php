<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Schema\Type\FieldType;
use TiffinDB\Core\Storage\Database;

/**
 * The DDL of user tables, columns, and join tables (`docs/formats/tiffin.md`
 * sections 5 and 7; M3 spec section 7, P130), in one place so that the
 * schema editor and the project archive rebuild write the same text.
 *
 * Each method runs its statements on the connection it is given, inside
 * the caller's transaction (it opens none). The SQL is built from string
 * literals, Identifier names (validated keys), and ColumnDefinition only,
 * under the Schema rules of SqlStringsTest; nothing read from a file or a
 * request reaches the text. (The spec's sketch returns statement lists;
 * the scanner refuses a variable as the SQL of a query, so the class runs
 * the statements itself.)
 */
final class Ddl
{
    private function __construct()
    {
    }

    /** `CREATE TABLE "t_<key>"` with the seven system columns, and its trash index. */
    public static function createTable(Database $db, string $tableKey): void
    {
        $db->run('CREATE TABLE ' . Identifier::table($tableKey) . ' (
    "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
    "_created_at" TEXT NOT NULL,
    "_created_by" TEXT,
    "_updated_at" TEXT NOT NULL,
    "_updated_by" TEXT,
    "_trashed_at" TEXT,
    "_trashed_by" TEXT
)');
        $db->run('CREATE INDEX ' . Identifier::index(Identifier::tableName($tableKey), 'trash')
            . ' ON ' . Identifier::table($tableKey) . ' ("_trashed_at")');
    }

    /**
     * `ALTER TABLE ... ADD COLUMN "f_<key>" <definition>` for a field whose
     * type has a column; nothing for relation and attachment fields.
     * \InvalidArgumentException when $type is not the field's type.
     */
    public static function addColumn(Database $db, FieldDef $field, FieldType $type): void
    {
        if ($type->id() !== $field->type) {
            throw new \InvalidArgumentException('The type is not the type of the field.');
        }
        if ($type->columnDefinition() === null) {
            return;
        }
        $db->run('ALTER TABLE ' . Identifier::table($field->tableKey) . ' ADD COLUMN ' . Identifier::column($field->key) . ' ' . ColumnDefinition::of($type));
    }

    /** The unique index `x_f_<key>_u` of an auto-number field; \InvalidArgumentException for any other type. */
    public static function uniqueIndex(Database $db, FieldDef $field): void
    {
        if ($field->type !== 'auto_number') {
            throw new \InvalidArgumentException('Only auto-number fields have a unique index.');
        }
        $db->run('CREATE UNIQUE INDEX ' . Identifier::index(Identifier::columnName($field->key), 'u')
            . ' ON ' . Identifier::table($field->tableKey) . ' (' . Identifier::column($field->key) . ')');
    }

    /** The join table `j_<key>`, its `r` index, and the `l1` and `r1` unique indexes its maximums need. */
    public static function createJoinTable(Database $db, RelationDef $relation): void
    {
        $db->run('CREATE TABLE ' . Identifier::joinTable($relation->key) . ' (
    "left_id"  INTEGER NOT NULL REFERENCES ' . Identifier::table($relation->leftTable) . ' ("id") ON DELETE CASCADE,
    "right_id" INTEGER NOT NULL REFERENCES ' . Identifier::table($relation->rightTable) . ' ("id") ON DELETE CASCADE,
    "position" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("left_id", "right_id")
) WITHOUT ROWID');
        $db->run('CREATE INDEX ' . Identifier::index(Identifier::joinName($relation->key), 'r') . ' ON ' . Identifier::joinTable($relation->key) . ' ("right_id")');
        if ($relation->leftMax === Cardinality::One) {
            self::oneIndex($db, $relation, 'left');
        }
        if ($relation->rightMax === Cardinality::One) {
            self::oneIndex($db, $relation, 'right');
        }
    }

    /** The unique index that limits one side of a relation to one link: `l1` on `left_id`, `r1` on `right_id`. */
    public static function oneIndex(Database $db, RelationDef $relation, string $side): void
    {
        if ($side === 'left') {
            $db->run('CREATE UNIQUE INDEX ' . Identifier::index(Identifier::joinName($relation->key), 'l1') . ' ON ' . Identifier::joinTable($relation->key) . ' ("left_id")');
        } elseif ($side === 'right') {
            $db->run('CREATE UNIQUE INDEX ' . Identifier::index(Identifier::joinName($relation->key), 'r1') . ' ON ' . Identifier::joinTable($relation->key) . ' ("right_id")');
        } else {
            throw new \InvalidArgumentException('The side must be left or right.');
        }
    }
}
