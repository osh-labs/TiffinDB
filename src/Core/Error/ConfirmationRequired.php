<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/** The change would clear or rewrite stored values; repeat it with confirmation. */
final class ConfirmationRequired extends CoreException
{
    public function __construct(public readonly int $records, public readonly int $values)
    {
        parent::__construct('Confirmation required: the change affects stored values.');
    }
}
