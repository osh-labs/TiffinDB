<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\CoreException;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Query\AttachmentRow;
use TiffinDB\Core\Query\AttachmentSummary;
use TiffinDB\Core\Query\Attachments;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Files attached to records (M4 spec section 10.2, ADR 0018): the upload
 * page, adding (and replacing), the file page, removing, and serving.
 *
 * Authorization (security-critical): every method starts with
 * Context::project for its permission, before any key, id, or file is
 * looked at, so a Member without a grant, an unknown project, and an
 * inactive project are NotFound; a person deactivated (or changed) since
 * the session began is AccessDenied (Context::requireCurrentActor). Then:
 * the table must be a table of the
 * project, the field an attachment field of that table (the field in the
 * address must belong to the table in the address), the record live, and
 * an attachment id must be linked from that record through that field;
 * ids must be canonical digits. Anything else is NotFound.
 *
 * Uploads: refused when empty or over MAX_BYTES (from the file's size,
 * before any hashing), when the disk would keep less than the reserve,
 * and as Attachments::add refuses them. The staging folder is removed in
 * every case. Audits `attachment.added` and `attachment.removed` with ids
 * and sizes only, never a file name.
 */
final class AttachmentOperations
{
    /** The core cap per file (ADR 0018 decision 3); the web layer takes the smaller PHP limit. */
    public const MAX_BYTES = IoLimits::MAX_UPLOAD_BYTES;
    /** Free space the disk keeps after an upload (ADR 0018 decision 3). */
    public const DISK_RESERVE_BYTES = IoLimits::DISK_RESERVE_BYTES;
    /** The staged file's name inside its job folder (M4 spec section 3.3). */
    public const STAGED_NAME = 'upload.bin';
    /** Field of every upload problem. */
    private const FIELD = 'file';

    public function __construct(private readonly Context $c)
    {
    }

    /** The upload page; with $replaceId, the file it replaces (linked from this record and field, else NotFound). */
    public function uploadScreen(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, ?string $replaceId): UploadScreen
    {
        [$project, , $schema, $records, $attachments] = $this->open($actor, $projectKey, Permission::EditRecords);
        [$table, $field, $record] = self::target($schema, $records, $tableKey, $recordId, $fieldKey);
        $replacing = null;
        if ($replaceId !== null) {
            $replacing = self::linked($attachments, $field, $record, $replaceId)->summary();
        }

        return new UploadScreen(
            $project,
            $table,
            new RecordRef($record->id, $this->c->listings()->title($schema, $table, $record)),
            $field,
            $replacing,
            self::MAX_BYTES,
            ($field->options['images_only'] ?? false) === true,
            count($record->attachments[$field->key] ?? []),
        );
    }

    /**
     * Adds the uploaded file at $uploadPath (outside the data folder, as
     * PHP leaves it) to the record's attachment field; with $replaceId,
     * replaces that file in the same transaction. Returns the new file.
     *
     * @throws ValidationFailed problem.upload.missing, empty, too_large, no_space;
     *     problem.attachment.not_image, too_many, store_failed (field `file`)
     */
    public function add(
        Actor $actor,
        string $projectKey,
        string $tableKey,
        string $recordId,
        string $fieldKey,
        string $uploadPath,
        string $clientName,
        ?string $replaceId,
    ): AttachmentSummary {
        [$project, $db, $schema, $records, $attachments] = $this->open($actor, $projectKey, Permission::EditRecords);
        [$table, $field, $record] = self::target($schema, $records, $tableKey, $recordId, $fieldKey);
        $replace = null;
        if ($replaceId !== null) {
            $replace = self::linked($attachments, $field, $record, $replaceId)->id;
        }
        if ($uploadPath === '' || str_contains($uploadPath, "\0") || !Fs::isFile($uploadPath)) {
            throw self::problem('problem.upload.missing');
        }
        $free = Fs::freeBytes($this->c->opener->folder($project->key));
        if ($free !== null && $free - self::DISK_RESERVE_BYTES < Fs::size($uploadPath)) {
            throw self::problem('problem.upload.no_space');
        }

        $this->c->sweepStaging($project, $db);
        [$jobKey, $folder] = $this->c->staging()->create(StagingArea::UPLOADS);
        try {
            $staged = $folder . '/' . self::STAGED_NAME;
            try {
                Fs::moveIn($uploadPath, $staged);
                $size = Fs::size($staged);
            } catch (\RuntimeException $failed) {
                error_log('TiffinDB: an upload could not be staged (' . $failed::class . ').');

                throw self::problem('problem.upload.failed');
            }
            if ($size === 0) {
                throw self::problem('problem.upload.empty');
            }
            if ($size > self::MAX_BYTES) {
                throw self::problem('problem.upload.too_large', ['max' => self::MAX_BYTES]);
            }
            try {
                $upload = $this->c->store($project)->inspect($staged);
                $added = $attachments->add($field->key, $record->id, $upload, $clientName, $actor->userKey, $replace);
            } catch (ValidationFailed $failed) {
                throw new ValidationFailed(array_map(static fn (Problem $p): Problem => new Problem(self::FIELD, $p->key, $p->params), $failed->problems));
            } catch (CoreException $other) {
                throw $other;
            } catch (\RuntimeException $refused) {
                error_log('TiffinDB: an attachment could not be stored (' . $refused::class . ').');

                throw self::problem('problem.attachment.store_failed');
            }
        } finally {
            IoSupport::removeJob($this->c, StagingArea::UPLOADS, $jobKey);
        }
        $attachments->deleteFiles($added->freedHashes);

        $this->audit(AuditAction::ATTACHMENT_ADDED, $actor, $project, $table, $field, $record, $added->attachment);
        if ($added->replaced !== null) {
            $this->audit(AuditAction::ATTACHMENT_REMOVED, $actor, $project, $table, $field, $record, $added->replaced);
        }

        return $added->attachment;
    }

    /** The page of one file the live record links through the field (P172). */
    public function file(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, string $attachmentId): FileScreen
    {
        [$project, , $schema, $records, $attachments] = $this->open($actor, $projectKey, Permission::ViewRecords);
        [$table, $field, $record] = self::target($schema, $records, $tableKey, $recordId, $fieldKey);
        $row = self::linked($attachments, $field, $record, $attachmentId);

        return new FileScreen(
            $project,
            $table,
            new RecordRef($record->id, $this->c->listings()->title($schema, $table, $record)),
            $field,
            $row->summary(),
            $row->createdBy === null ? null : $this->c->users->byKey($row->createdBy)?->displayName,
            MediaType::isInline($row->mediaType),
            $this->c->authorizer->can($actor, Permission::EditRecords, $project),
        );
    }

    /** Removes the file from the live record's field; the stored file goes once no row names it. Returns the file removed. */
    public function remove(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, string $attachmentId): AttachmentSummary
    {
        [$project, , $schema, $records, $attachments] = $this->open($actor, $projectKey, Permission::EditRecords);
        [$table, $field, $record] = self::target($schema, $records, $tableKey, $recordId, $fieldKey);
        $id = self::id($attachmentId);
        $removed = $attachments->remove($field->key, $record->id, $id, $actor->userKey);
        $attachments->deleteFiles($removed->freedHashes);
        $this->audit(AuditAction::ATTACHMENT_REMOVED, $actor, $project, $table, $field, $record, $removed->attachment);

        return $removed->attachment;
    }

    /**
     * A file to send (ADR 0018 decision 5): NotFound unless the row
     * exists, a live record links it through an attachment field that
     * exists, and the stored file is a regular file (a missing file is
     * logged by id, never by name). Inline only for the four image types
     * and without $download; every other type is sent as
     * application/octet-stream. Never writes.
     */
    public function serve(Actor $actor, string $projectKey, string $attachmentId, bool $download): ServedFile
    {
        [$project, , , , $attachments] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $id = self::id($attachmentId);
        $row = $attachments->get($id);
        if ($row === null || !$attachments->isServable($id)) {
            throw new NotFound('No such file.');
        }
        try {
            $path = $this->c->store($project)->path($row->sha256);
        } catch (\InvalidArgumentException) {
            $path = null;
        }
        if ($path === null) {
            error_log('TiffinDB: an attachment file is missing; attachment=' . $id);

            throw new NotFound('No such file.');
        }
        $inline = MediaType::isInline($row->mediaType);

        return new ServedFile(
            $path,
            $inline ? $row->mediaType : MediaType::OTHER,
            Attachments::cleanName($row->originalName),
            Fs::size($path),
            $inline && !$download,
        );
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ProjectRecord, 1: Database, 2: Schema, 3: Records, 4: Attachments} */
    private function open(Actor $actor, string $projectKey, Permission $permission): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        // Files are the one place a stale session could carry bytes away: the person must still be who it says (T-APP-127).
        $this->c->requireCurrentActor($actor);
        $db = $this->c->opener->open($project);
        $schema = new Schema($db, $this->c->types);

        return [$project, $db, $schema, $this->c->records($project, $db, $schema), $this->c->attachments($project, $db, $schema)];
    }

    /**
     * The table, the attachment field of that table, and the live record
     * named by an address; NotFound for anything that does not fit.
     *
     * @return array{0: TableDef, 1: FieldDef, 2: RecordData}
     */
    private static function target(Schema $schema, Records $records, string $tableKey, string $recordId, string $fieldKey): array
    {
        $table = $schema->table($tableKey);
        $id = self::id($recordId);
        $field = $schema->field($fieldKey);
        if ($field->tableKey !== $table->key || $field->type !== 'attachment') {
            throw new NotFound('No such attachment field in this table.');
        }
        $record = $records->get($table->key, $id);
        if ($record === null || $record->trashedAt !== null) {
            throw new NotFound('No such live record.');
        }

        return [$table, $field, $record];
    }

    /** The attachment row the live record links through the field; NotFound otherwise. */
    private static function linked(Attachments $attachments, FieldDef $field, RecordData $record, string $attachmentId): AttachmentRow
    {
        $id = self::id($attachmentId);
        $row = $attachments->isLinked($field->key, $record->id, $id) ? $attachments->get($id) : null;
        if ($row === null) {
            throw new NotFound('The record does not link that file.');
        }

        return $row;
    }

    /** An id from an address: canonical digits, 1 to PHP_INT_MAX (P104); NotFound otherwise. */
    private static function id(string $text): int
    {
        try {
            return ListSql::recordId($text);
        } catch (\InvalidArgumentException) {
            throw new NotFound('No such id.');
        }
    }

    /** @param array<string, string|int> $params */
    private static function problem(string $key, array $params = []): ValidationFailed
    {
        return new ValidationFailed([new Problem(self::FIELD, $key, $params)]);
    }

    /** One entry with ids and the size only (ADR 0018 decision 8): never the file name or its content. */
    private function audit(string $action, Actor $actor, ProjectRecord $project, TableDef $table, FieldDef $field, RecordData $record, AttachmentSummary $file): void
    {
        $this->c->audit->record($action, $actor->userId, $project->id, 'table', $table->key, [
            'field' => $field->key,
            'record' => $record->id,
            'attachment' => $file->id,
            'size' => $file->size,
        ]);
    }
}
