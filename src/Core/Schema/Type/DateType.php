<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * A calendar date, `YYYY-MM-DD`, years 0001 to 9999 (M1 spec section 8.2).
 * Lenient parsing also accepts `YYYY-MM-DDTHH:MM[:SS]` and drops the time.
 */
final class DateType implements FieldType
{
    public function id(): string
    {
        return 'date';
    }

    public function columnDefinition(): ?string
    {
        return 'TEXT';
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
        return is_string($stored) ? $this->canonical($stored, false) : null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        return TextValues::toStorage($value);
    }

    public function check(mixed $value, array $options): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.date.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        $text = Calendar::prepare($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $date = $text === null ? null : $this->canonical($text, $lenient);

        return $date === null ? Checked::problem('problem.date.invalid') : Checked::ok($date);
    }

    public function toText(mixed $value, array $options): string
    {
        return TextValues::toText($value);
    }

    public function canBeTitle(): bool
    {
        return true;
    }

    public function isEditable(): bool
    {
        return true;
    }

    private function canonical(string $text, bool $lenient): ?string
    {
        if (preg_match('/^' . Calendar::DATE . '\z/', $text, $m) === 1) {
            return Calendar::date($m[1], $m[2], $m[3]);
        }
        if ($lenient && preg_match('/^' . Calendar::DATE . 'T' . Calendar::TIME . '\z/', $text, $m) === 1) {
            return Calendar::date($m[1], $m[2], $m[3]);
        }

        return null;
    }
}
