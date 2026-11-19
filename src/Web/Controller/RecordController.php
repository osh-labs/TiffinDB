<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use OutOfBoundsException;
use TiffinDB\Core\App\ConflictView;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordScreen;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Format;
use TiffinDB\Web\Ids;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The record screens (docs/design/m2/record.md): the record form, new
 * record, record in the trash, edit conflict, move to trash, and restore.
 *
 * Every decision about access and every check of what was typed is made by
 * RecordOperations; this controller reads the view state only through
 * ListRequest::fromQuery() and carries ListRequest::toQuery() on every
 * address it builds. Saving reads exactly the inputs the bound form shows
 * (BoundForm::editableKeys(), M2 spec part 2 section 2.1), so fields the
 * form does not name are never posted or changed.
 *
 * The bound form reaches the templates as plain arrays (templates cannot
 * call methods on data, and the web layer does not name Core\Forms
 * classes): nodes are told apart by kind(), and their texts are passed on
 * unescaped for the templates to escape once at output. Inline content of
 * a stored form becomes the {kind, text|children} arrays of the
 * form-inline partial, which prints links as their text only (ADR 0014
 * decision 1).
 */
final class RecordController
{
    /** @var array<string, array<string, array{name: string, item: string}>> project key => table key => names */
    private array $tableFacts = [];

    /** @var array<string, array<string, string>> project and table => relation field key => linked table name */
    private array $linkedNames = [];

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------------ screens

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $screen = $this->context->services()->operations()->records()
            ->blank($this->context->actor(), $project, $table, ListRequest::fromQuery($request->query));

        return $this->formPage($screen, [], 200, null);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $screen = $this->context->services()->operations()->records()
            ->screen($this->context->actor(), $project, $table, $params['record'] ?? '', ListRequest::fromQuery($request->query));
        if ($screen->trashed) {
            return $this->trashedPage($screen);
        }

        return $this->formPage($screen, [], 200, null);
    }

    // ------------------------------------------------------------------ writes

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $actor = $this->context->actor();
        $records = $this->context->services()->operations()->records();
        $state = ListRequest::fromQuery($request->query);
        $screen = $records->blank($actor, $project, $table, $state);
        $input = self::input($request, $screen);
        try {
            $id = $records->create($actor, $project, $table, $input);
        } catch (ValidationFailed $failed) {
            $again = $records->redisplay($actor, $project, $table, null, $input, $failed->problems, $state);

            return $this->formPage($again, $failed->problems, 422, null);
        }
        $this->context->flash('notice.record_added', ['item' => $screen->table->itemName]);

        return $this->context->redirect(self::recordPath($project, $table, $id), $screen->request->toQuery());
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $record = $params['record'] ?? '';
        $actor = $this->context->actor();
        $records = $this->context->services()->operations()->records();
        $state = ListRequest::fromQuery($request->query);
        // The screen names the inputs the form shows. For a record in the
        // trash it has no form; update() then answers NotFound (a stale form).
        $screen = $records->screen($actor, $project, $table, $record, $state);
        $input = self::input($request, $screen);
        $version = $request->input('version');
        try {
            $records->update($actor, $project, $table, $record, $input, $version);
        } catch (ValidationFailed $failed) {
            $again = $records->redisplay($actor, $project, $table, $record, $input, $failed->problems, $state);

            // The version posted is kept, so a change saved meanwhile is
            // still caught when the corrected form is saved.
            return $this->formPage($again, $failed->problems, 422, $version);
        } catch (Conflict) {
            return $this->conflictPage($records->conflict($actor, $project, $table, $record, $input, $state), $input);
        }
        $this->context->flash('notice.record_saved');

        return $this->context->redirect(self::recordPath($project, $table, $screen->id ?? 0), $screen->request->toQuery());
    }

    /** @param array<string, string> $params */
    public function trash(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $operations = $this->context->services()->operations();
        $actor = $this->context->actor();
        $ref = $operations->records()->trash($actor, $project, $table, $params['record'] ?? '');
        $item = $this->itemName($project, $table);
        $this->context->flash('notice.record_trashed', [
            'title' => $ref->title ?? $this->untitled($item, $ref->id),
            'undo' => self::recordPath($project, $table, $ref->id) . '/restore',
        ]);

        return $this->context->redirect(self::listPath($project, $table), ListRequest::fromQuery($request->query)->toQuery());
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $actor = $this->context->actor();
        $ref = $this->context->services()->operations()->records()->restore($actor, $project, $table, $params['record'] ?? '');
        $this->context->flash('notice.record_restored', [
            'title' => $ref->title ?? $this->untitled($this->itemName($project, $table), $ref->id),
        ]);

        return $this->context->redirect(self::recordPath($project, $table, $ref->id), ListRequest::fromQuery($request->query)->toQuery());
    }

    // ------------------------------------------------------------------ pages

    /**
     * The record form or the new record form (record.md sections 2 and 3).
     *
     * @param list<Problem> $problems from ValidationFailed (422), else []
     * @param string|null   $version  the version posted with a form shown again, else the screen's
     */
    private function formPage(RecordScreen $screen, array $problems, int $status, ?string $version): Response
    {
        $view = $this->context->view();
        $format = $view->format();
        $project = $screen->project->key;
        $table = $screen->table;
        $item = $table->itemName;
        $state = $screen->request->toQuery();
        $form = $screen->form;
        $tables = $this->tableFacts($project);
        $nodes = $form === null ? [] : $this->nodes($form->nodes, $project, $tables);

        if ($screen->isNew) {
            $title = $view->text('records.new.title', ['item' => $item]);
            $current = $view->text('records.new.crumb', ['item' => $item]);
            $action = self::listPath($project, $table->key) . '/records';
        } else {
            $title = $this->titleOf($screen);
            $current = $title;
            $action = self::recordPath($project, $table->key, $screen->id ?? 0);
        }

        $lead = null;
        $when = $screen->updatedAt ?? $screen->createdAt;
        if (!$screen->isNew && $when !== null) {
            $when = $format->savedAt($when);
            $lead = $screen->position === null
                ? $view->text('records.lead_saved', ['when' => $when])
                : $view->text('records.lead', [
                    'Item' => self::upperFirst($item),
                    'position' => $format->number($screen->position->index),
                    'total' => $format->number($screen->position->total),
                    'when' => $when,
                ]);
        }

        $nav = null;
        $position = $screen->position;
        if (!$screen->isNew && $position !== null) {
            $nav = [
                'previous' => $position->previousId === null ? null : [
                    'path' => self::recordPath($project, $table->key, $position->previousId),
                    'label' => $view->text('records.previous_label', ['item' => $item, 'title' => $position->previousTitle ?? $this->untitled($item, $position->previousId)]),
                ],
                'next' => $position->nextId === null ? null : [
                    'path' => self::recordPath($project, $table->key, $position->nextId),
                    'label' => $view->text('records.next_label', ['item' => $item, 'title' => $position->nextTitle ?? $this->untitled($item, $position->nextId)]),
                ],
            ];
        }

        $meta = null;
        if (!$screen->isNew && $screen->createdAt !== null && $screen->updatedAt !== null) {
            $meta = [
                'added' => $this->day($screen->createdAt),
                'addedBy' => $screen->createdByName,
                'changed' => $this->day($screen->updatedAt),
                'changedBy' => $screen->updatedByName,
            ];
        }

        return $this->context->page('records/form', [
            'title' => $title,
            'layout' => $form !== null && $form->hasGrid() ? 'record' : 'record-narrow',
            'breadcrumb' => $this->crumbs($screen, $current),
            'problems' => self::summary($problems, $nodes, $format),
            'heading' => $title,
            'lead' => $lead,
            'nav' => $nav,
            'state' => $state,
            'action' => $action,
            'isNew' => $screen->isNew,
            'version' => $version ?? $screen->version ?? '',
            'nodes' => $nodes,
            'list' => self::listPath($project, $table->key),
            'cancelLabel' => $view->text('form.cancel'),
            'trash' => $screen->isNew ? null : $action . '/trash',
            'meta' => $meta,
        ], $status);
    }

    /** A record in the trash (record.md section 4). */
    private function trashedPage(RecordScreen $screen): Response
    {
        $project = $screen->project->key;
        $table = $screen->table;
        $title = $this->titleOf($screen);
        $path = self::recordPath($project, $table->key, $screen->id ?? 0);

        return $this->context->page('records/trashed', [
            'title' => $title,
            'layout' => 'record-narrow',
            'breadcrumb' => $this->crumbs($screen, $title),
            'item' => $table->itemName,
            'record' => $title,
            'date' => $screen->trashedAt === null ? '' : $this->day($screen->trashedAt),
            'restore' => $path . '/restore',
            'list' => self::listPath($project, $table->key),
            'state' => $screen->request->toQuery(),
        ]);
    }

    /**
     * The edit conflict (record.md section 5), status 409.
     *
     * @param array<string, string> $input what the form posted, field key => text
     */
    private function conflictPage(ConflictView $conflict, array $input): Response
    {
        $view = $this->context->view();
        $screen = $conflict->screen;
        $project = $screen->project->key;
        $table = $screen->table;
        $title = $this->titleOf($screen);
        $when = $screen->updatedAt === null ? '' : $this->context->view()->format()->savedAt($screen->updatedAt);

        $rows = [];
        foreach ($conflict->rows as $row) {
            $checkbox = $row->field->type === 'checkbox';
            $rows[] = [
                'label' => $row->label,
                'field' => $row->field,
                'saved' => $row->saved,
                'savedEmpty' => !$checkbox && ($row->saved === null || $row->saved === ''),
                'yours' => $checkbox ? $view->text($row->yours === '1' ? 'value.yes' : 'value.no') : $row->yours,
                'yoursEmpty' => !$checkbox && $row->yours === '',
                'multiline' => $row->field->type === 'long_text',
            ];
        }
        $hidden = [];
        foreach ($input as $key => $text) {
            $hidden[] = ['name' => 'f_' . $key, 'value' => $text];
        }

        return $this->context->page('records/conflict', [
            'title' => $title,
            'layout' => 'record-narrow',
            'breadcrumb' => $this->crumbs($screen, $title),
            'item' => $table->itemName,
            'name' => $screen->updatedByName,
            'when' => $when,
            'rows' => $rows,
            'path' => self::recordPath($project, $table->key, $screen->id ?? 0),
            'state' => $screen->request->toQuery(),
            'version' => $screen->version ?? '',
            'hidden' => $hidden,
        ], 409);
    }

    // ------------------------------------------------------------------ the bound form

    /**
     * The bound form's nodes as plain arrays for the record-form partials.
     *
     * @param list<object> $nodes BoundNode values
     * @param array<string, array{name: string, item: string}> $tables table key => names
     * @return list<array<string, mixed>>
     */
    private function nodes(array $nodes, string $project, array $tables): array
    {
        $list = [];
        foreach ($nodes as $node) {
            $kind = $node->kind();
            if ($kind === 'section') {
                $list[] = [
                    'kind' => 'section',
                    'legend' => $node->legend,
                    'grid' => $node->grid === true,
                    'spanFull' => $node->spanFull === true,
                    'children' => $this->nodes($node->children, $project, $tables),
                ];
            } elseif ($kind === 'field') {
                $field = $this->field($node, $project, $tables);
                if ($field !== null) {
                    $list[] = $field;
                }
            } elseif ($kind === 'heading') {
                $list[] = ['kind' => 'heading', 'level' => $node->level === 3 ? 3 : 2, 'children' => self::inlines($node->children)];
            } elseif ($kind === 'paragraph') {
                $list[] = ['kind' => 'paragraph', 'children' => self::inlines($node->children)];
            } elseif ($kind === 'divider') {
                $list[] = ['kind' => 'divider'];
            }
        }

        return $list;
    }

    /**
     * One field slot (record.md section 2.1); null for a type the form has
     * no markup for.
     *
     * @param array<string, array{name: string, item: string}> $tables
     * @return array<string, mixed>|null
     */
    private function field(object $node, string $project, array $tables): ?array
    {
        $view = $this->context->view();
        $format = $view->format();
        /** @var FieldDef $def */
        $def = $node->field;
        $type = $def->type;
        if ($node->editable === true) {
            $control = match ($type) {
                'rating' => 'rating',
                'checkbox' => 'checkbox',
                default => 'input',
            };
        } else {
            $control = match ($type) {
                'auto_number', 'relation', 'attachment' => $type,
                default => null,
            };
        }
        if ($control === null) {
            return null;
        }

        $errors = [];
        foreach ($node->problems as $problem) {
            $errors[] = $format->problem($problem);
        }
        $duration = null;
        if ($type === 'duration' && $control === 'input') {
            $duration = ($def->options['seconds'] ?? false) === true ? 'seconds' : 'minutes';
        }
        $hasHelp = $control !== 'relation' && ($def->help !== '' || $duration !== null);
        $describedBy = [];
        if ($hasHelp) {
            $describedBy[] = 'help';
        }
        if ($errors !== []) {
            $describedBy[] = 'err';
        }

        $links = [];
        $linkedTable = null;
        if ($control === 'relation') {
            foreach ($node->links as $link) {
                $links[] = [
                    'path' => self::recordPath($project, $link->tableKey, $link->id),
                    'title' => $link->title ?? $this->untitled($tables[$link->tableKey]['item'] ?? '', $link->id),
                ];
            }
            $linkedTable = $this->linkedTableName($project, $def);
        }
        $files = [];
        if ($control === 'attachment') {
            foreach ($node->files as $file) {
                $files[] = [
                    'name' => $file->name,
                    'type' => $view->text(match (true) {
                        $file->mediaType === 'application/pdf' => 'file.type.pdf',
                        str_starts_with($file->mediaType, 'image/') => 'file.type.image',
                        default => 'file.type.other',
                    }),
                    'size' => $format->fileSize(max(0, $file->size)),
                ];
            }
        }

        return [
            'kind' => 'field',
            'control' => $control,
            'field' => $def,
            'key' => $def->key,
            'label' => $node->label,
            'currencyName' => $type === 'currency' ? $this->currencyName(is_string($def->options['code'] ?? null) ? $def->options['code'] : 'USD') : null,
            'text' => $node->inputText,
            'submitted' => $node->submitted === true,
            'required' => $def->required && $node->editable === true,
            'invalid' => $errors !== [],
            'errors' => $errors,
            'help' => $def->help,
            'hasHelp' => $hasHelp,
            'duration' => $duration,
            'describedBy' => $describedBy,
            'spanFull' => $node->spanFull === true,
            'labelHidden' => $node->labelHidden === true,
            'isNew' => $node->isNew === true,
            'value' => $control === 'auto_number' ? $node->value : null,
            'links' => $links,
            'linkedTable' => $linkedTable,
            'files' => $files,
        ];
    }

    /**
     * Inline nodes of a stored form as the form-inline partial's arrays.
     * Unknown kinds are dropped.
     *
     * @param list<object> $inlines
     * @return list<array<string, mixed>>
     */
    private static function inlines(array $inlines): array
    {
        $list = [];
        foreach ($inlines as $inline) {
            $kind = $inline->kind();
            if ($kind === 'text') {
                $list[] = ['kind' => 'text', 'text' => (string) $inline->text];
            } elseif ($kind === 'strong' || $kind === 'em' || $kind === 'link') {
                $list[] = ['kind' => $kind, 'children' => self::inlines($inline->children)];
            } elseif ($kind === 'br') {
                $list[] = ['kind' => 'br'];
            }
        }

        return $list;
    }

    /**
     * The error summary: each problem's text, linked to its field's
     * control id when the form shows that field.
     *
     * @param list<Problem> $problems
     * @param list<array<string, mixed>> $nodes
     * @return list<array{field: string|null, text: string}>
     */
    private static function summary(array $problems, array $nodes, Format $format): array
    {
        $shown = self::shownKeys($nodes);
        $list = [];
        foreach ($problems as $problem) {
            $id = $problem->field !== null && isset($shown[$problem->field]) ? Ids::make('f', $problem->field) : null;
            $list[] = ['field' => $id, 'text' => $format->problem($problem)];
        }

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return array<string, true> keys of the fields with an element id on the page
     */
    private static function shownKeys(array $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            if ($node['kind'] === 'field') {
                $keys[$node['key']] = true;
            } elseif ($node['kind'] === 'section') {
                $keys += self::shownKeys($node['children']);
            }
        }

        return $keys;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The text typed for exactly the inputs the form shows; a missing input
     * (an unticked checkbox) is ''.
     *
     * @return array<string, string>
     */
    private static function input(Request $request, RecordScreen $screen): array
    {
        $input = [];
        foreach ($screen->form?->editableKeys() ?? [] as $key) {
            $input[$key] = $request->input('f_' . $key);
        }

        return $input;
    }

    /**
     * @param array<string, string> $params
     * @return array{0: string, 1: string}
     */
    private static function keys(array $params): array
    {
        return [$params['project'] ?? '', $params['table'] ?? ''];
    }

    private static function listPath(string $project, string $table): string
    {
        return '/projects/' . $project . '/tables/' . $table;
    }

    private static function recordPath(string $project, string $table, int $id): string
    {
        return self::listPath($project, $table) . '/records/' . $id;
    }

    /**
     * Breadcrumb: project, table (the list, with its view state), and the
     * current page.
     *
     * @return list<array{label: string, path: string|null, query?: array<string, string>}>
     */
    private function crumbs(RecordScreen $screen, string $current): array
    {
        $project = $screen->project;

        return [
            ['label' => $project->name, 'path' => '/projects/' . $project->key],
            ['label' => $screen->table->name, 'path' => self::listPath($project->key, $screen->table->key), 'query' => $screen->request->toQuery()],
            ['label' => $current, 'path' => null],
        ];
    }

    private function titleOf(RecordScreen $screen): string
    {
        return $screen->title ?? $this->untitled($screen->table->itemName, $screen->id ?? 0);
    }

    /** "{Item} {id}" for a record without a title. */
    private function untitled(string $itemName, int $id): string
    {
        return $this->context->view()->text('records.untitled', ['Item' => self::upperFirst($itemName), 'id' => (string) $id]);
    }

    private static function upperFirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /** The relative day of a stored timestamp, in lower case for today and yesterday. */
    private function day(string $timestamp): string
    {
        $view = $this->context->view();
        $day = $view->format()->relativeDate($timestamp);
        if ($day === $view->text('date.today')) {
            return $view->text('date.today_lower');
        }
        if ($day === $view->text('date.yesterday')) {
            return $view->text('date.yesterday_lower');
        }

        return $day;
    }

    /**
     * The name of a currency (`currency.<CODE>`) when the strings define
     * one, else the code: the rule of ValueText::currencyName(), which
     * controllers cannot reach (the view does not expose its Strings).
     */
    private function currencyName(string $code): string
    {
        if (preg_match('/^[A-Z]{3}\z/', $code) !== 1) {
            return $code;
        }
        try {
            return $this->context->view()->text('currency.' . $code);
        } catch (OutOfBoundsException) {
            return $code;
        }
    }

    /**
     * Names and item names of the project's tables, for untitled links.
     *
     * @return array<string, array{name: string, item: string}>
     */
    private function tableFacts(string $project): array
    {
        if (!isset($this->tableFacts[$project])) {
            $facts = [];
            foreach ($this->context->services()->operations()->schema()->tables($this->context->actor(), $project) as $summary) {
                $facts[$summary->table->key] = ['name' => $summary->table->name, 'item' => $summary->table->itemName];
            }
            $this->tableFacts[$project] = $facts;
        }

        return $this->tableFacts[$project];
    }

    private function itemName(string $project, string $table): string
    {
        return $this->tableFacts($project)[$table]['item'] ?? '';
    }

    /** The name of the table a relation field links to ("Linked to the {table} table."). */
    private function linkedTableName(string $project, FieldDef $field): string
    {
        if (!isset($this->linkedNames[$project . '/' . $field->tableKey])) {
            $names = [];
            $design = $this->context->services()->operations()->schema()->design($this->context->actor(), $project, $field->tableKey);
            foreach ($design->fields as $summary) {
                if ($summary->linkedTableName !== null) {
                    $names[$summary->field->key] = $summary->linkedTableName;
                }
            }
            $this->linkedNames[$project . '/' . $field->tableKey] = $names;
        }

        return $this->linkedNames[$project . '/' . $field->tableKey][$field->key] ?? '';
    }
}
