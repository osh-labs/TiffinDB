<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/**
 * Where an import job stands, used to route the import screens (M3 spec
 * section 13.1). `$state` is one of the STATE_* values; `$failure` is the
 * reason code of a failed job (`plugin-<reason>`, `plugin-unavailable`,
 * `commit-failed`).
 */
final class ImportJobView
{
    public const STATE_READING = 'reading';
    public const STATE_REVIEW = 'review';
    public const STATE_PREPARING = 'preparing';
    public const STATE_READY = 'ready';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';

    public function __construct(
        public readonly ProjectRecord $project,
        public readonly string $jobKey,
        public readonly string $state,
        public readonly string $fileName,
        public readonly int $readRows,
        public readonly ?int $totalRows,
        public readonly bool $readDone,
        public readonly int $storedRows,
        public readonly int $preparedRows,
        public readonly ?string $failure,
    ) {
    }
}
