<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;

/**
 * The database file of an uploaded archive, checked and opened read-only
 * (M3 spec section 11.4, ADR 0017 decision 3). Security-critical: the
 * file was written elsewhere and may have been built to attack TiffinDB.
 * It is never adopted as a project; ProjectRebuilder copies its values
 * into a new file whose every schema object TiffinDB creates itself.
 *
 * The checks run in this order, each before anything that the next would
 * expose:
 *
 * 1. Header: `SQLite format 3\0`; bytes 18 and 19 are 1 or 2, and a 2
 *    (WAL) is rewritten to 1 in the copy so it opens without a `-wal`
 *    file (`not-sqlite`).
 * 2. Sqlite::openUntrusted: read-only, `trusted_schema` off, `query_only`
 *    on. `application_id`, and `user_version` from 1 to 4 and equal to
 *    the manifest's (`not-project`, `newer`).
 * 3. Every `sqlite_master` row, before any user data is read: no trigger,
 *    view, or virtual table; every `_tiffin_*` table and index of that
 *    version present with exactly the documented SQL text (white space
 *    collapsed), so no metadata table carries a CHECK, a generated
 *    column, a partial-index condition, or an expression of its own;
 *    `sqlite_sequence` and `sqlite_stat1` exactly as SQLite writes them;
 *    `t_<key>` and `j_<key>` tables whose SQL holds no CHECK constraint
 *    (TiffinDB never writes one) and no hidden or generated column;
 *    `sqlite_autoindex_<table>_<n>` of an allowed table (of a metadata
 *    table only when its documented SQL has a UNIQUE column); and `x_<object>_
 *    <purpose>` indexes on a user table that are neither partial nor on
 *    an expression (`unexpected-object`, `bad-table`). This comes before
 *    `integrity_check`, which evaluates index expressions and conditions.
 * 4. `PRAGMA integrity_check(1)` is `ok` (`damaged`).
 * 5. `_tiffin_meta` `format` and `format_version` (`not-project`).
 * 6. Metadata read with explicit column lists and validated as the M1
 *    schema engine would have written it (`bad-metadata`). Collection
 *    rows (version 4) are only bounded here (row count, total `rules`
 *    text, each column's length) and their keys checked for uniqueness;
 *    everything else about them is left to the rebuild, which repairs or
 *    drops them (`docs/specs/M4-core.md` section 8).
 * 7. User and join table columns exactly as documented, and every user
 *    table's `id` equal to its rowid, so ids are whole numbers and unique
 *    (`bad-table`).
 *
 * Memory: every read is bounded before it runs. Row counts are checked
 * with COUNT(*) and value sizes with length() in SQL first, so a row whose
 * text is larger than TiffinDB could have written (a 300 MB form, a huge
 * name) is refused, or read as null, without ever reaching PHP.
 *
 * Any PDOException (or Busy) while checking, for example a schema naming
 * an unknown collation, refuses the file as `damaged`. The connection is
 * closed on every refusal.
 */
final class UntrustedProject
{
    public const MAX_TABLES = 200;
    public const MAX_FIELDS = SchemaEditor::MAX_FIELDS;
    public const MAX_FORM_NAME = 100;
    /** More stored forms than any project could use (one default per table, a few more each). */
    public const MAX_FORMS = 10000;
    public const MAX_SCHEMA_OBJECTS = 10000;
    /** All field options together; one choice field with 200 labels needs about 30 KiB. */
    public const MAX_OPTIONS_TOTAL = 16777216;
    /** Collection rows read from a version 4 file (`docs/specs/M4-core.md` section 8.1). */
    public const MAX_COLLECTION_ROWS = 1000;
    /** All collections' `rules` text together, in bytes. */
    public const MAX_RULES_TOTAL = 1048576;

    private const HEADER = "SQLite format 3\0";
    private const USER_OBJECT = '/^[tj]_[a-z2-7]{12}\z/';
    private const USER_INDEX = '/^x_([tfj])_([a-z2-7]{12})_(trash|u|r|l1|r1)\z/';
    private const AUTO_INDEX = '/^sqlite_autoindex_(.+)_[1-9][0-9]{0,3}\z/s';
    /** The tables SQLite itself creates, with the SQL text it stores for them. */
    private const SQLITE_TABLES = [
        'sqlite_sequence' => 'CREATE TABLE sqlite_sequence(name,seq)',
        'sqlite_stat1' => 'CREATE TABLE sqlite_stat1(tbl,idx,stat)',
    ];

    /**
     * @param array<string, string> $meta
     * @param array<string, TableDef> $tables by key, in position order
     * @param array<string, FieldDef> $fields by key, in table and position order
     * @param array<string, RelationDef> $relations by key
     * @param array<string, array<string, mixed>> $rows the times of each table, field, and relation by key
     * @param array<string, int> $sequences `sqlite_sequence` values by table name
     * @param list<array<string, mixed>> $collections the `_tiffin_collections` rows (version 4), length-guarded, by position then key
     */
    private function __construct(
        private ?Database $db,
        public readonly int $formatVersion,
        public readonly array $meta,
        public readonly array $tables,
        public readonly array $fields,
        public readonly array $relations,
        public readonly array $rows,
        public readonly array $sequences,
        public readonly array $collections,
    ) {
    }

    /**
     * Checks the extracted database at $path (an application-named file in
     * the job folder) whose manifest gives $manifestVersion, and keeps it
     * open read-only for the rebuild. ArchiveRefused on the first failing
     * rule.
     */
    public static function check(string $path, int $manifestVersion, FieldTypes $types): self
    {
        self::checkHeader($path);
        try {
            $db = Sqlite::openUntrusted($path);
        } catch (\PDOException|Unavailable $error) {
            throw new ArchiveRefused(ArchiveRefused::DAMAGED, 'open', $error);
        }
        try {
            return self::checkOpen($db, $manifestVersion, $types);
        } catch (ArchiveRefused $refused) {
            $db->close();

            throw $refused;
        } catch (\PDOException|Busy $error) {
            $db->close();

            throw new ArchiveRefused(ArchiveRefused::DAMAGED, 'database error', $error);
        } catch (\Throwable $error) {
            $db->close();

            throw $error;
        }
    }

    /** The read-only connection to the checked file. */
    public function db(): Database
    {
        if ($this->db === null) {
            throw new \LogicException('The checked file is closed.');
        }

        return $this->db;
    }

    /** @return list<FieldDef> the fields of a table, in position order */
    public function fieldsOf(string $tableKey): array
    {
        return array_values(array_filter($this->fields, static fn (FieldDef $field): bool => $field->tableKey === $tableKey));
    }

    public function close(): void
    {
        $this->db?->close();
        $this->db = null;
    }

    /** Step 1. */
    private static function checkHeader(string $path): void
    {
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'r+b'), 'Cannot open the copy.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the copy.');
        }
        try {
            $header = ErrorTrap::run(static fn (): string|false => fread($handle, 100), 'Cannot read the copy.');
            if (!is_string($header) || strlen($header) < 100 || !str_starts_with($header, self::HEADER)) {
                throw new ArchiveRefused(ArchiveRefused::NOT_SQLITE, 'header');
            }
            $write = ord($header[18]);
            $read = ord($header[19]);
            if (!in_array($write, [1, 2], true) || !in_array($read, [1, 2], true)) {
                throw new ArchiveRefused(ArchiveRefused::NOT_SQLITE, 'journal bytes');
            }
            if ($write === 2 || $read === 2) {
                // The copy has no -wal file; marking it as a rollback-journal file lets it open read-only on its own.
                $sought = ErrorTrap::run(static fn (): int => fseek($handle, 18), 'Cannot rewrite the header.');
                $written = ErrorTrap::run(static fn (): int|false => fwrite($handle, "\x01\x01"), 'Cannot rewrite the header.');
                if ($sought !== 0 || $written !== 2) {
                    throw new \RuntimeException('Cannot rewrite the header.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private static function checkOpen(Database $db, int $manifestVersion, FieldTypes $types): self
    {
        // Step 2: identity from the header fields.
        $applicationId = $db->value('PRAGMA application_id');
        $version = $db->value('PRAGMA user_version');
        if ($applicationId !== ProjectFile::APPLICATION_ID || !is_int($version) || $version < 1) {
            throw new ArchiveRefused(ArchiveRefused::NOT_PROJECT, 'application id or version');
        }
        if ($version > ProjectFile::VERSION) {
            throw new ArchiveRefused(ArchiveRefused::NEWER, 'format version');
        }
        if ($version !== $manifestVersion) {
            throw new ArchiveRefused(ArchiveRefused::NOT_PROJECT, 'version differs from the manifest');
        }

        // Step 3: the schema objects.
        $userObjects = self::checkObjects($db, $version);

        // Step 4.
        $integrity = $db->all('PRAGMA integrity_check(1)');
        if (count($integrity) !== 1 || array_values($integrity[0]) !== ['ok']) {
            throw new ArchiveRefused(ArchiveRefused::DAMAGED, 'integrity check');
        }

        // Step 5.
        $meta = [];
        foreach ($db->all('SELECT "key", "value" FROM "_tiffin_meta" WHERE length("key") <= 64 AND length("value") <= 4096 LIMIT 100') as $row) {
            if (is_string($row['key']) && is_string($row['value'])) {
                $meta[$row['key']] = $row['value'];
            }
        }
        if (($meta['format'] ?? null) !== ProjectFile::FORMAT || ($meta['format_version'] ?? null) !== (string) $version) {
            throw new ArchiveRefused(ArchiveRefused::NOT_PROJECT, 'meta format');
        }

        // Step 6.
        $rows = [];
        $tables = self::readTables($db, $rows);
        $fields = self::readFields($db, $types, $tables, $rows);
        $relations = self::readRelations($db, $fields, $rows);
        self::checkTitles($tables, $fields, $types);
        $formKeys = $version >= 2 ? self::checkFormKeys($db, $rows) : [];
        $collections = $version >= 4 ? self::readCollections($db, $rows, $formKeys) : [];
        $expected = [];
        foreach ($tables as $key => $table) {
            $expected[] = Identifier::tableName($key);
        }
        foreach ($relations as $key => $relation) {
            $expected[] = Identifier::joinName($key);
        }
        $present = array_keys($userObjects['tables']);
        sort($expected, SORT_STRING);
        sort($present, SORT_STRING);
        if ($expected !== $present) {
            throw new ArchiveRefused(ArchiveRefused::BAD_METADATA, 'user tables differ from the metadata');
        }

        // Step 7.
        foreach ($tables as $key => $table) {
            self::checkUserTable($db, $table, $fields);
        }
        foreach ($relations as $relation) {
            self::checkColumns($db, Identifier::joinName($relation->key), ['left_id', 'right_id', 'position']);
        }
        foreach ($userObjects['fieldIndexes'] as $fieldKey => $tableName) {
            $field = $fields[$fieldKey] ?? null;
            if ($field === null || Identifier::tableName($field->tableKey) !== $tableName) {
                throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'field index on another table');
            }
        }

        $sequences = [];
        foreach ($db->all('SELECT "name", "seq" FROM "sqlite_sequence" WHERE length("name") <= 64 AND typeof("seq") = \'integer\' LIMIT 10000') as $row) {
            if (is_string($row['name']) && is_int($row['seq']) && $row['seq'] > 0) {
                $sequences[$row['name']] = max($sequences[$row['name']] ?? 0, $row['seq']);
            }
        }

        return new self($db, $version, $meta, $tables, $fields, $relations, $rows, $sequences, $collections);
    }

    /**
     * Step 3. Returns the user tables found (by name) and the field
     * indexes (field key => table name) to compare with the metadata.
     *
     * @return array{tables: array<string, true>, fieldIndexes: array<string, string>}
     */
    private static function checkObjects(Database $db, int $version): array
    {
        $documented = self::documentedObjects($version);
        $found = [];
        $userTables = [];
        $userIndexes = [];
        $fieldIndexes = [];
        $sqliteTables = [];
        // Bounds first, so no schema row larger than TiffinDB could have written is ever read into PHP.
        if ((int) $db->value('SELECT COUNT(*) FROM "sqlite_master"') > self::MAX_SCHEMA_OBJECTS) {
            throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'too many schema objects');
        }
        $oversized = $db->value('SELECT COUNT(*) FROM "sqlite_master" WHERE length("type") > 16 OR length("name") > 256 OR length("tbl_name") > 256 OR length("sql") > 1048576');
        if ($oversized !== 0) {
            throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'oversized schema object');
        }
        $rows = $db->all('SELECT "type", "name", "tbl_name", "sql" FROM "sqlite_master"');
        foreach ($rows as $row) {
            $type = $row['type'];
            $name = $row['name'];
            $table = $row['tbl_name'];
            $sql = $row['sql'];
            if (!is_string($type) || !is_string($name) || !is_string($table) || ($sql !== null && !is_string($sql))) {
                throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'schema row types');
            }
            if ($type !== 'table' && $type !== 'index') {
                // Triggers, views, and anything else SQLite might store.
                throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'trigger, view, or other object');
            }
            if ($type === 'table' && is_string($sql) && preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE\b/i', $sql) === 1) {
                throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'virtual table');
            }
            if (isset($documented[$name])) {
                [$expectedType, $expectedTable, $expectedSql] = $documented[$name];
                if ($type !== $expectedType || $table !== $expectedTable || !is_string($sql) || self::collapse($sql) !== $expectedSql) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'metadata object differs from the format');
                }
                $found[$name] = true;
                continue;
            }
            if ($type === 'table' && isset(self::SQLITE_TABLES[$name])) {
                if ($table !== $name || !is_string($sql) || self::collapse($sql) !== self::SQLITE_TABLES[$name]) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'sqlite table differs');
                }
                $sqliteTables[$name] = true;
                continue;
            }
            if ($type === 'table' && preg_match(self::USER_OBJECT, $name) === 1) {
                if ($table !== $name || !is_string($sql) || preg_match('/\bCHECK\b/i', $sql) === 1) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'user table with a check constraint');
                }
                $userTables[$name] = true;
                continue;
            }
            if ($type === 'index' && preg_match(self::AUTO_INDEX, $name, $match) === 1 && $match[1] === $table && $sql === null) {
                if (isset($documented[$table]) && preg_match('/\bUNIQUE\b/', $documented[$table][2]) !== 1) {
                    // SQLite makes automatic indexes only for UNIQUE columns; `_tiffin_collections` (WITHOUT ROWID) and the other metadata tables without one never have any.
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'automatic index on a metadata table without a unique column');
                }
                $userIndexes[] = [$name, $table, true];
                continue;
            }
            if ($type === 'index' && preg_match(self::USER_INDEX, $name, $match) === 1 && is_string($sql)) {
                $object = $match[1] . '_' . $match[2];
                if ($match[1] === 'f') {
                    $fieldIndexes[$match[2]] = $table;
                    if ($match[3] !== 'u') {
                        throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index name');
                    }
                } elseif ($object !== $table || ($match[1] === 't') !== ($match[3] === 'trash')) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index name');
                }
                $userIndexes[] = [$name, $table, false];
                continue;
            }
            throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'object name');
        }
        foreach ($documented as $name => [$expectedType]) {
            if ($expectedType === 'table' && !isset($found[$name])) {
                throw new ArchiveRefused(ArchiveRefused::NOT_PROJECT, 'metadata table missing');
            }
        }
        foreach ($userIndexes as [$name, $table, $automatic]) {
            if (!isset($userTables[$table]) && !isset($found[$table])) {
                throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index on an unknown table');
            }
            if (!$automatic) {
                self::checkPlainIndex($db, $table, $name);
            }
        }
        foreach (array_keys($userTables) as $name) {
            foreach ($db->all('SELECT "hidden" FROM pragma_table_xinfo(?)', [$name]) as $column) {
                if ($column['hidden'] !== 0) {
                    throw new ArchiveRefused(ArchiveRefused::BAD_TABLE, 'hidden or generated column');
                }
            }
        }

        return ['tables' => $userTables, 'fieldIndexes' => $fieldIndexes];
    }

    /** A user index must be a plain index on named columns: not partial, no expression. */
    private static function checkPlainIndex(Database $db, string $table, string $index): void
    {
        $listed = false;
        foreach ($db->all('SELECT "name", "partial" FROM pragma_index_list(?)', [$table]) as $row) {
            if ($row['name'] === $index) {
                $listed = true;
                if ($row['partial'] !== 0) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'partial index');
                }
            }
        }
        if (!$listed) {
            throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index not on its table');
        }
        $columns = 0;
        foreach ($db->all('SELECT "cid", "name", "key" FROM pragma_index_xinfo(?)', [$index]) as $row) {
            if ($row['key'] === 1) {
                $columns++;
                if (!is_int($row['cid']) || $row['cid'] < 0 || !is_string($row['name'])) {
                    throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index on an expression');
                }
            }
        }
        if ($columns !== 1) {
            throw new ArchiveRefused(ArchiveRefused::UNEXPECTED_OBJECT, 'index columns');
        }
    }

    /**
     * The `_tiffin_*` tables and indexes of a format version, from the
     * statements TiffinDB itself runs (ProjectFile::DDL), white space
     * collapsed: name => [type, table, SQL].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function documentedObjects(int $version): array
    {
        $objects = [];
        foreach (ProjectFile::DDL as $statement) {
            if (preg_match('/^CREATE (UNIQUE )?(TABLE|INDEX) (_tiffin_[a-z0-9_]+)(?: ON (_tiffin_[a-z0-9_]+))?/', $statement, $match) !== 1) {
                throw new \LogicException('A DDL statement has an unexpected form.');
            }
            $name = $match[3];
            $since = match (true) {
                str_starts_with($name, '_tiffin_forms') => 2,
                str_starts_with($name, '_tiffin_import_log') => 3,
                str_starts_with($name, '_tiffin_collections') => 4,
                default => 1,
            };
            if ($since > $version) {
                continue;
            }
            $isTable = $match[2] === 'TABLE';
            $objects[$name] = [$isTable ? 'table' : 'index', $isTable ? $name : ($match[4] ?? ''), self::collapse($statement)];
        }

        return $objects;
    }

    private static function collapse(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, TableDef>
     */
    private static function readTables(Database $db, array &$rows): array
    {
        self::require((int) $db->value('SELECT COUNT(*) FROM "_tiffin_tables"') <= self::MAX_TABLES, 'too many tables');
        self::require($db->value(
            'SELECT COUNT(*) FROM "_tiffin_tables" WHERE length("key") > 64 OR length("sql_name") > 64 OR length("name") > 240'
            . ' OR length("item_name") > 160 OR length("position") > 20 OR length("title_field") > 64',
        ) === 0, 'oversized table row');
        $result = $db->all('SELECT "key", "sql_name", "name", "item_name", "position", "title_field",'
            . ' CASE WHEN length("created_at") <= 64 THEN "created_at" END AS "created_at",'
            . ' CASE WHEN length("updated_at") <= 64 THEN "updated_at" END AS "updated_at"'
            . ' FROM "_tiffin_tables" ORDER BY "position", "key"');
        $tables = [];
        $names = [];
        foreach ($result as $row) {
            $key = $row['key'];
            self::require(Keys::isValid($key) && !isset($rows[$key]), 'table key');
            self::require($row['sql_name'] === Identifier::tableName($key), 'table sql name');
            $name = self::name($row['name'], SchemaEditor::MAX_TABLE_NAME, 'table name');
            $folded = strtolower($name);
            self::require(!isset($names[$folded]), 'table name twice');
            $names[$folded] = true;
            $itemName = self::name($row['item_name'], SchemaEditor::MAX_ITEM_NAME, 'item name');
            self::require(is_int($row['position']) && $row['position'] >= 1, 'table position');
            $title = $row['title_field'];
            self::require($title === null || Keys::isValid($title), 'title field key');
            $tables[$key] = new TableDef($key, $name, $itemName, $row['position'], $title);
            $rows[$key] = ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
        }

        return $tables;
    }

    /**
     * @param array<string, TableDef> $tables
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, FieldDef>
     */
    private static function readFields(Database $db, FieldTypes $types, array $tables, array &$rows): array
    {
        self::require((int) $db->value('SELECT COUNT(*) FROM "_tiffin_fields"') <= self::MAX_TABLES * self::MAX_FIELDS, 'too many fields');
        self::require($db->value('SELECT COUNT(*) FROM (SELECT 1 FROM "_tiffin_fields" GROUP BY "table_key" HAVING COUNT(*) > 500)') === 0, 'too many fields');
        self::require($db->value(
            'SELECT COUNT(*) FROM "_tiffin_fields" WHERE length("key") > 64 OR length("table_key") > 64 OR length("sql_name") > 64'
            . ' OR length("name") > 240 OR length("type") > 64 OR length("options") > 65536 OR length("required") > 20'
            . ' OR length("help") > 800 OR length("position") > 20',
        ) === 0, 'oversized field row');
        self::require((int) $db->value('SELECT COALESCE(SUM(length("options")), 0) FROM "_tiffin_fields"') <= self::MAX_OPTIONS_TOTAL, 'field options too large');
        $result = $db->all('SELECT "key", "table_key", "sql_name", "name", "type", "options", "required", "help", "position",'
            . ' CASE WHEN length("created_at") <= 64 THEN "created_at" END AS "created_at",'
            . ' CASE WHEN length("updated_at") <= 64 THEN "updated_at" END AS "updated_at"'
            . ' FROM "_tiffin_fields" ORDER BY "table_key", "position", "key"');
        $fields = [];
        $names = [];
        $counts = [];
        foreach ($result as $row) {
            $key = $row['key'];
            $tableKey = $row['table_key'];
            self::require(Keys::isValid($key) && !isset($rows[$key]), 'field key');
            self::require(is_string($tableKey) && isset($tables[$tableKey]), 'field table');
            $counts[$tableKey] = ($counts[$tableKey] ?? 0) + 1;
            self::require($counts[$tableKey] <= self::MAX_FIELDS, 'too many fields');
            self::require(is_string($row['type']) && $types->has($row['type']), 'field type');
            $type = $types->get($row['type']);
            $hasColumn = $type->columnDefinition() !== null;
            self::require($row['sql_name'] === ($hasColumn ? Identifier::columnName($key) : null), 'field sql name');
            $name = self::name($row['name'], SchemaEditor::MAX_FIELD_NAME, 'field name');
            $folded = strtolower($name);
            self::require(!isset($names[$tableKey][$folded]), 'field name twice');
            $names[$tableKey][$folded] = true;
            self::require(is_string($row['help']) && Text::optionalTextProblems($row['help'], 'help', SchemaEditor::MAX_HELP) === [], 'field help');
            self::require($row['required'] === 0 || $row['required'] === 1, 'field required');
            self::require(is_int($row['position']) && $row['position'] >= 1, 'field position');
            $given = self::decodeOptions($row['options']);
            if ($row['type'] === 'relation') {
                self::require(Keys::isValid($given['relation'] ?? null) && in_array($given['side'] ?? null, ['left', 'right'], true), 'relation options');
                $options = ['relation' => $given['relation'], 'side' => $given['side']];
            } else {
                try {
                    $options = $type->normaliseOptions($given);
                } catch (ValidationFailed $failed) {
                    throw new ArchiveRefused(ArchiveRefused::BAD_METADATA, 'field options', $failed);
                }
            }
            $required = $row['required'] === 1 && $row['type'] !== 'checkbox' && $row['type'] !== 'auto_number';
            $fields[$key] = new FieldDef($key, $tableKey, $name, $row['type'], $options, $required, Text::trim($row['help']), $row['position'], $hasColumn);
            $rows[$key] = ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
        }

        return $fields;
    }

    /**
     * @param array<string, FieldDef> $fields
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, RelationDef>
     */
    private static function readRelations(Database $db, array $fields, array &$rows): array
    {
        self::require((int) $db->value('SELECT COUNT(*) FROM "_tiffin_relations"') <= count($fields), 'too many relations');
        self::require($db->value(
            'SELECT COUNT(*) FROM "_tiffin_relations" WHERE length("key") > 64 OR length("join_table") > 64 OR length("left_table") > 64'
            . ' OR length("left_field") > 64 OR length("left_max") > 16 OR length("right_table") > 64 OR length("right_field") > 64'
            . ' OR length("right_max") > 16',
        ) === 0, 'oversized relation row');
        $result = $db->all('SELECT "key", "join_table", "left_table", "left_field", "left_max", "right_table", "right_field", "right_max",'
            . ' CASE WHEN length("created_at") <= 64 THEN "created_at" END AS "created_at"'
            . ' FROM "_tiffin_relations" ORDER BY "key"');
        $relations = [];
        $sides = [];
        foreach ($result as $row) {
            $key = $row['key'];
            self::require(Keys::isValid($key) && !isset($rows[$key]), 'relation key');
            self::require($row['join_table'] === Identifier::joinName($key), 'join table name');
            $leftMax = is_string($row['left_max']) ? Cardinality::tryFrom($row['left_max']) : null;
            $rightMax = is_string($row['right_max']) ? Cardinality::tryFrom($row['right_max']) : null;
            self::require($leftMax !== null && $rightMax !== null, 'relation maximum');
            foreach (['left', 'right'] as $side) {
                $field = is_string($row[$side . '_field']) ? ($fields[$row[$side . '_field']] ?? null) : null;
                self::require($field !== null && $field->type === 'relation', 'relation field');
                self::require($field->tableKey === $row[$side . '_table'], 'relation table');
                self::require($field->options['relation'] === $key && $field->options['side'] === $side, 'relation field options');
                self::require(!isset($sides[$field->key]), 'relation field twice');
                $sides[$field->key] = true;
            }
            $relations[$key] = new RelationDef($key, $row['left_table'], $row['left_field'], $leftMax, $row['right_table'], $row['right_field'], $rightMax);
            $rows[$key] = ['created_at' => $row['created_at']];
        }
        foreach ($fields as $field) {
            self::require($field->type !== 'relation' || isset($sides[$field->key]), 'relation field without a relation');
        }

        return $relations;
    }

    /**
     * @param array<string, TableDef> $tables
     * @param array<string, FieldDef> $fields
     */
    private static function checkTitles(array $tables, array $fields, FieldTypes $types): void
    {
        foreach ($tables as $table) {
            if ($table->titleFieldKey === null) {
                continue;
            }
            $field = $fields[$table->titleFieldKey] ?? null;
            self::require($field !== null && $field->tableKey === $table->key && $types->get($field->type)->canBeTitle(), 'title field');
        }
    }

    /**
     * Form keys share the key scope of tables, fields, and relations
     * (format section 4.6); a form whose key is not a key at all is left
     * to the rebuild, which drops it. Returns the valid form keys.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, true>
     */
    private static function checkFormKeys(Database $db, array $rows): array
    {
        self::require((int) $db->value('SELECT COUNT(*) FROM "_tiffin_forms"') <= self::MAX_FORMS, 'too many forms');
        $keys = [];
        foreach ($db->all('SELECT "key" FROM "_tiffin_forms" WHERE length("key") <= 64') as $row) {
            if (!Keys::isValid($row['key'])) {
                continue;
            }
            self::require(!isset($rows[$row['key']]) && !isset($keys[$row['key']]), 'form key used twice');
            $keys[$row['key']] = true;
        }

        return $keys;
    }

    /**
     * Version 4: the collection rows, bounded before they are read (at
     * most MAX_COLLECTION_ROWS rows and MAX_RULES_TOTAL bytes of `rules`),
     * with an explicit column list and every column's length guarded, so a
     * value longer than TiffinDB could have written arrives as null. A
     * collection key that is a key must not be the key of a table, field,
     * relation, form, or other collection; a key that is not a key is left
     * to the rebuild, which drops the row (`docs/specs/M4-core.md` 8.1).
     *
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, true> $formKeys
     * @return list<array<string, mixed>>
     */
    private static function readCollections(Database $db, array $rows, array $formKeys): array
    {
        self::require((int) $db->value('SELECT COUNT(*) FROM "_tiffin_collections"') <= self::MAX_COLLECTION_ROWS, 'too many collections');
        self::require((int) $db->value('SELECT COALESCE(SUM(length(CAST("rules" AS BLOB))), 0) FROM "_tiffin_collections"') <= self::MAX_RULES_TOTAL, 'collection rules too large');
        $result = $db->all('SELECT CASE WHEN length("key") <= 64 THEN "key" END AS "key",'
            . ' CASE WHEN length("table_key") <= 64 THEN "table_key" END AS "table_key",'
            . ' CASE WHEN length("name") <= 400 THEN "name" END AS "name",'
            . ' CASE WHEN length("match_mode") <= 16 THEN "match_mode" END AS "match_mode",'
            . ' "rules",'
            . ' CASE WHEN length("sort") <= 64 THEN "sort" END AS "sort",'
            . ' CASE WHEN typeof("position") = \'integer\' THEN "position" END AS "position",'
            . ' CASE WHEN length("created_at") > 64 THEN x\'00\' ELSE "created_at" END AS "created_at",'
            . ' CASE WHEN length("created_by") > 64 THEN x\'00\' ELSE "created_by" END AS "created_by",'
            . ' CASE WHEN length("updated_at") > 64 THEN x\'00\' ELSE "updated_at" END AS "updated_at",'
            . ' CASE WHEN length("updated_by") > 64 THEN x\'00\' ELSE "updated_by" END AS "updated_by"'
            // Ordered by the stored columns, not the guarded values: a position that is not a whole number sorts after every one that is.
            . ' FROM "_tiffin_collections" ORDER BY "_tiffin_collections"."position", "_tiffin_collections"."key"');
        $keys = [];
        foreach ($result as $row) {
            $key = $row['key'];
            if (!Keys::isValid($key)) {
                continue;
            }
            self::require(!isset($rows[$key]) && !isset($formKeys[$key]) && !isset($keys[$key]), 'collection key used twice');
            $keys[$key] = true;
        }

        return $result;
    }

    /** @param array<string, FieldDef> $fields */
    private static function checkUserTable(Database $db, TableDef $table, array $fields): void
    {
        $expected = ['id', '_created_at', '_created_by', '_updated_at', '_updated_by', '_trashed_at', '_trashed_by'];
        foreach ($fields as $field) {
            if ($field->tableKey === $table->key && $field->hasColumn) {
                $expected[] = Identifier::columnName($field->key);
            }
        }
        self::checkColumns($db, Identifier::tableName($table->key), $expected);
        try {
            $different = $db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "id" IS NOT rowid');
        } catch (\PDOException $error) {
            // A table without a rowid (WITHOUT ROWID) has no rowid column.
            throw new ArchiveRefused(ArchiveRefused::BAD_TABLE, 'user table without rowid', $error);
        }
        if ($different !== 0) {
            throw new ArchiveRefused(ArchiveRefused::BAD_TABLE, 'record ids are not the rowid');
        }
    }

    /** @param list<string> $expected */
    private static function checkColumns(Database $db, string $tableName, array $expected): void
    {
        $names = [];
        foreach ($db->all('SELECT "name", "hidden" FROM pragma_table_xinfo(?)', [$tableName]) as $column) {
            if ($column['hidden'] !== 0 || !is_string($column['name'])) {
                throw new ArchiveRefused(ArchiveRefused::BAD_TABLE, 'hidden or generated column');
            }
            $names[] = $column['name'];
        }
        sort($names, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($names !== $expected) {
            throw new ArchiveRefused(ArchiveRefused::BAD_TABLE, 'columns differ from the fields');
        }
    }

    private static function name(mixed $value, int $max, string $rule): string
    {
        self::require(is_string($value) && Text::nameProblems($value, 'name', $max) === [], $rule);

        return Text::trim($value);
    }

    /** @return array<string, mixed> */
    private static function decodeOptions(mixed $text): array
    {
        self::require(is_string($text), 'field options');
        try {
            $object = json_decode($text, false, 64, JSON_THROW_ON_ERROR);
            $options = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ArchiveRefused(ArchiveRefused::BAD_METADATA, 'field options', $error);
        }
        self::require($object instanceof \stdClass && is_array($options), 'field options');

        return $options;
    }

    /** @phpstan-assert true $condition */
    private static function require(bool $condition, string $rule): void
    {
        if (!$condition) {
            throw new ArchiveRefused(ArchiveRefused::BAD_METADATA, $rule);
        }
    }
}
