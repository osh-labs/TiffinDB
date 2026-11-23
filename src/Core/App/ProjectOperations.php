<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\LocksUnavailable;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Projects: the list, creating, renaming, and sharing (M1 spec section
 * 9.4), and deleting (M7 spec section 6, ADR 0022 decision 3).
 */
final class ProjectOperations
{
    public const MAX_NAME = 80;
    /** A `creating` registry row older than this is an abandoned creation (P57). */
    public const ABANDONED_SECONDS = 3600;
    public const ATTACHMENTS = 'attachments';
    /** The item name of a first table made with the project. */
    public const FIRST_TABLE_ITEM = 'item';

    public function __construct(private readonly Context $c)
    {
    }

    /**
     * Admin: every active project; Member: the granted ones; both by name.
     * Each file is opened to build the summary; one that cannot be opened
     * gives `available` false.
     *
     * @return list<ProjectSummary>
     */
    public function listFor(Actor $actor): array
    {
        $projects = $actor->role === Role::Admin ? $this->c->registry->all() : $this->c->registry->forMember($actor->userId);
        $list = [];
        foreach ($projects as $project) {
            // The registry chose these for the actor; the authorizer still has the last word.
            if (!$this->c->authorizer->can($actor, Permission::ViewProject, $project)) {
                continue;
            }
            $list[] = $this->summary($project, $actor);
        }

        return $list;
    }

    /**
     * Creates a project, optionally with a first table (M1 spec section
     * 9.4.1). Abandoned creations older than an hour are cleared first.
     * If anything after the registry row fails, everything this call
     * created is removed again and the error rethrown.
     */
    public function create(Actor $actor, string $name, ?string $firstTableName): ProjectRecord
    {
        $this->c->authorizer->require($actor, Permission::CreateProject);
        $this->clearAbandoned();

        $firstTable = $firstTableName === null ? '' : $firstTableName;
        $withTable = !Text::isUtf8($firstTable) || Text::trim($firstTable) !== '';
        $problems = $this->nameProblems($name, null);
        if ($withTable) {
            $problems = [...$problems, ...Text::nameProblems($firstTable, 'first_table', SchemaEditor::MAX_TABLE_NAME)];
        }
        self::fail($problems);
        $name = Text::trim($name);

        $now = Timestamp::format($this->c->clock->now());
        $project = $this->c->db()->write(function () use ($actor, $name, $now): ProjectRecord {
            $this->c->requireCurrentAdmin($actor);
            self::fail($this->nameProblems($name, null));
            $key = $this->c->keys->generateUnique(fn (string $key): bool => $this->c->registry->byKey($key) !== null);

            return $this->c->registry->add($key, $name, $actor->userId, $now);
        });

        $made = [];
        $db = null;
        $table = null;
        try {
            $folder = $this->c->opener->folder($project->key);
            $projects = dirname($folder);
            if (!Fs::isDir($projects)) {
                try {
                    Fs::makeDir($projects);
                } catch (\RuntimeException $error) {
                    // Another request may have made it at the same moment.
                    if (!Fs::isDir($projects)) {
                        throw $error;
                    }
                }
            }
            Fs::makeDir($folder);
            $made[] = $folder;
            Fs::makeDir($folder . '/' . self::ATTACHMENTS);
            $made[] = $folder . '/' . self::ATTACHMENTS;
            Fs::makeDir($folder . '/' . Snapshots::FOLDER);
            $made[] = $folder . '/' . Snapshots::FOLDER;
            $db = ProjectFile::create($this->c->opener->path($project->key), $project->key, $name, $actor->userKey, $this->c->appVersion, $this->c->clock);
            if ($withTable) {
                $table = $this->c->editor($project, $db, $actor)->createTable($firstTable, self::FIRST_TABLE_ITEM, true);
            }
            $this->c->db()->write(function () use ($project): void {
                $this->c->registry->activate($project->id);
            });
        } catch (\Throwable $error) {
            $db?->close();
            $this->undoCreate($project, $made, $db !== null);

            throw $error;
        }
        $this->c->opener->adopt($project->key, $db);

        $this->c->audit->record(AuditAction::PROJECT_CREATED, $actor->userId, $project->id, 'project', $project->key);
        if ($table instanceof TableDef) {
            $this->c->audit->record(AuditAction::TABLE_CREATED, $actor->userId, $project->id, 'table', $table->key);
        }
        $active = $this->c->registry->byKey($project->key);
        if ($active === null) {
            throw new \LogicException('The project just created cannot be read back.');
        }

        return $active;
    }

    public function get(Actor $actor, string $projectKey): ProjectRecord
    {
        return $this->c->project($actor, Permission::ViewProject, $projectKey);
    }

    /**
     * Distinct stored files of the project and their total size, for the
     * settings page (M4 spec section 10.3, ADR 0018 decision 3).
     */
    public function attachmentTotals(Actor $actor, string $projectKey): AttachmentTotals
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        $db = $this->c->opener->open($project);
        $totals = $this->c->attachments($project, $db, new Schema($db, $this->c->types))->totals();

        return new AttachmentTotals($totals['files'], $totals['bytes']);
    }

    /** Renames a project: the file's meta first, then the registry. */
    public function rename(Actor $actor, string $projectKey, string $name): ProjectRecord
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        self::fail($this->nameProblems($name, $project->id));
        $name = Text::trim($name);

        $db = $this->c->opener->open($project);
        $db->write(function (Database $db) use ($name, $actor): void {
            ProjectFile::setName($db, $name);
            ProjectFile::touch($db, $actor->userKey, $this->c->clock);
        });
        $this->c->db()->write(function () use ($project, $name): void {
            self::fail($this->nameProblems($name, $project->id));
            $this->c->registry->rename($project->id, $name);
        });
        $this->c->audit->record(AuditAction::PROJECT_RENAMED, $actor->userId, $project->id, 'project', $project->key);

        return $this->c->project($actor, Permission::ManageProject, $projectKey);
    }

    /**
     * Every Member, active or not, by display name, with whether they hold
     * a grant on the project.
     *
     * @return list<SharingRow>
     */
    public function sharing(Actor $actor, string $projectKey): array
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        $granted = array_flip($this->c->grants->usersFor($project->id));
        $rows = [];
        foreach ($this->c->users->all() as $person) {
            if ($person->role === Role::Member) {
                $rows[] = new SharingRow($person, isset($granted[$person->id]));
            }
        }

        return $rows;
    }

    /**
     * Sets the grants of active Members to exactly $memberKeys. Admin keys
     * and unknown keys are ignored; grants held by anyone else (inactive
     * Members, and people who are not Members) are left as they are.
     *
     * @param list<string> $memberKeys
     */
    public function setSharing(Actor $actor, string $projectKey, array $memberKeys): void
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        $wantedKeys = [];
        foreach ($memberKeys as $key) {
            if (is_string($key)) {
                $wantedKeys[$key] = true;
            }
        }

        [$added, $removed] = $this->c->db()->write(function () use ($actor, $project, $wantedKeys): array {
            $this->c->requireCurrentAdmin($actor);
            $current = array_flip($this->c->grants->usersFor($project->id));
            $wanted = [];
            foreach ($this->c->users->all() as $person) {
                $activeMember = $person->role === Role::Member && $person->active;
                if ($activeMember ? isset($wantedKeys[$person->key]) : isset($current[$person->id])) {
                    $wanted[] = $person->id;
                }
            }

            return $this->c->grants->setUsersFor($project->id, $wanted, $actor->userId);
        });
        $this->c->audit->record(AuditAction::PROJECT_SHARING_CHANGED, $actor->userId, $project->id, 'project', $project->key, [
            'added' => $added,
            'removed' => $removed,
        ]);
    }

    private function summary(ProjectRecord $project, Actor $actor): ProjectSummary
    {
        $members = $this->c->grants->countMembersFor($project->id);
        try {
            $db = $this->c->opener->open($project);
            $schema = new Schema($db, $this->c->types);
        } catch (Unavailable) {
            return new ProjectSummary($project, false, [], 0, null, null, $members);
        }
        $editor = $this->c->editor($project, $db, $actor);
        $names = [];
        $records = 0;
        foreach ($schema->tables() as $table) {
            $names[] = $table->name;
            $records += $editor->recordCount($table->key, false);
        }
        $updatedBy = $schema->meta('updated_by');
        $person = $updatedBy === null ? null : $this->c->users->byKey($updatedBy);

        return new ProjectSummary($project, true, $names, $records, $schema->meta('updated_at'), $person?->displayName, $members);
    }

    /**
     * What deleting the project would remove (M7 spec section 6.2). When
     * nothing at all is at the project folder's path the project is not
     * opened: every count is 0 and folderMissing is true (P239). A file
     * that cannot be opened counts no records and no files.
     */
    public function deleteScreen(Actor $actor, string $projectKey): ProjectDeleteScreen
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        $this->c->requireCurrentActor($actor);
        $members = $this->grantedMembers($project);
        $folder = $this->c->opener->folder($project->key);
        if (!Fs::exists($folder)) {
            return new ProjectDeleteScreen($project, 0, 0, 0, 0, 0, $members, true);
        }
        $counts = $this->deletionCounts($project, $actor, true);

        return new ProjectDeleteScreen($project, $counts['records'], $counts['trashed'], $counts['files'], $counts['bytes'], $counts['snapshots'], $members, false);
    }

    /**
     * Deletes a project (M7 spec section 6.2): the typed name must be the
     * project's name (ignoring ASCII case and surrounding white space);
     * ConfirmationRequired when it holds more records than $expected. The
     * folder is moved to `staging/deleted/<key>` under the exclusive lock
     * and removed there; the registry row and its grants go.
     */
    public function delete(Actor $actor, string $projectKey, string $typedName, int $expected): ProjectDeleted
    {
        $project = $this->c->project($actor, Permission::ManageProject, $projectKey);
        $this->c->requireCurrentActor($actor);

        // 1. Name.
        $typed = Text::isUtf8($typedName) ? Text::trim($typedName) : '';
        if ($typed === '' || strcasecmp($typed, Text::trim($project->name)) !== 0) {
            throw new ValidationFailed([new Problem('confirm-name', 'problem.project.delete_name')]);
        }

        // 2. Counts (none for a missing folder, P239).
        $folder = $this->c->opener->folder($project->key);
        $counts = ['records' => 0, 'trashed' => 0, 'files' => 0, 'bytes' => 0, 'snapshots' => 0];
        if (Fs::exists($folder)) {
            $counts = $this->deletionCounts($project, $actor, false);
            if ($counts['records'] + $counts['trashed'] > $expected) {
                throw new ConfirmationRequired($counts['records'] + $counts['trashed'], 0);
            }
        }

        // 3. Mark: from here on no operation finds the project.
        $missing = $this->c->db()->write(function () use ($actor, $project, $folder): bool {
            $this->c->seam('delete.mark');
            $this->c->requireCurrentAdmin($actor);
            $current = $this->c->registry->byKey($project->key);
            if ($current === null || !$current->isActive()) {
                throw new NotFound('No such project.');
            }
            $this->c->registry->setState($project->id, ProjectRecord::STATE_DELETING);

            return !Fs::exists($folder);
        });
        $this->c->seam('delete.marked');

        if ($missing) {
            // P239: nothing to lock, move, or remove.
            $this->forgetRow($project);
            $counts = ['records' => 0, 'trashed' => 0, 'files' => 0, 'bytes' => 0, 'snapshots' => 0];

            return $this->recordDeletion($project, $actor, $counts);
        }

        // 4. Lock (the folder must be a real directory first: a link is never locked through).
        if (!Fs::isDir($folder)) {
            $this->setActive($project);

            throw self::deleteFailed();
        }
        try {
            $lock = $this->c->opener->lockExclusive($project->key);
        } catch (Busy $busy) {
            $this->setActive($project);

            throw $busy;
        } catch (LocksUnavailable) {
            $this->setActive($project);

            throw new ValidationFailed([new Problem(null, 'problem.lock.unavailable')]);
        }

        try {
            // An interrupted-deletion sweep may have finished it while this request waited for the lock.
            if ($this->c->registry->byKey($project->key) === null) {
                return $this->recordDeletion($project, $actor, $counts);
            }

            // 5. Move.
            try {
                if (!Fs::isDir($folder)) {
                    throw new \RuntimeException('The project folder is not a directory.');
                }
                $target = $this->c->staging()->reserve(StagingArea::DELETED, $project->key);
                $this->c->seam('delete.move');
                Fs::moveIn($folder, $target);
            } catch (\RuntimeException) {
                $this->setActive($project);

                throw self::deleteFailed();
            }
            $this->c->seam('delete.moved');

            // 6. Forget.
            $this->forgetRow($project);

            // 7. Remove, still holding the lock; the staging sweep finishes what fails here.
            try {
                Fs::removeTree($target, $this->c->staging()->root());
            } catch (\Throwable $error) {
                error_log('TiffinDB: a deleted project folder could not be removed yet; the staging sweep will retry (' . $error::class . ').');
            }
        } finally {
            // 8. Release.
            $lock->release();
        }

        // 9. Audit.
        return $this->recordDeletion($project, $actor, $counts);
    }

    /**
     * @return array{records: int, trashed: int, files: int, bytes: int, snapshots: int}
     */
    private function deletionCounts(ProjectRecord $project, Actor $actor, bool $withBytes): array
    {
        $records = 0;
        $all = 0;
        $files = 0;
        $bytes = 0;
        if (!Fs::isDir($this->c->opener->folder($project->key))) {
            // A link or a file at the folder's path: never opened through; the deletion refuses it.
            return ['records' => 0, 'trashed' => 0, 'files' => 0, 'bytes' => 0, 'snapshots' => 0];
        }
        try {
            $db = $this->c->opener->open($project);
            $schema = new Schema($db, $this->c->types);
            $editor = $this->c->editor($project, $db, $actor);
            foreach ($schema->tables() as $table) {
                $records += $editor->recordCount($table->key, false);
                $all += $editor->recordCount($table->key, true);
            }
            $totals = $this->c->attachments($project, $db, $schema)->totals();
            $files = $totals['files'];
            $bytes = $withBytes ? $totals['bytes'] : 0;
        } catch (Unavailable) {
            // A file that cannot be opened (damaged, missing) can still be deleted.
        }

        return [
            'records' => $records,
            'trashed' => $all - $records,
            'files' => $files,
            'bytes' => $bytes,
            'snapshots' => count($this->c->snapshots->list($this->c->opener->folder($project->key))),
        ];
    }

    /** Granted Members, active or not. */
    private function grantedMembers(ProjectRecord $project): int
    {
        $granted = array_flip($this->c->grants->usersFor($project->id));
        $members = 0;
        foreach ($this->c->users->all() as $person) {
            if ($person->role === Role::Member && isset($granted[$person->id])) {
                $members++;
            }
        }

        return $members;
    }

    private function setActive(ProjectRecord $project): void
    {
        $this->c->db()->write(function () use ($project): void {
            if ($this->c->registry->byKey($project->key)?->state === ProjectRecord::STATE_DELETING) {
                $this->c->registry->setState($project->id, ProjectRecord::STATE_ACTIVE);
            }
        });
    }

    private function forgetRow(ProjectRecord $project): void
    {
        $this->c->db()->write(function () use ($project): void {
            $this->c->registry->remove($project->id);
        });
    }

    /** @param array{records: int, trashed: int, files: int, snapshots: int} $counts */
    private function recordDeletion(ProjectRecord $project, Actor $actor, array $counts): ProjectDeleted
    {
        $this->c->audit->record(AuditAction::PROJECT_DELETED, $actor->userId, null, 'project', $project->key, [
            'name' => $project->name,
            'records' => $counts['records'],
            'trashed' => $counts['trashed'],
            'files' => $counts['files'],
            'snapshots' => $counts['snapshots'],
        ]);

        return new ProjectDeleted($project->name, $counts['records'], $counts['trashed'], $counts['files'], $counts['snapshots']);
    }

    private static function deleteFailed(): ValidationFailed
    {
        return new ValidationFailed([new Problem(null, 'problem.project.delete_failed')]);
    }

    /** @return list<Problem> */
    private function nameProblems(string $name, ?int $exceptId): array
    {
        $problems = Text::nameProblems($name, 'name', self::MAX_NAME);
        if ($problems === [] && $this->c->registry->nameTaken(Text::trim($name), $exceptId)) {
            $problems[] = new Problem('name', 'problem.project_name.taken');
        }

        return $problems;
    }

    /**
     * Removes what a failed create made, newest first, and the registry
     * row. Only paths this call created are touched.
     *
     * @param list<string> $made folders, in the order they were made
     */
    private function undoCreate(ProjectRecord $project, array $made, bool $fileMade): void
    {
        try {
            $path = $this->c->opener->path($project->key);
            if ($fileMade) {
                foreach (['-wal', '-shm', ''] as $suffix) {
                    Fs::remove($path . $suffix);
                }
            }
            Fs::remove($this->c->opener->folder($project->key) . '/' . ProjectLock::FILE);
            foreach (array_reverse($made) as $folder) {
                Fs::removeDir($folder);
            }
        } catch (\Throwable $cleanup) {
            error_log('TiffinDB: could not remove a failed project creation for project ' . $project->key . ' (' . $cleanup::class . ').');
        }
        $this->c->db()->write(function () use ($project): void {
            $this->c->registry->remove($project->id);
        });
    }

    /**
     * Step 0 of create (P57, T-CF-4), and of every staging sweep (P238,
     * Context::sweepStaging): every `creating` row older than an hour is
     * removed; its folder too when it holds nothing but what a creation
     * makes (empty `attachments/` and `snapshots/`, `project.tiffin` and its
     * `-wal` and `-shm`, `project.lock`). A folder holding anything else is
     * left in place, and a line naming the project key goes to the server
     * error log. Every `deleting` row, whatever its age, is finished (M7
     * spec section 6.2): its folder, when still a directory, is moved to
     * `staging/deleted/` under the exclusive lock (skipped, and retried
     * next time, when the lock cannot be had at once), the row is removed,
     * and the folder moved here removed; one the interrupted request had
     * already moved is removed by StagingArea::sweep. A failure on one row is logged without
     * a key or path and never stops the others. File-system access goes
     * through Fs and registry access through ProjectRegistry (P78); neither
     * follows symbolic links. Writes no audit row.
     *
     * @internal Called by create and Context::sweepStaging only.
     */
    public function clearAbandoned(): void
    {
        $cutoff = Timestamp::format($this->c->clock->now()->modify('-' . self::ABANDONED_SECONDS . ' seconds'));
        foreach ($this->c->registry->abandoned($cutoff) as $project) {
            $removed = $this->c->db()->write(function () use ($project, $cutoff): bool {
                // Checked again: the row must still be an old creation.
                foreach ($this->c->registry->abandoned($cutoff) as $still) {
                    if ($still->id === $project->id) {
                        $this->c->registry->remove($project->id);

                        return true;
                    }
                }

                return false;
            });
            if ($removed) {
                $this->removeAbandonedFolder($project->key);
            }
        }
        foreach ($this->c->registry->deleting() as $project) {
            try {
                $this->finishDeletion($project);
            } catch (\Throwable $error) {
                error_log('TiffinDB: an interrupted project deletion could not be finished yet (' . $error::class . ').');
            }
        }
    }

    /** One `deleting` row of clearAbandoned. */
    private function finishDeletion(ProjectRecord $project): void
    {
        $folder = $this->c->opener->folder($project->key);
        $lock = null;
        try {
            if (Fs::isDir($folder)) {
                $lock = $this->c->opener->tryLockExclusive($project->key);
                if ($lock === null) {
                    return;
                }
            }
            $target = null;
            $still = $this->c->registry->byKey($project->key);
            if ($still === null || $still->state !== ProjectRecord::STATE_DELETING) {
                return;
            }
            if (Fs::isDir($folder)) {
                $target = $this->c->staging()->reserve(StagingArea::DELETED, $project->key);
                Fs::moveIn($folder, $target);
            }
            $this->c->db()->write(function () use ($project): void {
                if ($this->c->registry->byKey($project->key)?->state === ProjectRecord::STATE_DELETING) {
                    $this->c->registry->remove($project->id);
                }
            });
            // A folder moved by the interrupted request is left to StagingArea::sweep, which checks its lock.
            if ($target !== null) {
                Fs::removeTree($target, $this->c->staging()->root());
            }
        } finally {
            $lock?->release();
        }
    }

    private function removeAbandonedFolder(string $key): void
    {
        $folder = $this->c->opener->folder($key);
        $file = ProjectOpener::FILE;
        $dirs = [self::ATTACHMENTS, Snapshots::FOLDER];
        $files = [$file, $file . '-wal', $file . '-shm', ProjectLock::FILE];
        if (!Fs::exists($folder)) {
            return;
        }
        // Fs::isDir is false for a symbolic link, so a linked folder is never entered.
        $entries = Fs::isDir($folder) ? self::entries($folder) : null;
        $clean = $entries !== null;
        foreach ($entries ?? [] as $entry) {
            $path = $folder . '/' . $entry;
            if (in_array($entry, $dirs, true)) {
                $clean = $clean && Fs::isDir($path) && self::entries($path) === [];
            } elseif (in_array($entry, $files, true)) {
                $clean = $clean && Fs::isFile($path);
            } else {
                $clean = false;
            }
        }
        if (!$clean) {
            error_log('TiffinDB: abandoned project creation ' . $key . ' left its folder in place, since it holds other files.');

            return;
        }
        try {
            foreach (array_reverse($files) as $name) {
                Fs::remove($folder . '/' . $name);
            }
            foreach ($dirs as $name) {
                if (Fs::isDir($folder . '/' . $name)) {
                    Fs::removeDir($folder . '/' . $name);
                }
            }
            Fs::removeDir($folder);
        } catch (\RuntimeException $error) {
            error_log('TiffinDB: could not remove the folder of abandoned project creation ' . $key . ' (' . $error::class . ').');
        }
    }

    /**
     * The names in a directory (Fs::entries); null when it cannot be listed
     * (missing, a symbolic link, not a directory, or unreadable).
     *
     * @return list<string>|null
     */
    private static function entries(string $path): ?array
    {
        try {
            return Fs::entries($path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @param list<Problem> $problems */
    private static function fail(array $problems): void
    {
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }
    }
}
