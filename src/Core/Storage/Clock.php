<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** The only source of the current time in the core. */
interface Clock
{
    /** The current time, always in UTC. */
    public function now(): \DateTimeImmutable;
}
