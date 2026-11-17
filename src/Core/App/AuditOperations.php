<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\System\AuditEntry;

/** Reading the audit log (M1 spec section 9.6); Admin-only. */
final class AuditOperations
{
    public const MAX_LIMIT = 500;

    public function __construct(private readonly Context $c)
    {
    }

    /** @return list<AuditEntry> newest first; $limit 1 to 500 */
    public function recent(Actor $actor, int $limit = 100, int $offset = 0): array
    {
        $this->c->authorizer->require($actor, Permission::ViewAudit);
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new \InvalidArgumentException('The limit must be 1 to ' . self::MAX_LIMIT . ' and the offset 0 or more.');
        }

        return $this->c->audit->recent($limit, $offset);
    }
}
