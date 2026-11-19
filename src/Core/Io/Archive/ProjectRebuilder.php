<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Io\ImportLog;
use TiffinDB\Core\Io\ImportReport;
use TiffinDB\Core\Query\Sql;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Ddl;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Schema\Type\AutoNumberType;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Builds a new project from a checked archive database (M3 spec section
 * 11.5, ADR 0017 decisions 2 to 4). Security-critical: the source file is
 * never adopted and no text from it is ever run as SQL. The new file is
 * made by ProjectFile::create at the current version, every table, column,
 * index, and join table by Ddl from the validated metadata, and every
 * statement here is a literal joined only with Identifier names and Sql
 * fragments of Identifier names; values cross from the source only as
 * bound parameters, each checked by its field type first.
 *
 * Keys and record ids are kept; the project gets $newKey, $name, and no
 * grants (grants live in the system database, which this class never
 * touches). Values that fail their type's check are emptied and counted,
 * links whose records are missing or that break a side limited to one
 * link are dropped, forms that the M2 parser rejects are dropped, and
 * every table's default form is then regenerated, so an edited default
 * form gains a slot for every field it does not name (ADR 0017 decision
 * 5). The source's import log is not copied; the new project's log gets
 * one `archive` row.
 */
final class ProjectRebuilder
{
    public const PAGE = 1000;
    public const FALLBACK_MEDIA_TYPE = 'application/octet-stream';
    public const FALLBACK_FILE_NAME = 'file';
    public const MAX_FILE_NAME = 255;

    private const SHA256 = '/^[0-9a-f]{64}\z/';
    private const MEDIA_TYPE = '/^[a-z0-9.+-]+\/[a-z0-9.+-]+\z/';
    private const CREATED_WITH = '/^[0-9A-Za-z.+_-]{1,40}\z/';

    private readonly FormParser $parser;

    private string $now = '';
    private int $timesReplaced = 0;
    private int $keysCleared = 0;

    public function __construct(
        private readonly FieldTypes $types,
        private readonly Clock $clock,
        private readonly Keys $keys,
        private readonly string $appVersion,
    ) {
        $this->parser = new FormParser($types);
    }

    /**
     * Rebuilds $src into $folder (a new folder, later moved to
     * `projects/<newKey>/`), streaming attachment files from $archive.
     * $clientName is the upload's name, kept only as text in the import
     * log. The folder holds `project.tiffin` (closed, no `-wal` left),
     * `attachments/`, and `snapshots/` afterwards. ArchiveRefused
     * ('rebuild-failed') when the new file cannot be written or fails its
     * final checks; the caller removes the job folder.
     */
    public function rebuild(
        UntrustedProject $src,
        ArchiveReader $archive,
        string $folder,
        string $newKey,
        string $name,
        string $userKey,
        string $clientName,
    ): RebuildReport {
        if (!Keys::isValid($newKey) || !Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The project and person keys must be valid keys.');
        }
        $this->now = Timestamp::format($this->clock->now());
        $this->timesReplaced = 0;
        $this->keysCleared = 0;
        Fs::makeDir($folder);
        Fs::makeDir($folder . '/attachments');
        Fs::makeDir($folder . '/' . Snapshots::FOLDER);
        $path = $folder . '/project.tiffin';
        try {
            $db = ProjectFile::create($path, $newKey, $name, $userKey, $this->appVersion, $this->clock);
        } catch (\PDOException|\RuntimeException $error) {
            throw new ArchiveRefused(ArchiveRefused::REBUILD_FAILED, 'create', $error);
        }
        try {
            $report = $db->write(fn (Database $db): RebuildReport => $this->copy($src, $archive, $db, $folder, $userKey, $clientName));
            $integrity = $db->all('PRAGMA integrity_check');
            $foreignKeys = $db->all('PRAGMA foreign_key_check');
            if (count($integrity) !== 1 || array_values($integrity[0]) !== ['ok'] || $foreignKeys !== []) {
                throw new ArchiveRefused(ArchiveRefused::REBUILD_FAILED, 'final checks');
            }
        } catch (ArchiveRefused $refused) {
            $db->close();

            throw $refused;
        } catch (\Throwable $error) {
            $db->close();

            throw new ArchiveRefused(ArchiveRefused::REBUILD_FAILED, 'copy', $error);
        }
        $db->close();

        return $report;
    }

    private function copy(UntrustedProject $src, ArchiveReader $archive, Database $db, string $folder, string $userKey, string $clientName): RebuildReport
    {
        $source = $src->db();
        $this->insertMetadata($src, $db);
        foreach ($src->tables as $table) {
            Ddl::createTable($db, $table->key);
        }
        foreach ($src->fields as $field) {
            Ddl::addColumn($db, $field, $this->types->get($field->type));
            if ($field->type === 'auto_number') {
                Ddl::uniqueIndex($db, $field);
            }
        }
        foreach ($src->relations as $relation) {
            Ddl::createJoinTable($db, $relation);
        }

        $records = 0;
        $skipped = 0;
        $emptied = [];
        foreach ($src->tables as $table) {
            [$copied, $notCopied] = $this->copyRecords($src, $source, $db, $table, $emptied);
            $records += $copied;
            $skipped += $notCopied;
            $this->continueSequence($src, $db, Identifier::tableName($table->key));
        }
        $linksDropped = 0;
        foreach ($src->relations as $relation) {
            $linksDropped += $this->copyLinks($source, $db, $relation);
        }
        [$attachmentsDropped, $files] = $this->copyAttachments($source, $db);
        $this->continueSequence($src, $db, '_tiffin_attachments');
        $this->copyAttachmentLinks($src, $source, $db);
        [$filesMissing, $filesSkipped] = $this->copyFiles($archive, $folder, $files);
        [$formsDropped, $formsExtended] = $this->copyForms($src, $source, $db);
        $this->copyMeta($src, $db);

        $valuesEmptied = [];
        foreach ($src->fields as $key => $field) {
            if (($emptied[$key] ?? 0) > 0) {
                $valuesEmptied[$key] = ['table' => $src->tables[$field->tableKey]->name, 'field' => $field->name, 'count' => $emptied[$key]];
            }
        }
        $report = new RebuildReport(
            $this->keys->generate(),
            count($src->tables),
            $records,
            $valuesEmptied,
            $linksDropped,
            $formsDropped,
            $formsExtended,
            $filesMissing,
            $filesSkipped,
            $this->timesReplaced,
            $this->keysCleared,
            $skipped,
            $attachmentsDropped,
        );
        $columns = [];
        foreach ($valuesEmptied as $key => $entry) {
            $columns[] = ['name' => $entry['field'], 'field' => $key, 'emptied' => $entry['count']];
        }
        $details = $columns === [] ? [] : ['columns' => $columns];
        $details['archive'] = $report->archiveMember();
        ImportLog::insert($db, new ImportReport(
            $report->importLogKey,
            $this->now,
            $userKey,
            ImportReport::SOURCE_ARCHIVE,
            null,
            self::logName($clientName),
            $archive->uploadSize,
            $archive->uploadSha256,
            null,
            false,
            $records + $skipped,
            $records,
            $skipped,
            $report->valuesEmptiedTotal(),
            0,
            null,
            $details,
        ));

        return $report;
    }

    /** Step 1: tables, fields (auto-number `next` raised later), and relations from the validated metadata. */
    private function insertMetadata(UntrustedProject $src, Database $db): void
    {
        foreach ($src->tables as $key => $table) {
            $db->run(
                'INSERT INTO "_tiffin_tables" ("key", "sql_name", "name", "item_name", "position", "title_field", "created_at", "updated_at")'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $key, Identifier::tableName($key), $table->name, $table->itemName, $table->position, $table->titleFieldKey,
                    $this->time($src->rows[$key]['created_at'] ?? null), $this->time($src->rows[$key]['updated_at'] ?? null),
                ],
            );
        }
        foreach ($src->fields as $key => $field) {
            $db->run(
                'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "required", "help", "position", "created_at", "updated_at")'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $key, $field->tableKey, $field->hasColumn ? Identifier::columnName($key) : null, $field->name, $field->type,
                    self::encode($field->options), $field->required ? 1 : 0, $field->help, $field->position,
                    $this->time($src->rows[$key]['created_at'] ?? null), $this->time($src->rows[$key]['updated_at'] ?? null),
                ],
            );
        }
        foreach ($src->relations as $key => $relation) {
            $db->run(
                'INSERT INTO "_tiffin_relations" ("key", "join_table", "left_table", "left_field", "left_max", "right_table", "right_field", "right_max", "created_at")'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $key, Identifier::joinName($key), $relation->leftTable, $relation->leftField, $relation->leftMax->value,
                    $relation->rightTable, $relation->rightField, $relation->rightMax->value, $this->time($src->rows[$key]['created_at'] ?? null),
                ],
            );
        }
    }

    /**
     * Step 3: the records of one table, in pages ordered by id. Returns
     * the records copied and those left out (an id below 1).
     *
     * @param array<string, int> $emptied by field key
     * @return array{0: int, 1: int}
     */
    private function copyRecords(UntrustedProject $src, Database $source, Database $db, TableDef $table, array &$emptied): array
    {
        $fields = array_values(array_filter($src->fieldsOf($table->key), static fn (FieldDef $f): bool => $f->hasColumn));
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = Sql::of(Identifier::column($field->key));
        }
        if ($parts === []) {
            return $this->copyPages($source, $db, $table, $fields, null, $emptied);
        }

        return $this->copyPages($source, $db, $table, $fields, Sql::join(', ', $parts), $emptied);
    }

    /**
     * @param list<FieldDef> $fields the fields with a column, in the order of $columns
     * @param array<string, int> $emptied
     * @return array{0: int, 1: int}
     */
    private function copyPages(Database $source, Database $db, TableDef $table, array $fields, ?Sql $columns, array &$emptied): array
    {
        $copied = 0;
        $skipped = 0;
        $seenNumbers = [];
        $highest = [];
        $after = PHP_INT_MIN;
        $count = 7 + count($fields);
        while (true) {
            if ($columns === null) {
                $page = $source->all('SELECT "id", "_created_at", "_created_by", "_updated_at", "_updated_by", "_trashed_at", "_trashed_by" FROM ' . $table->sql() . ' WHERE "id" > ? ORDER BY "id" LIMIT ?', [$after, self::PAGE]);
            } else {
                $page = $source->all('SELECT "id", "_created_at", "_created_by", "_updated_at", "_updated_by", "_trashed_at", "_trashed_by", ' . $columns->sql() . ' FROM ' . $table->sql() . ' WHERE "id" > ? ORDER BY "id" LIMIT ?', [$after, self::PAGE]);
            }
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $id = $row['id'];
                if (!is_int($id) || $id <= $after) {
                    // UntrustedProject made every id its rowid, so this cannot happen; stop rather than loop.
                    throw new ArchiveRefused(ArchiveRefused::REBUILD_FAILED, 'record ids out of order');
                }
                $after = $id;
                if ($id < 1) {
                    $skipped++;
                    continue;
                }
                $params = array_merge([$id], $this->systemValues($row));
                foreach ($fields as $field) {
                    $value = $this->checkedValue($field, $row[Identifier::columnName($field->key)] ?? null, $emptied);
                    if ($field->type === 'auto_number' && is_int($value)) {
                        if (isset($seenNumbers[$field->key][$value])) {
                            $emptied[$field->key] = ($emptied[$field->key] ?? 0) + 1;
                            $value = null;
                        } else {
                            $seenNumbers[$field->key][$value] = true;
                            $highest[$field->key] = max($highest[$field->key] ?? 0, $value);
                        }
                    }
                    $params[] = $value;
                }
                if ($columns === null) {
                    $db->run('INSERT INTO ' . $table->sql() . ' ("id", "_created_at", "_created_by", "_updated_at", "_updated_by", "_trashed_at", "_trashed_by") VALUES (' . Database::placeholders($count) . ')', $params);
                } else {
                    $db->run('INSERT INTO ' . $table->sql() . ' ("id", "_created_at", "_created_by", "_updated_at", "_updated_by", "_trashed_at", "_trashed_by", ' . $columns->sql() . ') VALUES (' . Database::placeholders($count) . ')', $params);
                }
                $copied++;
            }
        }
        foreach ($fields as $field) {
            if ($field->type === 'auto_number') {
                $next = max((int) $field->options['next'], ($highest[$field->key] ?? 0) + 1);
                $options = $field->options;
                $options['next'] = min($next, AutoNumberType::MAX);
                $db->run('UPDATE "_tiffin_fields" SET "options" = ? WHERE "key" = ?', [self::encode($options), $field->key]);
            }
        }

        return [$copied, $skipped];
    }

    /**
     * The six system values of a record: times kept when they are valid
     * timestamps (else now, counted), person keys when they are keys (else
     * null, counted), and the trash pair kept only together.
     *
     * @param array<string, mixed> $row
     * @return list<?string>
     */
    private function systemValues(array $row): array
    {
        $trashedAt = $row['_trashed_at'];
        $trashedBy = $row['_trashed_by'];
        if ($trashedAt !== null || $trashedBy !== null) {
            if (!self::isTime($trashedAt) || !Keys::isValid($trashedBy)) {
                $this->timesReplaced++;
                $trashedAt = null;
                $trashedBy = null;
            }
        }

        return [
            $this->time($row['_created_at']),
            $this->personKey($row['_created_by']),
            $this->time($row['_updated_at']),
            $this->personKey($row['_updated_by']),
            $trashedAt,
            $trashedBy,
        ];
    }

    /**
     * One stored value read with the field's type and checked with its
     * options; anything that fails is emptied (checkbox: not ticked) and
     * counted.
     *
     * @param array<string, int> $emptied
     */
    private function checkedValue(FieldDef $field, mixed $stored, array &$emptied): string|int|float|null
    {
        $type = $this->types->get($field->type);
        if ($field->type === 'checkbox') {
            if ($stored === 0 || $stored === 1) {
                return $stored;
            }
            $emptied[$field->key] = ($emptied[$field->key] ?? 0) + 1;

            return 0;
        }
        if ($stored === null) {
            return null;
        }
        $value = $type->fromStorage($stored, $field->options);
        $checked = $value === null ? null : $type->check($value, $field->options);
        if ($checked === null || !$checked->isOk() || $checked->value() === null) {
            $emptied[$field->key] = ($emptied[$field->key] ?? 0) + 1;

            return null;
        }

        return $type->toStorage($checked->value(), $field->options);
    }

    /**
     * Step 4: the links of one relation. Rows are read ordered so that a
     * side limited to one link keeps the row with the lowest position,
     * then the lowest other id; the new join table's own unique indexes
     * then refuse the rest (INSERT OR IGNORE), as they refuse a pair seen
     * twice. Rows whose ids are not whole numbers or whose records are
     * missing are dropped. Returns the rows dropped.
     */
    private function copyLinks(Database $source, Database $db, RelationDef $relation): int
    {
        $dropped = 0;
        $offset = 0;
        $leftOne = $relation->leftMax === Cardinality::One;
        while (true) {
            if ($leftOne) {
                $page = $source->all(
                    'SELECT "left_id", "right_id", "position" FROM ' . $relation->sql()
                    . ' ORDER BY CASE WHEN typeof("position") = \'integer\' THEN "position" ELSE 0 END, "right_id", "left_id" LIMIT ? OFFSET ?',
                    [self::PAGE, $offset],
                );
            } else {
                $page = $source->all(
                    'SELECT "left_id", "right_id", "position" FROM ' . $relation->sql()
                    . ' ORDER BY CASE WHEN typeof("position") = \'integer\' THEN "position" ELSE 0 END, "left_id", "right_id" LIMIT ? OFFSET ?',
                    [self::PAGE, $offset],
                );
            }
            if ($page === []) {
                break;
            }
            $offset += count($page);
            foreach ($page as $row) {
                $left = $row['left_id'];
                $right = $row['right_id'];
                $position = is_int($row['position']) ? $row['position'] : 0;
                if (!is_int($left) || !is_int($right) || $left < 1 || $right < 1) {
                    $dropped++;
                    continue;
                }
                $added = $db->run(
                    'INSERT OR IGNORE INTO ' . $relation->sql() . ' ("left_id", "right_id", "position")'
                    . ' SELECT ?, ?, ? WHERE EXISTS (SELECT 1 FROM ' . Identifier::table($relation->leftTable) . ' WHERE "id" = ?)'
                    . ' AND EXISTS (SELECT 1 FROM ' . Identifier::table($relation->rightTable) . ' WHERE "id" = ?)',
                    [$left, $right, $position, $left, $right],
                );
                if ($added !== 1) {
                    $dropped++;
                }
            }
        }

        return $dropped;
    }

    /**
     * Step 5, rows: each valid attachment row is copied (bad media types
     * and names replaced); rows without a whole positive id, a SHA-256, or
     * a size of 0 or more are dropped. Returns the rows dropped and, by
     * hash, the sizes the kept rows give.
     *
     * @return array{0: int, 1: array<string, list<int>>}
     */
    private function copyAttachments(Database $source, Database $db): array
    {
        $dropped = 0;
        $files = [];
        $offset = 0;
        while (true) {
            $page = $source->all(
                'SELECT "id", "sha256", "size", "media_type", "original_name", "created_at", "created_by" FROM "_tiffin_attachments"'
                . ' ORDER BY "id" LIMIT ? OFFSET ?',
                [self::PAGE, $offset],
            );
            if ($page === []) {
                break;
            }
            $offset += count($page);
            foreach ($page as $row) {
                $id = $row['id'];
                $sha256 = $row['sha256'];
                $size = $row['size'];
                if (!is_int($id) || $id < 1 || !is_string($sha256) || preg_match(self::SHA256, $sha256) !== 1 || !is_int($size) || $size < 0) {
                    $dropped++;
                    continue;
                }
                $mediaType = is_string($row['media_type']) && preg_match(self::MEDIA_TYPE, $row['media_type']) === 1 ? $row['media_type'] : self::FALLBACK_MEDIA_TYPE;
                $db->run(
                    'INSERT INTO "_tiffin_attachments" ("id", "sha256", "size", "media_type", "original_name", "created_at", "created_by")'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $sha256, $size, $mediaType, self::fileName($row['original_name']), $this->time($row['created_at']), $this->personKey($row['created_by'])],
                );
                $files[$sha256][] = $size;
            }
        }

        return [$dropped, $files];
    }

    /**
     * Step 5, links: kept when the field is an attachment field, the
     * record exists in its table, and the attachment row was kept; a
     * triple seen twice is kept once.
     */
    private function copyAttachmentLinks(UntrustedProject $src, Database $source, Database $db): void
    {
        $offset = 0;
        while (true) {
            $page = $source->all(
                'SELECT "field_key", "record_id", "attachment_id", "position" FROM "_tiffin_attachment_links"'
                . ' ORDER BY "field_key", "record_id", "attachment_id" LIMIT ? OFFSET ?',
                [self::PAGE, $offset],
            );
            if ($page === []) {
                break;
            }
            $offset += count($page);
            foreach ($page as $row) {
                $field = is_string($row['field_key']) ? ($src->fields[$row['field_key']] ?? null) : null;
                $recordId = $row['record_id'];
                $attachmentId = $row['attachment_id'];
                if ($field === null || $field->type !== 'attachment' || !is_int($recordId) || !is_int($attachmentId)) {
                    continue;
                }
                $db->run(
                    'INSERT OR IGNORE INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position")'
                    . ' SELECT ?, ?, ?, ? WHERE EXISTS (SELECT 1 FROM ' . Identifier::table($field->tableKey) . ' WHERE "id" = ?)'
                    . ' AND EXISTS (SELECT 1 FROM "_tiffin_attachments" WHERE "id" = ?)',
                    [$field->key, $recordId, $attachmentId, is_int($row['position']) ? $row['position'] : 0, $recordId, $attachmentId],
                );
            }
        }
    }

    /**
     * Attachment files: each hash the kept rows name is streamed from the
     * archive and kept only when its SHA-256 and size equal the name and
     * every row naming it. Returns the hashes not kept (missing) and the
     * archive entries no row names (skipped).
     *
     * @param array<string, list<int>> $files
     * @return array{0: int, 1: int}
     */
    private function copyFiles(ArchiveReader $archive, string $folder, array $files): array
    {
        $missing = 0;
        foreach ($files as $sha256 => $sizes) {
            $sha256 = (string) $sha256;
            if (count(array_unique($sizes)) !== 1) {
                $missing++;
                continue;
            }
            $subfolder = $folder . '/attachments/' . substr($sha256, 0, 2);
            if (!Fs::isDir($subfolder)) {
                Fs::makeDir($subfolder);
            }
            if (!$archive->copyAttachment($sha256, $subfolder . '/' . $sha256, $sizes[0])) {
                $missing++;
            }
        }
        $skipped = 0;
        foreach ($archive->attachmentHashes() as $sha256) {
            if (!isset($files[$sha256])) {
                $skipped++;
            }
        }

        return [$missing, $skipped];
    }

    /**
     * Step 6: forms of a version 2 or 3 file. A row is kept when its key
     * is a key, its table exists, its format is 1, and the M2 parser
     * accepts its HTML; at most one default per table (the first by key).
     * Then every table's default form is regenerated. Returns the forms
     * dropped and the edited default forms whose HTML regeneration changed.
     *
     * @return array{0: int, 1: int}
     */
    private function copyForms(UntrustedProject $src, Database $source, Database $db): array
    {
        $dropped = 0;
        $edited = [];
        if ($src->formatVersion >= 2) {
            $defaults = [];
            $rows = $source->all(
                'SELECT "key", "table_key", "name", "format", "html", "is_default", "generated", "created_at", "updated_at"'
                . ' FROM "_tiffin_forms" ORDER BY "key"',
            );
            foreach ($rows as $row) {
                $key = $row['key'];
                $tableKey = $row['table_key'];
                $html = $row['html'];
                if (!Keys::isValid($key) || !is_string($tableKey) || !isset($src->tables[$tableKey]) || $row['format'] !== 1 || !is_string($html)) {
                    $dropped++;
                    continue;
                }
                try {
                    $this->parser->parse($html);
                } catch (FormRejected) {
                    $dropped++;
                    continue;
                }
                $isDefault = $row['is_default'] === 1;
                if ($isDefault && isset($defaults[$tableKey])) {
                    $dropped++;
                    continue;
                }
                $generated = $row['generated'] === 1;
                if ($isDefault) {
                    $defaults[$tableKey] = true;
                    if (!$generated) {
                        $edited[$key] = $html;
                    }
                }
                $name = is_string($row['name']) && Text::nameProblems($row['name'], 'name', UntrustedProject::MAX_FORM_NAME) === []
                    ? Text::trim($row['name'])
                    : FormStore::GENERATED_NAME;
                $db->run(
                    'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at")'
                    . ' VALUES (?, ?, ?, 1, ?, \'\', ?, ?, ?, ?)',
                    [$key, $tableKey, $name, $html, $isDefault ? 1 : 0, $generated ? 1 : 0, $this->time($row['created_at']), $this->time($row['updated_at'])],
                );
            }
        }
        $schema = new Schema($db, $this->types);
        $store = new FormStore($db, $this->keys, $this->clock, $this->parser);
        $store->regenerate($schema, array_map(static fn (TableDef $t): string => $t->key, $schema->tables()));
        $extended = 0;
        foreach ($edited as $key => $html) {
            if ($db->value('SELECT "html" FROM "_tiffin_forms" WHERE "key" = ?', [$key]) !== $html) {
                $extended++;
            }
        }

        return [$dropped, $extended];
    }

    /** Step 8: creation facts from the source when valid; `updated_*` stay the import's (ProjectFile::create). */
    private function copyMeta(UntrustedProject $src, Database $db): void
    {
        $createdAt = $src->meta['created_at'] ?? null;
        if (self::isTime($createdAt)) {
            $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'created_at\'', [$createdAt]);
        }
        $createdBy = $src->meta['created_by'] ?? null;
        if (Keys::isValid($createdBy)) {
            $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'created_by\'', [$createdBy]);
        }
        $createdWith = $src->meta['created_with'] ?? null;
        if (is_string($createdWith) && preg_match(self::CREATED_WITH, $createdWith) === 1) {
            $db->run('UPDATE "_tiffin_meta" SET "value" = ? WHERE "key" = \'created_with\'', [$createdWith]);
        }
    }

    /**
     * `sqlite_sequence` for a table: the larger of the source's value and
     * the largest id now in the table, so new ids continue after both and
     * an id the source used before is never given out again.
     */
    private function continueSequence(UntrustedProject $src, Database $db, string $tableName): void
    {
        $sequence = $src->sequences[$tableName] ?? 0;
        $current = $db->value('SELECT "seq" FROM "sqlite_sequence" WHERE "name" = ?', [$tableName]);
        if (is_int($current)) {
            if ($sequence > $current) {
                $db->run('UPDATE "sqlite_sequence" SET "seq" = ? WHERE "name" = ?', [$sequence, $tableName]);
            }

            return;
        }
        if ($sequence > 0) {
            $db->run('INSERT INTO "sqlite_sequence" ("name", "seq") VALUES (?, ?)', [$tableName, $sequence]);
        }
    }

    /** A stored time kept when it is a valid timestamp, else now (counted). */
    private function time(mixed $value): string
    {
        if (self::isTime($value)) {
            return $value;
        }
        $this->timesReplaced++;

        return $this->now;
    }

    private function personKey(mixed $value): ?string
    {
        if ($value === null || Keys::isValid($value)) {
            return $value;
        }
        $this->keysCleared++;

        return null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isTime(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        try {
            Timestamp::parse($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function fileName(mixed $value): string
    {
        if (!is_string($value) || !Text::isUtf8($value) || $value === '' || Text::length($value) > self::MAX_FILE_NAME) {
            return self::FALLBACK_FILE_NAME;
        }
        if (Text::hasControl($value) || str_contains($value, '/') || str_contains($value, '\\')) {
            return self::FALLBACK_FILE_NAME;
        }

        return $value;
    }

    /** The upload's name as log text: valid UTF-8 without control characters, at most 255 characters. */
    private static function logName(string $name): string
    {
        if (!Text::isUtf8($name) || Text::hasControl($name)) {
            return self::FALLBACK_FILE_NAME;
        }
        $name = Text::trim($name);
        if ($name === '') {
            return self::FALLBACK_FILE_NAME;
        }
        if (Text::length($name) > self::MAX_FILE_NAME) {
            return (string) preg_replace('/^(.{255}).*\z/su', '$1', $name);
        }

        return $name;
    }

    /** @param array<string, mixed> $options */
    private static function encode(array $options): string
    {
        return json_encode((object) $options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
