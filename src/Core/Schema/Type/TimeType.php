<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * A clock time, `HH:MM:SS`, 24-hour (M1 spec section 8.2). Strict parsing
 * takes `HH:MM` or `HH:MM:SS`; lenient parsing also takes `H:MM`. The
 * `seconds` option only says whether seconds are shown and entered (M2).
 */
final class TimeType implements FieldType
{
    public function id(): string
    {
        return 'time';
    }

    public function columnDefinition(): ?string
    {
        return 'TEXT';
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
        return is_string($stored) && preg_match('/^' . Calendar::TIME . '\z/', $stored, $m) === 1 && isset($m[3])
            ? $stored
            : null;
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

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.time.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        $text = Calendar::prepare($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if ($text !== null && preg_match('/^' . Calendar::TIME . '\z/', $text, $m) === 1) {
            return Checked::ok(Calendar::time($m[1], $m[2], $m[3] ?? null));
        }
        if ($lenient && $text !== null && preg_match('/^(\d):([0-5]\d)\z/', $text, $m) === 1) {
            return Checked::ok(Calendar::time($m[1], $m[2], null));
        }

        return Checked::problem('problem.time.invalid');
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
}
