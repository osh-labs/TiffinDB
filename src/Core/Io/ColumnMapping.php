<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/** How one source column is imported (M3 spec section 6.4). */
final class ColumnMapping
{
    public function __construct(
        public readonly int $column,
        public readonly bool $include,
        public readonly ?string $fieldName,
        public readonly ?string $type,
        public readonly ?string $fieldKey,
    ) {
        if ($column < 0) {
            throw new \InvalidArgumentException('Columns are numbered from 0.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['column' => $this->column, 'include' => $this->include, 'field_name' => $this->fieldName, 'type' => $this->type, 'field_key' => $this->fieldKey];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        $include = $data['include'] ?? null;
        foreach (['field_name', 'type', 'field_key'] as $name) {
            if (isset($data[$name]) && !is_string($data[$name])) {
                throw new \UnexpectedValueException('A column mapping holds text.');
            }
        }
        if (!is_int($column) || !is_bool($include)) {
            throw new \UnexpectedValueException('A column mapping has a column number and an include flag.');
        }

        return new self($column, $include, $data['field_name'] ?? null, $data['type'] ?? null, $data['field_key'] ?? null);
    }
}
