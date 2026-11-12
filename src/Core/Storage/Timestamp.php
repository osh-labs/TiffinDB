<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** The stored time format: UTC, `YYYY-MM-DDTHH:MM:SSZ` (ADR 0010 decision 3). */
final class Timestamp
{
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    private function __construct()
    {
    }

    /**
     * The time in UTC, whole seconds (any fraction is dropped).
     *
     * @throws \InvalidArgumentException when the UTC year is outside 0000 to 9999,
     *     which the stored form cannot hold (so parse(format($t)) always works)
     */
    public static function format(\DateTimeImmutable $time): string
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');
        if ($year < 0 || $year > 9999) {
            throw new \InvalidArgumentException('The year must be from 0000 to 9999.');
        }

        return $utc->format(self::FORMAT);
    }

    /**
     * Accepts exactly the stored form, with a real calendar date and time.
     *
     * @throws \InvalidArgumentException for anything else
     */
    public static function parse(string $value): \DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Not a timestamp in the form YYYY-MM-DDTHH:MM:SSZ.');
        }
        $time = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new \DateTimeZone('UTC'));
        if ($time === false || $time->format(self::FORMAT) !== $value) {
            throw new \InvalidArgumentException('Not a valid calendar date and time.');
        }

        return $time;
    }
}
