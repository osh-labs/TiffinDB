<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A form joined with field definitions and values (M2 spec section 5.7).
 * columns: a root node has a span of 1 to 12, so the root is laid out in
 * twelve columns (M5 spec section 8).
 */
final class BoundForm
{
    /** @param list<BoundNode> $nodes */
    public function __construct(public readonly array $nodes, public readonly bool $columns = false)
    {
    }

    /**
     * Field keys of the editable bound fields in document order: exactly
     * the inputs the record form posts.
     *
     * @return list<string>
     */
    public function editableKeys(): array
    {
        $keys = [];
        foreach (self::fields($this->nodes) as $field) {
            if ($field->editable) {
                $keys[] = $field->field->key;
            }
        }

        return $keys;
    }

    public function hasGrid(): bool
    {
        foreach ($this->nodes as $node) {
            if ($node instanceof BoundSection && $node->grid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<BoundNode> $nodes
     * @return list<BoundField>
     */
    private static function fields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if ($node instanceof BoundField) {
                $fields[] = $node;
            } elseif ($node instanceof BoundSection) {
                $fields = [...$fields, ...self::fields($node->children)];
            }
        }

        return $fields;
    }
}
