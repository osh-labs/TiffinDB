<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;

/**
 * Password rules (ADR 0011 decision 4, M1 spec section 6.2, P32): at
 * least 10 characters (code points), at most 72 bytes whatever the hashing
 * algorithm, not equal to the username ignoring case. No composition
 * rules. Passwords are never trimmed.
 *
 * Invalid UTF-8, and a NUL byte (which bcrypt cannot hash, so the rule is
 * applied whatever the algorithm, as for the byte limit), give
 * problem.text.control.
 */
final class PasswordPolicy
{
    public const MIN_CHARACTERS = 10;
    public const MAX_BYTES = 72;

    private function __construct()
    {
    }

    /** @return list<Problem> */
    public static function problems(string $password, string $username, string $field = 'password'): array
    {
        if (!Text::isUtf8($password) || str_contains($password, "\0")) {
            return [new Problem($field, 'problem.text.control')];
        }
        $problems = [];
        if (Text::length($password) < self::MIN_CHARACTERS) {
            $problems[] = new Problem($field, 'problem.password.too_short', ['min' => self::MIN_CHARACTERS]);
        }
        if (strlen($password) > self::MAX_BYTES) {
            $problems[] = new Problem($field, 'problem.password.too_long');
        }
        $name = Usernames::normalise($username);
        if ($name !== '' && strtolower($password) === $name) {
            $problems[] = new Problem($field, 'problem.password.same_as_username');
        }

        return $problems;
    }
}
