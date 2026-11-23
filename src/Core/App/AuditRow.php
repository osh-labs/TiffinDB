<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * One line of the audit screen (M7 spec section 7.1). Values are as
 * stored; escaping is the template's.
 */
final class AuditRow
{
    /**
     * @param string $at Timestamp text
     * @param string|null $actorName display name, deactivated people included; null when no person acted
     * @param string|null $projectName current name; for project.deleted the deleted project's name; else null
     * @param array<string, string|int|bool|null> $detail
     */
    public function __construct(
        public readonly int $id,
        public readonly string $at,
        public readonly ?string $actorName,
        public readonly ?string $actorKey,
        public readonly string $action,
        public readonly ?string $projectName,
        public readonly ?string $targetKind,
        public readonly ?string $targetKey,
        public readonly array $detail,
    ) {
    }
}
