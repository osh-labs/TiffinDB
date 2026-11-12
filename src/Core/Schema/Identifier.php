<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Storage\Keys;

/**
 * The only producer of SQL identifiers (M1 spec section 7.1, ADR 0010
 * decision 2). Every name is built from a key that matches the key pattern
 * (or from one of a fixed list of system names), checked against an exact
 * pattern, and returned wrapped in double quotes. Display names, options,
 * and any other text never reach this class; anything that is not a key is
 * refused with \InvalidArgumentException, which is a bug in the caller.
 *
 * Because every accepted input is restricted to [a-z0-9_], no escaping is
 * needed; quote() asserts that restriction before it returns.
 */
final class Identifier
{
    public const PURPOSES = ['trash', 'u', 'r', 'l1', 'r1'];

    /** The fixed column names of user tables and join tables. */
    public const SYSTEM = [
        'id', '_created_at', '_created_by', '_updated_at', '_updated_by', '_trashed_at', '_trashed_by',
        'left_id', 'right_id', 'position',
    ];

    /** An object SQL name that may carry an index: t_, f_, or j_ and a key. */
    public const OBJECT_NAME = '/^(t|f|j)_[a-z2-7]{12}\z/';

    /** Every unquoted name this class can return. */
    public const NAME = '/^(?:[tfj]_[a-z2-7]{12}|x_[tfj]_[a-z2-7]{12}_(?:trash|u|r|l1|r1)|id|_created_at|_created_by|_updated_at|_updated_by|_trashed_at|_trashed_by|left_id|right_id|position)\z/';

    private function __construct()
    {
    }

    /** '"t_<key>"' */
    public static function table(string $tableKey): string
    {
        return self::quote(self::tableName($tableKey));
    }

    /** '"f_<key>"' */
    public static function column(string $fieldKey): string
    {
        return self::quote(self::columnName($fieldKey));
    }

    /** '"j_<key>"' */
    public static function joinTable(string $relationKey): string
    {
        return self::quote(self::joinName($relationKey));
    }

    /** '"x_<object name>_<purpose>"' for an unquoted object name such as 't_<key>'. */
    public static function index(string $objectSqlName, string $purpose): string
    {
        if (preg_match(self::OBJECT_NAME, $objectSqlName) !== 1) {
            throw new \InvalidArgumentException('An index must belong to a generated table, column, or join table name.');
        }
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unknown index purpose.');
        }

        return self::quote('x_' . $objectSqlName . '_' . $purpose);
    }

    /** Unquoted 't_<key>', for metadata rows. */
    public static function tableName(string $tableKey): string
    {
        return 't_' . self::key($tableKey);
    }

    /** Unquoted 'f_<key>', for metadata rows. */
    public static function columnName(string $fieldKey): string
    {
        return 'f_' . self::key($fieldKey);
    }

    /** Unquoted 'j_<key>', for metadata rows. */
    public static function joinName(string $relationKey): string
    {
        return 'j_' . self::key($relationKey);
    }

    /** '"<name>"' for one of the fixed names in SYSTEM. */
    public static function system(string $name): string
    {
        if (!in_array($name, self::SYSTEM, true)) {
            throw new \InvalidArgumentException('Not a system column name.');
        }

        return self::quote($name);
    }

    private static function key(string $key): string
    {
        if (!Keys::isValid($key)) {
            throw new \InvalidArgumentException('An SQL name can only be built from a valid key.');
        }

        return $key;
    }

    private static function quote(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1 || preg_match('/^[a-z0-9_]+\z/', $name) !== 1) {
            throw new \LogicException('A generated SQL name failed its final check.');
        }

        return '"' . $name . '"';
    }
}
