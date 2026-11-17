<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;

/** Manual snapshots and the snapshot list (M1 spec section 9.6); Admin-only. */
final class SnapshotOperations
{
    public function __construct(private readonly Context $c)
    {
    }

    public function take(Actor $actor, string $projectKey): SnapshotInfo
    {
        $project = $this->c->project($actor, Permission::ManageSnapshots, $projectKey);
        $info = $this->c->snapshots->take($this->c->opener->open($project), $this->c->opener->folder($project->key), SnapshotReason::Manual);
        $this->c->recordSnapshot($project, $actor, $info);

        return $info;
    }

    /** @return list<SnapshotInfo> newest first */
    public function list(Actor $actor, string $projectKey): array
    {
        $project = $this->c->project($actor, Permission::ManageSnapshots, $projectKey);

        return $this->c->snapshots->list($this->c->opener->folder($project->key));
    }
}
