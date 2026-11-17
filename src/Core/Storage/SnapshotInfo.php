<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** One snapshot file in a project's `snapshots/` folder. */
final class SnapshotInfo
{
    public function __construct(
        public readonly string $file,
        public readonly \DateTimeImmutable $takenAt,
        public readonly SnapshotReason $reason,
        public readonly int $size,
    ) {
    }
}
