<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** A stored rule and why it no longer works: null when it works, else a `collection.broken.*` message key (section 6.3). */
final class RuleState
{
    public function __construct(public readonly CollectionRule $rule, public readonly ?string $brokenReason)
    {
    }
}
