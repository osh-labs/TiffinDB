<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/** What deleting a project removes (M7 spec section 6.1). */
final class ProjectDeleteScreen
{
    /**
     * @param int $records live records, all tables
     * @param int $trashed records in the trash, all tables
     * @param int $files distinct stored files; $fileBytes their total size
     * @param int $members granted Members, active or not
     * @param bool $folderMissing nothing is at the project folder's path (P239): every count is 0
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly int $records,
        public readonly int $trashed,
        public readonly int $files,
        public readonly int $fileBytes,
        public readonly int $snapshots,
        public readonly int $members,
        public readonly bool $folderMissing,
    ) {
    }
}
