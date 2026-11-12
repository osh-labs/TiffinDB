<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;

/** Display-name rules (M1 spec section 6.2): Text::nameProblems with at most 60 characters. */
final class DisplayNames
{
    public const MAX = 60;

    private function __construct()
    {
    }

    /** @return list<Problem> */
    public static function problems(string $name, string $field = 'display_name'): array
    {
        return Text::nameProblems($name, $field, self::MAX);
    }
}
