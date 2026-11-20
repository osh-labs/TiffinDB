<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * One saved collection whose table is in scope (`docs/formats/tiffin.md`
 * section 4.8, M4 spec section 8.3): its rules as stored, broken ones
 * included.
 */
final class ExportCollection
{
    /** @param list<ExportRule> $rules */
    public function __construct(
        public readonly string $key,
        public readonly string $tableKey,
        public readonly string $name,
        public readonly string $match,
        public readonly array $rules,
        public readonly ?string $sort,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        if ($key === '' || $tableKey === '') {
            throw new \InvalidArgumentException('A collection has a key and a table.');
        }
        if ($match !== 'all' && $match !== 'any') {
            throw new \InvalidArgumentException('A collection matches all or any of its rules.');
        }
        if (!array_is_list($rules)) {
            throw new \InvalidArgumentException('Collection rules are a list.');
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof ExportRule) {
                throw new \InvalidArgumentException('Collection rules are ExportRule values.');
            }
        }
    }
}
