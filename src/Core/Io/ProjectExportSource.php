<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Contract\ExportAttachment;
use TiffinDB\Core\Io\Contract\ExportProject;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportRelation;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;

/**
 * The ExportSource ExportSourceBuilder returns (M3 spec sections 3.3 and
 * 10.1). Records are read in pages of PAGE by id; per page, links of each
 * relation field (live targets only, by position then id) and attachments
 * of each attachment field are read with one query each, and the titles
 * of linked records with one query per target table. A linked record with
 * no title text is shown as "{item name} {id}".
 */
final class ProjectExportSource implements ExportSource
{
    public const PAGE = 500;
    public const SEPARATOR = '; ';

    /** @param list<ExportTable> $tables */
    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        private readonly FieldTypes $types,
        private readonly ExportProject $project,
        private readonly array $tables,
    ) {
    }

    public function project(): ExportProject
    {
        return $this->project;
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function records(string $tableKey): iterable
    {
        if (!in_array($tableKey, $this->tableKeys(), true)) {
            throw new NotFound('The table is not in the export.');
        }
        $table = $this->schema->table($tableKey);
        $fields = $this->schema->fields($tableKey);
        $after = 0;
        do {
            $rows = $this->db->all('SELECT * FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NULL AND "id" > ? ORDER BY "id" LIMIT ?', [$after, self::PAGE]);
            if ($rows === []) {
                break;
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $links = [];
            $files = [];
            foreach ($fields as $field) {
                if ($field->type === 'relation') {
                    $links[$field->key] = $this->linksOf($field, $ids);
                } elseif ($field->type === 'attachment') {
                    $files[$field->key] = $this->filesOf($field, $ids);
                }
            }
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $after = $id;
                $values = [];
                foreach ($fields as $field) {
                    if ($field->type === 'relation') {
                        $linked = $links[$field->key][$id] ?? [];
                        $values[$field->key] = new ExportValue(
                            $linked === [] ? null : array_keys($linked),
                            self::joined(array_values($linked)),
                            array_keys($linked),
                        );
                    } elseif ($field->type === 'attachment') {
                        $attached = $files[$field->key][$id] ?? [];
                        $values[$field->key] = new ExportValue(
                            $attached === [] ? null : array_keys($attached),
                            self::joined(array_values($attached)),
                            array_keys($attached),
                        );
                    } else {
                        $type = $this->types->get($field->type);
                        $value = $type->fromStorage($row[Identifier::columnName($field->key)] ?? null, $field->options);
                        $values[$field->key] = new ExportValue($value, $value === null ? '' : $type->toText($value, $field->options), []);
                    }
                }
                yield new ExportRecord(
                    $id,
                    (string) $row['_created_at'],
                    is_string($row['_created_by']) ? $row['_created_by'] : null,
                    (string) $row['_updated_at'],
                    is_string($row['_updated_by']) ? $row['_updated_by'] : null,
                    $values,
                );
            }
        } while (count($rows) === self::PAGE);
    }

    public function relations(): array
    {
        $keys = $this->tableKeys();
        $seen = [];
        $relations = [];
        foreach ($keys as $tableKey) {
            foreach ($this->schema->relationsTouching($tableKey) as $relation) {
                if (isset($seen[$relation->key]) || !in_array($relation->leftTable, $keys, true) || !in_array($relation->rightTable, $keys, true)) {
                    continue;
                }
                $seen[$relation->key] = true;
                $relations[] = new ExportRelation(
                    $relation->key,
                    $relation->leftTable,
                    $relation->leftField,
                    $relation->leftMax->value,
                    $relation->rightTable,
                    $relation->rightField,
                    $relation->rightMax->value,
                );
            }
        }

        return $relations;
    }

    public function attachments(): iterable
    {
        $ids = [];
        foreach ($this->tableKeys() as $tableKey) {
            foreach ($this->schema->fields($tableKey) as $field) {
                if ($field->type !== 'attachment') {
                    continue;
                }
                $rows = $this->db->all(
                    'SELECT DISTINCT "l"."attachment_id" AS "id" FROM "_tiffin_attachment_links" AS "l" JOIN ' . Identifier::table($tableKey)
                    . ' AS "r" ON "r"."id" = "l"."record_id" AND "r"."_trashed_at" IS NULL WHERE "l"."field_key" = ?',
                    [$field->key],
                );
                foreach ($rows as $row) {
                    $ids[(int) $row['id']] = true;
                }
            }
        }
        $ids = array_keys($ids);
        sort($ids);
        foreach (array_chunk($ids, self::PAGE) as $chunk) {
            $rows = $this->db->all(
                'SELECT "id", "sha256", "size", "media_type", "original_name", "created_at" FROM "_tiffin_attachments" WHERE "id" IN ('
                . Database::placeholders(count($chunk)) . ') ORDER BY "id"',
                $chunk,
            );
            foreach ($rows as $row) {
                yield new ExportAttachment((int) $row['id'], (string) $row['sha256'], (int) $row['size'], (string) $row['media_type'], (string) $row['original_name'], (string) $row['created_at']);
            }
        }
    }

    /** @return list<string> */
    private function tableKeys(): array
    {
        return array_map(static fn (ExportTable $t): string => $t->key, $this->tables);
    }

    /**
     * Live links of the records in one relation field, by record id: linked id => title text, in link order.
     *
     * @param list<int> $ids
     * @return array<int, array<int, string>>
     */
    private function linksOf(FieldDef $field, array $ids): array
    {
        $relation = $this->schema->relationOf($field);
        if ($relation === null) {
            return [];
        }
        $other = $this->schema->table($relation->otherTable($field->key));
        $left = $relation->sideOf($field->key) === 'left';
        $thisSide = $left ? 'left_id' : 'right_id';
        $otherSide = $left ? 'right_id' : 'left_id';
        $rows = $this->db->all(
            'SELECT "jj".' . Identifier::system($thisSide) . ' AS "rid", "jj".' . Identifier::system($otherSide) . ' AS "oid" FROM '
            . Identifier::joinTable($relation->key) . ' AS "jj" JOIN ' . $other->sql() . ' AS "o" ON "o"."id" = "jj".' . Identifier::system($otherSide)
            . ' WHERE "o"."_trashed_at" IS NULL AND "jj".' . Identifier::system($thisSide) . ' IN (' . Database::placeholders(count($ids)) . ')'
            . ' ORDER BY "jj".' . Identifier::system($thisSide) . ', "jj"."position", "jj".' . Identifier::system($otherSide),
            $ids,
        );
        $linked = [];
        $targets = [];
        foreach ($rows as $row) {
            $linked[(int) $row['rid']][] = (int) $row['oid'];
            $targets[(int) $row['oid']] = true;
        }
        $titles = $this->titles($other, array_keys($targets));
        $result = [];
        foreach ($linked as $recordId => $otherIds) {
            foreach ($otherIds as $otherId) {
                $result[$recordId][$otherId] = $titles[$otherId] ?? '';
            }
        }

        return $result;
    }

    /**
     * Title text of records of a table, "{item name} {id}" when it has none.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function titles(TableDef $table, array $ids): array
    {
        $titles = [];
        $titleField = $table->titleFieldKey === null ? null : $this->schema->field($table->titleFieldKey);
        foreach (array_chunk($ids, self::PAGE) as $chunk) {
            if ($titleField === null || !$titleField->hasColumn) {
                $rows = $this->db->all('SELECT "id", NULL AS "title" FROM ' . $table->sql() . ' WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')', $chunk);
            } else {
                $rows = $this->db->all(
                    'SELECT "id", ' . Identifier::column($titleField->key) . ' AS "title" FROM ' . $table->sql() . ' WHERE "id" IN (' . Database::placeholders(count($chunk)) . ')',
                    $chunk,
                );
            }
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $text = '';
                if ($titleField !== null && $row['title'] !== null) {
                    $type = $this->types->get($titleField->type);
                    $value = $type->fromStorage($row['title'], $titleField->options);
                    $text = $value === null ? '' : $type->toText($value, $titleField->options);
                }
                $titles[$id] = $text === '' ? $table->itemName . ' ' . $id : $text;
            }
        }

        return $titles;
    }

    /**
     * Attachments of the records in one attachment field, by record id: attachment id => original name, in link order.
     *
     * @param list<int> $ids
     * @return array<int, array<int, string>>
     */
    private function filesOf(FieldDef $field, array $ids): array
    {
        $rows = $this->db->all(
            'SELECT "l"."record_id" AS "rid", "a"."id" AS "id", "a"."original_name" AS "name"'
            . ' FROM "_tiffin_attachment_links" AS "l" JOIN "_tiffin_attachments" AS "a" ON "a"."id" = "l"."attachment_id"'
            . ' WHERE "l"."field_key" = ? AND "l"."record_id" IN (' . Database::placeholders(count($ids)) . ')'
            . ' ORDER BY "l"."record_id", "l"."position", "a"."id"',
            [$field->key, ...$ids],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['rid']][(int) $row['id']] = (string) $row['name'];
        }

        return $result;
    }

    /** @param list<string> $texts */
    private static function joined(array $texts): string
    {
        $text = '';
        foreach ($texts as $index => $part) {
            $text = $index === 0 ? $part : $text . self::SEPARATOR . $part;
        }

        return $text;
    }
}
