<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/**
 * The proposal for one source column (M3 spec section 6.3): field name,
 * type (a type id or `link:<table key>`), options for that type, the type
 * menu (proposed type first), and whether "Bring in" starts ticked.
 */
final class TypeGuess
{
    /**
     * @param array<string, mixed> $options
     * @param list<string> $typeChoices
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $type,
        public readonly array $options,
        public readonly array $typeChoices,
        public readonly bool $include,
    ) {
    }
}
