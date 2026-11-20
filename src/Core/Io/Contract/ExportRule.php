<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * One rule of an exported collection, exactly as stored
 * (`docs/formats/tiffin.md` section 4.8.1, M4 spec section 8.3): a field
 * key, an operator, and its operands. A rule that no longer fits the
 * table is exported all the same.
 */
final class ExportRule
{
    /** @param list<string> $values */
    public function __construct(
        public readonly string $field,
        public readonly string $op,
        public readonly array $values,
    ) {
        if ($field === '' || $op === '') {
            throw new \InvalidArgumentException('A rule has a field and an operator.');
        }
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException('Rule operands are a list.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Rule operands are strings.');
            }
        }
    }
}
