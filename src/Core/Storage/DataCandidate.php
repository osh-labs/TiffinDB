<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** A place where the data directory may be (ADR 0011 decision 1). */
final class DataCandidate
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly bool $inTree,
    ) {
    }
}
