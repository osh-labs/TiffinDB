<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

/** A row of the project registry (M1 spec section 5.4; the state `deleting`, M7 spec section 6.2). */
final class ProjectRecord
{
    public const STATE_CREATING = 'creating';
    public const STATE_ACTIVE = 'active';
    /** Being deleted (M7 spec section 6.2 step 3): no list shows it and no operation finds it. */
    public const STATE_DELETING = 'deleting';
    public const STATES = [self::STATE_CREATING, self::STATE_ACTIVE, self::STATE_DELETING];

    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $state,
        public readonly string $createdAt,
        public readonly ?int $createdBy,
    ) {
        if (!in_array($state, self::STATES, true)) {
            throw new \UnexpectedValueException('Unknown project state.');
        }
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
