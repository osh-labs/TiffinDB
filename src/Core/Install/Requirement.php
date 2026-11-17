<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** One row of the requirement check (M1 spec section 10.2). */
final class Requirement
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const FAILED = 'failed';

    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $key,
        public readonly array $params = [],
    ) {
        if (!in_array($status, [self::OK, self::WARNING, self::FAILED], true)) {
            throw new \InvalidArgumentException('Unknown requirement status.');
        }
    }
}
