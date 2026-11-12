<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** Cryptographically secure randomness from the operating system. */
final class SecureRandom implements Random
{
    public function bytes(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('The length must be 0 or more.');
        }

        return $length === 0 ? '' : random_bytes($length);
    }
}
