<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** A stored session (M1 spec section 6.5); it holds the token's hash, never the token. */
final class SessionRecord
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly int $userId,
        public readonly string $csrfToken,
        public readonly string $createdAt,
        public readonly string $absoluteExpiresAt,
    ) {
    }
}
