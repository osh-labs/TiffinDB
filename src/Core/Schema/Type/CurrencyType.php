<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * An amount in minor units, an int (M1 spec section 8.2, P19): with
 * `minor_digits` 2, 32900 means 329.00. Text is pattern NUMBER, optionally
 * with one leading `$`, `€`, `£`, or `¥` (directly before the number), or the
 * field's `code` before or after the number separated by one space (the
 * code is matched ignoring ASCII case). Magnitude at most 9e15 minor units.
 * The arithmetic is done on digit strings, never on floats.
 */
final class CurrencyType implements FieldType
{
    public const MINOR_DIGITS_MAX = 4;
    public const LIMIT = 9000000000000000;
    private const SYMBOLS = ['$', '€', '£', '¥'];

    public function id(): string
    {
        return 'currency';
    }

    public function columnDefinition(): ?string
    {
        return 'INTEGER';
    }

    public function defaultOptions(): array
    {
        return ['code' => 'USD', 'minor_digits' => 2];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);
        $code = 'USD';
        if ($reader->has('code')) {
            $given = $reader->raw('code');
            if (is_string($given) && preg_match('/^[A-Za-z]{3}\z/', $given) === 1) {
                $code = strtoupper($given);
            } else {
                $reader->add('code', 'problem.options.currency_code');
            }
        }
        $minorDigits = $reader->int('minor_digits', 2, 0, self::MINOR_DIGITS_MAX);

        return $reader->finish(['code' => $code, 'minor_digits' => $minorDigits]);
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        if (is_float($stored) && is_finite($stored) && floor($stored) === $stored) {
            $stored = abs($stored) <= self::LIMIT ? (int) $stored : null;
        }
        if (is_string($stored) && preg_match('/^-?\d{1,16}\z/', $stored) === 1) {
            $stored = (int) $stored;
        }

        return is_int($stored) && abs($stored) <= self::LIMIT ? $stored : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('A canonical currency value is an int or null.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }
        if (!is_int($value)) {
            return Checked::problem('problem.currency.invalid');
        }
        if (abs($value) > self::LIMIT) {
            return Checked::problem('problem.number.range');
        }

        return Checked::ok($value);
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.currency.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $number = $this->withoutSymbol($text, self::code($options));
        $parts = $number === null ? null : Decimal::split($number);
        if ($parts === null) {
            return Checked::problem('problem.currency.invalid');
        }
        [$negative, $integer, $fraction] = $parts;
        $digits = self::minorDigits($options);
        if (!$lenient && strlen($fraction) > $digits) {
            return Checked::problem('problem.currency.decimals', ['digits' => $digits]);
        }
        [$integer, $fraction] = Decimal::round($integer, $fraction, $digits);
        $minor = ltrim($integer . $fraction, '0');
        if (strlen($minor) > 16) {
            return Checked::problem('problem.number.range');
        }
        $value = (int) $minor;
        if ($value > self::LIMIT) {
            return Checked::problem('problem.number.range');
        }

        return Checked::ok($negative ? -$value : $value);
    }

    public function toText(mixed $value, array $options): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('A canonical currency value is an int or null.');
        }
        $digits = self::minorDigits($options);
        if ($digits === 0) {
            return (string) $value;
        }
        $scale = 10 ** $digits;
        $magnitude = abs($value);

        return ($value < 0 ? '-' : '') . intdiv($magnitude, $scale) . '.'
            . str_pad((string) ($magnitude % $scale), $digits, '0', STR_PAD_LEFT);
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return true;
    }

    /** The text with at most one symbol or code removed, or null when a code is malformed. */
    private function withoutSymbol(string $text, string $code): ?string
    {
        foreach (self::SYMBOLS as $symbol) {
            if (str_starts_with($text, $symbol)) {
                return substr($text, strlen($symbol));
            }
        }
        if (preg_match('/^([A-Za-z]{3}) (.+)\z/s', $text, $m) === 1) {
            return strtoupper($m[1]) === $code ? $m[2] : null;
        }
        if (preg_match('/^(.+) ([A-Za-z]{3})\z/s', $text, $m) === 1) {
            return strtoupper($m[2]) === $code ? $m[1] : null;
        }

        return $text;
    }

    /** @param array<string, mixed> $options */
    private static function code(array $options): string
    {
        $code = $options['code'] ?? 'USD';

        return is_string($code) ? strtoupper($code) : 'USD';
    }

    /** @param array<string, mixed> $options */
    private static function minorDigits(array $options): int
    {
        $digits = $options['minor_digits'] ?? 2;

        return is_int($digits) && $digits >= 0 && $digits <= self::MINOR_DIGITS_MAX ? $digits : 2;
    }
}
