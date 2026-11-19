<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use TiffinDB\Core\Error\Problem;

/**
 * Turns values into display text (M1 spec part 2 section 5.3). Every result
 * is plain, unescaped text taken from the strings file; templates escape it
 * on output (or use the view's count() helper, which does).
 *
 * The clock is a closure returning the current time, so that the web layer
 * needs no core storage type here (ADR 0012 decision 1 as amended by P58);
 * the composition root passes the core clock's now() as a first-class
 * callable, and tests pass a fixed time.
 */
final class Format
{
    /** The stored timestamp form (M1 spec part 1 section 3.2). */
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';

    /** A canonical date (`YYYY-MM-DD`). */
    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})\z/';

    /** A canonical time (`HH:MM:SS`; `HH:MM` is accepted too). */
    private const TIME = '/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?\z/';

    /** Currencies written with a symbol before the amount (M2 spec part 2 section 5.2). */
    private const SYMBOLS = ['USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}", 'JPY' => "\u{00A5}"];

    /** @param Closure(): DateTimeImmutable $clock */
    public function __construct(
        private readonly Strings $strings,
        private readonly DateTimeZone $timeZone,
        private readonly Closure $clock,
    ) {
    }

    /**
     * "Today", "Yesterday", or "3 Nov 2026", for a stored UTC timestamp
     * (`YYYY-MM-DDTHH:MM:SSZ`), judged by calendar days in the configured
     * time zone.
     */
    public function relativeDate(string $timestamp): string
    {
        $utc = new DateTimeZone('UTC');
        $moment = preg_match(self::TIMESTAMP, $timestamp) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, $utc)
            : false;
        if ($moment === false || $moment->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new InvalidArgumentException('Not a stored timestamp.');
        }

        $local = $moment->setTimezone($this->timeZone);
        $today = $this->now()->setTimezone($this->timeZone);
        $day = $local->format('Y-m-d');
        if ($day === $today->format('Y-m-d')) {
            return $this->strings->get('date.today');
        }
        // Calendar arithmetic on the local date, at noon, so that a day of
        // 23 or 25 hours (a clock change) still counts as one day.
        $yesterday = (new DateTimeImmutable($today->format('Y-m-d') . ' 12:00:00', $this->timeZone))->modify('-1 day');
        if ($yesterday !== false && $day === $yesterday->format('Y-m-d')) {
            return $this->strings->get('date.yesterday');
        }

        return $this->strings->get('date.day', [
            'day' => (int) $local->format('j'),
            'month' => $this->strings->get('date.month.' . $local->format('n')),
            'year' => $local->format('Y'),
        ]);
    }

    /**
     * A waiting time: 1 to 59 seconds in seconds ("1 second", "30 seconds"),
     * otherwise in minutes rounded up ("1 minute", "2 minutes"). Less than
     * one second is shown as one second.
     */
    public function wait(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 60) {
            return $seconds === 1
                ? $this->strings->get('wait.second')
                : $this->strings->get('wait.seconds', ['count' => $seconds]);
        }
        $minutes = intdiv($seconds + 59, 60);

        return $minutes === 1
            ? $this->strings->get('wait.minute')
            : $this->strings->get('wait.minutes', ['count' => $minutes]);
    }

    /** The text of a core problem with its parameters. */
    public function problem(Problem $problem): string
    {
        return $this->problemText($problem->key, $problem->params);
    }

    /**
     * The text of a problem key with its parameters; for `problem.throttled`
     * the `{wait}` placeholder is built from the `seconds` parameter.
     *
     * @param array<string, string|int> $params
     */
    public function problemText(string $key, array $params): string
    {
        if (!str_starts_with($key, 'problem.')) {
            throw new InvalidArgumentException('Not a problem key.');
        }
        if ($key === 'problem.throttled') {
            $seconds = $params['seconds'] ?? null;
            if (!is_int($seconds)) {
                throw new InvalidArgumentException('problem.throttled needs an integer seconds parameter.');
            }
            $params['wait'] = $this->wait($seconds);
        }

        return $this->strings->get($key, $params);
    }

    /**
     * `<keyBase>.one` when $n is 1, else `<keyBase>.many` with {count} as
     * a grouped number ("1,234").
     */
    public function count(string $keyBase, int $n): string
    {
        return $n === 1
            ? $this->strings->get($keyBase . '.one')
            : $this->strings->get($keyBase . '.many', ['count' => $this->number($n)]);
    }

    /** A whole number with thousands separators ("1,234"). */
    public function number(int $n): string
    {
        return number_format($n);
    }

    /** "14 Mar 2025" for a canonical date (`YYYY-MM-DD`). */
    public function date(string $ymd): string
    {
        if (preg_match(self::DATE, $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Not a canonical date.');
        }

        return $this->strings->get('date.day', [
            'day' => (int) $m[3],
            'month' => $this->strings->get('date.month.' . (int) $m[2]),
            'year' => $m[1],
        ]);
    }

    /** "14:30", or "14:30:15" with seconds, for a canonical time. */
    public function time(string $hms, bool $seconds): string
    {
        if (preg_match(self::TIME, $hms, $m) !== 1) {
            throw new InvalidArgumentException('Not a canonical time.');
        }

        return $seconds ? "{$m[1]}:{$m[2]}:" . ($m[3] ?? '00') : "{$m[1]}:{$m[2]}";
    }

    /** "14 Mar 2025, 14:30" for a canonical date and time (`YYYY-MM-DDTHH:MM:SS`). */
    public function dateTime(string $value, bool $seconds): string
    {
        $parts = explode('T', $value);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Not a canonical date and time.');
        }

        return $this->strings->get('value.date_time', [
            'date' => $this->date($parts[0]),
            'time' => $this->time($parts[1], $seconds),
        ]);
    }

    /** "1:30", or "1:30:00" with seconds; hours are not padded and may pass 24. */
    public function duration(int $seconds, bool $withSeconds): string
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A duration is not negative.');
        }
        $text = intdiv($seconds, 3600) . ':' . sprintf('%02d', intdiv($seconds % 3600, 60));

        return $withSeconds ? $text . ':' . sprintf('%02d', $seconds % 60) : $text;
    }

    /** A number with thousands separators and a fixed number of decimals ("1,234.5"). */
    public function decimal(int|float $n, int $decimals): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Decimals are not negative.');
        }
        $text = number_format($n, $decimals);

        // number_format() keeps the sign of a value that rounds to zero.
        return preg_match('/^-[0,.]+\z/', $text) === 1 ? substr($text, 1) : $text;
    }

    /**
     * An amount of money from its minor units: the symbol before the amount
     * for USD, EUR, GBP, and JPY, otherwise the code and a space; thousands
     * separators; a leading "-" for negatives ("-$5.00", "CHF 12.00").
     */
    public function money(int $minor, string $code, int $digits): string
    {
        if (preg_match('/^[A-Z]{3}\z/', $code) !== 1 || $digits < 0 || $digits > 4) {
            throw new InvalidArgumentException('Not a currency code and digits.');
        }
        $negative = $minor < 0;
        // Work on the digits as text, so that PHP_INT_MIN and large amounts stay exact.
        $abs = ltrim((string) $minor, '-');
        $abs = str_pad($abs, $digits + 1, '0', STR_PAD_LEFT);
        $whole = substr($abs, 0, strlen($abs) - $digits);
        $fraction = substr($abs, strlen($abs) - $digits);
        $amount = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) . ($digits > 0 ? '.' . $fraction : '');
        $prefix = self::SYMBOLS[$code] ?? $code . ' ';

        return ($negative ? '-' : '') . $prefix . $amount;
    }

    /** "512 bytes", "3 KB" (whole, rounded up), or "1.5 MB" (one decimal). */
    public function fileSize(int $bytes): string
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('A size is not negative.');
        }
        if ($bytes < 1024) {
            return $this->strings->get('size.bytes', ['count' => $bytes]);
        }
        if ($bytes < 1048576) {
            return $this->strings->get('size.kb', ['n' => number_format(intdiv($bytes + 1023, 1024))]);
        }

        return $this->strings->get('size.mb', ['n' => number_format($bytes / 1048576, 1)]);
    }

    /**
     * "today at 14:32", "yesterday at 09:05", or "3 Nov 2026 at 14:32", for
     * a stored UTC timestamp, in the configured time zone.
     */
    public function savedAt(string $timestamp): string
    {
        $date = $this->relativeDate($timestamp);
        if ($date === $this->strings->get('date.today')) {
            $date = $this->strings->get('date.today_lower');
        } elseif ($date === $this->strings->get('date.yesterday')) {
            $date = $this->strings->get('date.yesterday_lower');
        }
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new DateTimeZone('UTC'));
        if ($moment === false) {
            throw new InvalidArgumentException('Not a stored timestamp.');
        }

        return $this->strings->get('date.at', [
            'date' => $date,
            'time' => $moment->setTimezone($this->timeZone)->format('H:i'),
        ]);
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new LogicException('The clock must return a DateTimeImmutable.');
        }

        return $now;
    }
}
