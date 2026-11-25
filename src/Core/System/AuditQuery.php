<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

/**
 * Filters of an audit-log search (M7 spec section 7.2); a null filter is
 * off. $deletedProjects keeps only `project.deleted` entries, whose
 * project row is gone.
 */
final class AuditQuery
{
    public function __construct(
        public readonly ?int $actorUserId = null,
        public readonly ?int $projectId = null,
        public readonly ?string $action = null,
        public readonly bool $deletedProjects = false,
    ) {
    }
}
