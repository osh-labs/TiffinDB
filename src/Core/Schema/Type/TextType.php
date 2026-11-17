<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * One line of text (M1 spec section 8.2). Trimmed; tab allowed; line breaks
 * and other control characters (P61) refused; at most `max_length` code
 * points. Lenient parsing turns each line break into one space; text that
 * is too long is refused in both modes, never cut.
 */
final class TextType implements FieldType
{
    public const MAX_LENGTH_MIN = 1;
    public const MAX_LENGTH_MAX = 10000;
    public const MAX_LENGTH_DEFAULT = 500;

    public function id(): string
    {
        return 'text';
    }

    public function columnDefinition(): ?string
    {
        return 'TEXT';
    }

    public function defaultOptions(): array
    {
        return ['max_length' => self::MAX_LENGTH_DEFAULT];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);

        return $reader->finish([
            'max_length' => $reader->int('max_length', self::MAX_LENGTH_DEFAULT, self::MAX_LENGTH_MIN, self::MAX_LENGTH_MAX),
        ]);
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
        if (!is_string($value)) {
            return Checked::problem('problem.value.unsupported');
        }

        return $this->parse($value, $options);
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.text.control');
        }
        if ($lenient) {
            $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            return Checked::problem('problem.text.line_break');
        }

        return TextValues::limits($text, (int) ($options['max_length'] ?? self::MAX_LENGTH_DEFAULT), allowNewline: false);
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
