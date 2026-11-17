<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/**
 * The result of converting a field's values to another type: the new
 * options, the stored values to write by record id (never null), and the
 * number of non-empty values that could not be converted.
 *
 * @internal Private value of SchemaEditor.
 */
final class Conversion
{
    /**
     * @param array<string, mixed> $options
     * @param array<int, string|int|float> $values
     */
    public function __construct(
        public readonly array $options,
        public readonly array $values,
        public readonly int $failures,
    ) {
    }
}
