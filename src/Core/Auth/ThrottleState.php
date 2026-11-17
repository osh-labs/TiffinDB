<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** One login_throttle row (no bucket name), as read or as written by LoginThrottle::failure(). */
final class ThrottleState
{
    public function __construct(
        public readonly int $failures,
        public readonly string $windowStartedAt,
        public readonly string $lastFailureAt,
        public readonly ?string $blockedUntil,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->failures === $other->failures
            && $this->windowStartedAt === $other->windowStartedAt
            && $this->lastFailureAt === $other->lastFailureAt
            && $this->blockedUntil === $other->blockedUntil;
    }
}
