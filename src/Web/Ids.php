<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * Generated element ids (M2 spec part 2 section 4.2, P96): a short prefix
 * and parts that are field keys, table keys, or record ids, joined by "-".
 * Templates reach this through ViewScope::id(); controllers use make() for
 * the `field` of error-summary problems, so summary links match controls.
 */
final class Ids
{
    private const PREFIX = '/^[a-z][a-z-]{0,15}\z/';

    private const PART = '/^[a-z0-9]{1,24}\z/';

    private function __construct()
    {
    }

    /** @throws InvalidArgumentException for a prefix or part outside the patterns */
    public static function make(string $prefix, string|int ...$parts): string
    {
        if (preg_match(self::PREFIX, $prefix) !== 1) {
            throw new InvalidArgumentException('Invalid id prefix.');
        }
        if ($parts === []) {
            throw new InvalidArgumentException('An id needs at least one part.');
        }
        $texts = [];
        foreach ($parts as $part) {
            $text = (string) $part;
            if (preg_match(self::PART, $text) !== 1) {
                throw new InvalidArgumentException('Invalid id part.');
            }
            $texts[] = $text;
        }

        return $prefix . '-' . implode('-', $texts);
    }
}
