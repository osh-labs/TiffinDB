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

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new LogicException('The clock must return a DateTimeImmutable.');
        }

        return $now;
    }
}
