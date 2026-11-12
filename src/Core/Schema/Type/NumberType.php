<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * A number (M1 spec section 8.2): an int when `decimals` is 0, otherwise a
 * float holding at most `decimals` fraction digits. Text follows pattern
 * NUMBER (optional sign, plain digits or comma groups of three, optional
 * point and digits); no exponent, no locale-specific forms. Strict parsing
 * refuses extra fraction digits; lenient parsing rounds them half away from
 * zero, on the decimal text itself.
 *
 * Magnitude (P71, amends spec 8.2's 1e15): less than 10^(15 - decimals), so
 * a value never needs more than 15 significant digits and every value has
 * a float that prints back to exactly its decimal text. The same limit
 * applies to parsing, check(), and the `min` and `max` options.
 *
 * Reading back (review B1): floats are bound to SQLite as decimal text, and
 * SQLite's text-to-real conversion can be one bit off (27.318581 reads back
 * as 27.318581000000002). fromStorage() snaps a float that lies within a
 * few ulps of a value with `decimals` digits to that value; a float further
 * away (a value stored before `decimals` was lowered) is kept as it is.
 */
final class NumberType implements FieldType
{
    public const DECIMALS_MAX = 6;
    public const SIGNIFICANT_DIGITS = 15;
    /** Largest magnitude converted from a whole float to an int when read. */
    private const INT_SAFE = 1e15;

    public function id(): string
    {
        return 'number';
    }

    public function columnDefinition(): ?string
    {
        return 'NUMERIC';
    }

    public function defaultOptions(): array
    {
        return ['decimals' => 0, 'min' => null, 'max' => null];
    }

    /** The exclusive magnitude limit for $decimals fraction digits: 10^(15 - decimals) (P71). */
    public static function limit(int $decimals): int
    {
        return 10 ** (self::SIGNIFICANT_DIGITS - $decimals);
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);
        $decimals = $reader->int('decimals', 0, 0, self::DECIMALS_MAX);
        $min = $this->bound($reader, 'min', $decimals);
        $max = $this->bound($reader, 'max', $decimals);
        if ($min !== null && $max !== null && !($min < $max)) {
            $reader->add('min', 'problem.options.min_max');
        }

        return $reader->finish(['decimals' => $decimals, 'min' => $min, 'max' => $max]);
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        $decimals = self::decimals($options);
        if (is_string($stored)) {
            $stored = self::fromText($stored);
        }
        if (is_int($stored)) {
            return $decimals === 0 ? $stored : (float) $stored;
        }
        if (!is_float($stored) || !is_finite($stored)) {
            return null;
        }
        if ($stored == 0.0) {
            return $decimals === 0 ? 0 : 0.0;
        }
        if ($decimals === 0) {
            return floor($stored) === $stored && abs($stored) <= self::INT_SAFE ? (int) $stored : $stored;
        }
        $snap = (float) Decimal::fixed($stored, $decimals);
        if (abs($snap - $stored) <= 4 * PHP_FLOAT_EPSILON * max(1.0, abs($stored))) {
            return $snap == 0.0 ? 0.0 : $snap;
        }

        return $stored;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new \InvalidArgumentException('A canonical number is an int, a finite float, or null.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }
        $decimals = self::decimals($options);
        if (is_int($value)) {
            return $this->limits($decimals === 0 ? $value : (float) $value, $options);
        }
        if (!is_float($value) || !is_finite($value)) {
            return Checked::problem('problem.number.invalid');
        }
        if (abs($value) >= self::limit($decimals)) {
            return Checked::problem('problem.number.range');
        }
        if ($decimals === 0) {
            if (floor($value) !== $value) {
                return Checked::problem('problem.number.whole');
            }

            return $this->limits((int) $value, $options);
        }
        if ((float) Decimal::fixed($value, $decimals) !== $value) {
            return Checked::problem('problem.number.decimals', ['decimals' => $decimals]);
        }

        return $this->limits($value == 0.0 ? 0.0 : $value, $options);
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.number.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $parts = Decimal::split($text);
        if ($parts === null) {
            return Checked::problem('problem.number.invalid');
        }
        [$negative, $integer, $fraction] = $parts;
        $decimals = self::decimals($options);
        if (!$lenient && strlen($fraction) > $decimals) {
            return $decimals === 0
                ? Checked::problem('problem.number.whole')
                : Checked::problem('problem.number.decimals', ['decimals' => $decimals]);
        }
        [$integer, $fraction] = Decimal::round($integer, $fraction, $decimals);
        if (strlen($integer) > self::SIGNIFICANT_DIGITS - $decimals) {
            return Checked::problem('problem.number.range');
        }
        if ($decimals === 0) {
            $value = (int) $integer;
            $value = $negative ? -$value : $value;
        } else {
            $value = (float) ($integer . '.' . $fraction);
            $value = $negative && $value != 0.0 ? -$value : $value;
        }

        return $this->limits($value, $options);
    }

    public function toText(mixed $value, array $options): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return self::decimals($options) === 0 ? (string) $value : Decimal::fixed((float) $value, self::decimals($options));
        }
        if (!is_float($value) || !is_finite($value)) {
            throw new \InvalidArgumentException('A canonical number is an int, a finite float, or null.');
        }
        $decimals = self::decimals($options);
        if ($decimals > 0) {
            $fixed = Decimal::fixed($value, $decimals);
            if ((float) $fixed === $value) {
                return $fixed;
            }
        }

        // A stored value with more digits than the field now allows: keep every digit.
        return Decimal::floatText($value);
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $options */
    private static function decimals(array $options): int
    {
        $decimals = $options['decimals'] ?? 0;

        return is_int($decimals) && $decimals >= 0 && $decimals <= self::DECIMALS_MAX ? $decimals : 0;
    }

    /** A number from stored text (a NUMERIC column keeps text it cannot convert), without limits; null otherwise. */
    private static function fromText(string $stored): int|float|null
    {
        $parts = Text::isUtf8($stored) ? Decimal::split(Text::trim($stored)) : null;
        if ($parts === null || strlen($parts[1]) > 16) {
            return null;
        }
        [$negative, $integer, $fraction] = $parts;
        if ($fraction === '') {
            $value = (int) $integer;

            return $negative ? -$value : $value;
        }
        $value = (float) ($integer . '.' . $fraction);

        return $negative && $value != 0.0 ? -$value : $value;
    }

    /** @param array<string, mixed> $options */
    private function limits(int|float $value, array $options): Checked
    {
        if (abs($value) >= self::limit(self::decimals($options))) {
            return Checked::problem('problem.number.range');
        }
        $min = $options['min'] ?? null;
        $max = $options['max'] ?? null;
        if ((is_int($min) || is_float($min)) && $value < $min) {
            return Checked::problem('problem.number.too_small', ['min' => Decimal::param($min)]);
        }
        if ((is_int($max) || is_float($max)) && $value > $max) {
            return Checked::problem('problem.number.too_large', ['max' => Decimal::param($max)]);
        }

        return Checked::ok($value);
    }

    /** Null, or a finite number below the magnitude limit for $decimals: an int when whole, otherwise a float. */
    private function bound(Options $reader, string $name, int $decimals): int|float|null
    {
        if (!$reader->has($name)) {
            return null;
        }
        $value = $reader->raw($name);
        if (is_string($value)) {
            if (Text::isUtf8($value) && Text::trim($value) === '') {
                return null;
            }
            $value = self::fromText($value) ?? $value;
        }
        $limit = self::limit($decimals);
        if (is_int($value) && abs($value) < $limit) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && abs($value) < $limit) {
            if ($value == 0.0) {
                return 0;
            }

            return floor($value) === $value ? (int) $value : $value;
        }
        $reader->add($name, 'problem.number.invalid');

        return null;
    }
}
