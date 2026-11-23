<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\Storage\StoredUpload;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Attachment rows and links of one project (M4 spec section 4.2, ADR 0018
 * decisions 4 and 6). Takes no actor: Core\App checks permissions first.
 * Files are placed and deleted only through the AttachmentStore, and only
 * inside this project's write, so SQLite's write lock orders every
 * placement against every deletion.
 *
 * Order of operations (security-critical):
 * - adding: the file is placed before its row is inserted, in the same
 *   write; a failure after placement rolls the rows back and leaves only
 *   an orphan file (no row names it), never a row without its file;
 * - removing: the link and, when unlinked, the row go in one write; the
 *   file goes later, in deleteFiles' own write, only once no row names its
 *   hash. A failure in between leaves an orphan file for sweep().
 *
 * Every key argument is checked like Records: an unknown field key, or a
 * field that is not an attachment field, is NotFound. Record and
 * attachment ids must be positive (\InvalidArgumentException).
 *
 * SQL: names come only from Identifier and definition objects; every value
 * is bound. Joins of the link table with a user table are CROSS JOINs with
 * the link row first, found by its key (as Records, M3-WP1c).
 */
final class Attachments
{
    public const MAX_PER_FIELD = 100;
    /** Longest cleaned original name, in code points (ADR 0018 decision 2). */
    public const MAX_NAME_LENGTH = 255;
    /** Cc, Cf, Zl, and Zp, all of them (P61; zero-width joiners go too in a file name). */
    private const NAME_CONTROL = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /** Longest extension cleanName() keeps when it cuts a name (P187), in code points. */
    public const MAX_EXTENSION_LENGTH = 16;

    /** Stem and extension: the last `.`, followed by 1 to 16 letters or digits to the end. */
    private const NAME_EXTENSION = '/^(.*)\.([\p{L}\p{N}]{1,16})\z/su';

    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        private readonly AttachmentStore $store,
        private readonly Clock $clock,
    ) {
    }

    // ------------------------------------------------------------------ reading

    public function get(int $id): ?AttachmentRow
    {
        self::id($id);
        $row = $this->db->one(
            'SELECT "id", "sha256", "size", "media_type", "original_name", "created_at", "created_by" FROM "_tiffin_attachments" WHERE "id" = ?',
            [$id],
        );

        return $row === null ? null : self::row($row);
    }

    /** Whether the record (live) links the attachment through the attachment field. */
    public function isLinked(string $fieldKey, int $recordId, int $attachmentId): bool
    {
        self::id($recordId);
        self::id($attachmentId);
        $field = $this->attachmentField($fieldKey);

        return $this->linkedFromLive($this->schema->table($field->tableKey), $field->key, $recordId, $attachmentId);
    }

    /**
     * Whether a live record links the attachment through an attachment
     * field that exists (ADR 0018 decision 5): one query per attachment
     * field of the schema, true at the first hit.
     */
    public function isServable(int $attachmentId): bool
    {
        self::id($attachmentId);
        foreach ($this->schema->tables() as $candidate) {
            foreach ($this->schema->fields($candidate->key) as $field) {
                if ($field->type === 'attachment' && $this->servableThrough($this->schema->table($field->tableKey), $field->key, $attachmentId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{files: int, bytes: int} distinct stored contents (by sha256) and their total size */
    public function totals(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS "files", COALESCE(SUM("size"), 0) AS "bytes" FROM (SELECT "sha256", MAX("size") AS "size" FROM "_tiffin_attachments" GROUP BY "sha256")',
        );

        return ['files' => (int) ($row['files'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    // ------------------------------------------------------------------ adding and removing

    /**
     * Adds a staged upload to a live record's attachment field, in one
     * write (M4 spec section 4.2.1): checks, places the file, inserts the
     * row and the link at the end, removes the link to $replaceId (and the
     * row when nothing else links it), and touches the record and the
     * project meta. The caller removes the staged folder in every case and
     * passes freedHashes to deleteFiles() after this returns.
     *
     * @throws ValidationFailed problem.attachment.not_image, problem.attachment.too_many (field = $fieldKey)
     * @throws NotFound for an absent or trashed record, or a $replaceId this record and field do not link
     * @throws \RuntimeException when the store refuses the file (nothing is written)
     */
    public function add(string $fieldKey, int $recordId, StoredUpload $upload, string $originalName, string $userKey, ?int $replaceId): AttachmentAdded
    {
        self::id($recordId);
        if ($replaceId !== null) {
            self::id($replaceId);
        }
        self::person($userKey);
        $field = $this->attachmentField($fieldKey);
        $table = $this->schema->table($field->tableKey);
        $name = self::cleanName($originalName);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($field, $table, $recordId, $upload, $name, $userKey, $replaceId, $now): AttachmentAdded {
            $this->requireLive($table, $recordId);
            $replaced = null;
            if ($replaceId !== null) {
                if (!$this->linkedFromLive($table, $field->key, $recordId, $replaceId)) {
                    throw new NotFound('The record does not link that attachment.');
                }
                $replaced = $this->get($replaceId);
                if ($replaced === null) {
                    throw new \LogicException('A link names an attachment row that is not there.');
                }
            }
            $problems = [];
            if (($field->options['images_only'] ?? false) === true && !MediaType::isInline($upload->mediaType)) {
                $problems[] = new Problem($field->key, 'problem.attachment.not_image');
            }
            if ($replaceId === null && $this->fileCount($field->key, $recordId) >= self::MAX_PER_FIELD) {
                $problems[] = new Problem($field->key, 'problem.attachment.too_many', ['item' => $table->itemName, 'max' => self::MAX_PER_FIELD]);
            }
            if ($problems !== []) {
                throw new ValidationFailed($problems);
            }

            $this->place($db, $upload);
            $db->run(
                'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES (?, ?, ?, ?, ?, ?)',
                [$upload->sha256, $upload->size, $upload->mediaType, $name, $now, $userKey],
            );
            $id = $db->lastInsertId();
            $position = $db->value('SELECT COALESCE(MAX("position"), 0) + 1 FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" = ?', [$field->key, $recordId]);
            $db->run(
                'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
                [$field->key, $recordId, $id, (int) $position],
            );
            $freed = [];
            if ($replaced !== null) {
                $hash = $this->unlink($field->key, $recordId, $replaced);
                if ($hash !== null && (int) $db->value('SELECT COUNT(*) FROM "_tiffin_attachments" WHERE "sha256" = ?', [$hash]) === 0) {
                    $freed[] = $hash;
                }
            }
            $this->touchRecord($table, $recordId, $userKey, $now);

            return new AttachmentAdded(
                new AttachmentSummary($id, $name, $upload->mediaType, $upload->size, $now, $userKey, $upload->sha256),
                $replaced?->summary(),
                $freed,
            );
        });
    }

    /**
     * Removes the record's link to the attachment through the field, in one
     * write (M4 spec section 4.2.2); the row goes too when nothing else
     * links it, and its hash is reported. The caller passes freedHashes to
     * deleteFiles() after this returns.
     *
     * @throws NotFound for an absent or trashed record, or a link that is not there
     */
    public function remove(string $fieldKey, int $recordId, int $attachmentId, string $userKey): AttachmentRemoved
    {
        self::id($recordId);
        self::id($attachmentId);
        self::person($userKey);
        $field = $this->attachmentField($fieldKey);
        $table = $this->schema->table($field->tableKey);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function () use ($field, $table, $recordId, $attachmentId, $userKey, $now): AttachmentRemoved {
            $this->requireLive($table, $recordId);
            if (!$this->linkedFromLive($table, $field->key, $recordId, $attachmentId)) {
                throw new NotFound('The record does not link that attachment.');
            }
            $row = $this->get($attachmentId);
            if ($row === null) {
                throw new \LogicException('A link names an attachment row that is not there.');
            }
            $hash = $this->unlink($field->key, $recordId, $row);
            $this->touchRecord($table, $recordId, $userKey, $now);

            return new AttachmentRemoved($row->summary(), $hash === null ? [] : [$hash]);
        });
    }

    // ------------------------------------------------------------------ file clean-up

    /**
     * In its own write: deletes each stored file whose hash no row names
     * any more (M4 spec section 4.2.3); returns how many files it deleted.
     * Values that are not valid hashes are skipped. A file that cannot be
     * deleted is logged (no hash or name) and left for the next sweep.
     *
     * @param array<mixed> $hashes
     */
    public function deleteFiles(array $hashes): int
    {
        $valid = [];
        foreach ($hashes as $hash) {
            if (is_string($hash) && preg_match(AttachmentStore::HASH, $hash) === 1) {
                $valid[$hash] = $hash;
            }
        }
        if ($valid === []) {
            return 0;
        }

        return $this->db->write(function (Database $db) use ($valid): int {
            $deleted = 0;
            foreach ($valid as $hash) {
                if ((int) $db->value('SELECT COUNT(*) FROM "_tiffin_attachments" WHERE lower("sha256") = ?', [$hash]) === 0) {
                    $deleted += $this->deleteFile($db, $hash);
                }
            }

            return $deleted;
        });
    }

    /**
     * The full sweep (ADR 0018 decision 6): write 1 deletes the rows that
     * nothing links to; write 2 deletes every stored file that no row
     * names (sweepFiles). Returns the files deleted.
     */
    public function sweep(): int
    {
        $this->db->write(static function (Database $db): void {
            $db->run('DELETE FROM "_tiffin_attachments" WHERE "id" NOT IN (SELECT "attachment_id" FROM "_tiffin_attachment_links")');
        });

        return $this->sweepFiles();
    }

    /**
     * The orphan-file part of the sweep, in one write: every stored file
     * whose hash no row names is deleted (stray entries that are not
     * stored files are left alone by the store). The list of stored hashes
     * is read inside the write, so no placement can interleave. Row hashes
     * are compared in lower case, so a row naming a hash in another case
     * still keeps its file (P180 item 3). Also run with the staging sweep
     * (P175). Returns the files deleted.
     */
    public function sweepFiles(): int
    {
        return $this->db->write(function (Database $db): int {
            $named = [];
            foreach ($db->all('SELECT DISTINCT "sha256" FROM "_tiffin_attachments"') as $row) {
                // Compared in lower case (P180 item 3): a row naming the hash in another case still keeps its file.
                $named[strtolower((string) $row['sha256'])] = true;
            }
            $deleted = 0;
            foreach ($this->store->storedHashes() as $hash) {
                if (!isset($named[$hash])) {
                    $deleted += $this->deleteFile($db, $hash);
                }
            }

            return $deleted;
        });
    }

    /**
     * A file name kept as data only (ADR 0018 decision 2): invalid UTF-8
     * gives `file`; Unicode categories Cc, Cf, Zl, and Zp are removed (so
     * bidirectional and zero-width characters go too); `/` and `\` become
     * `_`; trimmed; cut to MAX_NAME_LENGTH code points; `file` when empty.
     *
     * The cut keeps the extension (P187, M7 CF7-3): when the name is too
     * long and its last `.` is followed by 1 to MAX_EXTENSION_LENGTH
     * letters or digits, the stem is cut to make room and the result is
     * the trimmed stem, `.`, and the extension. Otherwise the whole name
     * is cut and trimmed.
     */
    public static function cleanName(string $name): string
    {
        if (!Text::isUtf8($name)) {
            return 'file';
        }
        $name = (string) preg_replace(self::NAME_CONTROL, '', $name);
        $name = (string) preg_replace('#[/\\\\]#', '_', $name);
        $name = Text::trim($name);
        if (Text::length($name) > self::MAX_NAME_LENGTH) {
            if (preg_match(self::NAME_EXTENSION, $name, $parts) === 1) {
                $keep = self::MAX_NAME_LENGTH - 1 - Text::length($parts[2]);
                preg_match('/^.{0,' . $keep . '}/su', $parts[1], $match);
                $name = Text::trim($match[0] ?? '') . '.' . $parts[2];
            } else {
                preg_match('/^.{' . self::MAX_NAME_LENGTH . '}/su', $name, $match);
                $name = Text::trim($match[0] ?? '');
            }
        }

        return $name === '' ? 'file' : $name;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Places the upload (section 4.2.1 step 4). When the store refuses
     * because a regular file of another size is at the target and no row
     * has that hash, the file is an orphan (a copy cut short by a crash):
     * it is deleted and the placement tried once more. Any other refusal
     * is rethrown, rolling the write back.
     */
    private function place(Database $db, StoredUpload $upload): void
    {
        try {
            $this->store->place($db, $upload);

            return;
        } catch (\RuntimeException $refused) {
            $existing = $this->store->path($upload->sha256);
            if ($existing === null || Fs::size($existing) === $upload->size
                || (int) $db->value('SELECT COUNT(*) FROM "_tiffin_attachments" WHERE "sha256" = ?', [$upload->sha256]) !== 0) {
                throw $refused;
            }
        }
        $this->store->delete($db, $upload->sha256);
        $this->store->place($db, $upload);
    }

    /**
     * Deletes the link (field, record, attachment); when no link to the
     * row remains, deletes the row and returns its hash, else null.
     */
    private function unlink(string $fieldKey, int $recordId, AttachmentRow $row): ?string
    {
        $this->db->run(
            'DELETE FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" = ? AND "attachment_id" = ?',
            [$fieldKey, $recordId, $row->id],
        );
        $links = $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachment_links" WHERE "attachment_id" = ?', [$row->id]);
        if ((int) $links !== 0) {
            return null;
        }
        $this->db->run('DELETE FROM "_tiffin_attachments" WHERE "id" = ?', [$row->id]);

        return $row->sha256;
    }

    /**
     * Deletes one stored file inside the caller's write; 1 when a stored
     * file was deleted, 0 when none was there or it could not be deleted
     * (logged without the hash; the next sweep tries again).
     */
    private function deleteFile(Database $db, string $hash): int
    {
        try {
            $present = $this->store->path($hash) !== null;
            $this->store->delete($db, $hash);

            return $present ? 1 : 0;
        } catch (\RuntimeException) {
            error_log('TiffinDB: an attachment file could not be deleted.');

            return 0;
        }
    }

    private function fileCount(string $fieldKey, int $recordId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" = ?', [$fieldKey, $recordId]);
    }

    /** Whether the live record links the attachment through the field (link row by its key first). */
    private function linkedFromLive(TableDef $table, string $fieldKey, int $recordId, int $attachmentId): bool
    {
        $hit = $this->db->value(
            'SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN ' . $table->sql() . ' AS "r" ON "r"."id" = "l"."record_id"'
            . ' WHERE "l"."field_key" = ? AND "l"."record_id" = ? AND "l"."attachment_id" = ? AND "r"."_trashed_at" IS NULL LIMIT 1',
            [$fieldKey, $recordId, $attachmentId],
        );

        return $hit !== null;
    }

    /** Whether a live record of the table links the attachment through the field (section 4.2.3). */
    private function servableThrough(TableDef $table, string $fieldKey, int $attachmentId): bool
    {
        $hit = $this->db->value(
            'SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN ' . $table->sql() . ' AS "r" ON "r"."id" = "l"."record_id"'
            . ' WHERE "l"."field_key" = ? AND "l"."attachment_id" = ? AND "r"."_trashed_at" IS NULL LIMIT 1',
            [$fieldKey, $attachmentId],
        );

        return $hit !== null;
    }

    private function requireLive(TableDef $table, int $recordId): void
    {
        $live = $this->db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "id" = ? AND "_trashed_at" IS NULL', [$recordId]);
        if ((int) $live !== 1) {
            throw new NotFound('No such live record.');
        }
    }

    /** An upload or a removal is a change to the record (ADR 0018 decision 4, P106). */
    private function touchRecord(TableDef $table, int $recordId, string $userKey, string $now): void
    {
        $this->db->run('UPDATE ' . $table->sql() . ' SET "_updated_at" = ?, "_updated_by" = ? WHERE "id" = ?', [$now, $userKey, $recordId]);
        ProjectFile::touch($this->db, $userKey, $this->clock);
    }

    /** The attachment field with this key; NotFound for an unknown key or a field of another type. */
    private function attachmentField(string $fieldKey): FieldDef
    {
        $field = $this->schema->field($fieldKey);
        if ($field->type !== 'attachment') {
            throw new NotFound('Not an attachment field.');
        }

        return $field;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): AttachmentRow
    {
        return new AttachmentRow(
            (int) $row['id'],
            (string) $row['sha256'],
            (int) $row['size'],
            (string) $row['media_type'],
            (string) $row['original_name'],
            (string) $row['created_at'],
            is_string($row['created_by']) ? $row['created_by'] : null,
        );
    }

    private static function id(int $id): void
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Ids must be positive integers.');
        }
    }

    private static function person(string $userKey): void
    {
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
    }
}
