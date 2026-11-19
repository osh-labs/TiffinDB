<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/**
 * The import mapping (M3 spec section 6.4): the target (a new table with
 * its name, or an existing table by key) and one ColumnMapping per source
 * column, in order. Shape only: MappingCheck validates it against the
 * schema.
 */
final class Mapping
{
    public const NEW = 'new';
    public const EXISTING = 'existing';

    /** @param list<ColumnMapping> $columns */
    public function __construct(
        public readonly string $target,
        public readonly ?string $newTableName,
        public readonly ?string $tableKey,
        public readonly array $columns,
    ) {
        if ($target !== self::NEW && $target !== self::EXISTING) {
            throw new \InvalidArgumentException('The target is new or existing.');
        }
        if (!array_is_list($columns)) {
            throw new \InvalidArgumentException('Column mappings are a list.');
        }
        foreach ($columns as $index => $column) {
            if (!$column instanceof ColumnMapping || $column->column !== $index) {
                throw new \InvalidArgumentException('Column mappings are one per source column, in order.');
            }
        }
    }

    /** @return list<ColumnMapping> the included columns, in order */
    public function included(): array
    {
        return array_values(array_filter($this->columns, static fn (ColumnMapping $c): bool => $c->include));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'new_table_name' => $this->newTableName,
            'table_key' => $this->tableKey,
            'columns' => array_map(static fn (ColumnMapping $c): array => $c->toArray(), $this->columns),
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $columns = [];
        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
            if (!is_array($column)) {
                throw new \UnexpectedValueException('A column mapping is an object.');
            }
            $columns[] = ColumnMapping::fromArray($column);
        }
        foreach (['target', 'new_table_name', 'table_key'] as $name) {
            if (isset($data[$name]) && !is_string($data[$name])) {
                throw new \UnexpectedValueException('A mapping holds text.');
            }
        }

        return new self((string) ($data['target'] ?? ''), $data['new_table_name'] ?? null, $data['table_key'] ?? null, $columns);
    }
}
