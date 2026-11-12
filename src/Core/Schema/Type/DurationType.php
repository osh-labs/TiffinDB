<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * A length of time in whole seconds, 0 to 3,153,600,000 (M1 spec section
 * 8.2). Text is `H:MM` or `H:MM:SS` (minutes and seconds 00 to 59), or a
 * whole number of minutes. Canonical text is `H:MM:SS`. Lenient is the same.
 */
final class DurationType implements FieldType
{
    public const MAX = 3153600000;

    public function id(): string
    {
        return 'duration';
    }

    public function columnDefinition(): ?string
    {
        return 'INTEGER';
    }

    public function defaultOptions(): array
    {
        return ['seconds' => false];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);

        return $reader->finish(['seconds' => $reader->bool('seconds', false)]);
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        if (is_float($stored) && is_finite($stored) && floor($stored) === $stored && abs($stored) <= self::MAX) {
            $stored = (int) $stored;
        }

        return is_int($stored) && $stored >= 0 && $stored <= self::MAX ? $stored : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('A canonical duration is an int or null.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX
            ? Checked::ok($value)
            : Checked::problem('problem.duration.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        $text = Calendar::prepare($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $seconds = null;
        if ($text !== null && preg_match('/^(\d{1,7}):([0-5]\d)(?::([0-5]\d))?\z/', $text, $m) === 1) {
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
        } elseif ($text !== null && preg_match('/^\d{1,9}\z/', $text) === 1) {
            $seconds = (int) $text * 60;
        }

        return $seconds !== null && $seconds <= self::MAX
            ? Checked::ok($seconds)
            : Checked::problem('problem.duration.invalid');
    }

    public function toText(mixed $value, array $options): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('A canonical duration is an int of 0 or more, or null.');
        }

        return intdiv($value, 3600) . ':' . sprintf('%02d:%02d', intdiv($value % 3600, 60), $value % 60);
    }

    public function canBeTitle(): bool
    {
        return false;
    }

    public function isEditable(): bool
    {
        return true;
    }
}
