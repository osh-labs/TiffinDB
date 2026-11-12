<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/**
 * A session just started: the cookie token (only ever here, never stored
 * or logged) and its stored record.
 */
final class IssuedSession
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly SessionRecord $record,
    ) {
    }

    /** Keeps the token out of var_dump and print_r output. */
    public function __debugInfo(): array
    {
        return ['token' => '(hidden)', 'record' => $this->record];
    }
}
