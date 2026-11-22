<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use OutOfBoundsException;
use TiffinDB\Core\App\ConflictView;
use TiffinDB\Core\App\LinkOrigin;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordScreen;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Cardinality;
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
 *
 * M4 (docs/design/m4/record-controls.md): relation and attachment slots
 * get their controls (the link-control, link-rows, and file-rows
 * partials), whose paths are built here only from keys and ids the core
 * returned; a many-link field with links gets its unlink form after the
 * record form; a servable form image is shown. A new record opened from a
 * picker carries `from` (validated by the core, P154) and goes back to
 * that picker when saved. With a collection in the list state, "Back to
 * list" and the list redirects go to the collection (M4 spec part 2
 * section 4 rule 8).
 */
final class RecordController
{
    /** @var array<string, array<string, array{name: string, item: string}>> project key => table key => names */
    private array $tableFacts = [];

    /** @var array<string, array<string, array{table: string, onlyOne: bool}>> project and table => relation field key => facts */
    private array $relationFacts = [];

    /** A collection key in the list state (Keys::isValid shape), for the collection's address. */
    private const COLLECTION_KEY = '/^[a-z2-7]{12}\z/';

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------------ screens

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $screen = $this->context->services()->operations()->records()
            ->blank($this->context->actor(), $project, $table, ListRequest::fromQuery($request->query), self::from($request));

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
        $from = self::from($request);
        // With `from`, the origin is checked first: one linkOrigin refuses is NotFound.
        $screen = $records->blank($actor, $project, $table, $state, $from);
        $input = self::input($request, $screen);
        try {
            $id = $records->create($actor, $project, $table, $input, $from);
        } catch (ValidationFailed $failed) {
            $again = $records->redisplay($actor, $project, $table, null, $input, $failed->problems, $state, $from);

            return $this->formPage($again, $failed->problems, 422, null);
        }
        $origin = $screen->origin;
        if ($origin !== null) {
            $added = $records->screen($actor, $project, $table, (string) $id, ListRequest::none());
            $this->context->flash('notice.link_added_new', [
                'title' => $added->title ?? $this->untitled($screen->table->itemName, $id),
            ]);

            return $this->context->redirect(self::pickerPath($project, $origin));
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
        [$path, $query] = self::listTarget($project, $table, ListRequest::fromQuery($request->query));

        return $this->context->redirect($path, $query);
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
        $nodes = $form === null ? [] : $this->nodes($form->nodes, $screen, $tables);
        [$list, $listQuery] = self::listTarget($project, $table->key, $screen->request);
        $origin = $screen->isNew ? $screen->origin : null;
        $from = null;

        if ($screen->isNew) {
            $title = $view->text('records.new.title', ['item' => $item]);
            $current = $view->text('records.new.crumb', ['item' => $item]);
            $action = self::listPath($project, $table->key) . '/records';
            if ($origin !== null) {
                // Back to the picker it came from (record-controls.md section 6); no list state.
                $state = [];
                $list = self::pickerPath($project, $origin);
                $listQuery = [];
                $from = [
                    'value' => $origin->fieldKey . '-' . $origin->recordId,
                    'item' => $item,
                    'title' => $this->originTitle($project, $origin),
                ];
            }
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
            'breadcrumb' => $origin === null ? $this->crumbs($screen, $current) : $this->originCrumbs($screen, $origin, $current),
            'problems' => self::summary($problems, $nodes, $format),
            'heading' => $title,
            'lead' => $lead,
            'nav' => $nav,
            'state' => $state,
            'action' => $action,
            'isNew' => $screen->isNew,
            'version' => $version ?? $screen->version ?? '',
            'nodes' => $nodes,
            'unlinkForms' => self::unlinkForms($nodes),
            'from' => $from,
            'list' => $list,
            'listQuery' => $listQuery,
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
    private function nodes(array $nodes, RecordScreen $screen, array $tables): array
    {
        $project = $screen->project->key;
        $list = [];
        foreach ($nodes as $node) {
            $kind = $node->kind();
            if ($kind === 'section') {
                $list[] = [
                    'kind' => 'section',
                    'legend' => $node->legend,
                    'grid' => $node->grid === true,
                    'spanFull' => $node->spanFull === true,
                    'children' => $this->nodes($node->children, $screen, $tables),
                ];
            } elseif ($kind === 'field') {
                $field = $this->field($node, $screen, $tables);
                if ($field !== null) {
                    $list[] = $field;
                }
            } elseif ($kind === 'heading') {
                $list[] = ['kind' => 'heading', 'level' => $node->level === 3 ? 3 : 2, 'children' => self::inlines($node->children)];
            } elseif ($kind === 'paragraph') {
                $list[] = ['kind' => 'paragraph', 'children' => self::inlines($node->children)];
            } elseif ($kind === 'divider') {
                $list[] = ['kind' => 'divider'];
            } elseif ($kind === 'image' && $node->servable === true) {
                // A form image the core found servable (P173): shown from the serving route.
                $list[] = ['kind' => 'image', 'src' => self::filePath($project, (int) $node->attachmentId), 'alt' => (string) $node->alt];
            }
        }

        return $list;
    }

    /**
     * One field slot (record.md section 2.1; record-controls.md section 1);
     * null for a type the form has no markup for.
     *
     * A relation slot carries `relation`: the data of the link-control
     * partial (one link) or of the link-rows partial (many links). An
     * attachment slot carries `attachment`: the data of the file-rows
     * partial. The controls that leave the page are given only when the
     * person can edit and the record exists.
     *
     * @param array<string, array{name: string, item: string}> $tables
     * @return array<string, mixed>|null
     */
    private function field(object $node, RecordScreen $screen, array $tables): ?array
    {
        $view = $this->context->view();
        $format = $view->format();
        $project = $screen->project->key;
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

        $isNew = $node->isNew === true;
        $recordId = $screen->id ?? 0;
        // Controls that leave the page: only for a saved record the person may change.
        $controls = $screen->canEdit && !$isNew && $recordId > 0;

        $relation = null;
        if ($control === 'relation') {
            $facts = $this->relationFacts($project, $def);
            $other = $this->tableByName($tables, $facts['table']);
            $rows = [];
            foreach ($node->links as $link) {
                $linkTitle = $link->title ?? $this->untitled($tables[$link->tableKey]['item'] ?? $other['item'], $link->id);
                $detail = null;
                if ($link->detailField instanceof FieldDef) {
                    $text = $this->detailText($link->detailField, $link->detailValue);
                    $detail = $text === null || $text === '' ? null : $view->text('records.link_detail', ['field' => $link->detailField->name, 'value' => $text]);
                }
                $rows[] = [
                    'path' => self::recordPath($project, $link->tableKey, $link->id),
                    'title' => $linkTitle,
                    'detail' => $detail,
                    'target' => $link->id,
                ];
            }
            $picker = $controls ? self::recordPath($project, $def->tableKey, $recordId) . '/links/' . $def->key : null;
            $relation = $facts['onlyOne']
                ? [
                    'onlyOne' => true,
                    'key' => $def->key,
                    'link' => $rows === [] ? null : ['path' => $rows[0]['path'], 'title' => $rows[0]['title']],
                    'choosePath' => $picker,
                    'item' => $other['item'],
                    'table' => $facts['table'],
                    'required' => $def->required,
                    'isNew' => $isNew,
                ]
                : [
                    'onlyOne' => false,
                    'key' => $def->key,
                    'rows' => $rows,
                    'unlink' => $controls,
                    'linkPath' => $picker,
                    'item' => $other['item'],
                    'isNew' => $isNew,
                ];
        }

        $attachment = null;
        if ($control === 'attachment') {
            $base = self::recordPath($project, $def->tableKey, $recordId) . '/files/' . $def->key;
            $files = [];
            foreach ($node->files as $file) {
                $inline = in_array($file->mediaType, Response::INLINE_TYPES, true);
                $files[] = [
                    'name' => $file->name,
                    'type' => $view->text(match (true) {
                        $file->mediaType === 'application/pdf' => 'file.type.pdf',
                        $inline => 'file.type.image',
                        default => 'file.type.other',
                    }),
                    'size' => $format->fileSize(max(0, $file->size)),
                    'openPath' => $base . '/' . $file->id,
                    'replacePath' => $controls ? $base . '/new' : null,
                    'replaceQuery' => ['replace' => (string) $file->id],
                    'removePath' => $controls ? $base . '/' . $file->id . '/remove' : null,
                    'image' => $inline ? ['src' => self::filePath($project, $file->id), 'alt' => $file->name] : null,
                ];
            }
            $attachment = [
                'key' => $def->key,
                'files' => $files,
                'addPath' => $controls ? $base . '/new' : null,
                'imagesOnly' => ($def->options['images_only'] ?? false) === true,
                'isNew' => $isNew,
            ];
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
            // Relation and attachment fields are never edited through the form, but show the marker (record-controls.md section 5).
            'required' => $def->required && ($node->editable === true || $control === 'relation' || $control === 'attachment'),
            'invalid' => $errors !== [],
            'errors' => $errors,
            'help' => $def->help,
            'hasHelp' => $hasHelp,
            'duration' => $duration,
            'describedBy' => $describedBy,
            'spanFull' => $node->spanFull === true,
            'labelHidden' => $node->labelHidden === true,
            'isNew' => $isNew,
            'value' => $control === 'auto_number' ? $node->value : null,
            'relation' => $relation,
            'attachment' => $attachment,
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

    /**
     * The facts of a relation field the slot needs: the name of the table
     * it links to and whether it allows one link (from the table design,
     * as M2 read the name).
     *
     * @return array{table: string, onlyOne: bool}
     */
    private function relationFacts(string $project, FieldDef $field): array
    {
        if (!isset($this->relationFacts[$project . '/' . $field->tableKey])) {
            $facts = [];
            $design = $this->context->services()->operations()->schema()->design($this->context->actor(), $project, $field->tableKey);
            foreach ($design->fields as $summary) {
                if ($summary->linkedTableName !== null) {
                    $facts[$summary->field->key] = ['table' => $summary->linkedTableName, 'onlyOne' => $summary->links === Cardinality::One];
                }
            }
            $this->relationFacts[$project . '/' . $field->tableKey] = $facts;
        }

        return $this->relationFacts[$project . '/' . $field->tableKey][$field->key] ?? ['table' => '', 'onlyOne' => false];
    }

    /**
     * The names of the table called $name (table names are unique in a
     * project), for the other table's item name.
     *
     * @param array<string, array{name: string, item: string}> $tables
     * @return array{name: string, item: string}
     */
    private function tableByName(array $tables, string $name): array
    {
        foreach ($tables as $facts) {
            if ($facts['name'] === $name) {
                return $facts;
            }
        }

        return ['name' => $name, 'item' => ''];
    }

    /**
     * The display text of a linked record's detail value (P158): the
     * types the detail field can have (the list-column rule without long
     * text, relation, and attachment fields), as ValueText shows them in
     * text mode. Null when there is nothing to show.
     */
    private function detailText(FieldDef $field, mixed $value): ?string
    {
        $view = $this->context->view();
        $format = $view->format();
        $options = $field->options;
        if ($field->type === 'checkbox') {
            return $value === true ? $view->text('value.yes') : null;
        }
        if ($value === null || $value === '' || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }
        $seconds = ($options['seconds'] ?? false) === true;
        $int = static fn (mixed $v): int => is_int($v) ? $v : (int) (is_numeric($v) ? $v : 0);

        return match ($field->type) {
            'text', 'url', 'email', 'phone', 'choice' => (string) $value,
            'number' => is_numeric($value) ? $format->decimal($value + 0, is_int($options['decimals'] ?? null) ? $options['decimals'] : 0) : null,
            'currency' => $format->money(
                $int($value),
                is_string($options['code'] ?? null) ? $options['code'] : 'USD',
                is_int($options['minor_digits'] ?? null) ? $options['minor_digits'] : 2,
            ),
            'date' => $format->date((string) $value),
            'time' => $format->time((string) $value, $seconds),
            'date_time' => $format->dateTime((string) $value, $seconds),
            'duration' => $format->duration($int($value), $seconds),
            'rating' => $view->text('value.rating', ['value' => $int($value), 'max' => is_int($options['max'] ?? null) ? $options['max'] : 5]),
            'auto_number' => (string) $int($value),
            default => null,
        };
    }

    /**
     * The value of `from` (P154) from the query string or the POST body,
     * or null when absent or empty; the core validates it.
     */
    private static function from(Request $request): ?string
    {
        $posted = $request->input('from');
        if ($posted !== '') {
            return $posted;
        }
        $query = $request->query['from'] ?? null;

        return is_string($query) && $query !== '' ? $query : null;
    }

    /** The picker of the record and field a new record is being added from. */
    private static function pickerPath(string $project, LinkOrigin $origin): string
    {
        return self::recordPath($project, $origin->tableKey, (int) $origin->recordId) . '/links/' . $origin->fieldKey;
    }

    /** The serving route of an attachment (ADR 0018 decision 5). */
    private static function filePath(string $project, int $attachment): string
    {
        return '/projects/' . $project . '/files/' . $attachment;
    }

    /**
     * Where "Back to list" and the list redirects go, with their query:
     * the collection named in the list state when its key has the key
     * shape (M4 spec part 2 section 4 rule 8; the core dropped one that
     * names no collection of this table), else the table's list.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function listTarget(string $project, string $table, ListRequest $state): array
    {
        $query = $state->toQuery();
        $collection = $state->collection;
        if ($collection !== null && preg_match(self::COLLECTION_KEY, $collection) === 1) {
            unset($query['collection']);

            return ['/projects/' . $project . '/collections/' . $collection, $query];
        }
        unset($query['collection']);

        return [self::listPath($project, $table), $query];
    }

    /**
     * The unlink forms the page prints after the record form
     * (record-controls.md section 2): one per many-link relation slot that
     * shows Unlink buttons and has at least one link.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array{key: string, action: string}>
     */
    private static function unlinkForms(array $nodes): array
    {
        $forms = [];
        foreach ($nodes as $node) {
            if ($node['kind'] === 'section') {
                array_push($forms, ...self::unlinkForms($node['children']));
                continue;
            }
            $relation = $node['kind'] === 'field' ? $node['relation'] : null;
            if (is_array($relation) && $relation['onlyOne'] === false && $relation['unlink'] === true && $relation['rows'] !== [] && is_string($relation['linkPath'])) {
                $forms[] = ['key' => $node['key'], 'action' => $relation['linkPath'] . '/remove'];
            }
        }

        return $forms;
    }

    /** The origin record's title, or "{Item} {id}" in its table's item name. */
    private function originTitle(string $project, LinkOrigin $origin): string
    {
        return $origin->recordTitle ?? $this->untitled($this->itemName($project, $origin->tableKey), (int) $origin->recordId);
    }

    /**
     * Breadcrumb of a new record opened from a picker (record-controls.md
     * section 6): the origin's project, table, and record, then the page.
     *
     * @return list<array{label: string, path: string|null}>
     */
    private function originCrumbs(RecordScreen $screen, LinkOrigin $origin, string $current): array
    {
        $project = $screen->project;
        $tables = $this->tableFacts($project->key);

        return [
            ['label' => $project->name, 'path' => '/projects/' . $project->key],
            ['label' => $tables[$origin->tableKey]['name'] ?? '', 'path' => self::listPath($project->key, $origin->tableKey)],
            ['label' => $this->originTitle($project->key, $origin), 'path' => self::recordPath($project->key, $origin->tableKey, (int) $origin->recordId)],
            ['label' => $current, 'path' => null],
        ];
    }
}
