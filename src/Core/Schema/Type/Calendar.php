<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * Private helper for `date`, `time`, and `date_time`: fixed ASCII patterns,
 * proleptic Gregorian dates from 0001-01-01 to 9999-12-31 (checkdate), no
 * time zones, no locale.
 */
final class Calendar
{
    public const DATE = '(\d{4})-(\d{2})-(\d{2})';
    /** 24-hour HH:MM with optional :SS. */
    public const TIME = '([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?';

    private function __construct()
    {
    }

    /** Canonical `YYYY-MM-DD` when the three parts form a real date in years 1 to 9999, else null. */
    public static function date(string $year, string $month, string $day): ?string
    {
        $y = (int) $year;
        if ($y < 1 || !checkdate((int) $month, (int) $day, $y)) {
            return null;
        }

        return $year . '-' . $month . '-' . $day;
    }

    /** Canonical `HH:MM:SS` from matched parts; a missing seconds part is 00. */
    public static function time(string $hours, string $minutes, ?string $seconds): string
    {
        return str_pad($hours, 2, '0', STR_PAD_LEFT) . ':' . $minutes . ':' . ($seconds === null || $seconds === '' ? '00' : $seconds);
    }

    /** Trimmed valid UTF-8 text, or null when invalid UTF-8. */
    public static function prepare(string $text): ?string
    {
        return Text::isUtf8($text) ? Text::trim($text) : null;
    }
}
