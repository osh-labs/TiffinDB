<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/**
 * The one place for the input-string rules of M1 spec section 3.4 (P52):
 * valid UTF-8, trimming of white space, length in code points, control
 * characters, and the problems of a name or an optional piece of text.
 *
 * A control character (P61) is one in Unicode category Cc (U+0000 to
 * U+001F, U+007F, U+0080 to U+009F), Zl (U+2028), Zp (U+2029), or Cf
 * (format characters such as U+200B, U+202E, U+2066, U+FEFF), except
 * U+200C ZERO WIDTH NON-JOINER and U+200D ZERO WIDTH JOINER, which some
 * scripts and emoji sequences need. `mbstring` is not used.
 */
final class Text
{
    /** Cc, Zl, Zp, and Cf, except U+200C and U+200D (P61). */
    private const CONTROL = '/(?![\x{200C}\x{200D}])[\p{Cc}\p{Zl}\p{Zp}\p{Cf}]/u';

    private function __construct()
    {
    }

    public static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    /**
     * Removes leading and trailing white space (`\s` in Unicode mode, which
     * includes no-break and other Unicode spaces). Invalid UTF-8 is returned
     * unchanged; check it with isUtf8() first.
     */
    public static function trim(string $s): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $s);

        return $trimmed ?? $s;
    }

    /**
     * Length in Unicode code points.
     *
     * @throws \InvalidArgumentException when $s is not valid UTF-8
     */
    public static function length(string $s): int
    {
        $count = preg_match_all('/./su', $s);
        if ($count === false) {
            throw new \InvalidArgumentException('Text::length needs valid UTF-8.');
        }

        return $count;
    }

    /**
     * True when $s holds a control character other than those allowed (tab
     * and \n are opt-in; carriage return is never allowed). Invalid UTF-8
     * counts as holding one.
     */
    public static function hasControl(string $s, bool $allowTab = false, bool $allowNewline = false): bool
    {
        if (!self::isUtf8($s)) {
            return true;
        }
        if ($allowTab) {
            $s = str_replace("\t", '', $s);
        }
        if ($allowNewline) {
            $s = str_replace("\n", '', $s);
        }

        return preg_match(self::CONTROL, $s) === 1;
    }

    /**
     * Problems for a required one-line name after trimming: empty
     * (problem.name.empty), longer than $max (problem.name.too_long, max),
     * control characters or invalid UTF-8 (problem.text.control).
     *
     * @return list<Problem>
     */
    public static function nameProblems(string $value, string $field, int $max): array
    {
        if (!self::isUtf8($value)) {
            return [new Problem($field, 'problem.text.control')];
        }
        $value = self::trim($value);
        if ($value === '') {
            return [new Problem($field, 'problem.name.empty')];
        }

        return self::lengthAndControl($value, $field, $max, 'problem.name.too_long');
    }

    /**
     * The same for optional text: empty is allowed. Too long gives
     * problem.text.too_long (max).
     *
     * @return list<Problem>
     */
    public static function optionalTextProblems(string $value, string $field, int $max): array
    {
        if (!self::isUtf8($value)) {
            return [new Problem($field, 'problem.text.control')];
        }
        $value = self::trim($value);
        if ($value === '') {
            return [];
        }

        return self::lengthAndControl($value, $field, $max, 'problem.text.too_long');
    }

    /** @return list<Problem> */
    private static function lengthAndControl(string $value, string $field, int $max, string $tooLongKey): array
    {
        $problems = [];
        if (self::length($value) > $max) {
            $problems[] = new Problem($field, $tooLongKey, ['max' => $max]);
        }
        if (self::hasControl($value)) {
            $problems[] = new Problem($field, 'problem.text.control');
        }

        return $problems;
    }
}
