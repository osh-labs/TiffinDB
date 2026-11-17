<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\TableDesign;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The tables, new table, table fields, and delete table screens
 * (docs/design/m1/schema-editor.md sections 3 to 6). The tables page is
 * open to everyone who can view the project; AuthOperations::can() says
 * whether to show the editing controls. Every other screen calls
 * AuthOperations::require(schema.change) before it renders, and every
 * change is made by a SchemaOperations call, which checks again.
 */
final class TableController
{
    /**
     * Field types that can be a table's title (M1 spec part 1 section 8.2,
     * `canBeTitle()`), used only to fill the title-field list; the core
     * refuses any other choice.
     */
    public const TITLE_TYPES = [
        'text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'choice', 'email', 'phone', 'url', 'auto_number',
    ];

    private const NEW_FIELDS = ['name' => 'table-name', 'item_name' => 'table-item'];

    private const SETTINGS_FIELDS = ['name' => 'settings-name', 'item_name' => 'settings-item', 'title_field' => 'settings-title'];

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $project = $operations->projects()->get($actor, $key);
        $tables = $operations->schema()->tables($actor, $project->key);
        $view = $this->context->view();

        $rows = [];
        foreach ($tables as $summary) {
            $rows[] = [
                'key' => $summary->table->key,
                'name' => $summary->table->name,
                'fields' => $view->format()->number($summary->fieldCount),
                'records' => $view->format()->number($summary->recordCount),
            ];
        }

        return $this->context->page('tables/index', [
            'title' => $project->name,
            'breadcrumb' => [
                ['label' => $view->text('shell.nav.projects'), 'path' => '/projects'],
                ['label' => $project->name, 'path' => null],
            ],
            'project' => ['key' => $project->key, 'name' => $project->name],
            'lead' => $rows === [] ? $view->text('schema.tables.lead_empty') : $view->format()->count('count.tables', count($rows)),
            'canEdit' => $operations->auth()->can($actor, Permission::ChangeSchema, $project->key),
            'tables' => $rows,
        ], 200, 'projects');
    }

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $this->requireSchema($key);

        return $this->newPage($key, ['name' => '', 'item_name' => 'item', 'with_name' => true], [], 200);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $values = [
            'name' => $request->input('name'),
            'item_name' => $request->input('item_name'),
            'with_name' => $request->input('with_name') === '1',
        ];
        $operations = $this->context->services()->operations();
        try {
            $table = $operations->schema()->createTable($this->context->actor(), $key, $values['name'], $values['item_name'], $values['with_name']);
        } catch (ValidationFailed $failed) {
            return $this->newPage($key, $values, $failed->problems, 422);
        }
        $this->context->flash('notice.table_created', ['table' => $table->name]);

        return $this->context->redirect('/projects/' . $key . '/tables/' . $table->key . '/fields');
    }

    /** @param array<string, string> $params */
    public function fields(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $tableKey = $params['table'] ?? '';
        $this->requireSchema($key);
        $design = $this->context->services()->operations()->schema()->design($this->context->actor(), $key, $tableKey);
        $table = $design->table;

        return $this->fieldsPage($key, $design, [
            'name' => $table->name,
            'item_name' => $table->itemName,
            'title_field' => $table->titleFieldKey ?? '',
        ], [], 200);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $tableKey = $params['table'] ?? '';
        $values = [
            'name' => $request->input('name'),
            'item_name' => $request->input('item_name'),
            'title_field' => $request->input('title_field'),
        ];
        $actor = $this->context->actor();
        $schema = $this->context->services()->operations()->schema();
        try {
            $table = $schema->updateTable($actor, $key, $tableKey, $values['name'], $values['item_name'], $values['title_field'] === '' ? null : $values['title_field']);
        } catch (ValidationFailed $failed) {
            return $this->fieldsPage($key, $schema->design($actor, $key, $tableKey), $values, $failed->problems, 422);
        }
        $this->context->flash('notice.table_saved');

        return $this->context->redirect('/projects/' . $key . '/tables/' . $table->key . '/fields');
    }

    /** @param array<string, string> $params */
    public function deleteForm(Request $request, array $params): Response
    {
        return $this->deletePage($params['project'] ?? '', $params['table'] ?? '', null, 200);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $tableKey = $params['table'] ?? '';
        if ($request->input('confirm') !== '1') {
            // Checked here; the core is not called (schema-editor.md section 6).
            $this->requireSchema($key);

            return $this->deletePage($key, $tableKey, $this->context->view()->text('schema.delete.confirm_required'), 422);
        }
        $actor = $this->context->actor();
        $schema = $this->context->services()->operations()->schema();
        $name = $schema->design($actor, $key, $tableKey)->table->name;
        $schema->deleteTable($actor, $key, $tableKey);
        $this->context->flash('notice.table_deleted', ['table' => $name]);

        return $this->context->redirect('/projects/' . $key . '/tables');
    }

    private function requireSchema(string $projectKey): void
    {
        $this->context->services()->operations()->auth()->require($this->context->actor(), Permission::ChangeSchema, $projectKey);
    }

    /**
     * @param array{name: string, item_name: string, with_name: bool} $values
     * @param list<Problem>                                             $problems
     */
    private function newPage(string $key, array $values, array $problems, int $status): Response
    {
        $project = $this->context->services()->operations()->projects()->get($this->context->actor(), $key);
        [$list, $errors] = ProjectController::problems($this->context, $problems, self::NEW_FIELDS);
        $view = $this->context->view();

        return $this->context->page('tables/new', [
            'title' => $view->text('schema.table.new.title'),
            'breadcrumb' => ProjectController::crumbs($this->context, $project->key, $project->name, null, [['label' => $view->text('schema.table.new.crumb'), 'path' => null]]),
            'problems' => $list,
            'errors' => $errors,
            'values' => $values,
            'action' => '/projects/' . $project->key . '/tables',
            'cancel' => '/projects/' . $project->key . '/tables',
        ], $status, 'projects');
    }

    /**
     * @param array{name: string, item_name: string, title_field: string} $values
     * @param list<Problem>                                                 $problems
     */
    private function fieldsPage(string $key, TableDesign $design, array $values, array $problems, int $status): Response
    {
        $actor = $this->context->actor();
        $project = $this->context->services()->operations()->projects()->get($actor, $key);
        $table = $design->table;
        [$list, $errors] = ProjectController::problems($this->context, $problems, self::SETTINGS_FIELDS);
        $view = $this->context->view();
        $format = $view->format();

        $fields = [];
        $titleOptions = [];
        $last = count($design->fields) - 1;
        foreach ($design->fields as $i => $summary) {
            $field = $summary->field;
            $fields[] = [
                'key' => $field->key,
                'name' => $field->name,
                'type' => $summary->linkedTableName === null
                    ? $view->text('type.' . $field->type)
                    : $view->text('type.relation.to', ['table' => $summary->linkedTableName]),
                'links' => $summary->links === null ? null : ($summary->links === Cardinality::One ? 'one' : 'many'),
                'required' => $field->required,
                'isTitle' => $table->titleFieldKey === $field->key,
                'first' => $i === 0,
                'last' => $i === $last,
            ];
            if (in_array($field->type, self::TITLE_TYPES, true)) {
                $titleOptions[] = ['key' => $field->key, 'name' => $field->name];
            }
        }
        $base = '/projects/' . $project->key . '/tables/' . $table->key;

        return $this->context->page('tables/fields', [
            'title' => $table->name,
            'breadcrumb' => ProjectController::crumbs($this->context, $project->key, $project->name, null, [['label' => $table->name, 'path' => null]]),
            'problems' => $list,
            'errors' => $errors,
            'table' => ['name' => $table->name],
            'lead' => $view->text('schema.fields.lead', [
                'fields' => $format->count('count.fields', count($fields)),
                'records' => $format->count('count.records', $design->recordCount),
            ]),
            'base' => $base,
            'fields' => $fields,
            'titleOptions' => $titleOptions,
            'values' => $values,
        ], $status, 'projects');
    }

    private function deletePage(string $key, string $tableKey, ?string $error, int $status): Response
    {
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $this->requireSchema($key);
        $project = $operations->projects()->get($actor, $key);
        $schema = $operations->schema();
        $design = $schema->design($actor, $project->key, $tableKey);
        $table = $design->table;
        $impact = $schema->tableImpact($actor, $project->key, $table->key);

        // Fields in other tables that link to this one (deleted with it).
        $links = [];
        foreach ($schema->tables($actor, $project->key) as $other) {
            if ($other->table->key === $table->key) {
                continue;
            }
            foreach ($schema->design($actor, $project->key, $other->table->key)->fields as $summary) {
                if ($summary->linkedTableName === $table->name) {
                    $links[] = ['field' => $summary->field->name, 'table' => $other->table->name];
                }
            }
        }
        $view = $this->context->view();
        $records = $view->format()->count('count.records', $impact->records);
        $problems = $error === null ? [] : [['field' => 'delete-confirm', 'text' => $error]];

        return $this->context->page('tables/delete', [
            'title' => $view->text('schema.table.delete_confirm.title', ['table' => $table->name]),
            'breadcrumb' => ProjectController::crumbs($this->context, $project->key, $project->name, [$table->key, $table->name], [['label' => $view->text('schema.delete.crumb'), 'path' => null]]),
            'problems' => $problems,
            'error' => $error !== null,
            'table' => ['name' => $table->name],
            'records' => $records,
            'links' => $links,
            'action' => '/projects/' . $project->key . '/tables/' . $table->key . '/delete',
            'cancel' => '/projects/' . $project->key . '/tables/' . $table->key . '/fields',
        ], $status, 'projects');
    }
}
