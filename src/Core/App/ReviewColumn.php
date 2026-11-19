<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** One source column on the review page (M3 spec section 13.1). */
final class ReviewColumn
{
    /**
     * @param list<TypeChoice> $typeChoices
     * @param list<FieldChoice> $fieldChoices empty for a new-table target
     * @param list<string> $firstValues
     */
    public function __construct(
        public readonly int $index,
        public readonly string $heading,
        public readonly bool $include,
        public readonly string $fieldName,
        public readonly string $type,
        public readonly array $typeChoices,
        public readonly ?string $fieldKey,
        public readonly array $fieldChoices,
        public readonly array $firstValues,
        public readonly bool $leftOutByDefault,
    ) {
    }
}
