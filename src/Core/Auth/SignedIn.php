<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** The person and session behind a cookie token (M1 spec section 6.7). */
final class SignedIn
{
    public function __construct(
        public readonly Actor $actor,
        public readonly SessionRecord $session,
        public readonly bool $mustChangePassword,
    ) {
    }
}
