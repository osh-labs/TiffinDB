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

/**
 * Forward-only upgrades of the project file format (M1 spec section 7.2,
 * `docs/formats/tiffin.md` section 11). STEPS maps a target version to the
 * statements that bring a file from the version before it; it is empty in
 * M1 (version 2 arrives with M2). Every statement run here comes from a
 * constant of this class (M1 spec section 3.3): the steps run as
 * functions, and only the test seam supplies functions other than those
 * that run STEPS.
 */
final class FormatMigrations
{
    /** @var array<int, list<string>> */
    public const STEPS = [];

    /**
     * `PRAGMA user_version` takes no bound parameter, so each target version
     * has its statement here as a literal. Every version from 1 to
     * ProjectFile::VERSION must be listed.
     */
    public const VERSION_PRAGMAS = [
        1 => 'PRAGMA user_version = 1',
    ];

    /** @var array<int, \Closure(Database): void> */
    private readonly array $steps;

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
        foreach ($steps as $version => $step) {
            if (!is_int($version) || $version < 1 || !$step instanceof \Closure) {
                throw new \InvalidArgumentException('Format steps map a version to a function.');
            }
        }
        $this->steps = $steps;
    }

    /**
     * Brings a file from $from to ProjectFile::VERSION: a `format-upgrade`
     * snapshot first, then each step in its own transaction, which also sets
     * `PRAGMA user_version` and meta `format_version`. A failing step rolls
     * back and is rethrown; earlier steps stay applied. Unavailable
     * ('not-project') when a needed step is missing (nothing is changed).
     */
    public function upgrade(Database $db, string $projectFolder, Snapshots $snapshots, int $from): void
    {
        if ($from >= ProjectFile::VERSION) {
            return;
        }
        for ($version = $from + 1; $version <= ProjectFile::VERSION; $version++) {
            if (!isset($this->steps[$version]) || !isset(self::VERSION_PRAGMAS[$version])) {
                throw new Unavailable(Unavailable::NOT_PROJECT);
            }
        }
        $snapshots->take($db, $projectFolder, SnapshotReason::FormatUpgrade);
        for ($version = $from + 1; $version <= ProjectFile::VERSION; $version++) {
            $step = $this->steps[$version];
            $db->write(static function (Database $db) use ($step, $version): void {
                $step($db);
                foreach (self::VERSION_PRAGMAS as $target => $pragma) {
                    if ($target === $version) {
                        $db->run($pragma);
                    }
                }
                $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'format_version\'', [(string) $version]);
            });
        }
    }
}
