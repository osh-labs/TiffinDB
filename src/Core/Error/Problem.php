<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/**
 * One thing wrong with an input: the input name it concerns (or null for
 * the whole request), a message key (M1 spec section 12), and the key's
 * parameters. Core never carries English sentences meant for people.
 */
final class Problem
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly ?string $field,
        public readonly string $key,
        public readonly array $params = [],
    ) {
    }
}
