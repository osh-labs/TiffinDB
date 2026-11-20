<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Query\TrashImpact;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Record trash (M1 spec section 9.6, M4 spec section 10.5): the trash
 * screen, restoring, and emptying. Moving to the trash and restoring are
 * record-level and not audited; emptying is Admin-only, takes an
 * `empty-trash` snapshot when there is anything to delete, deletes the
 * files it frees, and is audited.
 */
final class TrashOperations
{
    public const MAX_LIST = 200;
    /** Trashed records per table on the trash screen (P168). */
    public const PAGE_SIZE = 50;

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
        $names = [];

        return $this->trashed($db, $schema, $schema->table($tableKey), $limit, $offset, $names);
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

    /**
     * The trash screen (M4 spec section 10.5, P168). Without $tableKey:
     * every table holding a record in the trash, with its first PAGE_SIZE
     * records (page 1). With $tableKey: that table only, PAGE_SIZE per
     * page; pages below 1 and past the end clamp.
     */
    public function screen(Actor $actor, string $projectKey, ?string $tableKey, int $page): TrashScreen
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $chosen = $tableKey === null ? null : $schema->table($tableKey);
        $trash = new Trash($db, $this->c->clock);
        $sections = [];
        $total = 0;
        $names = [];
        foreach ($schema->tables() as $table) {
            $count = $trash->count($table);
            $total += $count;
            if ($chosen !== null ? $table->key !== $chosen->key : $count === 0) {
                continue;
            }
            $pages = max(1, intdiv($count + self::PAGE_SIZE - 1, self::PAGE_SIZE));
            $current = $chosen === null ? 1 : max(1, min($page, $pages));
            $records = $count === 0 ? [] : $this->trashed($db, $schema, $table, self::PAGE_SIZE, ($current - 1) * self::PAGE_SIZE, $names);
            $sections[] = new TrashSection($table, $count, $records, $current, $pages);
        }

        return new TrashScreen(
            $project,
            $sections,
            $total,
            $this->c->authorizer->can($actor, Permission::TrashRecords, $project),
            $this->c->authorizer->can($actor, Permission::EmptyTrash, $project),
        );
    }

    /** Restores every record in the table's trash (M4 spec section 10.5). */
    public function restoreAll(Actor $actor, string $projectKey, string $tableKey): RestoredAll
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::TrashRecords);
        $table = $schema->table($tableKey);

        return new RestoredAll($table, (new Trash($db, $this->c->clock))->restoreAll($table, $actor->userKey));
    }

    /** What emptying the whole trash would delete: records per table, and stored files (M4 spec section 10.5). */
    public function emptyImpact(Actor $actor, string $projectKey): TrashImpact
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::EmptyTrash);
        $tables = $schema->tables();

        return (new Trash($db, $this->c->clock))->impact($tables, self::attachmentFields($schema, $tables));
    }

    /**
     * Deletes for good every record in the trash of one table, or of every
     * table. When $expectedRecords is given and more records wait than
     * that, nothing happens: ConfirmationRequired carries the records and
     * files now waiting (P82 pattern; the screen asks again). Otherwise an
     * `empty-trash` snapshot is taken when anything waits, the records go,
     * then the freed files (Attachments::deleteFiles) and every other file
     * no row names (Attachments::sweep, P175). Audits `trash.emptied` with
     * the records and files deleted.
     *
     * @throws ConfirmationRequired
     */
    public function empty(Actor $actor, string $projectKey, ?string $tableKey, ?int $expectedRecords = null): TrashEmptied
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::EmptyTrash);
        $tables = self::tables($schema, $tableKey);
        $trash = new Trash($db, $this->c->clock);
        $fields = self::attachmentFields($schema, $tables);
        $waiting = 0;
        foreach ($tables as $table) {
            $waiting += $trash->count($table);
        }
        if ($expectedRecords !== null && $waiting > $expectedRecords) {
            throw new ConfirmationRequired($waiting, $trash->impact($tables, $fields)->files);
        }
        $deleted = 0;
        $files = 0;
        if ($waiting > 0) {
            $info = $this->c->snapshots->take($db, $this->c->opener->folder($project->key), SnapshotReason::EmptyTrash);
            $this->c->recordSnapshot($project, $actor, $info);
            $emptied = $trash->empty($tables, $fields, $actor->userKey);
            $deleted = $emptied->records;
            $files = $this->deleteFiles($project, $db, $emptied->freedHashes);
        }
        $this->c->audit->record(AuditAction::TRASH_EMPTIED, $actor->userId, $project->id, $tableKey === null ? null : 'table', $tableKey, [
            'records' => $deleted,
            'files' => $files,
        ]);

        return new TrashEmptied($deleted, $files);
    }

    /**
     * After the emptying write: the freed files, then the full sweep. The
     * records are gone already, so a failure here is logged (no path or
     * hash) and left for the next sweep. Returns the files deleted.
     *
     * @param list<string> $freedHashes
     */
    private function deleteFiles(ProjectRecord $project, Database $db, array $freedHashes): int
    {
        try {
            $attachments = $this->c->attachments($project, $db, new Schema($db, $this->c->types));

            return $attachments->deleteFiles($freedHashes) + $attachments->sweep();
        } catch (\Throwable $error) {
            error_log('TiffinDB: files freed by emptying the trash could not all be deleted (' . $error::class . ').');

            return 0;
        }
    }

    /**
     * @param list<TableDef> $tables
     * @return array<string, list<FieldDef>> every attachment field of each table, by table key
     */
    private static function attachmentFields(Schema $schema, array $tables): array
    {
        $fields = [];
        foreach ($tables as $table) {
            $fields[$table->key] = array_values(array_filter(
                $schema->fields($table->key),
                static fn (FieldDef $field): bool => $field->type === 'attachment',
            ));
        }

        return $fields;
    }

    /**
     * Trashed records of the table, newest first, with the display name of
     * who trashed each ($names caches display names by person key).
     *
     * @param array<string, ?string> $names
     * @return list<TrashedRecord>
     */
    private function trashed(Database $db, Schema $schema, TableDef $table, int $limit, int $offset, array &$names): array
    {
        $title = null;
        if ($table->titleFieldKey !== null) {
            $field = $schema->field($table->titleFieldKey);
            $title = $field->hasColumn ? $field : null;
        }
        $list = [];
        foreach ((new Trash($db, $this->c->clock))->list($table, $title, $this->c->types, $limit, $offset) as $row) {
            $name = null;
            if ($row->trashedBy !== null) {
                if (!array_key_exists($row->trashedBy, $names)) {
                    $names[$row->trashedBy] = $this->c->users->byKey($row->trashedBy)?->displayName;
                }
                $name = $names[$row->trashedBy];
            }
            $list[] = new TrashedRecord($row->id, $row->trashedAt, $row->trashedBy, $row->title, $name);
        }

        return $list;
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
