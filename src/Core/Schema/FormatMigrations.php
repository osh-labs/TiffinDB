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
 * M1 (version 2 arrives with M2). The constructor takes other steps as a
 * test seam.
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

    /** @var array<int, list<string>> */
    private readonly array $steps;

    /** @param array<int, list<string>>|null $steps */
    public function __construct(?array $steps = null)
    {
        $steps ??= self::STEPS;
        foreach ($steps as $version => $statements) {
            if (!is_int($version) || $version < 1 || !is_array($statements) || !array_is_list($statements)) {
                throw new \InvalidArgumentException('Format steps map a version to a list of statements.');
            }
            foreach ($statements as $statement) {
                if (!is_string($statement)) {
                    throw new \InvalidArgumentException('A format step statement must be a string.');
                }
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
            $statements = $this->steps[$version];
            $pragma = self::VERSION_PRAGMAS[$version];
            $db->write(static function (Database $db) use ($statements, $pragma, $version): void {
                foreach ($statements as $statement) {
                    $db->run($statement);
                }
                $db->run($pragma);
                $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'format_version\'', [(string) $version]);
            });
        }
    }
}
