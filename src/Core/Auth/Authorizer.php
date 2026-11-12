<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The permission table of M1 spec section 6.9 (ADR 0012 decision 2, P29).
 *
 * Instance-wide permissions: Admins only; any project passed is ignored.
 * Project-scoped permissions need the project (null throws
 * \InvalidArgumentException). A project that is not active is not found
 * for everyone; a Member without a grant gets "not found", so project
 * names and keys do not leak; a Member with a grant gets the permissions
 * open to granted Members and "access denied" for the rest.
 */
final class Authorizer
{
    private const ALLOWED = 'allowed';
    private const DENIED = 'denied';
    private const NOT_FOUND = 'not-found';

    public function __construct(private readonly Grants $grants)
    {
    }

    public function can(Actor $actor, Permission $permission, ?ProjectRecord $project = null): bool
    {
        return $this->decide($actor, $permission, $project) === self::ALLOWED;
    }

    /** Throws AccessDenied, or NotFound for a project the actor may not see. */
    public function require(Actor $actor, Permission $permission, ?ProjectRecord $project = null): void
    {
        $decision = $this->decide($actor, $permission, $project);
        if ($decision === self::NOT_FOUND) {
            throw new NotFound('Not found.');
        }
        if ($decision === self::DENIED) {
            throw new AccessDenied('Access denied.');
        }
    }

    private function decide(Actor $actor, Permission $permission, ?ProjectRecord $project): string
    {
        if ($permission->isInstanceWide()) {
            return $actor->isAdmin() ? self::ALLOWED : self::DENIED;
        }
        if ($project === null) {
            throw new \InvalidArgumentException('A project-scoped permission needs a project.');
        }
        if (!$project->isActive()) {
            return self::NOT_FOUND;
        }
        if ($actor->isAdmin()) {
            return self::ALLOWED;
        }
        if ($actor->role !== Role::Member || $this->grants->level($project->id, $actor->userId) !== GrantLevel::Edit) {
            return self::NOT_FOUND;
        }

        return $permission->isOpenToGrantedMembers() ? self::ALLOWED : self::DENIED;
    }
}
