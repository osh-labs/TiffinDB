<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Record trash (M1 spec section 9.7, ADR 0010 decision 4): a record is in
 * the trash while `_trashed_at` is set. Nothing is copied; links in join
 * tables are kept while a record is in the trash and go by ON DELETE
 * CASCADE when the trash is emptied. Each change runs in one write(),
 * which also records the change in project meta `updated_at` and
 * `updated_by` (ProjectFile::touch) when any record changed. Permission
 * checks, the empty-trash snapshot, and deleting the freed files after
 * the write belong to Core\App.
 */
final class Trash
{
    public const MAX_IDS = 1000;
    /** Ids per IN (...) list. */
    private const CHUNK = 500;

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Moves live records to the trash; returns how many moved (records
     * already in the trash or absent are not counted).
     *
     * @param list<int> $ids positive record ids, 1 to MAX_IDS
     */
    public function moveToTrash(TableDef $table, array $ids, string $userKey): int
    {
        $ids = self::ids($ids);
        self::person($userKey);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($table, $ids, $userKey, $now): int {
            $moved = $db->run(
                'UPDATE ' . $table->sql() . ' SET "_trashed_at" = ?, "_trashed_by" = ?, "_updated_at" = ?, "_updated_by" = ?'
                . ' WHERE "id" IN (' . Database::placeholders(count($ids)) . ') AND "_trashed_at" IS NULL',
                [$now, $userKey, $now, $userKey, ...$ids],
            );
            $this->touch($db, $moved, $userKey);

            return $moved;
        });
    }

    /**
     * Restores records from the trash; returns how many were restored.
     *
     * @param list<int> $ids positive record ids, 1 to MAX_IDS
     */
    public function restore(TableDef $table, array $ids, string $userKey): int
    {
        $ids = self::ids($ids);
        self::person($userKey);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($table, $ids, $userKey, $now): int {
            $restored = $db->run(
                'UPDATE ' . $table->sql() . ' SET "_trashed_at" = NULL, "_trashed_by" = NULL, "_updated_at" = ?, "_updated_by" = ?'
                . ' WHERE "id" IN (' . Database::placeholders(count($ids)) . ') AND "_trashed_at" IS NOT NULL',
                [$now, $userKey, ...$ids],
            );
            $this->touch($db, $restored, $userKey);

            return $restored;
        });
    }

    /** Records of the table in the trash. */
    public function count(TableDef $table): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL');
    }

    /**
     * Records in the trash, newest first (ties by id, highest first). The
     * title is the title field's value through its type's fromStorage and
     * toText, or null when there is no title field or no value.
     *
     * @return list<TrashedRow>
     */
    public function list(TableDef $table, ?FieldDef $titleField, FieldTypes $types, int $limit, int $offset): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('The limit must be 1 or more and the offset 0 or more.');
        }
        if ($titleField !== null && ($titleField->tableKey !== $table->key || !$titleField->hasColumn)) {
            throw new \InvalidArgumentException('The title field must be a field with a column in this table.');
        }
        if ($titleField === null) {
            $rows = $this->db->all(
                'SELECT "id", "_trashed_at", "_trashed_by", NULL AS "title" FROM ' . $table->sql()
                . ' WHERE "_trashed_at" IS NOT NULL ORDER BY "_trashed_at" DESC, "id" DESC LIMIT ? OFFSET ?',
                [$limit, $offset],
            );
        } else {
            $rows = $this->db->all(
                'SELECT "id", "_trashed_at", "_trashed_by", ' . $titleField->sql() . ' AS "title" FROM ' . $table->sql()
                . ' WHERE "_trashed_at" IS NOT NULL ORDER BY "_trashed_at" DESC, "id" DESC LIMIT ? OFFSET ?',
                [$limit, $offset],
            );
        }
        $type = $titleField === null ? null : $types->get($titleField->type);
        $list = [];
        foreach ($rows as $row) {
            $title = null;
            if ($type !== null && $titleField !== null) {
                $value = $type->fromStorage($row['title'], $titleField->options);
                $title = $value === null ? null : $type->toText($value, $titleField->options);
            }
            $list[] = new TrashedRow(
                (int) $row['id'],
                (string) $row['_trashed_at'],
                is_string($row['_trashed_by']) ? $row['_trashed_by'] : null,
                $title,
            );
        }

        return $list;
    }

    /**
     * Restores every record in the table's trash with one statement;
     * returns how many (M4 spec section 4.5).
     */
    public function restoreAll(TableDef $table, string $userKey): int
    {
        self::person($userKey);
        $now = Timestamp::format($this->clock->now());

        return $this->db->write(function (Database $db) use ($table, $userKey, $now): int {
            $restored = $db->run(
                'UPDATE ' . $table->sql() . ' SET "_trashed_at" = NULL, "_trashed_by" = NULL, "_updated_at" = ?, "_updated_by" = ?'
                . ' WHERE "_trashed_at" IS NOT NULL',
                [$now, $userKey],
            );
            $this->touch($db, $restored, $userKey);

            return $restored;
        });
    }

    /**
     * What empty() over the same tables and fields would delete (M4 spec
     * section 4.5): records in the trash per table, and the stored files
     * that would go: the distinct hashes of the attachment rows linked only
     * from trashed records of those tables, leaving out a hash that a row
     * empty() keeps also has. Links through every field of each table are
     * counted, as empty() deletes them (P180 item 4); $attachmentFields is
     * checked only.
     *
     * @param list<TableDef> $tables
     * @param array<string, list<FieldDef>> $attachmentFields by table key
     */
    public function impact(array $tables, array $attachmentFields): TrashImpact
    {
        self::checkTargets($tables, $attachmentFields);
        $records = [];
        $trashedLinks = [];
        foreach ($tables as $table) {
            $records[$table->key] = $this->count($table);
            foreach (self::trashedLinkCounts($this->db, $table) as $attachmentId => $count) {
                $trashedLinks[$attachmentId] = ($trashedLinks[$attachmentId] ?? 0) + $count;
            }
        }
        $freed = [];
        foreach (array_chunk(array_keys($trashedLinks), self::CHUNK) as $chunk) {
            $rows = $this->db->all(
                'SELECT "attachment_id" AS "id", COUNT(*) AS "links" FROM "_tiffin_attachment_links" WHERE "attachment_id" IN ('
                . Database::placeholders(count($chunk)) . ') GROUP BY "attachment_id"',
                $chunk,
            );
            foreach ($rows as $row) {
                if ((int) $row['links'] === $trashedLinks[(int) $row['id']]) {
                    $freed[(int) $row['id']] = true;
                }
            }
        }
        $hashes = [];
        foreach (array_chunk(array_keys($freed), self::CHUNK) as $chunk) {
            foreach ($this->db->all('SELECT DISTINCT "sha256" FROM "_tiffin_attachments" WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')', $chunk) as $row) {
                $hashes[(string) $row['sha256']] = true;
            }
        }
        foreach (array_chunk(array_keys($hashes), self::CHUNK) as $chunk) {
            foreach ($this->db->all('SELECT "id", "sha256" FROM "_tiffin_attachments" WHERE "sha256" IN (' . Database::placeholders(count($chunk)) . ')', $chunk) as $row) {
                if (!isset($freed[(int) $row['id']])) {
                    unset($hashes[(string) $row['sha256']]);
                }
            }
        }

        return new TrashImpact($records, array_sum($records), count($hashes));
    }

    /**
     * Deletes every record in the trash of the given tables, in one
     * transaction: first the attachment links of those records (their
     * attachment ids read first), then the rows (join rows go by ON DELETE
     * CASCADE), then the attachment rows among those ids that nothing links
     * to any more. Returns the records deleted and the deleted rows'
     * distinct hashes; the caller deletes the files after the write
     * (Attachments::deleteFiles, ADR 0018 decision 6). The links through
     * every field of each table go, whatever $attachmentFields lists
     * (P180 item 4); $attachmentFields is checked only.
     *
     * @param list<TableDef> $tables
     * @param array<string, list<FieldDef>> $attachmentFields by table key
     */
    public function empty(array $tables, array $attachmentFields, string $userKey): EmptiedTrash
    {
        self::checkTargets($tables, $attachmentFields);
        self::person($userKey);

        return $this->db->write(function (Database $db) use ($tables, $userKey): EmptiedTrash {
            $deleted = 0;
            $held = [];
            foreach ($tables as $table) {
                foreach (array_keys(self::trashedLinkCounts($db, $table)) as $attachmentId) {
                    $held[$attachmentId] = true;
                }
                $deleted += self::emptyTable($db, $table);
            }
            $hashes = [];
            foreach (array_chunk(array_keys($held), self::CHUNK) as $chunk) {
                $rows = $db->all(
                    'DELETE FROM "_tiffin_attachments" WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')'
                    . ' AND "id" NOT IN (SELECT "attachment_id" FROM "_tiffin_attachment_links") RETURNING "sha256"',
                    $chunk,
                );
                foreach ($rows as $row) {
                    $hashes[(string) $row['sha256']] = (string) $row['sha256'];
                }
            }
            $this->touch($db, $deleted, $userKey);

            return new EmptiedTrash($deleted, array_values($hashes));
        });
    }

    /**
     * Attachment id => links to it from trashed records of the table
     * through any field of the table (P180 item 4: whatever fields the
     * caller lists, so no link of a deleted record can be left behind).
     *
     * @return array<int, int>
     */
    private static function trashedLinkCounts(Database $db, TableDef $table): array
    {
        $rows = $db->all(
            'SELECT "attachment_id" AS "id", COUNT(*) AS "links" FROM "_tiffin_attachment_links"'
            . ' WHERE "field_key" IN (SELECT "key" FROM "_tiffin_fields" WHERE "table_key" = ?) AND "record_id" IN (SELECT "id" FROM '
            . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL) GROUP BY "attachment_id"',
            [$table->key],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['links'];
        }

        return $counts;
    }

    /**
     * \InvalidArgumentException unless every table is a TableDef and every
     * listed field an attachment field of the table it is listed under.
     *
     * @param array<mixed> $tables
     * @param array<mixed> $attachmentFields
     */
    private static function checkTargets(array $tables, array $attachmentFields): void
    {
        foreach ($tables as $table) {
            if (!$table instanceof TableDef) {
                throw new \InvalidArgumentException('Tables must be table definitions.');
            }
        }
        foreach ($attachmentFields as $tableKey => $fields) {
            if (!is_array($fields)) {
                throw new \InvalidArgumentException('Attachment fields are listed by table key.');
            }
            foreach ($fields as $field) {
                if (!$field instanceof FieldDef || $field->type !== 'attachment' || $field->tableKey !== $tableKey) {
                    throw new \InvalidArgumentException('Attachment fields must be attachment fields of the table they are listed under.');
                }
            }
        }
    }

    /** Deletes the table's trashed records and their attachment links through every field of the table (P180 item 4). */
    private static function emptyTable(Database $db, TableDef $table): int
    {
        $db->run(
            'DELETE FROM "_tiffin_attachment_links" WHERE "field_key" IN (SELECT "key" FROM "_tiffin_fields" WHERE "table_key" = ?) AND "record_id" IN (SELECT "id" FROM '
            . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL)',
            [$table->key],
        );

        return $db->run('DELETE FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL');
    }

    /** Records the change in project meta when any record changed (`docs/formats/tiffin.md` section 4.1). */
    private function touch(Database $db, int $changed, string $userKey): void
    {
        if ($changed > 0) {
            ProjectFile::touch($db, $userKey, $this->clock);
        }
    }

    /**
     * @param array<mixed> $ids
     * @return list<int> without duplicates
     */
    private static function ids(array $ids): array
    {
        if ($ids === [] || count($ids) > self::MAX_IDS) {
            throw new \InvalidArgumentException('Give 1 to ' . self::MAX_IDS . ' record ids.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Record ids must be positive integers.');
            }
        }

        return array_values(array_unique($ids));
    }

    private static function person(string $userKey): void
    {
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
    }
}
