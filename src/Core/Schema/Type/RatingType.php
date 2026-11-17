<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * A rating, an int from 1 to `max` (3 to 10, default 5); null means not
 * rated (M1 spec section 8.2). Text is a whole number of ASCII digits; `0`
 * gives null. Lenient is the same.
 */
final class RatingType implements FieldType
{
    public const MAX_MIN = 3;
    public const MAX_MAX = 10;
    public const MAX_DEFAULT = 5;

    public function id(): string
    {
        return 'rating';
    }

    public function columnDefinition(): ?string
    {
        return 'INTEGER';
    }

    public function defaultOptions(): array
    {
        return ['max' => self::MAX_DEFAULT];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);

        return $reader->finish(['max' => $reader->int('max', self::MAX_DEFAULT, self::MAX_MIN, self::MAX_MAX)]);
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        if (is_float($stored) && is_finite($stored) && floor($stored) === $stored && abs($stored) <= 1000) {
            $stored = (int) $stored;
        }

        return is_int($stored) && $stored >= 1 ? $stored : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('A canonical rating is an int or null.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null || $value === 0) {
            return Checked::ok(null);
        }
        $max = self::max($options);

        return is_int($value) && $value >= 1 && $value <= $max
            ? Checked::ok($value)
            : Checked::problem('problem.rating.invalid', ['max' => $max]);
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        $max = self::max($options);
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.rating.invalid', ['max' => $max]);
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if (preg_match('/^\d{1,4}\z/', $text) !== 1) {
            return Checked::problem('problem.rating.invalid', ['max' => $max]);
        }

        return $this->check((int) $text, $options);
    }

    public function toText(mixed $value, array $options): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('A canonical rating is an int or null.');
        }

        return (string) $value;
    }

    public function canBeTitle(): bool
    {
        return false;
    }

    public function isEditable(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $options */
    private static function max(array $options): int
    {
        $max = $options['max'] ?? self::MAX_DEFAULT;

        return is_int($max) && $max >= self::MAX_MIN && $max <= self::MAX_MAX ? $max : self::MAX_DEFAULT;
    }
}
