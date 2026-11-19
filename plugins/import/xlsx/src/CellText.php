<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

/**
 * The text of a cell value (M3 spec section 9.2). Pure functions; no
 * PHP setting changes the result (floats are printed by trying precisions,
 * not with `precision` or `serialize_precision`).
 *
 * - Whole numbers: decimal digits (`-0` is `0`).
 * - Other numbers: the shortest decimal that reads back to the same float,
 *   never with an exponent (`0.1`, `0.0000001`, `100000000000000000000`).
 * - Dates (number formats of Styles::DATE): `YYYY-MM-DD` at midnight, else
 *   `YYYY-MM-DDTHH:MM`, with `:SS` when the seconds are not 0; the 1900
 *   system counts from 1899-12-30 (day 60, the 29 February 1900 that never
 *   was, has no text) and the 1904 system from 1904-01-01. Times of day
 *   (Styles::TIME, a value under one day): `HH:MM` or `HH:MM:SS`.
 *   Durations (elapsed formats such as `[h]:mm:ss`): `H:MM:SS`. Values
 *   outside the format's calendar (before its start, after 9999-12-31)
 *   have no text.
 * - Booleans: `true` or `false`.
 *
 * @internal
 */
final class CellText
{
    private const SECONDS_PER_DAY = 86400;
    private const NUMBER = '/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\z/';
    private const INTEGER = '/^([+-]?)0*(\d{1,18})\z/';
    /** Serial numbers of 9999-12-31 plus one day, per system. */
    private const END_1900 = 2958466;
    private const END_1904 = 2957004;

    private function __construct()
    {
    }

    /** The text of a numeric cell's stored value with number format kind $kind (Styles::*), or null. */
    public static function number(string $stored, int $kind, bool $date1904): ?string
    {
        $stored = trim($stored, " \t\r\n");
        if (preg_match(self::NUMBER, $stored) !== 1) {
            return null;
        }
        if ($kind === Styles::NUMBER) {
            if (preg_match(self::INTEGER, $stored, $m) === 1) {
                return $m[2] === '0' || $m[1] !== '-' ? $m[2] : '-' . $m[2];
            }

            return self::float((float) $stored);
        }
        $value = (float) $stored;
        if (!is_finite($value)) {
            return null;
        }

        return $kind === Styles::DURATION ? self::duration($value) : self::serial($value, $kind === Styles::TIME, $date1904);
    }

    /** The shortest plain decimal that reads back to $value, or null when it is not finite. */
    public static function float(float $value): ?string
    {
        if (!is_finite($value)) {
            return null;
        }
        if ($value == 0.0) {
            return '0';
        }
        for ($digits = 1; $digits <= 17; $digits++) {
            $text = sprintf('%.' . ($digits - 1) . 'e', $value);
            if ((float) $text === $value) {
                break;
            }
        }

        return self::plain($text);
    }

    /** "-1.25e+3" as "-1250": the scientific text of sprintf('%e') as a plain decimal. */
    private static function plain(string $scientific): string
    {
        preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]\d+)\z/', $scientific, $m);
        $sign = $m[1];
        $digits = rtrim($m[2] . ($m[3] ?? ''), '0');
        if ($digits === '') {
            return '0';
        }
        $exponent = (int) $m[4];
        $count = strlen($digits);
        if ($exponent >= $count - 1) {
            $text = $digits . str_repeat('0', $exponent - $count + 1);
        } elseif ($exponent >= 0) {
            $text = substr($digits, 0, $exponent + 1) . '.' . substr($digits, $exponent + 1);
        } else {
            $text = '0.' . str_repeat('0', -$exponent - 1) . $digits;
        }

        return $sign . $text;
    }

    /** A duration of $days days as `H:MM:SS` (with `-` when negative). */
    private static function duration(float $days): ?string
    {
        if (abs($days) >= 1e9) {
            return null;
        }
        $seconds = (int) round($days * self::SECONDS_PER_DAY);
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return sprintf('%s%d:%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** The text of serial date $serial; $timeOfDay: the format shows only a time. */
    private static function serial(float $serial, bool $timeOfDay, bool $date1904): ?string
    {
        if ($serial < 0 || $serial >= ($date1904 ? self::END_1904 : self::END_1900)) {
            return null;
        }
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * self::SECONDS_PER_DAY);
        if ($seconds >= self::SECONDS_PER_DAY) {
            $days++;
            $seconds -= self::SECONDS_PER_DAY;
        }
        if ($days === 0 && ($timeOfDay || !$date1904)) {
            // A time of day, or day 0 of the 1900 system (shown by spreadsheets as 1900-01-00).
            return self::time($seconds);
        }
        if ($date1904) {
            $date = self::civil(self::daysFromCivil(1904, 1, 1) + $days);
        } elseif ($days === 60) {
            return null;
        } else {
            $date = self::civil(self::daysFromCivil(1899, 12, $days < 60 ? 31 : 30) + $days);
        }
        if ($date === null) {
            return null;
        }

        return $seconds === 0 ? $date : $date . 'T' . self::time($seconds);
    }

    /** `HH:MM`, or `HH:MM:SS` when the seconds are not 0. */
    private static function time(int $seconds): string
    {
        $text = sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $seconds % 60 === 0 ? $text : $text . sprintf(':%02d', $seconds % 60);
    }

    /**
     * The text of an ISO 8601 date cell (`t="d"`): a date, or a date and a
     * time (fractions of a second dropped, a trailing `Z` ignored); null when
     * it is not one.
     */
    public static function isoDate(string $stored): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?)?Z?\z/', trim($stored), $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($year < 1 || !checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if (!isset($m[4]) || $m[4] === '') {
            return $date;
        }
        $hours = (int) $m[4];
        $minutes = (int) $m[5];
        $seconds = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }
        $total = $hours * 3600 + $minutes * 60 + $seconds;

        return $total === 0 ? $date : $date . 'T' . self::time($total);
    }

    /** `true` or `false` for a boolean cell's stored value, or null. */
    public static function boolean(string $stored): ?string
    {
        return match (trim($stored)) {
            '1', 'true' => 'true',
            '0', 'false' => 'false',
            default => null,
        };
    }

    /**
     * A string as stored in a workbook, with the `_xHHHH_` escapes of
     * OOXML resolved (upper-case hexadecimal, as the format writes them;
     * `_x005F_` is a literal underscore). An escaped NUL becomes U+FFFD;
     * escaped surrogates are left as written. Returns null for ''.
     */
    public static function string(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }
        if (!str_contains($stored, '_x')) {
            return $stored;
        }

        return preg_replace_callback('/_x([0-9A-F]{4})_/', static function (array $m): string {
            $code = hexdec($m[1]);
            if ($code >= 0xD800 && $code <= 0xDFFF) {
                return $m[0];
            }

            return self::utf8($code === 0 ? 0xFFFD : (int) $code);
        }, $stored);
    }

    /** UTF-8 bytes of code point $code (U+0000 to U+FFFF). */
    private static function utf8(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }

        return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    /** Days since 0000-03-01 of a proleptic Gregorian date (H. Hinnant's days_from_civil). */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yearOfEra = $year - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra;
    }

    /** `YYYY-MM-DD` of a day count from daysFromCivil(), or null outside years 1 to 9999. */
    private static function civil(int $days): ?string
    {
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $dayOfEra = $days - $era * 146097;
        $yearOfEra = intdiv($dayOfEra - intdiv($dayOfEra, 1460) + intdiv($dayOfEra, 36524) - intdiv($dayOfEra, 146096), 365);
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthIndex = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthIndex + 2, 5) + 1;
        $month = $monthIndex < 10 ? $monthIndex + 3 : $monthIndex - 9;
        $year = $yearOfEra + $era * 400 + ($month <= 2 ? 1 : 0);
        if ($year < 1 || $year > 9999) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
