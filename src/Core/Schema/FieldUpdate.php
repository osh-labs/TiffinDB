<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/**
 * A validated field update and its effect on stored values: the stored
 * values to write by record id (null clears), how many are cleared, and the
 * new maximum of a relation side when it changes.
 *
 * @internal Private value of SchemaEditor.
 */
final class FieldUpdate
{
    /**
     * @param array<string, mixed> $options
     * @param array<int, string|int|null> $changes
     */
    public function __construct(
        public readonly FieldDef $field,
        public readonly string $name,
        public readonly array $options,
        public readonly bool $required,
        public readonly string $help,
        public readonly array $changes,
        public readonly int $cleared,
        public readonly ?Cardinality $links,
    ) {
    }
}
