<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * A number TiffinDB assigns, an int of 0 or more, with a unique index (M1
 * spec section 8.2). People never type it: strict parsing always gives
 * problem.auto_number.read_only for non-empty text. Lenient parsing (import,
 * conversion) takes a whole number of 0 or more, so imported numbers are
 * kept. Values are capped at 2^53 - 1 so they stay exact in JSON exports.
 *
 * Options: `start` (0 to 1,000,000,000, default 1) and `next`, the next
 * number to assign (format document section 6). `next` is maintained by the
 * schema engine; a given whole number from `start` up to the cap is kept,
 * anything else becomes `start`.
 */
final class AutoNumberType implements FieldType
{
    public const START_MAX = 1000000000;
    public const MAX = 9007199254740991;

    public function id(): string
    {
        return 'auto_number';
    }

    public function columnDefinition(): ?string
    {
        return 'INTEGER';
    }

    public function defaultOptions(): array
    {
        return ['start' => 1, 'next' => 1];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);
        $start = $reader->int('start', 1, 0, self::START_MAX);
        $next = Options::toInt($reader->raw('next'));
        if ($next === null || $next < $start || $next > self::MAX) {
            $next = $start;
        }

        return $reader->finish(['start' => $start, 'next' => $next]);
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        if (is_float($stored) && is_finite($stored) && floor($stored) === $stored && $stored >= 0 && $stored <= self::MAX) {
            $stored = (int) $stored;
        }

        return is_int($stored) && $stored >= 0 && $stored <= self::MAX ? $stored : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('A canonical auto-number is an int or null.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX
            ? Checked::ok($value)
            : Checked::problem('problem.auto_number.read_only');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.auto_number.read_only');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if (!$lenient || preg_match('/^\d{1,16}\z/', $text) !== 1) {
            return Checked::problem('problem.auto_number.read_only');
        }

        return $this->check((int) $text, $options);
    }

    public function toText(mixed $value, array $options): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('A canonical auto-number is an int or null.');
        }

        return (string) $value;
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return false;
    }
}
