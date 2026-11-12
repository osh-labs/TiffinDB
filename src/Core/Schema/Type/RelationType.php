<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * A link to records of another table (M1 spec section 8.2): no column; links
 * live in the relation's join table (format document section 7). The
 * canonical value is a list of record ids (M2, M4). Only empty text parses (to null).
 *
 * Options `relation` and `side` are set by the schema engine only, so
 * normaliseOptions() ignores every option a caller gives and returns none;
 * the engine writes `{"relation": <key>, "side": "left"|"right"}` itself.
 */
final class RelationType implements FieldType
{
    public function id(): string
    {
        return 'relation';
    }

    public function columnDefinition(): ?string
    {
        return null;
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
        return null;
    }

    public function toStorage(mixed $value, array $options): string|int|float|null
    {
        return null;
    }

    public function check(mixed $value, array $options): Checked
    {
        return IdList::check($value);
    }

    public function parse(string $text, array $options, bool $lenient = false): Checked
    {
        return IdList::parse($text);
    }

    public function toText(mixed $value, array $options): string
    {
        return IdList::toText($value);
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
