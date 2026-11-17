<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use InvalidArgumentException;
use TiffinDB\Core\Storage\Random;

/**
 * Deterministic "randomness" for tests, from a seed written in the test.
 *
 * Each call to bytes() concatenates blocks `hash('sha256', seed . counter,
 * true)` (the counter as decimal text, starting at 0 and increasing by one
 * per block for the life of the object) and returns the first $length
 * bytes; the rest of the last block is discarded. The same seed and the
 * same sequence of calls give the same bytes. Not secure; tests only.
 */
final class SeededRandom implements Random
{
    private int $counter = 0;

    public function __construct(private readonly string $seed)
    {
    }

    public function bytes(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('The length must be 0 or more.');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $bytes .= hash('sha256', $this->seed . (string) $this->counter, true);
            $this->counter++;
        }

        return substr($bytes, 0, $length);
    }
}
