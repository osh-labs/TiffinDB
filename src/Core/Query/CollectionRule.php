<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Storage\Keys;

/**
 * One rule of a collection exactly as stored (`docs/formats/tiffin.md`
 * section 4.8.1): a field key, an operator, and at most two strings of at
 * most 200 characters. Only the shape is checked here; whether the rule
 * fits the table is CollectionRules' question (`docs/specs/M4-core.md`
 * section 6.1). The archive rebuild uses the same check.
 */
final class CollectionRule
{
    public const MAX_VALUES = 2;
    public const MAX_VALUE_LENGTH = 200;

    /** @var list<string> */
    public readonly array $values;

    /** @param list<string> $values */
    public function __construct(public readonly string $field, public readonly string $op, array $values)
    {
        if (!Keys::isValid($field)) {
            throw new \InvalidArgumentException('A rule names a field by a valid key.');
        }
        if (Operator::tryFrom($op) === null) {
            throw new \InvalidArgumentException('A rule uses a known operator.');
        }
        if (!array_is_list($values) || count($values) > self::MAX_VALUES) {
            throw new \InvalidArgumentException('A rule holds a list of at most two operands.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !Text::isUtf8($value) || Text::length($value) > self::MAX_VALUE_LENGTH) {
                throw new \InvalidArgumentException('A rule operand is text of at most 200 characters.');
            }
        }
        $this->values = $values;
    }

    /**
     * The rule read from a decoded JSON value: an object with string
     * `field` and `op` and a `values` list; members it does not know are
     * ignored (section 4.8.1). \InvalidArgumentException otherwise.
     */
    public static function fromArray(mixed $entry): self
    {
        if (!is_array($entry)) {
            throw new \InvalidArgumentException('A rule is an object.');
        }
        $field = $entry['field'] ?? null;
        $op = $entry['op'] ?? null;
        $values = $entry['values'] ?? null;
        if (!is_string($field) || !is_string($op) || !is_array($values)) {
            throw new \InvalidArgumentException('A rule has field, op, and values.');
        }

        return new self($field, $op, $values);
    }

    /** @return array{field: string, op: string, values: list<string>} the stored shape */
    public function toArray(): array
    {
        return ['field' => $this->field, 'op' => $this->op, 'values' => $this->values];
    }
}
