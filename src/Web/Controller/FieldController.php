<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\FieldDesign;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Add, change, change the type of, move, and delete a field
 * (docs/design/m1/schema-editor.md sections 7 to 11). Every screen calls
 * AuthOperations::require(schema.change) before it renders; every change
 * is one SchemaOperations call, which checks again.
 *
 * Option inputs are named `opt_<option>`; the controller turns them into
 * the core's option arrays and maps problems with field `options.<name>`
 * to element id `opt-<name with _ as ->`. A change the core reports as
 * clearing values (ConfirmationRequired) gives the confirmation page
 * (status 409), which posts every submitted field again with `confirm`
 * (P59) set to the number of values it showed. The core is given that
 * number as the accepted loss and asks again when the real count is
 * larger (P82, M2 spec part 1 section 7.5).
 */
final class FieldController
{
    /** The type picker's groups, in order (schema-editor.md section 7.1). */
    public const GROUPS = [
        'words' => ['text', 'long_text'],
        'numbers' => ['number', 'currency', 'rating', 'auto_number'],
        'time' => ['date', 'time', 'date_time', 'duration'],
        'choices' => ['checkbox', 'choice'],
        'contact' => ['email', 'phone', 'url'],
        'links' => ['attachment', 'relation'],
    ];

    /** Type id => options partial (`templates/partials/field-options/<kind>.php`), for every type id. */
    public const KINDS = [
        'text' => 'text',
        'long_text' => 'text',
        'number' => 'number',
        'currency' => 'currency',
        'date' => 'none',
        'time' => 'seconds',
        'date_time' => 'seconds',
        'duration' => 'seconds',
        'checkbox' => 'none',
        'choice' => 'choice',
        'rating' => 'rating',
        'url' => 'none',
        'email' => 'none',
        'phone' => 'none',
        'attachment' => 'attachment',
        'relation' => 'relation',
        'auto_number' => 'auto-number',
    ];

    /** Types that are never required (the core forces false). */
    private const NEVER_REQUIRED = ['checkbox', 'auto_number'];

    /** Types whose value count is not shown (links and files). */
    private const NO_VALUE_COUNT = ['relation', 'attachment'];

    /** Longest allowed text, per type (M1 spec part 1 section 8.2). */
    private const MAX_LENGTH = ['text' => '10000', 'long_text' => '1000000'];

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        [$project, $table] = $this->tableOf($params);
        $type = $request->query['type'] ?? null;
        if ($type === null) {
            return $this->choosePage($project, $table, false);
        }
        if (!is_string($type) || !isset(self::KINDS[$type])) {
            return $this->choosePage($project, $table, true);
        }

        return $this->newPage($project, $table, $type, $this->defaults($type, $table['name']), [], 200);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$project, $table] = $this->tableOf($params);
        $type = $request->input('type');
        if (!isset(self::KINDS[$type])) {
            return $this->choosePage($project, $table, true);
        }
        $values = $this->posted($request, $type, null);
        $actor = $this->context->actor();
        $schema = $this->context->services()->operations()->schema();
        try {
            if ($type === 'relation') {
                $thisMax = Cardinality::tryFrom($values['opt_this_max']);
                $otherMax = Cardinality::tryFrom($values['opt_other_max']);
                $invalid = [];
                if ($thisMax === null) {
                    $invalid[] = new Problem('options.links', 'problem.options.invalid');
                }
                if ($otherMax === null) {
                    $invalid[] = new Problem('options.other_links', 'problem.options.invalid');
                }
                if ($thisMax === null || $otherMax === null) {
                    throw new ValidationFailed($invalid);
                }
                $field = $schema->addRelationField(
                    $actor,
                    $project['key'],
                    $table['key'],
                    $values['name'],
                    $values['opt_target'],
                    $thisMax,
                    $otherMax,
                    $values['opt_reverse_name'],
                    $values['required'] === '1',
                    $values['help'],
                );
            } else {
                $field = $schema->addField(
                    $actor,
                    $project['key'],
                    $table['key'],
                    $values['name'],
                    $type,
                    $this->options($type, $values, null),
                    $values['required'] === '1',
                    $values['help'],
                );
            }
        } catch (ValidationFailed $failed) {
            return $this->newPage($project, $table, $type, $values, $failed->problems, 422);
        }
        $this->context->flash('notice.field_added', ['field' => $field->name]);

        return $this->context->redirect($table['path']);
    }

    /** @param array<string, string> $params */
    public function editForm(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);

        return $this->editPage($project, $table, $design, $this->current($design), [], 200);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);
        $field = $design->field;
        $values = $this->posted($request, $field->type, $field);
        try {
            $saved = $this->context->services()->operations()->schema()->updateField(
                $this->context->actor(),
                $project['key'],
                $table['key'],
                $field->key,
                $values['name'],
                $this->options($field->type, $values, $field),
                $values['required'] === '1',
                $values['help'],
                self::acceptedLoss($request),
            );
        } catch (ValidationFailed $failed) {
            return $this->editPage($project, $table, $design, $values, $failed->problems, 422);
        } catch (ConfirmationRequired $needed) {
            $path = $table['path'] . '/' . $field->key;

            return $this->confirmPage($request, $project, $table, $field, null, $needed, $path, $path);
        }
        $this->context->flash('notice.field_saved', ['field' => $saved->name]);

        return $this->context->redirect($table['path']);
    }

    /** @param array<string, string> $params */
    public function typeForm(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);
        if ($design->convertibleTo === []) {
            return $this->context->redirect($table['path'] . '/' . $design->field->key);
        }

        return $this->typePage($project, $table, $design, [], 200);
    }

    /** @param array<string, string> $params */
    public function changeType(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);
        $field = $design->field;
        $type = $request->input('type');
        try {
            $impact = $this->context->services()->operations()->schema()->changeFieldType(
                $this->context->actor(),
                $project['key'],
                $table['key'],
                $field->key,
                $type,
                [],
                self::acceptedLoss($request),
            );
        } catch (ValidationFailed $failed) {
            return $this->typePage($project, $table, $design, $failed->problems, 422);
        } catch (ConfirmationRequired $needed) {
            $path = $table['path'] . '/' . $field->key;

            return $this->confirmPage($request, $project, $table, $field, $this->context->view()->text('schema.type.crumb'), $needed, $path . '/type', $path . '/type');
        }
        $label = $this->context->view()->text('type.' . $type);
        if ($impact->values > 0) {
            $this->context->flash('notice.type_changed_cleared', [
                'field' => $field->name,
                'type' => $label,
                'count' => $this->context->view()->format()->number($impact->values),
            ]);
        } else {
            $this->context->flash('notice.type_changed', ['field' => $field->name, 'type' => $label]);
        }

        return $this->context->redirect($table['path'] . '/' . $field->key);
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        $project = $params['project'] ?? '';
        $tableKey = $params['table'] ?? '';
        $direction = match ($request->input('direction')) {
            'up' => -1,
            'down' => 1,
            default => 0,
        };
        if ($direction !== 0) {
            $this->context->services()->operations()->schema()->moveField(
                $this->context->actor(),
                $project,
                $tableKey,
                $params['field'] ?? '',
                $direction,
            );
        } else {
            $this->requireSchema($project);
        }

        return $this->context->redirect('/projects/' . $project . '/tables/' . $tableKey . '/fields');
    }

    /** @param array<string, string> $params */
    public function deleteForm(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);
        $field = $design->field;
        $view = $this->context->view();
        $actor = $this->context->actor();

        $reverse = null;
        if ($design->relation !== null) {
            $otherTable = $design->relation->otherTable($field->key);
            $otherField = $design->relation->otherField($field->key);
            foreach ($this->context->services()->operations()->schema()->design($actor, $project['key'], $otherTable)->fields as $summary) {
                if ($summary->field->key === $otherField) {
                    $reverse = ['field' => $summary->field->name, 'table' => (string) $design->linkedTableName];
                }
            }
        }
        $path = $table['path'] . '/' . $field->key;

        return $this->context->page('fields/delete', [
            'title' => $view->text('schema.field.delete_confirm.title', ['field' => $field->name]),
            'breadcrumb' => $this->crumbs($project, $table, [
                ['label' => $field->name, 'path' => $path],
                ['label' => $view->text('schema.delete.crumb'), 'path' => null],
            ]),
            'field' => ['name' => $field->name],
            'links' => in_array($field->type, self::NO_VALUE_COUNT, true),
            'records' => $view->format()->count('count.records', $design->valueCount),
            'reverse' => $reverse,
            'action' => $path . '/delete',
            'cancel' => $path,
        ], 200, 'projects');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        [$project, $table, $design] = $this->fieldOf($params);
        $this->context->services()->operations()->schema()->deleteField(
            $this->context->actor(),
            $project['key'],
            $table['key'],
            $design->field->key,
        );
        $this->context->flash('notice.field_deleted', ['field' => $design->field->name]);

        return $this->context->redirect($table['path']);
    }

    // Loading.

    private function requireSchema(string $projectKey): void
    {
        $this->context->services()->operations()->auth()->require($this->context->actor(), Permission::ChangeSchema, $projectKey);
    }

    /**
     * The project and table of the route, after the permission check.
     *
     * @param array<string, string> $params
     * @return array{0: array{key: string, name: string}, 1: array{key: string, name: string, itemName: string, path: string}}
     */
    private function tableOf(array $params): array
    {
        $key = $params['project'] ?? '';
        $this->requireSchema($key);
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $project = $operations->projects()->get($actor, $key);
        $table = $operations->schema()->design($actor, $project->key, $params['table'] ?? '')->table;

        return [
            ['key' => $project->key, 'name' => $project->name],
            [
                'key' => $table->key,
                'name' => $table->name,
                'itemName' => $table->itemName,
                'path' => '/projects/' . $project->key . '/tables/' . $table->key . '/fields',
            ],
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array{0: array{key: string, name: string}, 1: array{key: string, name: string, itemName: string, path: string}, 2: FieldDesign}
     */
    private function fieldOf(array $params): array
    {
        [$project, $table] = $this->tableOf($params);
        $design = $this->context->services()->operations()->schema()->field(
            $this->context->actor(),
            $project['key'],
            $table['key'],
            $params['field'] ?? '',
        );

        return [$project, $table, $design];
    }

    // Values and options.

    /**
     * The form values of a new field of $type.
     *
     * @return array<string, string>
     */
    private function defaults(string $type, string $tableName): array
    {
        $values = ['name' => '', 'help' => '', 'required' => ''];

        return $values + match (self::KINDS[$type]) {
            'text' => ['opt_max_length' => $type === 'text' ? '500' : '20000'],
            'number' => ['opt_decimals' => '0', 'opt_min' => '', 'opt_max' => ''],
            'currency' => ['opt_code' => 'USD', 'opt_minor_digits' => '2'],
            'seconds' => ['opt_seconds' => ''],
            'choice' => ['opt_choices' => ''],
            'rating' => ['opt_max' => '5'],
            'attachment' => ['opt_images_only' => ''],
            'auto-number' => ['opt_start' => '1'],
            'relation' => ['opt_target' => '', 'opt_this_max' => 'one', 'opt_other_max' => 'many', 'opt_reverse_name' => $tableName],
            default => [],
        };
    }

    /**
     * The form values of an existing field.
     *
     * @return array<string, string>
     */
    private function current(FieldDesign $design): array
    {
        $field = $design->field;
        $o = $field->options;
        $values = ['name' => $field->name, 'help' => $field->help, 'required' => $field->required ? '1' : ''];

        return $values + match (self::KINDS[$field->type] ?? 'none') {
            'text' => ['opt_max_length' => self::text($o['max_length'] ?? '')],
            'number' => ['opt_decimals' => self::text($o['decimals'] ?? 0), 'opt_min' => self::text($o['min'] ?? ''), 'opt_max' => self::text($o['max'] ?? '')],
            'currency' => ['opt_code' => self::text($o['code'] ?? '')],
            'seconds' => ['opt_seconds' => ($o['seconds'] ?? false) === true ? '1' : ''],
            'choice' => self::choiceValues($field, null) + ['opt_choices' => ''],
            'rating' => ['opt_max' => self::text($o['max'] ?? 5)],
            'attachment' => ['opt_images_only' => ($o['images_only'] ?? false) === true ? '1' : ''],
            'relation' => ['opt_this_max' => $design->relation?->maxFor($field->key)->value ?? 'one'],
            default => [],
        };
    }

    /**
     * The submitted form values for $type; $field is the field being
     * changed (edit mode), or null for a new one.
     *
     * @return array<string, string>
     */
    private function posted(Request $request, string $type, ?FieldDef $field): array
    {
        $values = ['name' => $request->input('name'), 'help' => $request->input('help'), 'required' => $request->input('required')];
        $names = match (self::KINDS[$type]) {
            'text' => ['opt_max_length'],
            'number' => ['opt_decimals', 'opt_min', 'opt_max'],
            'currency' => $field === null ? ['opt_code', 'opt_minor_digits'] : ['opt_code'],
            'seconds' => ['opt_seconds'],
            'choice' => ['opt_choices'],
            'rating' => ['opt_max'],
            'attachment' => ['opt_images_only'],
            'auto-number' => $field === null ? ['opt_start'] : [],
            'relation' => $field === null ? ['opt_target', 'opt_this_max', 'opt_other_max', 'opt_reverse_name'] : ['opt_this_max'],
            default => [],
        };
        foreach ($names as $name) {
            $values[$name] = $request->input($name);
        }
        if ($field !== null && $field->type === 'choice') {
            $values = self::choiceValues($field, $request) + $values;
        }

        return $values;
    }

    /**
     * One `opt_choice_<key>` value per existing choice: the stored label,
     * or the submitted text when $request is given.
     *
     * @return array<string, string>
     */
    private static function choiceValues(FieldDef $field, ?Request $request): array
    {
        $values = [];
        foreach ($field->options['choices'] ?? [] as $choice) {
            $name = 'opt_choice_' . $choice['key'];
            $values[$name] = $request === null ? (string) $choice['label'] : $request->input($name);
        }

        return $values;
    }

    /**
     * The core options from the form values.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function options(string $type, array $values, ?FieldDef $field): array
    {
        $o = $field?->options ?? [];

        return match (self::KINDS[$type]) {
            'text' => ['max_length' => $values['opt_max_length']],
            'number' => ['decimals' => $values['opt_decimals'], 'min' => $values['opt_min'], 'max' => $values['opt_max']],
            'currency' => ['code' => $values['opt_code'], 'minor_digits' => $field === null ? $values['opt_minor_digits'] : ($o['minor_digits'] ?? null)],
            'seconds' => ['seconds' => $values['opt_seconds'] === '1'],
            'choice' => ['choices' => self::choices($values, $field)],
            'rating' => ['max' => $values['opt_max']],
            'attachment' => ['images_only' => $values['opt_images_only'] === '1'],
            'auto-number' => ['start' => $field === null ? $values['opt_start'] : ($o['start'] ?? null)],
            'relation' => ['links' => $values['opt_this_max']],
            default => [],
        };
    }

    /**
     * Choices: the existing ones under their keys (an emptied box removes
     * that choice, which the core does when the label is empty), then one
     * per non-empty line of the text area.
     *
     * @param array<string, string> $values
     * @return list<array{key: string, label: string}|string>
     */
    private static function choices(array $values, ?FieldDef $field): array
    {
        $choices = [];
        foreach ($field?->options['choices'] ?? [] as $choice) {
            $choices[] = ['key' => (string) $choice['key'], 'label' => $values['opt_choice_' . $choice['key']] ?? ''];
        }
        foreach (preg_split('/\r\n|\r|\n/', $values['opt_choices'] ?? '') ?: [] as $line) {
            if (trim($line) !== '') {
                $choices[] = $line;
            }
        }

        return $choices;
    }

    /** @return array{id: string, label: string, hint: string} the type's id and its string keys */
    private static function typeKeys(string $type): array
    {
        return ['id' => $type, 'label' => 'type.' . $type, 'hint' => 'type.' . $type . '.hint'];
    }

    private static function text(mixed $value): string
    {
        return is_int($value) || is_float($value) || is_string($value) ? (string) $value : '';
    }

    // Pages.

    /**
     * Core field name => element id for the field form of $type.
     *
     * @return array<string, string>
     */
    private static function fieldMap(string $type, bool $edit): array
    {
        $ids = match (self::KINDS[$type]) {
            'text' => ['max_length'],
            'number' => ['decimals', 'min', 'max'],
            'currency' => $edit ? ['code'] : ['code', 'minor_digits'],
            'seconds' => ['seconds'],
            'rating' => ['max'],
            'attachment' => ['images_only'],
            'auto-number' => $edit ? [] : ['start'],
            default => [],
        };
        $map = ['name' => 'field-name', 'help' => 'field-help'];
        foreach ($ids as $option) {
            $map['options.' . $option] = 'opt-' . str_replace('_', '-', $option);
        }
        if ($type === 'choice') {
            $map['options.choices'] = $edit ? 'opt-choices-new' : 'opt-choices';
        }
        if ($type === 'relation') {
            $map['options.links'] = 'opt-this-max';
            if (!$edit) {
                $map += ['target' => 'opt-target', 'reverse_name' => 'opt-reverse-name', 'options.other_links' => 'opt-other-max'];
            }
        }

        return $map;
    }

    /**
     * @param array{key: string, name: string}                                   $project
     * @param array{key: string, name: string, itemName: string, path: string}   $table
     * @param list<array{label: string, path: string|null}>                       $more
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(array $project, array $table, array $more): array
    {
        return ProjectController::crumbs($this->context, $project['key'], $project['name'], [$table['key'], $table['name']], $more);
    }

    /**
     * @param array{key: string, name: string}                                 $project
     * @param array{key: string, name: string, itemName: string, path: string} $table
     */
    private function choosePage(array $project, array $table, bool $error): Response
    {
        $view = $this->context->view();
        $text = $view->text('schema.field.type_required');
        $groups = [];
        foreach (self::GROUPS as $group => $types) {
            $groups[] = ['label' => 'type.group.' . $group, 'types' => array_map(self::typeKeys(...), $types)];
        }

        return $this->context->page('fields/choose-type', [
            'title' => $view->text('schema.field.new.title', ['table' => $table['name']]),
            'breadcrumb' => $this->crumbs($project, $table, [['label' => $view->text('schema.field.new.crumb'), 'path' => null]]),
            'problems' => $error ? [['field' => 'field-type', 'text' => $text]] : [],
            'error' => $error,
            'table' => ['name' => $table['name']],
            'groups' => $groups,
            'action' => $table['path'] . '/new',
            'cancel' => $table['path'],
        ], $error ? 422 : 200, 'projects');
    }

    /**
     * @param array{key: string, name: string}                                 $project
     * @param array{key: string, name: string, itemName: string, path: string} $table
     * @param array<string, string>                                            $values
     * @param list<Problem>                                                    $problems
     */
    private function newPage(array $project, array $table, string $type, array $values, array $problems, int $status): Response
    {
        $view = $this->context->view();
        [$list, $errors] = ProjectController::problems($this->context, $problems, self::fieldMap($type, false));
        $tables = [];
        if ($type === 'relation') {
            foreach ($this->context->services()->operations()->schema()->tables($this->context->actor(), $project['key']) as $summary) {
                $tables[] = ['key' => $summary->table->key, 'name' => $summary->table->name, 'isThis' => $summary->table->key === $table['key']];
            }
        }
        $typeLabel = $view->text('type.' . $type);

        return $this->context->page('fields/new', [
            'title' => $view->text('schema.field.new.type_title', ['type' => $typeLabel]),
            'breadcrumb' => $this->crumbs($project, $table, [['label' => $view->text('schema.field.new.crumb'), 'path' => null]]),
            'problems' => $list,
            'errors' => $errors,
            'type' => $type,
            'typeLabel' => $typeLabel,
            'table' => ['name' => $table['name']],
            'showRequired' => !in_array($type, self::NEVER_REQUIRED, true),
            'options' => $this->optionsData($type, 'new', $values, $errors, $table, $tables, null),
            'values' => $values,
            'action' => $table['path'],
            'cancel' => $table['path'],
            'chooseType' => $table['path'] . '/new',
        ], $status, 'projects');
    }

    /**
     * @param array{key: string, name: string}                                 $project
     * @param array{key: string, name: string, itemName: string, path: string} $table
     * @param array<string, string>                                            $values
     * @param list<Problem>                                                    $problems
     */
    private function editPage(array $project, array $table, FieldDesign $design, array $values, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $field = $design->field;
        [$list, $errors] = ProjectController::problems($this->context, $problems, self::fieldMap($field->type, true));
        $path = $table['path'] . '/' . $field->key;
        $typeLabel = $design->linkedTableName === null
            ? $view->text('type.' . $field->type)
            : $view->text('type.relation.to', ['table' => $design->linkedTableName]);

        return $this->context->page('fields/edit', [
            'title' => $view->text('schema.field.edit.title', ['field' => $field->name]),
            'breadcrumb' => $this->crumbs($project, $table, [['label' => $field->name, 'path' => null]]),
            'problems' => $list,
            'errors' => $errors,
            'field' => ['name' => $field->name],
            'typeLabel' => $typeLabel,
            'canChangeType' => $design->convertibleTo !== [],
            'showValues' => !in_array($field->type, self::NO_VALUE_COUNT, true),
            'valueCount' => $design->valueCount,
            'valueText' => $view->format()->number($design->valueCount),
            'showRequired' => !in_array($field->type, self::NEVER_REQUIRED, true),
            'options' => $this->optionsData($field->type, 'edit', $values, $errors, $table, [], $design),
            'values' => $values,
            'action' => $path,
            'cancel' => $table['path'],
            'typePath' => $path . '/type',
            'deletePath' => $path . '/delete',
        ], $status, 'projects');
    }

    /**
     * The data of the options partial (schema-editor.md section 7.3).
     *
     * @param array<string, string>                                            $values
     * @param array<string, string>                                            $errors
     * @param array{key: string, name: string, itemName: string, path: string} $table
     * @param list<array{key: string, name: string, isThis: bool}>             $tables
     * @return array<string, mixed>
     */
    private function optionsData(string $type, string $mode, array $values, array $errors, array $table, array $tables, ?FieldDesign $design): array
    {
        $data = [
            'kind' => self::KINDS[$type],
            'mode' => $mode,
            'values' => $values,
            'errors' => $errors,
            'itemName' => $table['itemName'],
            'tables' => $tables,
            'max' => self::MAX_LENGTH[$type] ?? '',
        ];
        if ($design === null) {
            return $data;
        }
        $o = $design->field->options;
        $data['linkedTable'] = $design->linkedTableName ?? '';
        $data['digits'] = self::text($o['minor_digits'] ?? '');
        $data['start'] = self::text($o['start'] ?? '');
        $data['next'] = self::text($o['next'] ?? '');
        $choices = [];
        foreach ($o['choices'] ?? [] as $i => $choice) {
            $name = 'opt_choice_' . $choice['key'];
            $choices[] = ['name' => $name, 'value' => $values[$name] ?? '', 'number' => $i + 1];
        }
        $data['choices'] = $choices;

        return $data;
    }

    /**
     * @param array{key: string, name: string}                                 $project
     * @param array{key: string, name: string, itemName: string, path: string} $table
     * @param list<Problem>                                                    $problems
     */
    private function typePage(array $project, array $table, FieldDesign $design, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $field = $design->field;
        [$list, $errors] = ProjectController::problems($this->context, $problems, ['type' => 'type-choice', 'options.choices' => 'type-choice']);
        $path = $table['path'] . '/' . $field->key;

        return $this->context->page('fields/type', [
            'title' => $view->text('schema.type.title', ['field' => $field->name]),
            'breadcrumb' => $this->crumbs($project, $table, [
                ['label' => $field->name, 'path' => $path],
                ['label' => $view->text('schema.type.crumb'), 'path' => null],
            ]),
            'problems' => $list,
            'error' => $errors['type-choice'] ?? null,
            'field' => ['name' => $field->name],
            'typeLabel' => $view->text('type.' . $field->type),
            'types' => array_map(self::typeKeys(...), $design->convertibleTo),
            'action' => $path . '/type',
            'cancel' => $path,
        ], $status, 'projects');
    }

    /**
     * The number of values the person accepted on the confirmation page:
     * the posted `confirm` when it is a plain non-negative integer, else 0
     * (P82). The core compares it with the real count.
     */
    private static function acceptedLoss(Request $request): int
    {
        $confirm = $request->input('confirm');

        return preg_match('/^(?:0|[1-9][0-9]{0,17})\z/', $confirm) === 1 ? (int) $confirm : 0;
    }

    /**
     * Screen 11: the change would clear values. Every submitted field is
     * posted again as a hidden input, except `_csrf` (the form prints the
     * current token) and `confirm` (P59), which carries the number of
     * values shown (P82).
     *
     * @param array{key: string, name: string}                                 $project
     * @param array{key: string, name: string, itemName: string, path: string} $table
     */
    private function confirmPage(
        Request $request,
        array $project,
        array $table,
        FieldDef $field,
        ?string $lastCrumb,
        ConfirmationRequired $needed,
        string $action,
        string $back,
    ): Response {
        $view = $this->context->view();
        $hidden = [];
        foreach ($request->post as $name => $value) {
            if (is_string($name) && is_string($value) && $name !== '_csrf' && $name !== 'confirm') {
                $hidden[] = ['name' => $name, 'value' => $value];
            }
        }
        $crumbs = [['label' => $field->name, 'path' => $lastCrumb === null ? null : $table['path'] . '/' . $field->key]];
        if ($lastCrumb !== null) {
            $crumbs[] = ['label' => $lastCrumb, 'path' => null];
        }

        return $this->context->page('fields/confirm', [
            'title' => $view->text('schema.confirm.title', ['values' => $needed->values]),
            'breadcrumb' => $this->crumbs($project, $table, $crumbs),
            'values' => $needed->values,
            'records' => $needed->records,
            'hidden' => $hidden,
            'action' => $action,
            'back' => $back,
        ], 409, 'projects');
    }
}
