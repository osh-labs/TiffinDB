<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * Ticked or not, a bool; stored as 1 or 0 in a `NOT NULL DEFAULT 0` column
 * (M1 spec section 8.2). Never empty: empty text and null give false.
 */
final class CheckboxType implements FieldType
{
    private const TRUE = ['1', 'on', 'true', 'yes'];
    private const FALSE = ['', '0', 'off', 'false', 'no'];

    public function id(): string
    {
        return 'checkbox';
    }

    public function columnDefinition(): ?string
    {
        return 'INTEGER NOT NULL DEFAULT 0';
    }

    public function defaultOptions(): array
    {
        return [];
    }

    public function normaliseOptions(array $options): array
    {
        return [];
    }

    public function fromStorage(mixed $stored, array $options): mixed
    {
        return (is_int($stored) || is_float($stored)) ? $stored != 0 : $stored === '1';
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? 1 : 0;
        }

        throw new \InvalidArgumentException('A canonical checkbox value is a bool.');
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(false);
        }

        return is_bool($value) ? Checked::ok($value) : Checked::problem('problem.checkbox.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.checkbox.invalid');
        }
        // strtolower folds ASCII letters only (PHP 8.2 and later), whatever the locale.
        $word = strtolower(Text::trim($text));
        if (in_array($word, self::TRUE, true)) {
            return Checked::ok(true);
        }
        if (in_array($word, self::FALSE, true)) {
            return Checked::ok(false);
        }

        return Checked::problem('problem.checkbox.invalid');
    }

    public function toText(mixed $value, array $options): string
    {
        return $value === true ? '1' : '0';
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
