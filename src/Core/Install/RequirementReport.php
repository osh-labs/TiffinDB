<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** The ordered rows of the requirement check (M1 spec section 10.2). */
final class RequirementReport
{
    /** @param list<Requirement> $requirements */
    public function __construct(public readonly array $requirements)
    {
    }

    /** True when no row failed. */
    public function canContinue(): bool
    {
        foreach ($this->requirements as $requirement) {
            if ($requirement->status === Requirement::FAILED) {
                return false;
            }
        }

        return true;
    }

    /** The row with this id, or null. */
    public function get(string $id): ?Requirement
    {
        foreach ($this->requirements as $requirement) {
            if ($requirement->id === $id) {
                return $requirement;
            }
        }

        return null;
    }
}
