<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Record trash (M1 spec section 9.6). Moving to the trash and restoring
 * are record-level and not audited; emptying is Admin-only, takes an
 * `empty-trash` snapshot when there is anything to delete, and is audited.
 */
final class TrashOperations
{
    public const MAX_LIST = 200;

    public function __construct(private readonly Context $c)
    {
    }

    /** @param list<int> $ids 1 to Trash::MAX_IDS positive record ids */
    public function moveToTrash(Actor $actor, string $projectKey, string $tableKey, array $ids): int
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::TrashRecords);

        return (new Trash($db, $this->c->clock))->moveToTrash($schema->table($tableKey), $ids, $actor->userKey);
    }

    /** @param list<int> $ids 1 to Trash::MAX_IDS positive record ids */
    public function restore(Actor $actor, string $projectKey, string $tableKey, array $ids): int
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::TrashRecords);

        return (new Trash($db, $this->c->clock))->restore($schema->table($tableKey), $ids, $actor->userKey);
    }

    /**
     * Records in the table's trash, newest first; $limit 1 to 200.
     *
     * @return list<TrashedRecord>
     */
    public function list(Actor $actor, string $projectKey, string $tableKey, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > self::MAX_LIST || $offset < 0) {
            throw new \InvalidArgumentException('The limit must be 1 to ' . self::MAX_LIST . ' and the offset 0 or more.');
        }
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $title = null;
        if ($table->titleFieldKey !== null) {
            $field = $schema->field($table->titleFieldKey);
            $title = $field->hasColumn ? $field : null;
        }
        $list = [];
        foreach ((new Trash($db, $this->c->clock))->list($table, $title, $this->c->types, $limit, $offset) as $row) {
            $list[] = new TrashedRecord($row->id, $row->trashedAt, $row->trashedBy, $row->title);
        }

        return $list;
    }

    /** Records in the trash of one table, or of every table when $tableKey is null. */
    public function count(Actor $actor, string $projectKey, ?string $tableKey): int
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $trash = new Trash($db, $this->c->clock);
        $count = 0;
        foreach (self::tables($schema, $tableKey) as $table) {
            $count += $trash->count($table);
        }

        return $count;
    }

    /** Deletes for good every record in the trash of one table, or of every table; returns how many. */
    public function empty(Actor $actor, string $projectKey, ?string $tableKey): int
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::EmptyTrash);
        $tables = self::tables($schema, $tableKey);
        $trash = new Trash($db, $this->c->clock);
        $waiting = 0;
        foreach ($tables as $table) {
            $waiting += $trash->count($table);
        }
        if ($waiting > 0) {
            $info = $this->c->snapshots->take($db, $this->c->opener->folder($project->key), SnapshotReason::EmptyTrash);
            $this->c->recordSnapshot($project, $actor, $info);
        }
        $attachments = [];
        foreach ($tables as $table) {
            $attachments[$table->key] = array_values(array_filter(
                $schema->fields($table->key),
                static fn (FieldDef $field): bool => $field->type === 'attachment',
            ));
        }
        $deleted = $waiting > 0 ? $trash->empty($tables, $attachments, $actor->userKey)->records : 0;
        $this->c->audit->record(AuditAction::TRASH_EMPTIED, $actor->userId, $project->id, $tableKey === null ? null : 'table', $tableKey, [
            'records' => $deleted,
        ]);

        return $deleted;
    }

    /** @return list<TableDef> */
    private static function tables(Schema $schema, ?string $tableKey): array
    {
        return $tableKey === null ? $schema->tables() : [$schema->table($tableKey)];
    }

    /** @return array{0: ProjectRecord, 1: Database, 2: Schema} */
    private function open(Actor $actor, string $projectKey, Permission $permission): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        $db = $this->c->opener->open($project);

        return [$project, $db, new Schema($db, $this->c->types)];
    }
}
