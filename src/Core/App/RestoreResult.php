<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Storage\SnapshotInfo;

/** A finished restore (M7 spec section 5.1). */
final class RestoreResult
{
    /** @param int|null $upgradedFrom the snapshot's format version when it was upgraded on reopening, else null */
    public function __construct(
        public readonly SnapshotInfo $restored,
        public readonly SnapshotInfo $preRestore,
        public readonly int $filesMissing,
        public readonly int $filesRemoved,
        public readonly ?int $upgradedFrom,
    ) {
    }
}
