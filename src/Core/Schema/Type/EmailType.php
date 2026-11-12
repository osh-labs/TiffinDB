<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * An email address (M1 spec section 8.2): trimmed, at most 254 characters,
 * and accepted by filter_var(FILTER_VALIDATE_EMAIL) without the Unicode
 * flag, so only ASCII addresses pass. Quoted local parts, and local parts
 * holding any of < > " ' ` \ ( ) , ; : [ ], are refused (P67); a domain
 * literal such as `user@[192.0.2.1]` is still accepted. Security-relevant: the value is later
 * written into pages and links, so control characters (P61) and invalid
 * UTF-8 are refused first, the length is checked before the validator runs
 * (which bounds its work), and stored values are checked again when read.
 * Lenient is the same.
 */
final class EmailType implements FieldType
{
    public const MAX_LENGTH = 254;
    /**
     * Characters refused in the local part even where filter_var accepts
     * them (P67). The double quote covers every quoted local part.
     */
    private const LOCAL_REFUSED = '<>"\'`\\(),;:[]';

    public function id(): string
    {
        return 'email';
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
        if (!is_string($stored)) {
            return null;
        }
        $checked = $this->parse($stored, $options);

        return $checked->isOk() && $checked->value() === $stored ? $stored : null;
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

        return is_string($value) ? $this->parse($value, $options) : Checked::problem('problem.email.invalid');
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        if (!Text::isUtf8($text)) {
            return Checked::problem('problem.email.invalid');
        }
        $text = Text::trim($text);
        if ($text === '') {
            return Checked::ok(null);
        }
        if (strlen($text) > self::MAX_LENGTH
            || Text::hasControl($text)
            || filter_var($text, FILTER_VALIDATE_EMAIL) === false
            || strpbrk(substr($text, 0, (int) strrpos($text, '@')), self::LOCAL_REFUSED) !== false
        ) {
            return Checked::problem('problem.email.invalid');
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
