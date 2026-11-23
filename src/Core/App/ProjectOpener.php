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
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Opens project files and keeps each connection, by project key, for the
 * life of one Operations instance (one request; M1 spec section 9.1).
 * Makes no permission decision: callers have already authorised the
 * project they pass.
 *
 * Every connection holds a shared project lock (M7 spec section 4.2),
 * taken before the file is opened and released after it is closed. A
 * process holds at most one lock, and one open descriptor of
 * `project.lock`, per project: openExclusive and lockExclusive release
 * this opener's shared lock before they ask for the exclusive one. When
 * the file system cannot lock, opening goes on without a lock ("none"),
 * logged once per opener.
 *
 * @internal Used by the Core\App operation classes only.
 */
final class ProjectOpener
{
    public const FILE = 'project.tiffin';

    /** @var array<string, Database> */
    private array $open = [];
    /** @var array<string, ProjectLock|null> the shared lock of each open connection; null is "none" */
    private array $locks = [];
    private bool $loggedNoLocks = false;

    public function __construct(
        private readonly DataDirectory $data,
        private readonly Snapshots $snapshots,
    ) {
    }

    /**
     * The project's database, under a shared project lock; throws Busy when
     * an exclusive lock is held past the wait, and Unavailable when the file
     * is missing, not a project, newer, or another project's.
     */
    public function open(ProjectRecord $project): Database
    {
        if (!isset($this->open[$project->key])) {
            $lock = $this->sharedLock($project->key);
            try {
                $db = ProjectFile::open($this->path($project->key), $project->key, $this->snapshots);
            } catch (\Throwable $error) {
                $lock?->release();

                throw $error;
            }
            $this->open[$project->key] = $db;
            $this->locks[$project->key] = $lock;
        }

        return $this->open[$project->key];
    }

    /** Keeps a connection made by ProjectOperations::create, taking the shared lock as open() does. */
    public function adopt(string $projectKey, Database $db): void
    {
        $this->forget($projectKey);
        try {
            $lock = $this->sharedLock($projectKey);
        } catch (\Throwable $error) {
            $db->close();

            throw $error;
        }
        $this->open[$projectKey] = $db;
        $this->locks[$projectKey] = $lock;
    }

    /**
     * Whether the open connection for a key holds its shared lock (false for
     * "none", when the file system cannot lock, and when nothing is open).
     */
    public function locked(string $projectKey): bool
    {
        return isset($this->locks[$projectKey]) && $this->locks[$projectKey]->isHeld();
    }

    /**
     * For a restore only (M7 spec section 5.3 step 4): forgets this opener's
     * connection, takes the exclusive lock (Busy, LocksUnavailable), and
     * opens the file with Sqlite::open (no upgrade, no identity check).
     * The caller closes the database and releases the lock.
     *
     * @return array{0: Database, 1: ProjectLock}
     */
    public function openExclusive(ProjectRecord $project): array
    {
        $lock = $this->lockExclusive($project->key);
        try {
            $db = Sqlite::open($this->path($project->key));
        } catch (\Throwable $error) {
            $lock->release();

            throw $error;
        }

        return [$db, $lock];
    }

    /** For a deletion only (M7 spec section 6.2 step 4): forgets the connection and takes the exclusive lock (Busy, LocksUnavailable). */
    public function lockExclusive(string $projectKey): ProjectLock
    {
        $this->forget($projectKey);

        return ProjectLock::exclusive($this->folder($projectKey));
    }

    /** As lockExclusive, but null at once when another request holds the lock (finishing an interrupted deletion). */
    public function tryLockExclusive(string $projectKey): ?ProjectLock
    {
        $this->forget($projectKey);

        return ProjectLock::tryExclusive($this->folder($projectKey));
    }

    /** Closes and drops the connection for a key, if one is open, then releases its lock. */
    public function forget(string $projectKey): void
    {
        if (isset($this->open[$projectKey])) {
            $this->open[$projectKey]->close();
            unset($this->open[$projectKey]);
        }
        if (array_key_exists($projectKey, $this->locks)) {
            $this->locks[$projectKey]?->release();
            unset($this->locks[$projectKey]);
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
        foreach ($this->locks as $lock) {
            $lock?->release();
        }
        $this->locks = [];
    }

    /** The shared lock for a key; null ("none") when the file system cannot lock (logged once) or the folder is missing (not logged). */
    private function sharedLock(string $projectKey): ?ProjectLock
    {
        $folder = $this->folder($projectKey);
        $lock = ProjectLock::shared($folder);
        if ($lock === null && Fs::exists($folder) && !$this->loggedNoLocks) {
            $this->loggedNoLocks = true;
            error_log('TiffinDB: a project was opened without its lock, because this server cannot lock files there.');
        }

        return $lock;
    }
}
