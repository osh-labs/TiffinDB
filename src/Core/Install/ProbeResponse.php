<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** An answer to one probe request: the status code and the body (M1 spec section 10.4). */
final class ProbeResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }
}
