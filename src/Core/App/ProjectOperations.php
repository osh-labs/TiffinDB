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
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Projects: the list, creating, renaming, and sharing (M1 spec section 9.4).
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
            if (!is_dir($projects)) {
                try {
                    Fs::makeDir($projects);
                } catch (\RuntimeException $error) {
                    // Another request may have made it at the same moment.
                    if (!is_dir($projects)) {
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
     * P57: every `creating` row older than an hour is removed; its folder
     * too when it holds nothing but what a creation makes (empty
     * `attachments/` and `snapshots/`, `project.tiffin` and its `-wal` and
     * `-shm`). A folder holding anything else is left in place, and a line
     * naming the project key goes to the server error log.
     */
    private function clearAbandoned(): void
    {
        $cutoff = Timestamp::format($this->c->clock->now()->modify('-' . self::ABANDONED_SECONDS . ' seconds'));
        $rows = $this->c->db()->all(
            'SELECT id, key FROM projects WHERE state = ? AND created_at < ? ORDER BY id',
            [ProjectRecord::STATE_CREATING, $cutoff],
        );
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $removed = $this->c->db()->write(function () use ($row, $cutoff): bool {
                // Checked again: the row must still be an old creation.
                $still = $this->c->db()->value(
                    'SELECT 1 FROM projects WHERE id = ? AND state = ? AND created_at < ?',
                    [(int) $row['id'], ProjectRecord::STATE_CREATING, $cutoff],
                );
                if ($still === null) {
                    return false;
                }
                $this->c->registry->remove((int) $row['id']);

                return true;
            });
            if ($removed) {
                $this->removeAbandonedFolder($key);
            }
        }
    }

    private function removeAbandonedFolder(string $key): void
    {
        $folder = $this->c->opener->folder($key);
        if (!file_exists($folder) && !is_link($folder)) {
            return;
        }
        $file = ProjectOpener::FILE;
        $dirs = [self::ATTACHMENTS, Snapshots::FOLDER];
        $files = [$file, $file . '-wal', $file . '-shm'];
        $clean = !is_link($folder) && is_dir($folder);
        $entries = $clean ? self::entries($folder) : null;
        if ($entries === null) {
            $clean = false;
        } else {
            foreach ($entries as $entry) {
                $path = $folder . '/' . $entry;
                if (in_array($entry, $dirs, true)) {
                    $clean = $clean && !is_link($path) && is_dir($path) && self::entries($path) === [];
                } elseif (in_array($entry, $files, true)) {
                    $clean = $clean && !is_link($path) && is_file($path);
                } else {
                    $clean = false;
                }
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
                if (is_dir($folder . '/' . $name)) {
                    Fs::removeDir($folder . '/' . $name);
                }
            }
            Fs::removeDir($folder);
        } catch (\RuntimeException $error) {
            error_log('TiffinDB: could not remove the folder of abandoned project creation ' . $key . ' (' . $error::class . ').');
        }
    }

    /**
     * The names in a directory, without `.` and `..`; null when it cannot
     * be read. (Fs has no listing helper yet; this reads under a local
     * error handler as Fs does.)
     *
     * @return list<string>|null
     */
    private static function entries(string $path): ?array
    {
        try {
            $entries = ErrorTrap::run(static fn (): array|false => scandir($path), 'Cannot read the directory.');
        } catch (\RuntimeException) {
            return null;
        }
        if ($entries === false) {
            return null;
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    /** @param list<Problem> $problems */
    private static function fail(array $problems): void
    {
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }
    }
}
