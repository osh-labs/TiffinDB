<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Creates and opens project files (M1 spec section 7.2). The file format
 * is `docs/formats/tiffin.md`, version 1; DDL holds its section 4
 * statements verbatim (T-SCH-1 compares them).
 */
final class ProjectFile
{
    public const FORMAT = 'tiffindb-project';
    public const VERSION = 1;
    public const APPLICATION_ID = 1413891138;

    /** `docs/formats/tiffin.md` section 4, format version 1, in order. */
    public const DDL = [
        'CREATE TABLE _tiffin_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
) WITHOUT ROWID',
        'CREATE TABLE _tiffin_tables (
    key         TEXT PRIMARY KEY,
    sql_name    TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    item_name   TEXT NOT NULL,
    position    INTEGER NOT NULL,
    title_field TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
) WITHOUT ROWID',
        'CREATE UNIQUE INDEX _tiffin_tables_name ON _tiffin_tables (name COLLATE NOCASE)',
        'CREATE TABLE _tiffin_fields (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    sql_name   TEXT UNIQUE,
    name       TEXT NOT NULL,
    type       TEXT NOT NULL,
    options    TEXT NOT NULL DEFAULT \'{}\',
    required   INTEGER NOT NULL DEFAULT 0,
    help       TEXT NOT NULL DEFAULT \'\',
    position   INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) WITHOUT ROWID',
        'CREATE UNIQUE INDEX _tiffin_fields_name ON _tiffin_fields (table_key, name COLLATE NOCASE)',
        'CREATE INDEX _tiffin_fields_order ON _tiffin_fields (table_key, position)',
        'CREATE TABLE _tiffin_relations (
    key         TEXT PRIMARY KEY,
    join_table  TEXT NOT NULL UNIQUE,
    left_table  TEXT NOT NULL REFERENCES _tiffin_tables (key),
    left_field  TEXT NOT NULL UNIQUE REFERENCES _tiffin_fields (key),
    left_max    TEXT NOT NULL,
    right_table TEXT NOT NULL REFERENCES _tiffin_tables (key),
    right_field TEXT NOT NULL UNIQUE REFERENCES _tiffin_fields (key),
    right_max   TEXT NOT NULL,
    created_at  TEXT NOT NULL
) WITHOUT ROWID',
        'CREATE TABLE _tiffin_attachments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    sha256        TEXT NOT NULL,
    size          INTEGER NOT NULL,
    media_type    TEXT NOT NULL,
    original_name TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    created_by    TEXT
)',
        'CREATE INDEX _tiffin_attachments_sha256 ON _tiffin_attachments (sha256)',
        'CREATE TABLE _tiffin_attachment_links (
    field_key     TEXT NOT NULL REFERENCES _tiffin_fields (key) ON DELETE CASCADE,
    record_id     INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL REFERENCES _tiffin_attachments (id),
    position      INTEGER NOT NULL,
    PRIMARY KEY (field_key, record_id, attachment_id)
) WITHOUT ROWID',
    ];

    private function __construct()
    {
    }

    /**
     * Creates project.tiffin at $path with every format-1 table and the nine
     * meta rows of `docs/formats/tiffin.md` section 4.1. On any failure after
     * the file was made, the file (and its -wal and -shm) is removed again.
     */
    public static function create(
        string $path,
        string $projectKey,
        string $name,
        string $createdByKey,
        string $appVersion,
        Clock $clock,
    ): Database {
        if (!Keys::isValid($projectKey) || !Keys::isValid($createdByKey)) {
            throw new \InvalidArgumentException('Project and person keys must be valid keys.');
        }
        $db = Sqlite::create($path);
        try {
            $db->run('PRAGMA application_id = 1413891138');
            $db->run('PRAGMA user_version = 1');
            $now = Timestamp::format($clock->now());
            $db->write(static function (Database $db) use ($projectKey, $name, $createdByKey, $appVersion, $now): void {
                foreach (self::DDL as $statement) {
                    $db->run($statement);
                }
                $meta = [
                    'format' => self::FORMAT,
                    'format_version' => (string) self::VERSION,
                    'project_key' => $projectKey,
                    'project_name' => $name,
                    'created_at' => $now,
                    'created_by' => $createdByKey,
                    'created_with' => $appVersion,
                    'updated_at' => $now,
                    'updated_by' => $createdByKey,
                ];
                foreach ($meta as $key => $value) {
                    $db->run('INSERT INTO "_tiffin_meta" ("key", "value") VALUES (?, ?)', [$key, $value]);
                }
            });
        } catch (\Throwable $error) {
            $db->close();
            foreach (['', '-wal', '-shm'] as $suffix) {
                Fs::remove($path . $suffix);
            }

            throw $error;
        }

        return $db;
    }

    /**
     * Opens a project file, checks that it is a TiffinDB project with the
     * expected key, and upgrades an older format (after a `format-upgrade`
     * snapshot). Throws Unavailable('missing'), ('not-project'), ('newer'),
     * or ('mismatch'); the connection is closed before any of them.
     */
    public static function open(
        string $path,
        string $expectedKey,
        Snapshots $snapshots,
        ?FormatMigrations $migrations = null,
    ): Database {
        try {
            $db = Sqlite::open($path);
        } catch (\PDOException) {
            // The connection settings could not be applied: the file is not an SQLite database.
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }
        try {
            $version = self::checkIdentity($db, $expectedKey);
            if ($version < self::VERSION) {
                ($migrations ?? new FormatMigrations())->upgrade($db, dirname($path), $snapshots, $version);
            }
        } catch (\Throwable $error) {
            $db->close();

            throw $error;
        }

        return $db;
    }

    /** Records who changed the project, and when (meta `updated_at`, `updated_by`). */
    public static function touch(Database $db, string $userKey, Clock $clock): void
    {
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
        $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'updated_at\'', [Timestamp::format($clock->now())]);
        $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'updated_by\'', [$userKey]);
    }

    /** Sets meta `project_name`. The caller validates the name. */
    public static function setName(Database $db, string $name): void
    {
        $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'project_name\'', [$name]);
    }

    /** The file's format version, after checking application id, format, version, and key. */
    private static function checkIdentity(Database $db, string $expectedKey): int
    {
        try {
            $applicationId = $db->value('PRAGMA application_id');
            $format = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format\'');
            $version = $db->value('PRAGMA user_version');
            $projectKey = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_key\'');
        } catch (\PDOException) {
            // Not a database, or a database without the metadata tables.
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }
        if ($applicationId !== self::APPLICATION_ID || $format !== self::FORMAT || !is_int($version) || $version < 0) {
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }
        if ($version > self::VERSION) {
            throw new Unavailable(Unavailable::NEWER);
        }
        if ($projectKey !== $expectedKey) {
            throw new Unavailable(Unavailable::MISMATCH);
        }

        return $version;
    }
}
