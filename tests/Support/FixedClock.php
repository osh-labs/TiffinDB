<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use TiffinDB\Core\Storage\Clock;

/** A clock for tests: stands still until set or advanced; always UTC. */
final class FixedClock implements Clock
{
    public const DEFAULT_START = '2026-01-15T09:30:00Z';

    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $start = self::DEFAULT_START)
    {
        $this->set($start);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable|string $time): void
    {
        $utc = new DateTimeZone('UTC');
        $this->now = is_string($time)
            ? new DateTimeImmutable($time, $utc)
            : $time;
        $this->now = $this->now->setTimezone($utc);
    }

    /** Moves the clock forward (or back, with a negative number) by whole seconds. */
    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
