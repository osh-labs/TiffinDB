<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

/** A row of the project registry (M1 spec section 5.4). */
final class ProjectRecord
{
    public const STATE_CREATING = 'creating';
    public const STATE_ACTIVE = 'active';

    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $state,
        public readonly string $createdAt,
        public readonly ?int $createdBy,
    ) {
        if ($state !== self::STATE_CREATING && $state !== self::STATE_ACTIVE) {
            throw new \UnexpectedValueException('Unknown project state.');
        }
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
