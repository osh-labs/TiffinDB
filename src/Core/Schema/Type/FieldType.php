<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\ValidationFailed;

/**
 * One field type (M1 spec section 8.1). Stored forms and option names are
 * part of the project file format (`docs/formats/tiffin.md` section 6).
 * Types never build SQL from values and produce no HTML.
 */
interface FieldType
{
    public function id(): string;

    /** SQL column definition, or null for types stored outside the user table. */
    public function columnDefinition(): ?string;

    /** @return array<string, mixed> */
    public function defaultOptions(): array;

    /**
     * Complete, normalised options; unknown names are dropped.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws ValidationFailed with fields 'options.<name>'
     */
    public function normaliseOptions(array $options): array;

    /**
     * Canonical value from what SQLite returned.
     *
     * @param array<string, mixed> $options
     */
    public function fromStorage(mixed $stored, array $options): mixed;

    /**
     * SQLite value from a canonical value.
     *
     * @param array<string, mixed> $options
     */
    public function toStorage(mixed $value, array $options): string|int|float|null;

    /**
     * Checks a canonical PHP value given by a core caller.
     *
     * @param array<string, mixed> $options
     */
    public function check(mixed $value, array $options): Checked;

    /**
     * Parses text from a form field (strict) or from an import or a type conversion (lenient).
     *
     * @param array<string, mixed> $options
     */
    public function parse(string $text, array $options, bool $lenient = false): Checked;

    /**
     * Canonical text of a canonical value; parse(toText(v)) gives v back.
     *
     * @param array<string, mixed> $options
     */
    public function toText(mixed $value, array $options): string;

    public function canBeTitle(): bool;

    /** False when people never type the value (auto-number). */
    public function isEditable(): bool;
}
