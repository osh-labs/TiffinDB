<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** The only source of randomness in the core. */
interface Random
{
    /** $length random bytes; $length must be 0 or more. */
    public function bytes(int $length): string;
}
