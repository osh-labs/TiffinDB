<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Schema\FieldDef;

/**
 * One row of the collection editor (M4 spec section 10.4): its index from
 * 1, the field (null when none is chosen or the stored field is gone), the
 * chosen test and the tests the field offers (label is a message key),
 * the value text, the chosen record for a relation and up to 500 records
 * to choose from, the choices of a choice field (key and label), and the
 * broken reason (a message key) of a stored rule that no longer fits.
 */
final class EditorRule
{
    /**
     * @param list<array{op: string, label: string}> $tests
     * @param list<LinkItem> $records
     * @param list<array{key: string, label: string}> $choices
     */
    public function __construct(
        public readonly int $index,
        public readonly ?FieldDef $field,
        public readonly string $test,
        public readonly array $tests,
        public readonly string $value,
        public readonly ?string $record,
        public readonly array $records,
        public readonly bool $moreRecords,
        public readonly array $choices,
        public readonly ?string $brokenReason,
    ) {
    }
}
