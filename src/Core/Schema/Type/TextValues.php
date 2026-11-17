<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/** Private helper shared by the types whose canonical value is a string. */
final class TextValues
{
    private function __construct()
    {
    }

    /** Control characters (tab always allowed, \n when asked), then the length limit. */
    public static function limits(string $text, int $max, bool $allowNewline): Checked
    {
        if (Text::hasControl($text, allowTab: true, allowNewline: $allowNewline)) {
            return Checked::problem('problem.text.control');
        }
        if (Text::length($text) > $max) {
            return Checked::problem('problem.text.too_long', ['max' => $max]);
        }

        return Checked::ok($text);
    }

    /** A stored TEXT value as a string; null and values of other kinds give null. */
    public static function fromStorage(mixed $stored): ?string
    {
        if (is_int($stored)) {
            return (string) $stored;
        }
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        return $stored;
    }

    public static function toStorage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('A canonical text value is a string or null.');
        }

        return $value;
    }

    public static function toText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('A canonical text value is a string or null.');
        }

        return $value;
    }
}
