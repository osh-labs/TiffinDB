<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\LocksUnavailable;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Snapshots: the list, manual snapshots (M1 spec section 9.6), and
 * restoring one (M7 spec section 5, ADR 0022 decision 2); Admin-only.
 *
 * A restore checks a working copy of the snapshot in staging, then, under
 * the project's exclusive lock, takes a `pre-restore` snapshot, closes the
 * live file, and renames the checked copy over it. Every failure before
 * that rename leaves the live file as it was.
 */
final class SnapshotOperations
{
    public const STAGING = 'restores';
    public const CANDIDATE = 'candidate.tiffin';
    public const RESTORING = 'project.tiffin.restoring';
    private const HEADER = "SQLite format 3\0";
    private const UNREADABLE = 'problem.snapshot.unreadable';

    public function __construct(private readonly Context $c)
    {
    }

    /** The snapshots page: the list, and whether this server can lock the project. */
    public function screen(Actor $actor, string $projectKey): SnapshotScreen
    {
        $project = $this->c->project($actor, Permission::ManageSnapshots, $projectKey);
        $this->c->opener->open($project);

        return new SnapshotScreen(
            $project,
            $this->c->snapshots->list($this->c->opener->folder($project->key)),
            $this->c->opener->locked($project->key),
        );
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

    /**
     * What restoring $snapshotId (a snapshot file name without `.tiffin`)
     * would do; steps 1 to 3 of section 5.3 on a working copy that is
     * removed again. Nothing is changed.
     */
    public function restoreScreen(Actor $actor, string $projectKey, string $snapshotId): RestoreScreen
    {
        $project = $this->c->project($actor, Permission::ManageSnapshots, $projectKey);
        $this->c->requireCurrentActor($actor);
        $snapshot = $this->find($project, $snapshotId);
        $db = $this->c->opener->open($project);
        $recordsNow = $this->liveRecords($project, $db);
        [$jobKey, $job] = $this->c->staging()->create(self::STAGING);
        try {
            $checked = $this->copyAndCheck($project, $snapshot, $job);
        } finally {
            $this->removeJob($jobKey);
        }

        return new RestoreScreen($project, $snapshot, $checked['version'], $recordsNow, $checked['records'], $checked['missing'], $checked['removed']);
    }

    /**
     * Restores a snapshot (M7 spec section 5.3). ValidationFailed with
     * `problem.snapshot.unreadable` for a damaged or foreign snapshot and
     * `problem.lock.unavailable` where locks do not work; Busy while others
     * are still using the project. In each of those cases nothing changed.
     */
    public function restore(Actor $actor, string $projectKey, string $snapshotId): RestoreResult
    {
        $project = $this->c->project($actor, Permission::ManageSnapshots, $projectKey);
        $this->c->requireCurrentActor($actor);
        $snapshot = $this->find($project, $snapshotId);
        $folder = $this->c->opener->folder($project->key);
        $live = $this->c->opener->path($project->key);

        // 1 to 3: copy, check, count.
        [$jobKey, $job] = $this->c->staging()->create(self::STAGING);
        try {
            $checked = $this->copyAndCheck($project, $snapshot, $job);

            // 4: exclusive, after checking that the actor is still an Admin.
            $this->c->db()->write(function () use ($actor): void {
                $this->c->seam('restore.admin');
                $this->c->requireCurrentAdmin($actor);
            });
            try {
                [$db, $lock] = $this->c->opener->openExclusive($project);
            } catch (LocksUnavailable) {
                throw new ValidationFailed([new Problem(null, 'problem.lock.unavailable')]);
            }
        } catch (\Throwable $error) {
            $this->removeJob($jobKey);

            throw $error;
        }

        $preRestore = null;
        $replaced = false;
        try {
            // 5: pre-restore snapshot, then checkpoint and close the live file.
            try {
                $preRestore = $this->c->snapshots->take($db, $folder, SnapshotReason::PreRestore);
                $this->c->recordSnapshot($project, $actor, $preRestore);
                $this->c->seam('restore.snapshot');
                $db->run('PRAGMA wal_checkpoint(TRUNCATE)');
            } finally {
                $db->close();
            }

            // 6: swap.
            $restoring = $folder . '/' . self::RESTORING;
            try {
                if (Fs::exists($restoring)) {
                    Fs::remove($restoring);
                }
                Fs::moveIn($job . '/' . self::CANDIDATE, $restoring);
                $this->c->seam('restore.swap');
                Fs::replace($restoring, $live);
                $replaced = true;
            } finally {
                if (!$replaced) {
                    self::quietlyRemove($restoring);
                }
            }
            foreach (['-wal', '-shm'] as $suffix) {
                if (Fs::exists($live . $suffix)) {
                    Fs::remove($live . $suffix);
                }
            }
        } catch (\Throwable $error) {
            $lock->release();
            $this->removeJob($jobKey);
            if ($replaced && $preRestore !== null) {
                $this->recordRestore($project, $actor, $snapshot, $preRestore, $checked);
            }

            throw $error;
        }

        if ($preRestore === null) {
            throw new \LogicException('The pre-restore snapshot is missing.');
        }

        // 7: release, then remove the job.
        $lock->release();
        $this->removeJob($jobKey);

        // 8 and 9: reopen (upgrading an older format), name and touch, sweep files, count again.
        $missing = $checked['missing'];
        try {
            $this->c->seam('restore.swapped');
            $db = $this->c->opener->open($project);
            $db->write(function (Database $db) use ($project, $actor): void {
                ProjectFile::setName($db, $project->name);
                ProjectFile::touch($db, $actor->userKey, $this->c->clock);
            });
            $this->c->seam('restore.reopen');
            $this->c->attachments($project, $db, new Schema($db, $this->c->types))->sweepFiles();
            $this->c->seam('restore.files');
            $missing = $this->missingFiles($db, $this->c->store($project));
        } catch (\Throwable $error) {
            $this->recordRestore($project, $actor, $snapshot, $preRestore, ['missing' => $missing] + $checked);

            throw $error;
        }

        // 10: audit.
        $this->recordRestore($project, $actor, $snapshot, $preRestore, ['missing' => $missing] + $checked);

        return new RestoreResult(
            $snapshot,
            $preRestore,
            $missing,
            $checked['removed'],
            $checked['version'] < ProjectFile::VERSION ? $checked['version'] : null,
        );
    }

    /** The snapshot an id names; NotFound for anything list() would not list. */
    private function find(ProjectRecord $project, string $snapshotId): SnapshotInfo
    {
        $snapshot = $this->c->snapshots->find($this->c->opener->folder($project->key), $snapshotId . '.tiffin');
        if ($snapshot === null) {
            throw new NotFound('No such snapshot.');
        }

        return $snapshot;
    }

    /**
     * Steps 1 to 3: copies the snapshot into the job folder, checks the
     * copy, and counts. ValidationFailed (problem.snapshot.unreadable) for
     * any failure of the check or the counts.
     *
     * @return array{version: int, records: int, missing: int, removed: int}
     */
    private function copyAndCheck(ProjectRecord $project, SnapshotInfo $snapshot, string $job): array
    {
        $candidate = $job . '/' . self::CANDIDATE;
        try {
            Fs::copyIn(Snapshots::path($this->c->opener->folder($project->key), $snapshot), $candidate);
        } catch (\RuntimeException) {
            throw self::unreadable();
        }
        $this->c->seam('restore.check');
        if (!self::headerIsPlain($candidate)) {
            throw self::unreadable();
        }
        $store = $this->c->store($project);
        $db = null;
        try {
            $db = Sqlite::openUntrusted($candidate);
            $version = $this->checkCandidate($db, $project->key);
            $schema = new Schema($db, $this->c->types);
            $records = 0;
            $count = $this->c->records($project, $db, $schema);
            foreach ($schema->tables() as $table) {
                $records += $count->count($table->key);
            }
            $missing = $this->missingFiles($db, $store);
            $named = $this->namedHashes($db);
        } catch (ValidationFailed $failed) {
            throw $failed;
        } catch (\Throwable) {
            throw self::unreadable();
        } finally {
            $db?->close();
        }
        $removed = 0;
        foreach ($store->storedHashes() as $hash) {
            if (!isset($named[$hash])) {
                $removed++;
            }
        }

        return ['version' => $version, 'records' => $records, 'missing' => $missing, 'removed' => $removed];
    }

    /**
     * The checks of section 5.3 step 2 after the header: application id,
     * format, version from 1 to ProjectFile::VERSION equal to meta
     * `format_version`, the project's key, and the integrity check.
     */
    private function checkCandidate(Database $db, string $projectKey): int
    {
        $applicationId = $db->value('PRAGMA application_id');
        $version = $db->value('PRAGMA user_version');
        $format = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format\'');
        $formatVersion = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\'');
        $key = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'project_key\'');
        if ($applicationId !== ProjectFile::APPLICATION_ID || $format !== ProjectFile::FORMAT || $key !== $projectKey) {
            throw self::unreadable();
        }
        if (!is_int($version) || $version < 1 || $version > ProjectFile::VERSION || $formatVersion !== (string) $version) {
            throw self::unreadable();
        }
        $integrity = $db->all('PRAGMA integrity_check(1)');
        if (count($integrity) !== 1 || array_values($integrity[0]) !== ['ok']) {
            throw self::unreadable();
        }

        return $version;
    }

    /** Whether the file starts with the SQLite header and is not in WAL mode (bytes 18 and 19 both 1). */
    private static function headerIsPlain(string $path): bool
    {
        try {
            $head = ErrorTrap::run(static fn (): string|false => file_get_contents($path, false, null, 0, 20), 'Cannot read the snapshot copy.');
        } catch (\RuntimeException) {
            return false;
        }

        return is_string($head) && strlen($head) === 20 && str_starts_with($head, self::HEADER) && $head[18] === "\x01" && $head[19] === "\x01";
    }

    /** @return array<string, true> distinct attachment hashes the file names, in lower case */
    private function namedHashes(Database $db): array
    {
        $named = [];
        foreach ($db->all('SELECT DISTINCT "sha256" FROM "_tiffin_attachments"') as $row) {
            $named[strtolower((string) $row['sha256'])] = true;
        }

        return $named;
    }

    /** Distinct hashes the file names whose file is not stored. */
    private function missingFiles(Database $db, AttachmentStore $store): int
    {
        $missing = 0;
        foreach (array_keys($this->namedHashes($db)) as $hash) {
            $hash = (string) $hash;
            if (preg_match(AttachmentStore::HASH, $hash) !== 1 || $store->path($hash) === null) {
                $missing++;
            }
        }

        return $missing;
    }

    private function liveRecords(ProjectRecord $project, Database $db): int
    {
        $schema = new Schema($db, $this->c->types);
        $records = $this->c->records($project, $db, $schema);
        $total = 0;
        foreach ($schema->tables() as $table) {
            $total += $records->count($table->key);
        }

        return $total;
    }

    /** @param array{version: int, missing: int, removed: int} $checked */
    private function recordRestore(ProjectRecord $project, Actor $actor, SnapshotInfo $snapshot, SnapshotInfo $preRestore, array $checked): void
    {
        $this->c->audit->record(AuditAction::SNAPSHOT_RESTORED, $actor->userId, $project->id, 'snapshot', $snapshot->file, [
            'pre_restore' => $preRestore->file,
            'files_missing' => $checked['missing'],
            'files_removed' => $checked['removed'],
            'from_version' => $checked['version'],
        ]);
    }

    private function removeJob(string $jobKey): void
    {
        try {
            $this->c->staging()->remove(self::STAGING, $jobKey);
        } catch (\Throwable $error) {
            error_log('TiffinDB: could not remove a restore job folder (' . $error::class . ').');
        }
    }

    private static function quietlyRemove(string $path): void
    {
        try {
            Fs::remove($path);
        } catch (\Throwable $error) {
            error_log('TiffinDB: could not remove a partly restored file (' . $error::class . ').');
        }
    }

    private static function unreadable(): ValidationFailed
    {
        return new ValidationFailed([new Problem(null, self::UNREADABLE)]);
    }
}
