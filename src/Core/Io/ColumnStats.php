<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldTypes;

/**
 * Statistics of one source column, updated per non-empty cell after
 * Text::trim (M3 spec section 6.3). Kept in the job file between chunks
 * (toArray, fromArray), so that statistics gathered over several chunks
 * equal those of one pass.
 *
 * The type counters count cells the M1 type's lenient parse accepts, with
 * the narrowings of section 6.3: checkbox only for the words yes, no,
 * true, false; time and duration only for cells holding `:` (rule 6 and
 * the duration counter); url only for cells with an explicit `http://`,
 * `https://`, or leading `www.`; date only for cells without `:` (M1's lenient
 * date parse accepts a date-time and drops its time, which would propose
 * `date` for a date-time column); currency by the cell pattern of section
 * 6.3.1.
 */
final class ColumnStats
{
    /** Distinct values are tracked exactly up to this many, then only "more". */
    public const DISTINCT_LIMIT = 201;
    public const FIRST_VALUES = 2;
    public const FIRST_VALUE_CHARS = 60;
    public const CURRENCY_PATTERNS = [
        '/^-?([$€£¥])?\d{1,3}(,\d{3})*(\.\d{1,3})?\z/u',
        '/^-?([$€£¥])?\d+(\.\d{1,3})?\z/u',
    ];
    public const COUNTERS = ['checkbox', 'number', 'currency', 'date', 'date_time', 'time', 'duration', 'url', 'email', 'integer1to5', 'intDistinctIncreasing'];
    /** Currency options under which the amount limit is tightest (CurrencyType::MINOR_DIGITS_MAX). */
    private const STRICTEST_CURRENCY = ['code' => 'USD', 'minor_digits' => 4];
    public const ELLIPSIS = "\u{2026}";
    private const CHECKBOX_WORDS = ['yes', 'no', 'true', 'false'];
    private const LENIENT = ['number', 'date', 'date_time', 'time', 'duration', 'url', 'email'];

    public int $count = 0;
    /** @var list<string> distinct trimmed values in first-seen order, at most DISTINCT_LIMIT */
    public array $distinctValues = [];
    public bool $moreDistinct = false;
    /** @var list<string> */
    public array $firstValues = [];
    public int $maxLength = 0;
    public bool $multiline = false;
    /** @var array<string, int> */
    public array $counters = [];
    public int $decimals = 0;
    public int $currencyDecimals = 0;
    public int $twoDecimals = 0;
    /** @var list<string> currency symbols seen, in first-seen order */
    public array $symbols = [];
    public ?int $lastInt = null;

    public function __construct()
    {
        foreach (self::COUNTERS as $counter) {
            $this->counters[$counter] = 0;
        }
    }

    /** The number of distinct values, exact up to DISTINCT_LIMIT (then DISTINCT_LIMIT and moreDistinct). */
    public function distinct(): int
    {
        return count($this->distinctValues);
    }

    public function counter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    /** Adds one cell (null or text, untrimmed). Empty cells are not counted. */
    public function add(?string $cell, FieldTypes $types): void
    {
        if ($cell === null || !Text::isUtf8($cell)) {
            return;
        }
        $text = Text::trim($cell);
        if ($text === '') {
            return;
        }
        $this->count++;
        if (!in_array($text, $this->distinctValues, true)) {
            if (count($this->distinctValues) < self::DISTINCT_LIMIT) {
                $this->distinctValues[] = $text;
            } else {
                $this->moreDistinct = true;
            }
            if (count($this->firstValues) < self::FIRST_VALUES) {
                $this->firstValues[] = self::sample($text, self::FIRST_VALUE_CHARS);
            }
        }
        $this->maxLength = max($this->maxLength, Text::length($text));
        if (str_contains($text, "\n")) {
            $this->multiline = true;
        }

        if (in_array(strtolower($text), self::CHECKBOX_WORDS, true)) {
            $this->counters['checkbox']++;
        }
        foreach (self::LENIENT as $type) {
            if (($type === 'duration' || $type === 'time') && !str_contains($text, ':')) {
                continue;
            }
            if ($type === 'url' && !self::hasUrlScheme($text)) {
                // M1's lenient parse makes `stand-mixer.jpg` into `https://stand-mixer.jpg`; only an explicit
                // scheme or a leading `www.` counts as a web address (M3-WP11 X-13).
                continue;
            }
            if ($type === 'date' && str_contains($text, ':')) {
                // The lenient date parse drops a time of day; such a cell is a date-time, not a date.
                continue;
            }
            if ($types->get($type)->parse($text, $types->get($type)->defaultOptions(), true)->isOk()) {
                $this->counters[$type]++;
                if ($type === 'number' && preg_match('/\.(\d+)\z/', $text, $m) === 1) {
                    $this->decimals = min(6, max($this->decimals, strlen($m[1])));
                }
            }
        }
        if (self::isCurrencyLike($text) && $types->get('currency')->parse(self::cleanCurrency($text), self::STRICTEST_CURRENCY, true)->isOk()) {
            // The preparer's parse, with the most minor digits a currency field can have, so the amount limit is the
            // tightest any proposed options could give (a 20-digit amount is not counted).
            $this->counters['currency']++;
            if (preg_match('/([$€£¥])/u', $text, $m) === 1 && !in_array($m[1], $this->symbols, true)) {
                $this->symbols[] = $m[1];
            }
            if (preg_match('/\.(\d+)\z/', $text, $m) === 1) {
                $this->currencyDecimals = max($this->currencyDecimals, strlen($m[1]));
                if (strlen($m[1]) === 2) {
                    $this->twoDecimals++;
                }
            }
        }
        if (preg_match('/^[1-5]\z/', $text) === 1) {
            $this->counters['integer1to5']++;
        }
        if (preg_match('/^\d{1,15}\z/', $text) === 1) {
            $int = (int) $text;
            if ($this->lastInt === null || $int > $this->lastInt) {
                $this->counters['intDistinctIncreasing']++;
            }
            $this->lastInt = $int;
        }
    }

    /** Whether a trimmed cell matches the currency patterns of section 6.3.1. */
    public static function isCurrencyLike(string $text): bool
    {
        foreach (self::CURRENCY_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The cell without its currency symbol and thousands commas (section 6.3.1); other cells unchanged. */
    public static function cleanCurrency(string $text): string
    {
        $text = Text::trim($text);
        if (!self::isCurrencyLike($text)) {
            return $text;
        }

        return (string) preg_replace('/[$€£¥,]/u', '', $text);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'distinct' => $this->distinctValues,
            'more' => $this->moreDistinct,
            'first' => $this->firstValues,
            'max_length' => $this->maxLength,
            'multiline' => $this->multiline,
            'counters' => $this->counters,
            'decimals' => $this->decimals,
            'currency_decimals' => $this->currencyDecimals,
            'two_decimals' => $this->twoDecimals,
            'symbols' => $this->symbols,
            'last_int' => $this->lastInt,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $stats = new self();
        $stats->count = self::int($data['count'] ?? 0);
        $stats->distinctValues = self::strings($data['distinct'] ?? []);
        $stats->moreDistinct = ($data['more'] ?? false) === true;
        $stats->firstValues = self::strings($data['first'] ?? []);
        $stats->maxLength = self::int($data['max_length'] ?? 0);
        $stats->multiline = ($data['multiline'] ?? false) === true;
        $counters = is_array($data['counters'] ?? null) ? $data['counters'] : [];
        foreach (self::COUNTERS as $counter) {
            $stats->counters[$counter] = self::int($counters[$counter] ?? 0);
        }
        $stats->decimals = self::int($data['decimals'] ?? 0);
        $stats->currencyDecimals = self::int($data['currency_decimals'] ?? 0);
        $stats->twoDecimals = self::int($data['two_decimals'] ?? 0);
        $stats->symbols = self::strings($data['symbols'] ?? []);
        $stats->lastInt = is_int($data['last_int'] ?? null) ? $data['last_int'] : null;

        return $stats;
    }

    /** Whether a trimmed cell names a web address explicitly: `http://`, `https://`, or a leading `www.` (ASCII case-insensitive). */
    public static function hasUrlScheme(string $text): bool
    {
        return preg_match('~^(?:https?://|www\.)~i', $text) === 1;
    }

    /**
     * $text for showing as a sample: unchanged when at most $max characters,
     * else its first $max - 1 characters and "…" (cut on a character
     * boundary, so the result is valid UTF-8 of $max characters).
     */
    public static function sample(string $text, int $max): string
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('A sample is at least one character.');
        }
        if (!Text::isUtf8($text) || Text::length($text) <= $max) {
            return Text::isUtf8($text) ? $text : '';
        }

        return self::cut($text, $max - 1) . self::ELLIPSIS;
    }

    /** The first $max characters of $text. */
    public static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        preg_match('/^.{0,' . $max . '}/su', $text, $m);

        return $m[0] ?? '';
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException('A column statistic is not a count.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Column statistics hold lists of text.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('Column statistics hold lists of text.');
            }
        }

        return $value;
    }
}
