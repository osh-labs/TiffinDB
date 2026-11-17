<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** What Authenticator::signIn() reports (M1 spec section 6.7). */
final class SignInResult
{
    public function __construct(
        public readonly SignInOutcome $outcome,
        public readonly ?IssuedSession $session = null,
        public readonly int $waitSeconds = 0,
        public readonly bool $mustChangePassword = false,
    ) {
    }
}
