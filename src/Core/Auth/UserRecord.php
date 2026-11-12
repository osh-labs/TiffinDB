<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** A person, without the password hash (M1 spec section 6.1). */
final class UserRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $username,
        public readonly string $displayName,
        public readonly Role $role,
        public readonly bool $active,
        public readonly bool $mustChangePassword,
        public readonly string $createdAt,
        public readonly ?string $lastSignInAt,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * The first letter of the first and of the last word of the display
     * name, upper-cased (section 6.3); one word gives one letter. Letters
     * are code points; upper-casing uses \IntlChar when available, else
     * ASCII only. Decoration only.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', $this->displayName, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }
        $first = self::firstLetter($words[0]);
        if (count($words) === 1) {
            return $first;
        }

        return $first . self::firstLetter($words[count($words) - 1]);
    }

    private static function firstLetter(string $word): string
    {
        if (preg_match('/^./su', $word, $match) !== 1) {
            return '';
        }
        $letter = $match[0];
        if (class_exists(\IntlChar::class)) {
            $upper = \IntlChar::toupper($letter);

            return is_string($upper) ? $upper : $letter;
        }

        return strlen($letter) === 1 ? strtoupper($letter) : $letter;
    }
}
