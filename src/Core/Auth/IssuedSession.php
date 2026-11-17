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
 *
 * The token is read with token(). It is held inside a closure in a
 * private property, so var_export(), an (array) cast, and json_encode()
 * show no token text; var_dump() and print_r() see only __debugInfo();
 * serialize() throws.
 */
final class IssuedSession
{
    private readonly \Closure $token;

    public function __construct(#[\SensitiveParameter] string $token, public readonly SessionRecord $record)
    {
        $this->token = static fn (): string => $token;
    }

    /** The cookie token: 43 base64url characters. */
    public function token(): string
    {
        return ($this->token)();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['token' => '(hidden)', 'record' => $this->record];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('An issued session must not be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('An issued session cannot be unserialized.');
    }
}
