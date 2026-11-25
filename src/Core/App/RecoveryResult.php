<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * The outcome of an Admin recovery attempt (M7 spec section 9.2). `failed`
 * is one result whatever went wrong (wrong or missing code, unknown name,
 * a Member, an inactive account), so it discloses nothing; its message is
 * FAILED_KEY. `wait` carries the seconds of the throttle's block.
 */
final class RecoveryResult
{
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const WAIT = 'wait';

    /** The message key of the `failed` outcome (M7 spec section 11.3). */
    public const FAILED_KEY = 'problem.recovery.failed';

    public function __construct(
        public readonly string $outcome,
        public readonly int $waitSeconds = 0,
    ) {
        if (!in_array($outcome, [self::DONE, self::FAILED, self::WAIT], true)) {
            throw new \InvalidArgumentException('Unknown recovery outcome.');
        }
    }
}
