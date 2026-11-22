<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\CollectionEditor;
use TiffinDB\Core\App\EditorRule;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Ids;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Saved collections (docs/design/m4/collections.md; M4 spec part 2
 * sections 3 and 4; ADR 0019 decisions 5 and 7): the collection view, the
 * editor of a new or an existing collection, and the delete confirmation.
 *
 * Every decision about who may see or change what is made in Core\App
 * (CollectionOperations): an unknown project, table, or collection, and a
 * person without a grant, are NotFound there and reach the kernel as a
 * 404. The editor's posted draft goes to the core as posted (strings only
 * are read there); this controller only picks the action.
 *
 * The editor is one form whose submit buttons carry `action`: `save`
 * stores (303 to the view, or 422 with every problem); `preview`, `add`,
 * `remove-<n>`, and anything else redisplay the form with the posted
 * values (200, nothing stored; rule 5 of spec part 2 section 4).
 *
 * Templates receive plain values only: text is plain (escaped once, at
 * output), message keys are keys, paths are application paths.
 */
final class CollectionController
{
    /** Kind word of a rule's value control, by field type (collections.md section 4). */
    private const KINDS = [
        'text' => 'collections.kind.text', 'long_text' => 'collections.kind.text', 'url' => 'collections.kind.text',
        'email' => 'collections.kind.text', 'phone' => 'collections.kind.text',
        'number' => 'collections.kind.number', 'currency' => 'collections.kind.amount', 'rating' => 'collections.kind.rating',
        'duration' => 'collections.kind.duration', 'date' => 'collections.kind.date', 'date_time' => 'collections.kind.date',
        'choice' => 'collections.kind.choice', 'relation' => 'collections.kind.record',
    ];

    /** Text-like field types, whose `is` reads "is exactly". */
    private const TEXT_LIKE = ['text', 'long_text', 'url', 'email', 'phone'];

    /** Tests that take no value. */
    private const NO_VALUE = ['empty', 'not-empty', 'ticked', 'not-ticked'];

    /** @var array<string, string>|null see itemNames() */
    private ?array $itemNames = null;

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------------ view

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $asked = ListRequest::fromQuery($request->query);
        $listing = $this->context->services()->operations()->collections()->listing(
            $this->context->actor(),
            $params['project'] ?? '',
            $params['collection'] ?? '',
            $asked,
        );

        return (new RecordListController($this->context))->render($listing, $asked);
    }

    // ------------------------------------------------------------------ editor

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        return $this->editorPage($params, $params['table'] ?? '', null, null, 'show', [], 200);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        return $this->submit($request, $params, $params['table'] ?? '', null);
    }

    /** @param array<string, string> $params */
    public function editForm(Request $request, array $params): Response
    {
        return $this->editorPage($params, null, $params['collection'] ?? '', null, 'show', [], 200);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        return $this->submit($request, $params, null, $params['collection'] ?? '');
    }

    // ------------------------------------------------------------------ delete

    /** @param array<string, string> $params */
    public function deleteForm(Request $request, array $params): Response
    {
        $projectKey = $params['project'] ?? '';
        $summary = $this->context->services()->operations()->collections()->deleteScreen($this->context->actor(), $projectKey, $params['collection'] ?? '');
        $project = $this->context->services()->operations()->projects()->get($this->context->actor(), $projectKey);
        $view = $this->context->view();
        $path = '/projects/' . $project->key . '/collections/' . $summary->key;
        $table = $this->context->services()->operations()->records()->filterOptions($this->context->actor(), $project->key, $summary->tableKey, null)->table;

        return $this->context->page('collections/delete', [
            'title' => $view->text('collections.delete.title', ['name' => $summary->name]),
            'layout' => 'collection',
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $table->name, 'path' => '/projects/' . $project->key . '/tables/' . $table->key],
                ['label' => $view->text('collections.crumb', ['name' => $summary->name]), 'path' => $path . '/edit'],
                ['label' => $view->text('collections.delete.crumb'), 'path' => null],
            ],
            'name' => $summary->name,
            'items' => mb_strtolower($table->name),
            'action' => $path . '/delete',
            'cancel' => $path . '/edit',
        ]);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $projectKey = $params['project'] ?? '';
        $collectionKey = $params['collection'] ?? '';
        $collections = $this->context->services()->operations()->collections();
        $summary = $collections->deleteScreen($this->context->actor(), $projectKey, $collectionKey);
        $collections->delete($this->context->actor(), $projectKey, $summary->key);
        $this->context->flash('notice.collection_deleted', ['name' => $summary->name]);

        return $this->context->redirect('/projects/' . $projectKey . '/tables/' . $summary->tableKey);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, string> $params */
    private function submit(Request $request, array $params, ?string $tableKey, ?string $collectionKey): Response
    {
        $action = $request->input('action');
        $input = self::input($request);
        if ($action !== 'save') {
            return $this->editorPage($params, $tableKey, $collectionKey, $input, $action, [], 200);
        }
        $projectKey = $params['project'] ?? '';
        try {
            $key = $this->context->services()->operations()->collections()->save($this->context->actor(), $projectKey, $tableKey, $collectionKey, $input);
        } catch (ValidationFailed $failed) {
            return $this->editorPage($params, $tableKey, $collectionKey, $input, 'preview', $failed->problems, 422);
        }
        $this->context->flash('notice.collection_saved');

        return $this->context->redirect('/projects/' . $projectKey . '/collections/' . $key);
    }

    /**
     * The posted draft in the shape CollectionOperations reads: the three
     * texts, and `rules` only when it is an array (the core reads each row
     * defensively).
     *
     * @return array<string, mixed>
     */
    private static function input(Request $request): array
    {
        $rules = $request->post['rules'] ?? [];

        return [
            'name' => $request->input('name'),
            'match' => $request->input('match'),
            'sort' => $request->input('sort'),
            'rules' => is_array($rules) ? $rules : [],
        ];
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>|null $input
     * @param list<Problem> $problems
     */
    private function editorPage(array $params, ?string $tableKey, ?string $collectionKey, ?array $input, string $action, array $problems, int $status): Response
    {
        $actor = $this->context->actor();
        $editor = $this->context->services()->operations()->collections()->editor(
            $actor,
            $params['project'] ?? '',
            $tableKey,
            $collectionKey,
            $input,
            $action,
            $problems,
        );
        $view = $this->context->view();
        $format = $view->format();
        $project = $editor->project;
        $table = $editor->table;
        $tablePath = '/projects/' . $project->key . '/tables/' . $table->key;
        $path = $editor->key === null ? null : '/projects/' . $project->key . '/collections/' . $editor->key;
        $items = mb_strtolower($table->name);

        // Problems: the name, each row (its field select), the rest only in the summary.
        $summary = [];
        $rowProblems = [];
        $nameError = null;
        foreach ($editor->problems as $problem) {
            $text = $format->problem($problem);
            $id = null;
            if ($problem->field === 'name') {
                $id = Ids::make('f', 'name');
                $nameError ??= $text;
            } elseif ($problem->field !== null && preg_match('/^rule-([1-9][0-9]?)\z/', $problem->field, $m) === 1) {
                $id = Ids::make('f', 'rule', (int) $m[1], 'field');
                $rowProblems[(int) $m[1]][] = $text;
            }
            $summary[] = ['field' => $id, 'text' => $text];
        }

        $rows = [];
        foreach ($editor->rules as $rule) {
            $rows[] = $this->row($editor, $rule, $rowProblems[$rule->index] ?? []);
        }

        $sorts = [];
        $listController = new RecordListController($this->context);
        foreach ($editor->sorts as $option) {
            $sorts[] = ['token' => $option->token, 'label' => $listController->sortLabel($option), 'selected' => $option->token === $editor->sort];
        }

        // The title, heading, and breadcrumb name the collection as stored,
        // not the draft: a redisplayed draft may hold an empty or refused
        // name (P188).
        if ($editor->key === null) {
            $title = $view->text('collections.new.title');
            $crumb = $view->text('collections.new.crumb');
        } else {
            $stored = $this->context->services()->operations()->collections()->deleteScreen($actor, $project->key, $editor->key)->name;
            $title = $stored;
            $crumb = $view->text('collections.crumb', ['name' => $stored]);
        }

        return $this->context->page('collections/editor', [
            'title' => $title,
            'layout' => 'collection',
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $table->name, 'path' => $tablePath],
                ['label' => $crumb, 'path' => null],
            ],
            'problems' => $summary,
            'heading' => $title,
            'action' => $path === null ? $tablePath . '/collections' : $path . '/edit',
            'name' => $editor->name,
            'nameError' => $nameError,
            'items' => $items,
            'matchAny' => $editor->match->value === 'any',
            'rows' => $rows,
            'canAddRule' => $editor->canAddRule,
            'sorts' => $sorts,
            'cancel' => $path ?? $tablePath,
            'deletePath' => $path === null ? null : $path . '/delete',
            'preview' => $this->preview($editor, $listController),
        ], $status);
    }

    /**
     * The plain data of one rule row (rule-row partial).
     *
     * @param list<string> $problems
     * @return array<string, mixed>
     */
    private function row(CollectionEditor $editor, EditorRule $rule, array $problems): array
    {
        $n = $rule->index;
        $field = $rule->field;
        $names = ['field' => "rules[{$n}][field]", 'test' => "rules[{$n}][test]", 'value' => "rules[{$n}][value]", 'record' => "rules[{$n}][record]"];

        $fields = [];
        foreach ($editor->fields as $candidate) {
            $fields[] = ['key' => $candidate->key, 'label' => $candidate->name, 'selected' => $field !== null && $candidate->key === $field->key];
        }

        $tests = null;
        $value = null;
        if ($field !== null) {
            $tests = [];
            foreach ($rule->tests as $test) {
                $tests[] = ['op' => $test['op'], 'label' => self::testKey($field, $test['op']), 'selected' => $test['op'] === $rule->test];
            }
            if (!in_array($rule->test, self::NO_VALUE, true) && $rule->test !== '') {
                $value = $this->valueControl($editor, $rule, $field, $names);
            }
        }

        return [
            'index' => $n,
            'names' => $names,
            'remove' => 'remove-' . $n,
            'fields' => $fields,
            'tests' => $tests,
            'value' => $value,
            'problems' => $problems,
            'broken' => $rule->brokenReason,
        ];
    }

    /**
     * The value control of a row whose test takes a value.
     *
     * @param array{field: string, test: string, value: string, record: string} $names
     * @return array<string, mixed>
     */
    private function valueControl(CollectionEditor $editor, EditorRule $rule, FieldDef $field, array $names): array
    {
        $view = $this->context->view();
        $kind = self::KINDS[$field->type] ?? 'collections.kind.text';
        $control = [
            'control' => 'text',
            'name' => $names['value'],
            'value' => $rule->value,
            'label' => $view->text('collections.rule.value_label', ['kind' => $view->text($kind), 'n' => (string) $rule->index]),
            'options' => [],
            'help' => null,
            'more' => false,
        ];
        if (in_array($field->type, ['number', 'currency'], true)) {
            $control['control'] = 'decimal';
        } elseif ($field->type === 'duration') {
            $control['help'] = 'input.duration.help';
        } elseif ($field->type === 'date' || $field->type === 'date_time') {
            $control['control'] = 'date';
        } elseif ($field->type === 'rating') {
            $control['control'] = 'select';
            $max = is_int($field->options['max'] ?? null) ? max(1, min(10, $field->options['max'])) : 5;
            for ($i = 1; $i <= $max; $i++) {
                $control['options'][] = ['value' => (string) $i, 'label' => (string) $i, 'selected' => $rule->value === (string) $i];
            }
        } elseif ($field->type === 'choice') {
            $control['control'] = 'select';
            foreach ($rule->choices as $choice) {
                $control['options'][] = ['value' => $choice['key'], 'label' => $choice['label'], 'selected' => $rule->value === $choice['key']];
            }
        } elseif ($field->type === 'relation') {
            $control['control'] = 'select';
            $control['name'] = $names['record'];
            foreach ($rule->records as $item) {
                $title = $item->title;
                if ($title === null || $title === '') {
                    $title = $view->text('lists.untitled', ['Item' => $this->itemNames($editor)[$item->tableKey] ?? '', 'id' => (string) $item->id]);
                }
                $id = (string) $item->id;
                $control['options'][] = ['value' => $id, 'label' => $title, 'selected' => $rule->record === $id];
            }
            $control['more'] = $rule->moreRecords;
        }
        // A choice or record select with nothing chosen yet starts with an
        // empty option, so it never shows a value that would not be posted
        // as chosen (a rating always has one).
        $control['placeholder'] = in_array($field->type, ['choice', 'relation'], true)
            && !in_array(true, array_column($control['options'], 'selected'), true);

        return $control;
    }

    /**
     * Table key => item name with a capital first letter, for records
     * without a title; read once per request, only when needed.
     *
     * @return array<string, string>
     */
    private function itemNames(CollectionEditor $editor): array
    {
        if ($this->itemNames === null) {
            $this->itemNames = [];
            $options = $this->context->services()->operations()->records()->filterOptions($this->context->actor(), $editor->project->key, $editor->table->key, null);
            foreach ($options->tables as $summary) {
                $this->itemNames[$summary->table->key] = RecordListController::capital($summary->table->itemName);
            }
        }

        return $this->itemNames;
    }

    /** The message key of a test as the editor prints it (collections.md section 4). */
    private static function testKey(FieldDef $field, string $op): string
    {
        if ($op === 'is' && in_array($field->type, self::TEXT_LIKE, true)) {
            return 'collections.test.is-exactly';
        }
        if ($op === 'empty' && $field->type === 'choice') {
            return 'collections.test.not-chosen';
        }
        if ($op === 'empty' && $field->type === 'relation') {
            return 'collections.test.no-links';
        }

        return 'collections.test.' . $op;
    }

    /**
     * The preview section's data: the heading, then either the table of the
     * first records, "nothing matches", or "fix the rules" when the draft
     * has problems.
     *
     * @return array<string, mixed>
     */
    private function preview(CollectionEditor $editor, RecordListController $listController): array
    {
        $view = $this->context->view();
        $format = $view->format();
        $table = $editor->table;
        $count = $editor->matchCount;
        $shown = count($editor->preview);
        $heading = $count === 1
            ? $view->text('collections.preview.heading_one', ['count' => $format->number($count), 'item' => $table->itemName])
            : $view->text('collections.preview.heading_many', ['count' => $format->number($count), 'items' => mb_strtolower($table->name)]);

        $data = [
            'heading' => $heading,
            'shown' => $count > $shown ? $format->number($shown) : null,
            'state' => $editor->problems !== [] ? 'fix' : ($count === 0 ? 'none' : 'table'),
            'caption' => $view->text('collections.preview.caption', ['shown' => $format->number($shown)]),
            'columns' => [],
            'rows' => [],
            'itemNames' => [],
        ];
        if ($data['state'] !== 'table') {
            return $data;
        }

        $columns = [];
        $cellColumns = $editor->previewColumns;
        if ($table->titleFieldKey === null) {
            $columns[] = ['header' => $view->text('lists.col.record', ['Item' => RecordListController::capital($table->itemName)]), 'numeric' => false, 'sort' => null];
        } else {
            array_shift($cellColumns);
        }
        foreach ($editor->previewColumns as $field) {
            $head = $listController->columnHeader($field, $editor->sort);
            $columns[] = ['header' => $head['header'], 'numeric' => RecordListController::isNumeric($field), 'sort' => $head['sort']];
        }

        $base = '/projects/' . $editor->project->key . '/tables/' . $table->key;
        $query = $editor->key === null ? [] : ['collection' => $editor->key];
        $rows = [];
        foreach ($editor->preview as $row) {
            $cells = [];
            foreach ($cellColumns as $field) {
                $cell = $row->cells[$field->key] ?? null;
                $cells[] = $cell === null
                    ? ['field' => $field, 'value' => null, 'links' => null, 'moreLinks' => 0, 'fileCount' => null]
                    : RecordListController::cellData($editor->project->key, $cell);
            }
            $title = $row->title === null || $row->title === ''
                ? $view->text('lists.untitled', ['Item' => RecordListController::capital($table->itemName), 'id' => (string) $row->id])
                : $row->title;
            $rows[] = ['path' => $base . '/records/' . $row->id, 'query' => $query, 'title' => $title, 'cells' => $cells];
        }
        $data['columns'] = $columns;
        $data['rows'] = $rows;
        $data['itemNames'] = $this->itemNames($editor);

        return $data;
    }
}
