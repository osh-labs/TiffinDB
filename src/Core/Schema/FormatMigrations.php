<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;

/**
 * Forward-only upgrades of the project file format (M1 spec section 7.2,
 * `docs/formats/tiffin.md` section 11). STEPS maps a target version to the
 * statements that bring a file from the version before it: version 2
 * (M2) adds `_tiffin_forms` with exactly the statements of section 11.1
 * (T-FMT-1 compares them), version 3 (M3) adds `_tiffin_import_log` with
 * exactly those of section 11.2 (T-FMT-5), version 4 (M4) adds
 * `_tiffin_collections` with exactly those of section 11.3 (T-FMT-7). Every statement run here comes from a
 * constant of this class (M1 spec section 3.3): the steps run as
 * functions, and only the test seam supplies functions other than those
 * that run STEPS.
 */
final class FormatMigrations
{
    /** @var array<int, list<string>> */
    public const STEPS = [
        2 => [
            'CREATE TABLE _tiffin_forms (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    format     INTEGER NOT NULL,
    html       TEXT NOT NULL,
    css        TEXT NOT NULL DEFAULT \'\',
    is_default INTEGER NOT NULL DEFAULT 0,
    generated  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) WITHOUT ROWID',
            'CREATE INDEX _tiffin_forms_table ON _tiffin_forms (table_key)',
            'CREATE UNIQUE INDEX _tiffin_forms_default ON _tiffin_forms (table_key) WHERE is_default = 1',
        ],
        3 => [
            'CREATE TABLE _tiffin_import_log (
    key            TEXT PRIMARY KEY,
    imported_at    TEXT NOT NULL,
    imported_by    TEXT,
    source         TEXT NOT NULL,
    plugin         TEXT,
    file_name      TEXT NOT NULL,
    file_size      INTEGER NOT NULL,
    file_sha256    TEXT NOT NULL,
    table_key      TEXT,
    new_table      INTEGER NOT NULL DEFAULT 0,
    rows_read      INTEGER NOT NULL,
    rows_imported  INTEGER NOT NULL,
    rows_skipped   INTEGER NOT NULL,
    values_emptied INTEGER NOT NULL,
    links_added    INTEGER NOT NULL DEFAULT 0,
    snapshot       TEXT,
    report         TEXT NOT NULL DEFAULT \'{}\'
) WITHOUT ROWID',
            'CREATE INDEX _tiffin_import_log_at ON _tiffin_import_log (imported_at)',
        ],
        4 => [
            'CREATE TABLE _tiffin_collections (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    match_mode TEXT NOT NULL DEFAULT \'all\',
    rules      TEXT NOT NULL DEFAULT \'[]\',
    sort       TEXT,
    position   INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    created_by TEXT,
    updated_at TEXT NOT NULL,
    updated_by TEXT
) WITHOUT ROWID',
            'CREATE UNIQUE INDEX _tiffin_collections_name ON _tiffin_collections (name COLLATE NOCASE)',
            'CREATE INDEX _tiffin_collections_table ON _tiffin_collections (table_key, position)',
        ],
    ];

    /**
     * `PRAGMA user_version` takes no bound parameter, so each target version
     * has its statement here as a literal. Every version from 1 to
     * ProjectFile::VERSION must be listed.
     */
    public const VERSION_PRAGMAS = [
        1 => 'PRAGMA user_version = 1',
        2 => 'PRAGMA user_version = 2',
        3 => 'PRAGMA user_version = 3',
        4 => 'PRAGMA user_version = 4',
    ];

    /**
     * The `PRAGMA user_version` literals of versions past ProjectFile::VERSION,
     * used only by the test seam to run a simulated next step (T-UPG-5).
     * Application code never reaches them: without injected steps the
     * target is ProjectFile::VERSION.
     */
    public const SEAM_VERSION_PRAGMAS = [
        5 => 'PRAGMA user_version = 5',
        6 => 'PRAGMA user_version = 6',
        7 => 'PRAGMA user_version = 7',
        8 => 'PRAGMA user_version = 8',
    ];

    /** @var array<int, \Closure(Database): void> */
    private readonly array $steps;
    private readonly int $target;

    /**
     * Without arguments, the steps run the statements of STEPS.
     *
     * @internal The $steps argument is a test seam: each function makes
     *     the changes of one target version on the connection it is given.
     *     Application code calls this constructor with no arguments.
     * @param array<int, \Closure(Database): void>|null $steps
     */
    public function __construct(?array $steps = null)
    {
        if ($steps === null) {
            $steps = [];
            foreach (array_keys(self::STEPS) as $version) {
                $steps[$version] = static function (Database $db) use ($version): void {
                    foreach (self::STEPS[$version] as $statement) {
                        $db->run($statement);
                    }
                };
            }
        }
        $target = ProjectFile::VERSION;
        foreach ($steps as $version => $step) {
            if (!is_int($version) || $version < 1 || !$step instanceof \Closure) {
                throw new \InvalidArgumentException('Format steps map a version to a function.');
            }
            if ($version > ProjectFile::VERSION && !isset(self::SEAM_VERSION_PRAGMAS[$version])) {
                throw new \InvalidArgumentException('A simulated step must target a version listed in SEAM_VERSION_PRAGMAS.');
            }
            $target = max($target, $version);
        }
        $this->steps = $steps;
        $this->target = $target;
    }

    /**
     * The version upgrade() brings a file to: ProjectFile::VERSION, or with
     * injected steps (the test seam) the highest version they name, when
     * that is higher. ProjectFile::open refuses a file newer than this.
     */
    public function target(): int
    {
        return $this->target;
    }

    /**
     * Brings a file from $from to target() (ProjectFile::VERSION in the application): a `format-upgrade`
     * snapshot first (unless the newest snapshot already is one of this
     * file at $from, P183), then each step in its own transaction, which also sets
     * `PRAGMA user_version` and meta `format_version`. A failing step rolls
     * back and is rethrown; earlier steps stay applied. Unavailable
     * ('not-project') when a needed step is missing (nothing is changed).
     */
    public function upgrade(Database $db, string $projectFolder, Snapshots $snapshots, int $from): void
    {
        $target = $this->target;
        if ($from >= $target) {
            return;
        }
        for ($version = $from + 1; $version <= $target; $version++) {
            if (!isset($this->steps[$version]) || !isset(self::VERSION_PRAGMAS[$version]) && !isset(self::SEAM_VERSION_PRAGMAS[$version])) {
                throw new Unavailable(Unavailable::NOT_PROJECT);
            }
        }
        if (!self::alreadySaved($db, $projectFolder, $snapshots, $from)) {
            $snapshots->take($db, $projectFolder, SnapshotReason::FormatUpgrade);
        }
        for ($version = $from + 1; $version <= $target; $version++) {
            $step = $this->steps[$version];
            $db->write(static fn (Database $db) => self::apply($db, $step, $version));
        }
    }

    /**
     * Whether the newest snapshot is a `format-upgrade` snapshot of this
     * file at $from (its `PRAGMA user_version` is $from and its meta
     * `project_key` is the file's own): an earlier attempt from this
     * version already saved this state, and a failed upgrade leaves the
     * project unopenable, so nothing has changed it since (P183, M7 spec
     * section 8). Any error while reading means no.
     */
    private static function alreadySaved(Database $db, string $projectFolder, Snapshots $snapshots, int $from): bool
    {
        $copy = null;
        try {
            $newest = $snapshots->list($projectFolder)[0] ?? null;
            if ($newest === null || $newest->reason !== SnapshotReason::FormatUpgrade) {
                return false;
            }
            $key = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_key\'');
            $copy = Sqlite::openUntrusted(Snapshots::path($projectFolder, $newest));
            $version = $copy->value('PRAGMA user_version');
            $copyKey = $copy->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_key\'');

            return is_string($key) && $version === $from && $copyKey === $key;
        } catch (\Throwable) {
            return false;
        } finally {
            $copy?->close();
        }
    }

    /** One step, then the version's pragma and meta `format_version`, inside the caller's transaction. */
    private static function apply(Database $db, \Closure $step, int $version): void
    {
        $step($db);
        foreach (self::VERSION_PRAGMAS as $target => $pragma) {
            if ($target === $version) {
                $db->run($pragma);
            }
        }
        foreach (self::SEAM_VERSION_PRAGMAS as $target => $pragma) {
            if ($target === $version) {
                $db->run($pragma);
            }
        }
        $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'format_version\'', [(string) $version]);
    }
}
