<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * Private helper for `number` and `currency`: decimal text handled as
 * digit strings, so parsing and rounding are exact and never depend on the
 * locale or on float formatting settings. Float text uses `%F`, which is
 * locale-independent.
 */
final class Decimal
{
    /** Pattern NUMBER of M1 spec section 8.2. */
    public const NUMBER = '/^[+-]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?\z/';

    private function __construct()
    {
    }

    /**
     * Splits text matching NUMBER into sign, integer digits (no leading
     * zeros, '0' for zero), and fraction digits (possibly empty).
     *
     * @return array{0: bool, 1: string, 2: string}|null null when the text does not match
     */
    public static function split(string $text): ?array
    {
        if (preg_match(self::NUMBER, $text) !== 1) {
            return null;
        }
        $negative = $text[0] === '-';
        if ($text[0] === '-' || $text[0] === '+') {
            $text = substr($text, 1);
        }
        $point = strpos($text, '.');
        $integer = $point === false ? $text : substr($text, 0, $point);
        $fraction = $point === false ? '' : substr($text, $point + 1);

        return [$negative, self::stripZeros(str_replace(',', '', $integer)), $fraction];
    }

    /**
     * Rounds to exactly $digits fraction digits, half away from zero (the
     * sign is handled by the caller, so rounding the magnitude up is away
     * from zero).
     *
     * @return array{0: string, 1: string} integer digits and exactly $digits fraction digits
     */
    public static function round(string $integer, string $fraction, int $digits): array
    {
        if (strlen($fraction) <= $digits) {
            return [$integer, str_pad($fraction, $digits, '0')];
        }
        $all = $integer . substr($fraction, 0, $digits);
        if ($fraction[$digits] >= '5') {
            $all = self::increment($all);
        }
        $integerLength = strlen($all) - $digits;

        return [self::stripZeros(substr($all, 0, $integerLength)), substr($all, $integerLength)];
    }

    /** Shortest `%F` text (no exponent) that reads back as exactly $value; integral values have no point. */
    public static function floatText(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('The number must be finite.');
        }
        for ($digits = 0; $digits <= 20; $digits++) {
            $text = sprintf('%.' . $digits . 'F', $value);
            if ((float) $text === $value) {
                return $text === '-0' ? '0' : $text;
            }
        }

        return sprintf('%.20F', $value);
    }

    /** $value with exactly $digits fraction digits (`%F`, locale-independent). */
    public static function fixed(float $value, int $digits): string
    {
        return sprintf('%.' . $digits . 'F', $value);
    }

    /** A problem parameter for a number: ints as themselves, floats as their shortest text. */
    public static function param(int|float $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        return self::floatText($value);
    }

    private static function stripZeros(string $digits): string
    {
        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
        }

        return '1' . $digits;
    }
}
