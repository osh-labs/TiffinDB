<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/**
 * The signed-in person an operation acts for (M1 spec section 6.1).
 * Created only by Authenticator (from a session), by the installer (the
 * first Admin), and by the command-line password reset.
 */
final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userKey,
        public readonly Role $role,
        public readonly string $displayName,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }
}
