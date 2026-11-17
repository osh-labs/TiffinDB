<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;

/** Username rules (ADR 0011 decision 3, M1 spec section 6.2). */
final class Usernames
{
    public const MIN = 3;
    public const MAX = 32;
    public const PATTERN = '/^[A-Za-z0-9._-]{3,32}\z/';

    private function __construct()
    {
    }

    /**
     * The form used for uniqueness and look-up: trimmed (as every input
     * string is, section 3.4), then ASCII letters lower-cased. Trimming
     * here means "admin" and " admin " share one throttle bucket.
     */
    public static function normalise(string $username): string
    {
        return strtolower(Text::trim($username));
    }

    /** @return list<Problem> */
    public static function problems(string $username, string $field = 'username'): array
    {
        if (!Text::isUtf8($username) || preg_match(self::PATTERN, Text::trim($username)) !== 1) {
            return [new Problem($field, 'problem.username.invalid')];
        }

        return [];
    }
}
