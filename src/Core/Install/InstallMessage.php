<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** A message the installer returns for the web layer to word: a key and its parameters (E9). */
final class InstallMessage
{
    public const CODE_LOCATION = 'setup.code_location';
    public const CODE_LOCATION_CONFIGURED = 'setup.code_location.configured';

    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
    }
}
