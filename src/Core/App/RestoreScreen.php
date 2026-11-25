<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\System\ProjectRecord;

/** What restoring a snapshot would do, read without changing anything (M7 spec section 5.1). */
final class RestoreScreen
{
    /**
     * @param int $formatVersion the snapshot's format version (PRAGMA user_version)
     * @param int $recordsNow live records of the project now, all tables
     * @param int $recordsThen live records in the snapshot, all tables
     * @param int $filesMissing files the snapshot names that are not stored
     * @param int $filesRemoved stored files no row of the snapshot names (deleted by the restore)
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly SnapshotInfo $snapshot,
        public readonly int $formatVersion,
        public readonly int $recordsNow,
        public readonly int $recordsThen,
        public readonly int $filesMissing,
        public readonly int $filesRemoved,
    ) {
    }
}
