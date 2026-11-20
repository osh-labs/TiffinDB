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
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Impact;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Tables and fields of a project (M1 spec section 9.5). Reading needs
 * `project.view`, changing `schema.change`. Each method opens the project,
 * reads its schema, checks that the table (and the field) exist and belong
 * together (NotFound otherwise), and calls SchemaEditor acting as the
 * actor. Snapshots the editor takes are recorded as they are taken, so
 * `snapshot.taken` comes before the entry for the change.
 */
final class SchemaOperations
{
    public function __construct(private readonly Context $c)
    {
    }

    /** @return list<TableSummary> by position */
    public function tables(Actor $actor, string $projectKey): array
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewProject);
        $editor = $this->c->editor($project, $db, $actor);
        $list = [];
        foreach ($schema->tables() as $table) {
            $list[] = new TableSummary($table, count($schema->fields($table->key)), $editor->recordCount($table->key, false));
        }

        return $list;
    }

    public function design(Actor $actor, string $projectKey, string $tableKey): TableDesign
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewProject);
        $table = $schema->table($tableKey);
        $fields = [];
        foreach ($schema->fields($table->key) as $field) {
            $relation = $schema->relationOf($field);
            $fields[] = new FieldSummary(
                $field,
                $relation === null ? null : $schema->table($relation->otherTable($field->key))->name,
                $relation?->maxFor($field->key),
            );
        }
        $editor = $this->c->editor($project, $db, $actor);

        return new TableDesign($table, $fields, $editor->recordCount($table->key, false), (new Trash($db, $this->c->clock))->count($table));
    }

    public function field(Actor $actor, string $projectKey, string $tableKey, string $fieldKey): FieldDesign
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $field = self::fieldOf($schema, $tableKey, $fieldKey);
        $relation = $schema->relationOf($field);
        $convertible = [];
        foreach ($this->c->types->ids() as $type) {
            if ($this->c->types->canConvert($field->type, $type)) {
                $convertible[] = $type;
            }
        }

        return new FieldDesign(
            $field,
            $relation,
            $relation === null ? null : $schema->table($relation->otherTable($field->key))->name,
            $convertible,
            $this->c->editor($project, $db, $actor)->valueCount($field->key),
        );
    }

    public function createTable(Actor $actor, string $projectKey, string $name, string $itemName, bool $withNameField): TableDef
    {
        [$project, $db] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $table = $this->c->editor($project, $db, $actor)->createTable($name, $itemName, $withNameField);
        $this->record(AuditAction::TABLE_CREATED, $actor, $project, 'table', $table->key);

        return $table;
    }

    public function updateTable(Actor $actor, string $projectKey, string $tableKey, string $name, string $itemName, ?string $titleFieldKey): TableDef
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $schema->table($tableKey);
        $table = $this->c->editor($project, $db, $actor)->updateTable($tableKey, $name, $itemName, $titleFieldKey);
        $this->record(AuditAction::TABLE_CHANGED, $actor, $project, 'table', $table->key);

        return $table;
    }

    /** What deleting the table would remove: its records, live and trashed. */
    public function tableImpact(Actor $actor, string $projectKey, string $tableKey): Impact
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $table = $schema->table($tableKey);

        return new Impact($this->c->editor($project, $db, $actor)->recordCount($table->key, true), 0);
    }

    public function deleteTable(Actor $actor, string $projectKey, string $tableKey): void
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $table = $schema->table($tableKey);
        $records = $this->c->editor($project, $db, $actor)->deleteTable($table->key);
        $this->record(AuditAction::TABLE_DELETED, $actor, $project, 'table', $table->key, ['records' => $records]);
        $this->sweep($project, $db);
    }

    /** @param array<string, mixed> $options */
    public function addField(
        Actor $actor,
        string $projectKey,
        string $tableKey,
        string $name,
        string $type,
        array $options,
        bool $required,
        string $help,
    ): FieldDef {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $schema->table($tableKey);
        $field = $this->c->editor($project, $db, $actor)->addField($tableKey, $name, $type, $options, $required, $help);
        $this->record(AuditAction::FIELD_CREATED, $actor, $project, 'field', $field->key, ['type' => $field->type]);

        return $field;
    }

    public function addRelationField(
        Actor $actor,
        string $projectKey,
        string $tableKey,
        string $name,
        string $targetTableKey,
        Cardinality $thisMax,
        Cardinality $otherMax,
        string $reverseName,
        bool $required,
        string $help,
    ): FieldDef {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $schema->table($tableKey);
        $field = $this->c->editor($project, $db, $actor)
            ->addRelation($tableKey, $name, $targetTableKey, $thisMax, $otherMax, $reverseName, $required, $help);
        $this->record(AuditAction::FIELD_CREATED, $actor, $project, 'field', $field->key, ['type' => $field->type]);

        return $field;
    }

    /** @param array<string, mixed> $options */
    public function updateField(
        Actor $actor,
        string $projectKey,
        string $tableKey,
        string $fieldKey,
        string $name,
        array $options,
        bool $required,
        string $help,
        int $acceptedLoss,
    ): FieldDef {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        self::fieldOf($schema, $tableKey, $fieldKey);
        $field = $this->c->editor($project, $db, $actor)->updateField($fieldKey, $name, $options, $required, $help, $acceptedLoss);
        $this->record(AuditAction::FIELD_CHANGED, $actor, $project, 'field', $field->key, ['type' => $field->type]);

        return $field;
    }

    /** @param array<string, mixed> $options */
    public function previewFieldUpdate(Actor $actor, string $projectKey, string $tableKey, string $fieldKey, array $options): Impact
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        self::fieldOf($schema, $tableKey, $fieldKey);

        return $this->c->editor($project, $db, $actor)->previewUpdate($fieldKey, $options);
    }

    /** @param array<string, mixed> $options */
    public function changeFieldType(
        Actor $actor,
        string $projectKey,
        string $tableKey,
        string $fieldKey,
        string $newType,
        array $options,
        int $acceptedLoss,
    ): Impact {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $before = self::fieldOf($schema, $tableKey, $fieldKey);
        $impact = $this->c->editor($project, $db, $actor)->changeType($fieldKey, $newType, $options, $acceptedLoss);
        $this->record(AuditAction::FIELD_CHANGED, $actor, $project, 'field', $fieldKey, [
            'type' => $newType,
            'type_from' => $before->type,
            'values_cleared' => $impact->values,
        ]);

        return $impact;
    }

    /** @param array<string, mixed> $options */
    public function previewTypeChange(Actor $actor, string $projectKey, string $tableKey, string $fieldKey, string $newType, array $options): Impact
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        self::fieldOf($schema, $tableKey, $fieldKey);

        return $this->c->editor($project, $db, $actor)->previewTypeChange($fieldKey, $newType, $options);
    }

    /** $direction: -1 earlier, +1 later. */
    public function moveField(Actor $actor, string $projectKey, string $tableKey, string $fieldKey, int $direction): void
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $field = self::fieldOf($schema, $tableKey, $fieldKey);
        $this->c->editor($project, $db, $actor)->moveField($fieldKey, $direction);
        $this->record(AuditAction::FIELD_MOVED, $actor, $project, 'field', $fieldKey, ['type' => $field->type]);
    }

    /** Deletes a field (for a relation, both its fields) after a `field-delete` snapshot. */
    public function deleteField(Actor $actor, string $projectKey, string $tableKey, string $fieldKey): void
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ChangeSchema);
        $field = self::fieldOf($schema, $tableKey, $fieldKey);
        $this->c->editor($project, $db, $actor)->deleteField($fieldKey);
        $this->record(AuditAction::FIELD_DELETED, $actor, $project, 'field', $fieldKey, ['type' => $field->type]);
        if ($field->type === 'attachment') {
            $this->sweep($project, $db);
        }
    }

    /**
     * After an editor write that removed attachment links has committed
     * (M4 spec section 10.6): the full attachment sweep. The change is
     * done already, so a failure is logged (no path or hash) and left for
     * the next sweep.
     */
    private function sweep(ProjectRecord $project, Database $db): void
    {
        try {
            $this->c->attachments($project, $db, new Schema($db, $this->c->types))->sweep();
        } catch (\Throwable $error) {
            error_log('TiffinDB: the attachment sweep after a schema change failed (' . $error::class . ').');
        }
    }

    /** @return array{0: ProjectRecord, 1: Database, 2: Schema} */
    private function open(Actor $actor, string $projectKey, Permission $permission): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        $db = $this->c->opener->open($project);

        return [$project, $db, new Schema($db, $this->c->types)];
    }

    private static function fieldOf(Schema $schema, string $tableKey, string $fieldKey): FieldDef
    {
        $table = $schema->table($tableKey);
        $field = $schema->field($fieldKey);
        if ($field->tableKey !== $table->key) {
            throw new NotFound('No such field in this table.');
        }

        return $field;
    }

    /** @param array<string, string|int|bool|null> $detail */
    private function record(string $action, Actor $actor, ProjectRecord $project, string $kind, string $key, array $detail = []): void
    {
        $this->c->audit->record($action, $actor->userId, $project->id, $kind, $key, $detail);
    }
}
