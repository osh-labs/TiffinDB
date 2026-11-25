<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** One page of the audit screen (M7 spec section 7.1). */
final class AuditPage
{
    /**
     * @param list<AuditRow> $rows newest first
     * @param string|null $person the person filter as applied (a user key), or null
     * @param string|null $project the project filter as applied (a project key, or `deleted`), or null
     * @param string|null $action the action filter as applied, or null
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly AuditChoices $choices,
        public readonly ?string $person,
        public readonly ?string $project,
        public readonly ?string $action,
    ) {
    }
}
