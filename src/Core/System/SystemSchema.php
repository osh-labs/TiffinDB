<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\SystemClock;

/**
 * The system database schema and its forward-only upgrade steps (M1 spec
 * sections 5.1 and 5.3, P36).
 *
 * Each step runs in its own write() transaction, which also sets
 * meta.schema_version, so a failing step leaves the previous version and
 * its tables unchanged. Before a step runs on an existing installation
 * (a file database already at version 1 or later), the file is copied
 * with VACUUM INTO to `system-before-v<N>-<YYYYMMDDTHHMMSSZ>.sqlite` beside
 * it.
 *
 * Amended from the M1 spec section 5.1 DDL by P65: login_throttle has a
 * last_failure_at column (the reset rule is measured from it).
 *
 * Enumerated values are validated in code, not with CHECK constraints
 * (ADR 0011 decision 2, P44).
 */
final class SystemSchema
{
    public const VERSION = 1;

    /** @var array<int, list<string>> target version => statements */
    public const STEPS = [
        1 => [
            'CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
) WITHOUT ROWID',
            'CREATE TABLE users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    key                  TEXT NOT NULL UNIQUE,
    username             TEXT NOT NULL,
    username_norm        TEXT NOT NULL UNIQUE,
    display_name         TEXT NOT NULL,
    role                 TEXT NOT NULL,
    password_hash        TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    active               INTEGER NOT NULL DEFAULT 1,
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    password_changed_at  TEXT NOT NULL,
    last_sign_in_at      TEXT
)',
            "CREATE TABLE user_settings (
    user_id       INTEGER PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    theme         TEXT NOT NULL DEFAULT 'light',
    accent        TEXT NOT NULL DEFAULT 'teal',
    typeface      TEXT NOT NULL DEFAULT 'inter',
    text_size     TEXT NOT NULL DEFAULT 'standard',
    line_spacing  TEXT NOT NULL DEFAULT 'standard',
    reduce_motion INTEGER NOT NULL DEFAULT 0
)",
            'CREATE TABLE sessions (
    token_hash          TEXT PRIMARY KEY,
    user_id             INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    csrf_token          TEXT NOT NULL,
    flash               TEXT,
    created_at          TEXT NOT NULL,
    last_seen_at        TEXT NOT NULL,
    idle_expires_at     TEXT NOT NULL,
    absolute_expires_at TEXT NOT NULL
) WITHOUT ROWID',
            'CREATE INDEX sessions_user ON sessions (user_id)',
            'CREATE TABLE login_throttle (
    bucket            TEXT PRIMARY KEY,
    failures          INTEGER NOT NULL,
    window_started_at TEXT NOT NULL,
    last_failure_at   TEXT NOT NULL,
    blocked_until     TEXT
) WITHOUT ROWID',
            "CREATE TABLE projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    key        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    state      TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL,
    created_by INTEGER REFERENCES users (id) ON DELETE SET NULL
)",
            'CREATE UNIQUE INDEX projects_name ON projects (name COLLATE NOCASE)',
            "CREATE TABLE project_grants (
    project_id INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    level      TEXT NOT NULL DEFAULT 'edit',
    granted_at TEXT NOT NULL,
    granted_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    PRIMARY KEY (project_id, user_id)
) WITHOUT ROWID",
            'CREATE INDEX project_grants_user ON project_grants (user_id)',
            "CREATE TABLE audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    at            TEXT NOT NULL,
    actor_user_id INTEGER REFERENCES users (id) ON DELETE SET NULL,
    action        TEXT NOT NULL,
    project_id    INTEGER REFERENCES projects (id) ON DELETE SET NULL,
    target_kind   TEXT,
    target_key    TEXT,
    detail        TEXT NOT NULL DEFAULT '{}'
)",
            'CREATE INDEX audit_log_at ON audit_log (at)',
            'CREATE INDEX audit_log_action ON audit_log (action, at)',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Brings $db up to the highest version in $steps (default STEPS).
     * Throws Unavailable('newer') when the database is already at a higher
     * version, and \LogicException when called inside a transaction.
     *
     * @param array<int, list<string>>|null $steps test seam; versions 1..N, contiguous
     * @param Clock|null $clock for the name of the copy taken before a step (default: the system clock)
     */
    public static function migrate(Database $db, ?array $steps = null, ?Clock $clock = null): void
    {
        if ($db->inTransaction()) {
            throw new \LogicException('SystemSchema::migrate() must not run inside a transaction.');
        }
        $steps ??= self::STEPS;
        $target = self::checkSteps($steps);
        $current = self::currentVersion($db);
        if ($current > $target) {
            throw new Unavailable(Unavailable::NEWER);
        }
        for ($version = $current + 1; $version <= $target; $version++) {
            if ($current >= 1 && $db->path() !== Sqlite::MEMORY) {
                self::copyBefore($db, $version, $clock ?? new SystemClock());
            }
            $statements = $steps[$version];
            $db->write(static function (Database $db) use ($statements, $version): void {
                foreach ($statements as $sql) {
                    $db->run($sql);
                }
                $db->run(
                    'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
                    ['schema_version', (string) $version],
                );
            });
            $current = $version;
        }
    }

    /** meta.schema_version, or 0 when there is no meta table or no version. */
    public static function currentVersion(Database $db): int
    {
        $hasMeta = $db->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'meta'");
        if ($hasMeta === null) {
            return 0;
        }
        $value = $db->value("SELECT value FROM meta WHERE key = 'schema_version'");
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}\z/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param array<int, list<string>> $steps
     * @return int the highest version
     */
    private static function checkSteps(array $steps): int
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('At least one schema step is needed.');
        }
        $expected = 1;
        foreach ($steps as $version => $statements) {
            if ($version !== $expected || !is_array($statements) || !array_is_list($statements)) {
                throw new \InvalidArgumentException('Schema steps must be numbered 1, 2, 3, ... with a list of statements each.');
            }
            foreach ($statements as $sql) {
                if (!is_string($sql) || $sql === '') {
                    throw new \InvalidArgumentException('A schema statement must be a non-empty string.');
                }
            }
            $expected++;
        }

        return $expected - 1;
    }

    private static function copyBefore(Database $db, int $version, Clock $clock): void
    {
        $stamp = $clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $copy = dirname($db->path()) . '/system-before-v' . $version . '-' . $stamp . '.sqlite';
        $db->run('VACUUM INTO ?', [$copy]);
        $changed = ErrorTrap::run(static fn (): bool => chmod($copy, Fs::FILE_MODE), 'Cannot set the copy file mode.');
        if (!$changed) {
            throw new \RuntimeException('Cannot set the copy file mode.');
        }
    }
}
