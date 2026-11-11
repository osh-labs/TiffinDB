<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use InvalidArgumentException;

/**
 * WCAG 2.x contrast ratio, computed directly from the definitions of
 * relative luminance and contrast ratio for sRGB colours.
 *
 *   channel  c = value / 255
 *   linear   c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ^ 2.4
 *   L        = 0.2126 R + 0.7152 G + 0.0722 B
 *   ratio    = (L_lighter + 0.05) / (L_darker + 0.05)
 *
 * WCAG 2.0 and 2.1 print the threshold as 0.03928, WCAG 2.2 as 0.04045
 * (the sRGB specification's value); no 8-bit channel value lies between
 * the two, so the results are identical.
 */
final class Contrast
{
    private function __construct()
    {
    }

    /**
     * @return array{int, int, int}
     */
    public static function channels(string $hex): array
    {
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $hex, $m) !== 1) {
            throw new InvalidArgumentException("Not a hex colour: '{$hex}'.");
        }
        $digits = $m[1];
        if (strlen($digits) === 3) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }

        return [
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
        ];
    }

    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = array_map(
            static function (int $value): float {
                $c = $value / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            self::channels($hex),
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Unrounded contrast ratio; the order of the two colours does not matter. */
    public static function ratio(string $first, string $second): float
    {
        $a = self::relativeLuminance($first);
        $b = self::relativeLuminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Contrast ratio rounded to two decimals, as published. */
    public static function rounded(string $first, string $second): float
    {
        return round(self::ratio($first, $second), 2);
    }
}
