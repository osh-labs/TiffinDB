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
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Importing a project archive into a new project (M3 spec sections 11.2
 * to 11.6, 13.4). Needs `project.create` (Admins only) and an active
 * Admin. The archive is checked and rebuilt in an `archives` job folder
 * whose owner file names the Admin who uploaded it; nothing is registered
 * until the rebuild has succeeded, and then as M1 project creation does.
 *
 * Name problems (empty, too long, taken) keep the checked upload so the
 * Admin can choose another name: every such Problem carries the job key
 * as its `job` parameter, for importWithName(), which is NotFound for
 * anyone but that Admin.
 */
final class ArchiveOperations
{
    public const ARCHIVES = 'archives';
    /** Problem parameter holding the kept job key of a name problem. */
    public const JOB_PARAM = 'job';
    private const UPLOAD = 'upload.bin';
    private const REBUILT = 'rebuilt';
    /** Files an earlier attempt may have left in a kept job, removed before it is checked again. */
    private const LEFTOVERS = [ArchiveReader::MANIFEST_FILE, ArchiveReader::PROJECT_FILE, ArchiveReader::PROJECT_FILE . '-wal', ArchiveReader::PROJECT_FILE . '-shm', ArchiveReader::PROJECT_FILE . '-journal'];

    /** Reason codes of ArchiveRefused to message keys (section 11.2), as whole literals. */
    private const PROBLEMS = [
        ArchiveRefused::ENTRY_NAME => 'problem.archive.entry_name',
        ArchiveRefused::DUPLICATE => 'problem.archive.duplicate',
        ArchiveRefused::ENCRYPTED => 'problem.archive.encrypted',
        ArchiveRefused::COMPRESSION => 'problem.archive.compression',
        ArchiveRefused::TOO_LARGE => 'problem.archive.too_large',
        ArchiveRefused::MISSING_ENTRY => 'problem.archive.missing_entry',
        ArchiveRefused::MANIFEST => 'problem.archive.manifest',
        ArchiveRefused::NOT_SQLITE => 'problem.archive.not_sqlite',
        ArchiveRefused::DAMAGED => 'problem.archive.damaged',
        ArchiveRefused::NOT_PROJECT => 'problem.archive.not_project',
        ArchiveRefused::NEWER => 'problem.archive.newer',
        ArchiveRefused::UNEXPECTED_OBJECT => 'problem.archive.unexpected_object',
        ArchiveRefused::BAD_METADATA => 'problem.archive.bad_metadata',
        ArchiveRefused::BAD_TABLE => 'problem.archive.bad_table',
        ArchiveRefused::REBUILD_FAILED => 'problem.archive.rebuild_failed',
    ];

    public function __construct(private readonly Context $c)
    {
    }

    public function importScreen(Actor $actor): ArchiveImportScreen
    {
        $this->authorise($actor);

        return new ArchiveImportScreen(IoLimits::MAX_UPLOAD_BYTES);
    }

    /**
     * Checks the uploaded archive, rebuilds it, and registers the new
     * project, named $name or, when null or empty, the name in the archive.
     *
     * @throws ValidationFailed `problem.upload.*` or `problem.archive.*` at `file` (nothing kept), or name problems at `name` with the kept job key
     */
    public function import(Actor $actor, string $uploadPath, string $clientName, ?string $name): ArchiveImportResult
    {
        $this->authorise($actor);
        $clientName = IoSupport::clientName($clientName);
        IoSupport::checkUpload($this->c, $uploadPath);
        $this->c->staging()->sweep();
        [$key, $folder] = $this->c->staging()->create(self::ARCHIVES);
        $lock = null;
        try {
            IoSupport::writeOwner($folder, ['user' => $actor->userId, 'file' => $clientName]);
            $lock = IoSupport::lock($folder);
            Fs::moveIn($uploadPath, $folder . '/' . self::UPLOAD);
        } catch (\Throwable $error) {
            IoSupport::unlock($lock);
            IoSupport::removeJob($this->c, self::ARCHIVES, $key);

            throw $error;
        }

        return $this->process($actor, $key, $folder, $lock, $clientName, $name);
    }

    /**
     * Imports a kept archive (one whose name was refused) under another name.
     *
     * @throws NotFound unless the job exists and was started by the actor
     */
    public function importWithName(Actor $actor, string $jobKey, string $name): ArchiveImportResult
    {
        $this->authorise($actor);
        $folder = Keys::isValid($jobKey) ? $this->c->staging()->folder(self::ARCHIVES, $jobKey) : null;
        if ($folder === null || !IoSupport::ownedBy($folder, $actor->userId, null) || !Fs::isFile($folder . '/' . self::UPLOAD)) {
            throw new NotFound('No such archive.');
        }
        $owner = IoSupport::readOwner($folder) ?? [];
        $lock = IoSupport::lock($folder);
        try {
            foreach (self::LEFTOVERS as $leftover) {
                if (Fs::exists($folder . '/' . $leftover)) {
                    Fs::remove($folder . '/' . $leftover);
                }
            }
            if (Fs::exists($folder . '/' . self::REBUILT)) {
                throw new NotFound('The archive job is not in a state to continue.');
            }
        } catch (\Throwable $error) {
            IoSupport::unlock($lock);

            throw $error;
        }
        $clientName = is_string($owner['file'] ?? null) ? IoSupport::clientName($owner['file']) : IoSupport::CLIENT_NAME_FALLBACK;

        return $this->process($actor, $jobKey, $folder, $lock, $clientName, $name);
    }

    private function authorise(Actor $actor): void
    {
        $this->c->authorizer->require($actor, Permission::CreateProject);
        $this->c->requireActiveAdmin($actor);
    }

    /**
     * Steps 11.3 to 11.6 on a job folder holding the upload; the caller
     * holds the job lock, which is released here.
     *
     * @param resource $lock
     */
    private function process(Actor $actor, string $key, string $folder, $lock, string $clientName, ?string $name): ArchiveImportResult
    {
        $reader = null;
        $checked = null;
        $keep = false;
        $project = null;
        try {
            try {
                $reader = ArchiveReader::open($folder . '/' . self::UPLOAD, $folder);
                $checked = UntrustedProject::check($reader->projectPath(), $reader->manifest->projectFormatVersion, $this->c->types);
            } catch (ArchiveRefused $refused) {
                throw self::refused($refused);
            }
            $name = Text::trim($name ?? '') === '' ? Text::trim($reader->manifest->projectName) : Text::trim($name);
            $problems = $this->nameProblems($name);
            if ($problems !== []) {
                $keep = true;

                throw self::keptName($problems, $key);
            }

            $now = Timestamp::format($this->c->clock->now());
            $project = $this->c->db()->write(function () use ($actor, $name, $now, $key): ProjectRecord {
                $this->c->seam('archive.register');
                $this->c->requireCurrentAdmin($actor);
                $problems = $this->nameProblems($name);
                if ($problems !== []) {
                    throw self::keptName($problems, $key);
                }
                $projectKey = $this->c->keys->generateUnique(fn (string $k): bool => $this->c->registry->byKey($k) !== null || Fs::exists($this->c->opener->folder($k)));

                return $this->c->registry->add($projectKey, $name, $actor->userId, $now);
            });
        } catch (ValidationFailed $failed) {
            $checked?->close();
            $reader?->close();
            if (self::isKept($failed)) {
                $this->forgetExtracted($folder);
                IoSupport::unlock($lock);

                throw $failed;
            }
            IoSupport::unlock($lock);
            IoSupport::removeJob($this->c, self::ARCHIVES, $key);

            throw $failed;
        } catch (\Throwable $error) {
            $checked?->close();
            $reader?->close();
            IoSupport::unlock($lock);
            IoSupport::removeJob($this->c, self::ARCHIVES, $key);

            throw $error;
        }

        $moved = false;
        try {
            try {
                $report = (new ProjectRebuilder($this->c->types, $this->c->clock, $this->c->keys, $this->c->appVersion))
                    ->rebuild($checked, $reader, $folder . '/' . self::REBUILT, $project->key, $name, $actor->userKey, $clientName);
            } catch (ArchiveRefused $refused) {
                throw self::refused($refused);
            } finally {
                $checked->close();
                $reader->close();
            }
            $target = $this->c->opener->folder($project->key);
            $projects = dirname($target);
            if (!Fs::isDir($projects)) {
                try {
                    Fs::makeDir($projects);
                } catch (\RuntimeException $error) {
                    if (!Fs::isDir($projects)) {
                        throw $error;
                    }
                }
            }
            Fs::moveIn($folder . '/' . self::REBUILT, $target);
            $moved = true;
            $this->c->db()->write(function () use ($project): void {
                $this->c->seam('archive.activate');
                $this->c->registry->activate($project->id);
            });
        } catch (\Throwable $error) {
            $this->undo($project, $folder, $moved);
            IoSupport::unlock($lock);
            IoSupport::removeJob($this->c, self::ARCHIVES, $key);

            throw $error;
        }
        IoSupport::unlock($lock);
        IoSupport::removeJob($this->c, self::ARCHIVES, $key);

        $this->c->audit->record(AuditAction::PROJECT_CREATED, $actor->userId, $project->id, 'project', $project->key);
        $this->c->audit->record(AuditAction::ARCHIVE_IMPORTED, $actor->userId, $project->id, 'project', $project->key, [
            'tables' => $report->tables,
            'records' => $report->records,
            'values_emptied' => $report->valuesEmptiedTotal(),
            'links_dropped' => $report->linksDropped,
            'forms_dropped' => $report->formsDropped,
            'files_missing' => $report->filesMissing,
        ]);
        $active = $this->c->registry->byKey($project->key);
        if ($active === null || !$active->isActive()) {
            throw new \LogicException('The project just imported cannot be read back.');
        }

        return new ArchiveImportResult($active, $report);
    }

    /** @return list<Problem> */
    private function nameProblems(string $name): array
    {
        $problems = Text::nameProblems($name, 'name', ProjectOperations::MAX_NAME);
        if ($problems === [] && $this->c->registry->nameTaken($name)) {
            $problems[] = new Problem('name', 'problem.project_name.taken');
        }

        return $problems;
    }

    /** @param list<Problem> $problems */
    private static function keptName(array $problems, string $jobKey): ValidationFailed
    {
        return new ValidationFailed(array_map(
            static fn (Problem $p): Problem => new Problem($p->field, $p->key, [...$p->params, self::JOB_PARAM => $jobKey]),
            $problems,
        ));
    }

    private static function isKept(ValidationFailed $failed): bool
    {
        return isset($failed->problems[0]->params[self::JOB_PARAM]);
    }

    private static function refused(ArchiveRefused $refused): ValidationFailed
    {
        error_log('TiffinDB: an archive was refused (' . $refused->reason . ($refused->rule === '' ? '' : ': ' . $refused->rule) . ').');

        return new ValidationFailed([new Problem('file', self::PROBLEMS[$refused->reason] ?? 'problem.archive.rebuild_failed')]);
    }

    /** Removes what a refused name left in a kept job, so the next attempt checks the upload from the start. */
    private function forgetExtracted(string $folder): void
    {
        try {
            foreach (self::LEFTOVERS as $leftover) {
                if (Fs::exists($folder . '/' . $leftover)) {
                    Fs::remove($folder . '/' . $leftover);
                }
            }
        } catch (\Throwable $error) {
            error_log('TiffinDB: could not tidy a kept archive job (' . $error::class . ').');
        }
    }

    /**
     * Undoes a registration whose rebuild, move, or activation failed: the
     * project folder (when it was moved) goes back into the job folder,
     * which the caller removes, and the registry row is removed.
     */
    private function undo(ProjectRecord $project, string $folder, bool $moved): void
    {
        try {
            if ($moved) {
                Fs::moveIn($this->c->opener->folder($project->key), $folder . '/' . self::REBUILT . '-undone');
            }
        } catch (\Throwable $cleanup) {
            error_log('TiffinDB: could not remove a failed archive import for project ' . $project->key . ' (' . $cleanup::class . ').');
        }
        $this->c->db()->write(function () use ($project): void {
            $this->c->registry->remove($project->id);
        });
    }
}
