<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\Type\FieldType;

/**
 * How one included column is converted (M3 spec section 6.5), resolved
 * from a mapping and the schema; shared by Preparer and Committer.
 *
 * $key is the key of the value in a prepared record: `col-<n>` for a new
 * table (resolved to the new field's key at commit), the field key for an
 * existing table. A plain column has its field type and normalised
 * options; a link column has its target and whether a cell may name
 * several records (split on `;`).
 */
final class ColumnPlan
{
    /** @param array<string, mixed> $options */
    private function __construct(
        public readonly int $column,
        public readonly string $key,
        public readonly string $type,
        public readonly ?FieldType $fieldType,
        public readonly array $options,
        public readonly ?LinkTarget $target,
        public readonly bool $multi,
        public readonly bool $currency,
    ) {
    }

    public function isLink(): bool
    {
        return $this->target !== null;
    }

    /**
     * Plans for the columns the mapping imports. \LogicException for a
     * mapping that MappingCheck would refuse (callers check first);
     * NotFound when an existing target table is gone.
     *
     * @param list<ColumnStats> $stats
     * @return list<self>
     */
    public static function forMapping(Mapping $mapping, Schema $schema, FieldTypes $types, array $stats): array
    {
        $plans = [];
        foreach ($mapping->columns as $column) {
            if (!$column->include) {
                continue;
            }
            $n = $column->column;
            if ($mapping->target === Mapping::NEW) {
                $type = (string) $column->type;
                $key = 'col-' . $n;
                $target = MappingCheck::linkTarget($type, $schema);
                if ($target !== null) {
                    $plans[] = new self($n, $key, 'relation', null, [], $target, true, false);

                    continue;
                }
                if (!in_array($type, Inference::IMPORTABLE, true)) {
                    throw new \LogicException('The mapping holds a type that cannot be imported.');
                }
                $fieldType = $types->get($type);
                $options = $fieldType->normaliseOptions(Inference::optionsFor($type, $stats[$n] ?? new ColumnStats()));
                $plans[] = new self($n, $key, $type, $fieldType, $options, null, false, $type === 'currency');

                continue;
            }
            if ($column->fieldKey === null) {
                continue;
            }
            $eligible = MappingCheck::eligibleFields($schema, (string) $mapping->tableKey);
            $field = $eligible[$column->fieldKey] ?? null;
            if ($field === null) {
                throw new NotFound('The mapped field is not a field of the table.');
            }
            if ($field->type === 'relation') {
                $relation = $schema->relationOf($field);
                if ($relation === null) {
                    throw new NotFound('The relation of the field is gone.');
                }
                $target = LinkTarget::of($schema, $relation->otherTable($field->key));
                if ($target === null) {
                    throw new \LogicException('The linked table has no text-like title field.');
                }
                $plans[] = new self($n, $field->key, 'relation', null, [], $target, $relation->maxFor($field->key) === Cardinality::Many, false);

                continue;
            }
            $plans[] = new self($n, $field->key, $field->type, $types->get($field->type), $field->options, null, false, $field->type === 'currency');
        }

        return $plans;
    }

    /**
     * Converts one cell. Returns [storage value or {"titles": [...]},
     * whether the cell held a value, issue kind or null].
     *
     * @return array{0: mixed, 1: bool, 2: ?string}
     */
    public function convert(?string $cell): array
    {
        $text = $cell === null || !Text::isUtf8($cell) ? '' : Text::trim($cell);
        if ($this->target !== null) {
            $titles = [];
            if ($text !== '') {
                $parts = $this->multi ? explode(';', $text) : [$text];
                foreach ($parts as $part) {
                    $part = Text::trim($part);
                    if ($part !== '') {
                        $titles[] = $part;
                    }
                }
            }

            return [['titles' => $titles], $titles !== [], null];
        }
        $type = $this->fieldType;
        if ($type === null) {
            throw new \LogicException('A plain column has a field type.');
        }
        $empty = $type->toStorage($this->type === 'checkbox' ? false : null, $this->options);
        if ($text === '') {
            return [$empty, false, null];
        }
        $input = $this->currency ? ColumnStats::cleanCurrency($text) : $text;
        $checked = $type->parse($input, $this->options, true);
        if (!$checked->isOk()) {
            return [$empty, false, $this->type === 'choice' ? 'not-a-choice' : 'unreadable'];
        }
        $value = $checked->value();
        if ($value === null) {
            return [$empty, false, null];
        }

        return [$type->toStorage($value, $this->options), true, null];
    }
}
