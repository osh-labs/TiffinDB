<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * A phone number as typed (M1 spec section 8.2): trimmed, runs of white
 * space made one space, 3 to 40 characters, only ASCII digits, space, and
 * `+ ( ) - .`, optionally followed by ` x`, ` ext`, or ` ext.` (ASCII case
 * ignored), an optional space, and digits. The part before the extension
 * holds at least 3 digits. Lenient is the same.
 */
final class PhoneType implements FieldType
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 40;
    private const PATTERN = '/^([0-9 +().\-]+)(?: (?:x|ext\.?) ?[0-9]+)?\z/i';

    public function id(): string
    {
        return 'phone';
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
        return TextValues::fromStorage($stored);
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

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.phone.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.phone.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $length = strlen($text);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || preg_match(self::PATTERN, $text, $m) !== 1) {
            return Checked::problem('problem.phone.invalid');
        }
        if (preg_match_all('/\d/', $m[1]) < 3) {
            return Checked::problem('problem.phone.invalid');
        }

        return Checked::ok($text);
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
