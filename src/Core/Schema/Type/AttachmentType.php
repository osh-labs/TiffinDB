<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

/**
 * Files attached to a record (M1 spec section 8.2): no column; links live in
 * `_tiffin_attachment_links` (format document section 8). The canonical
 * value is a list of attachment ids (M4). Only empty text parses (to null).
 */
final class AttachmentType implements FieldType
{
    public function id(): string
    {
        return 'attachment';
    }

    public function columnDefinition(): ?string
    {
        return null;
    }

    public function defaultOptions(): array
    {
        return ['images_only' => false];
    }

    public function normaliseOptions(array $options): array
    {
        $reader = new Options($options);

        return $reader->finish(['images_only' => $reader->bool('images_only', false)]);
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
