<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One field of an exported table (M3 spec section 3.3). */
final class ExportField
{
    /** @param array<string, mixed> $options the stored options of the type */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $type,
        public readonly array $options,
        public readonly bool $required,
        public readonly string $help,
        public readonly ?string $linkedTable,
    ) {
    }
}
