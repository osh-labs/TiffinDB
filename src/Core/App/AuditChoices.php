<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** The values the audit screen's filters offer (M7 spec section 7.1). */
final class AuditChoices
{
    /**
     * @param list<array{key: string, name: string, active: bool}> $people every person, by display name, then key
     * @param list<array{key: string, name: string}> $projects every active project, by name
     * @param list<string> $actions AuditAction::ALL in its order
     */
    public function __construct(
        public readonly array $people,
        public readonly array $projects,
        public readonly array $actions,
    ) {
    }
}
