<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/**
 * A record changed after the person read it (ADR 0013 decision 6): the
 * version token they saved against is not the current one. Nothing was
 * changed. $currentVersion is the record's token now (16 hexadecimal
 * characters, no data). The message is for logs only.
 */
final class Conflict extends CoreException
{
    public function __construct(public readonly string $currentVersion)
    {
        if (preg_match('/^[0-9a-f]{16}\z/', $currentVersion) !== 1) {
            throw new \InvalidArgumentException('A version token is 16 lower-case hexadecimal characters.');
        }
        parent::__construct('The record changed after it was read.');
    }
}
