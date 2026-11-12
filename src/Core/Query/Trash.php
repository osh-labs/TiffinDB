<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Record trash (M1 spec section 9.7, ADR 0010 decision 4): a record is in
 * the trash while `_trashed_at` is set. Nothing is copied; links in join
 * tables are kept while a record is in the trash and go by ON DELETE
 * CASCADE when the trash is emptied. Each change runs in one write().
 * Permission checks and the empty-trash snapshot belong to Core\App.
 */
final class Trash
{
    public const MAX_IDS = 1000;

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

        return $this->db->write(fn (Database $db): int => $db->run(
            'UPDATE ' . $table->sql() . ' SET "_trashed_at" = ?, "_trashed_by" = ?, "_updated_at" = ?, "_updated_by" = ?'
            . ' WHERE "id" IN (' . Database::placeholders(count($ids)) . ') AND "_trashed_at" IS NULL',
            [$now, $userKey, $now, $userKey, ...$ids],
        ));
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

        return $this->db->write(fn (Database $db): int => $db->run(
            'UPDATE ' . $table->sql() . ' SET "_trashed_at" = NULL, "_trashed_by" = NULL, "_updated_at" = ?, "_updated_by" = ?'
            . ' WHERE "id" IN (' . Database::placeholders(count($ids)) . ') AND "_trashed_at" IS NOT NULL',
            [$now, $userKey, ...$ids],
        ));
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
     * @return list<array{id: int, trashed_at: string, trashed_by: ?string, title: ?string}>
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
            $list[] = [
                'id' => (int) $row['id'],
                'trashed_at' => (string) $row['_trashed_at'],
                'trashed_by' => is_string($row['_trashed_by']) ? $row['_trashed_by'] : null,
                'title' => $title,
            ];
        }

        return $list;
    }

    /**
     * Deletes every record in the trash of the given tables, in one
     * transaction: first the attachment links of those records, then the
     * rows (join rows go by ON DELETE CASCADE). Returns rows deleted.
     * Unreferenced attachment files are left for M4's cleanup.
     *
     * @param list<TableDef> $tables
     * @param array<string, list<FieldDef>> $attachmentFields by table key
     */
    public function empty(array $tables, array $attachmentFields): int
    {
        foreach ($attachmentFields as $tableKey => $fields) {
            foreach ($fields as $field) {
                if (!$field instanceof FieldDef || $field->type !== 'attachment' || $field->tableKey !== $tableKey) {
                    throw new \InvalidArgumentException('Attachment fields must be attachment fields of the table they are listed under.');
                }
            }
        }

        return $this->db->write(function (Database $db) use ($tables, $attachmentFields): int {
            $deleted = 0;
            foreach ($tables as $table) {
                foreach ($attachmentFields[$table->key] ?? [] as $field) {
                    $db->run(
                        'DELETE FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" IN (SELECT "id" FROM '
                        . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL)',
                        [$field->key],
                    );
                }
                $deleted += $db->run('DELETE FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NOT NULL');
            }

            return $deleted;
        });
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
