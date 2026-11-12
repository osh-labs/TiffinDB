<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * A local wall-clock date and time without a zone, `YYYY-MM-DDTHH:MM:SS`
 * (M1 spec section 8.2, P19). Strict parsing takes a date, `T` or one space,
 * then `HH:MM` or `HH:MM:SS`; lenient parsing also takes a date alone
 * (00:00:00). A trailing `Z` or offset is refused in both modes.
 */
final class DateTimeType implements FieldType
{
    public function id(): string
    {
        return 'date_time';
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
        if (!is_string($stored) || preg_match('/^' . Calendar::DATE . 'T' . Calendar::TIME . '\z/', $stored, $m) !== 1 || !isset($m[6])) {
            return null;
        }

        return Calendar::date($m[1], $m[2], $m[3]) === null ? null : $stored;
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

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.date_time.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        $text = Calendar::prepare($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if ($text !== null && preg_match('/^' . Calendar::DATE . '[T ]' . Calendar::TIME . '\z/', $text, $m) === 1) {
            $date = Calendar::date($m[1], $m[2], $m[3]);
            if ($date !== null) {
                return Checked::ok($date . 'T' . Calendar::time($m[4], $m[5], $m[6] ?? null));
            }
        }
        if ($lenient && $text !== null && preg_match('/^' . Calendar::DATE . '\z/', $text, $m) === 1) {
            $date = Calendar::date($m[1], $m[2], $m[3]);
            if ($date !== null) {
                return Checked::ok($date . 'T00:00:00');
            }
        }

        return Checked::problem('problem.date_time.invalid');
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
