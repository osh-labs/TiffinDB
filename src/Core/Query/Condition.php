<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Storage\Keys;

/** One filter condition: a field, an operator, and its operands as text (reused by saved collections). */
final class Condition
{
    /** @param list<string> $operands */
    public function __construct(public readonly string $fieldKey, public readonly Operator $operator, public readonly array $operands)
    {
        if (!Keys::isValid($fieldKey)) {
            throw new \InvalidArgumentException('A condition needs a valid field key.');
        }
        if (!array_is_list($operands)) {
            throw new \InvalidArgumentException('Operands must be a list.');
        }
        foreach ($operands as $operand) {
            if (!is_string($operand)) {
                throw new \InvalidArgumentException('Operands must be strings.');
            }
        }
    }
}
