<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;

/**
 * The outcome of ImportOperations::save: the import log key when the rows
 * were saved, or, when saving failed and was rolled back, the problem
 * (`problem.import.commit_failed`). The job is then Failed with reason
 * `commit-failed`, and the job address shows the failure page.
 */
final class ImportCommitResult
{
    public function __construct(
        public readonly ?string $logKey,
        public readonly ?Problem $problem = null,
    ) {
        if (($logKey === null) === ($problem === null)) {
            throw new \InvalidArgumentException('A commit result is either a log key or a problem.');
        }
    }

    public function failed(): bool
    {
        return $this->problem !== null;
    }
}
