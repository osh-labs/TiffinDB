<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\System\ProjectRecord;

/** The snapshots page of a project (M7 spec section 5.1). */
final class SnapshotScreen
{
    /**
     * @param list<SnapshotInfo> $snapshots newest first
     * @param bool $canRestore whether this server can lock the project (restore refuses without)
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly array $snapshots,
        public readonly bool $canRestore,
    ) {
    }
}
