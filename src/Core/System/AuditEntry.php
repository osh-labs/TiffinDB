<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

/** One audit_log row, with `detail` decoded (M1 spec section 5.6). */
final class AuditEntry
{
    /** @param array<string, string|int|bool|null> $detail */
    public function __construct(
        public readonly int $id,
        public readonly string $at,
        public readonly ?int $actorUserId,
        public readonly string $action,
        public readonly ?int $projectId,
        public readonly ?string $targetKind,
        public readonly ?string $targetKey,
        public readonly array $detail,
    ) {
    }
}
