<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * One value of an exported record (M3 spec section 3.3): the canonical PHP
 * value (null when empty), its text, and for relation and attachment
 * fields the linked live record ids or the attachment ids.
 */
final class ExportValue
{
    /** @param list<int> $ids */
    public function __construct(
        public readonly mixed $value,
        public readonly string $text,
        public readonly array $ids,
    ) {
    }
}
