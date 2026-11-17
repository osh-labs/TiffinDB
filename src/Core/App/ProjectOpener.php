<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Opens project files and keeps each connection, by project key, for the
 * life of one Operations instance (one request; M1 spec section 9.1).
 * Makes no permission decision: callers have already authorised the
 * project they pass.
 *
 * @internal Used by the Core\App operation classes only.
 */
final class ProjectOpener
{
    public const FILE = 'project.tiffin';

    /** @var array<string, Database> */
    private array $open = [];

    public function __construct(
        private readonly DataDirectory $data,
        private readonly Snapshots $snapshots,
    ) {
    }

    /** The project's database; throws Unavailable when the file is missing, not a project, newer, or another project's. */
    public function open(ProjectRecord $project): Database
    {
        if (!isset($this->open[$project->key])) {
            $this->open[$project->key] = ProjectFile::open($this->path($project->key), $project->key, $this->snapshots);
        }

        return $this->open[$project->key];
    }

    /** Keeps a connection made by ProjectOperations::create. */
    public function adopt(string $projectKey, Database $db): void
    {
        $this->forget($projectKey);
        $this->open[$projectKey] = $db;
    }

    /** Closes and drops the connection for a key, if one is open. */
    public function forget(string $projectKey): void
    {
        if (isset($this->open[$projectKey])) {
            $this->open[$projectKey]->close();
            unset($this->open[$projectKey]);
        }
    }

    public function path(string $projectKey): string
    {
        return $this->data->projectFolder($projectKey) . '/' . self::FILE;
    }

    public function folder(string $projectKey): string
    {
        return $this->data->projectFolder($projectKey);
    }

    public function closeAll(): void
    {
        foreach ($this->open as $db) {
            $db->close();
        }
        $this->open = [];
    }
}
