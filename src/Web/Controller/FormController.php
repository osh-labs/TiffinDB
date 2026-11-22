<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\FormEditorScreen;
use TiffinDB\Core\Forms\EditorRow;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The simple form editor and the "back to the standard form" confirmation
 * (docs/design/m5/editor.md and reset.md; M5 spec part 2 sections 4.1, 5,
 * and 6.5).
 *
 * Every decision is made in Core\App (FormOperations): a person without a
 * grant is NotFound (404), a granted Member AccessDenied (403), and the
 * posted draft is parsed again there on every request, so a tampered or
 * broken draft gives the page with a problem (422), never an error. This
 * controller only reads the request body into strings and arrays of
 * strings, turns the screen into plain arrays, and chooses the status.
 *
 * The editor is one form; every button is `name="do"`. Nothing is stored
 * until `do=save`, which answers 303; every other action answers the page
 * (200, or 422 with problems).
 */
final class FormController
{
    /** Most characters of a row's name (forms/row contract) and of a static row's summary (editor.md section 3.4). */
    private const MAX_NAME = 60;
    private const MAX_SUMMARY = 80;

    /** A problem field the page links to: a label or section name box of a row. */
    private const INPUT_ID = '/\A(?:label|legend)-[1-9][0-9]*(?:-[1-9][0-9]*)?\z/';

    /** Kind words of static rows, for their names ("Heading Care"). */
    private const KIND_KEYS = [
        'heading' => 'forms.row.kind.heading',
        'paragraph' => 'forms.row.kind.paragraph',
        'divider' => 'forms.row.kind.divider',
        'image' => 'forms.row.kind.image',
    ];

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $screen = $this->context->services()->operations()->forms()->editor($this->context->actor(), $project, $table);

        return $this->editorPage($screen, 200);
    }

    /** @param array<string, string> $params */
    public function change(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $input = [
            'draft' => $request->input('draft'),
            'base' => $request->input('base'),
            'do' => $request->input('do'),
            'label' => self::strings($request->post['label'] ?? null),
            'legend' => self::strings($request->post['legend'] ?? null),
            'width' => self::strings($request->post['width'] ?? null),
        ];
        $screen = $this->context->services()->operations()->forms()->editor($this->context->actor(), $project, $table, $input);
        $saved = $screen->saved;
        if ($saved !== null) {
            $name = ['table' => $screen->table->name];
            if (!$saved->changed) {
                $this->context->flash('notice.form_saved_unchanged');
            } elseif ($saved->generated) {
                $this->context->flash('notice.form_saved_standard', $name);
            } else {
                $this->context->flash('notice.form_saved', $name);
            }

            return $this->context->redirect(self::base($screen->project->key, $screen->table->key) . '/form');
        }

        return $this->editorPage($screen, $screen->problems === [] ? 200 : 422);
    }

    /** @param array<string, string> $params */
    public function resetForm(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $screen = $this->context->services()->operations()->forms()->resetScreen($this->context->actor(), $project, $table);
        $view = $this->context->view();
        $base = self::base($screen->project->key, $screen->table->key);

        return $this->context->page('forms/reset', [
            'title' => $view->text('forms.reset.title'),
            'layout' => 'form-narrow',
            'breadcrumb' => ProjectController::crumbs($this->context, $screen->project->key, $screen->project->name, [$screen->table->key, $screen->table->name], [
                ['label' => $view->text('forms.editor.crumb'), 'path' => $base . '/form'],
                ['label' => $view->text('forms.reset.crumb'), 'path' => null],
            ]),
            'base' => $base,
            'table' => ['name' => $screen->table->name],
            'isStandard' => $screen->isStandard,
        ], 200, 'projects');
    }

    /** @param array<string, string> $params */
    public function reset(Request $request, array $params): Response
    {
        [$project, $table] = self::keys($params);
        $forms = $this->context->services()->operations()->forms();
        $actor = $this->context->actor();
        $changed = $forms->reset($actor, $project, $table);
        $screen = $forms->resetScreen($actor, $project, $table);
        $this->context->flash($changed ? 'notice.form_reset' : 'notice.form_reset_unchanged', ['table' => $screen->table->name]);

        return $this->context->redirect(self::base($screen->project->key, $screen->table->key) . '/form');
    }

    // ------------------------------------------------------------------ internals

    private function editorPage(FormEditorScreen $screen, int $status): Response
    {
        $view = $this->context->view();
        $project = $screen->project;
        $table = $screen->table;
        $base = self::base($project->key, $table->key);
        $types = $this->typeLabels($project->key, $table->key);

        $problems = [];
        $errors = [];
        foreach ($screen->problems as $problem) {
            $text = $view->format()->problem($problem);
            $id = is_string($problem->field) && preg_match(self::INPUT_ID, $problem->field) === 1 ? $problem->field : null;
            $problems[] = ['field' => $id, 'text' => $text];
            if ($id !== null && !isset($errors[$id])) {
                $errors[$id] = $text;
            }
        }

        $imagesHidden = $screen->report->imagesNotShown;
        $focus = $screen->focus;
        $rows = [];
        $section = 0;
        $current = null;
        foreach ($screen->rows as $row) {
            $item = $this->row($row, $screen, $types, $errors, $imagesHidden);
            if ($row->depth === 0) {
                if ($row->kind === 'section') {
                    ++$section;
                }
                $rows[] = ['row' => $item, 'number' => $section];
                $current = count($rows) - 1;
            } elseif ($current !== null) {
                $rows[$current]['row']['children'][] = $item;
            }
        }

        $notOnForm = [];
        foreach ($screen->report->notOnForm as $field) {
            $notOnForm[] = [
                'key' => $field->key,
                'name' => $field->name,
                'typeLabel' => $types[$field->key] ?? $view->text('type.' . $field->type),
                'required' => $field->required,
            ];
        }
        $report = $screen->report;

        return $this->context->page('forms/edit', [
            'title' => $view->text('forms.editor.title', ['table' => $table->name]),
            'layout' => 'form',
            'breadcrumb' => ProjectController::crumbs($this->context, $project->key, $project->name, [$table->key, $table->name], [
                ['label' => $view->text('forms.editor.crumb'), 'path' => null],
            ]),
            'problems' => $problems,
            'base' => $base,
            'table' => ['name' => $table->name],
            'rows' => $rows,
            'focusAddSection' => $focus !== null && $focus['control'] === 'add-section',
            'notOnForm' => $notOnForm,
            'report' => [
                'unknown' => count($report->unknownKeys),
                'notShown' => array_map(static fn (array $f): array => ['name' => $f['name'], 'required' => $f['required']], $notOnForm),
                'requiredHidden' => $report->requiredNotOnForm !== [],
                'images' => count($report->imagesNotShown),
                'emptyLinks' => $report->emptyLinks,
            ],
            'showReport' => $report->unknownKeys !== [] || $report->imagesNotShown !== [] || $report->emptyLinks > 0,
            'draft' => $screen->draft,
            'formBase' => $screen->base,
            'state' => $screen->state,
            'isStandard' => $screen->isStandard,
        ], $status, 'projects');
    }

    /**
     * One row as the forms/row partial takes it (M5 spec part 2 section 4.1).
     *
     * @param array<string, string> $types field key => type label
     * @param array<string, string> $errors input id => problem text
     * @param list<int> $imagesHidden
     * @return array<string, mixed>
     */
    private function row(EditorRow $row, FormEditorScreen $screen, array $types, array $errors, array $imagesHidden): array
    {
        $view = $this->context->view();
        $path = $row->path;
        $field = $row->field;
        $summary = self::cut($row->summary, self::MAX_SUMMARY);
        $name = match ($row->kind) {
            'field' => $field instanceof FieldDef ? $field->name : (string) $row->fieldKey,
            'missing' => (string) $row->fieldKey,
            'section' => $row->legend,
            default => $row->summary === '' ? null : $view->text(self::KIND_KEYS[$row->kind] ?? 'forms.row.kind.image') . ' ' . $row->summary,
        };
        $focus = $screen->focus;
        $rowFocus = $focus !== null && $focus['path'] === $path && in_array($focus['control'], ['up', 'down', 'first', 'legend'], true) ? $focus['control'] : null;
        $rowErrors = [];
        foreach (['label-' . $path, 'legend-' . $path] as $id) {
            if (isset($errors[$id])) {
                $rowErrors[$id] = $errors[$id];
            }
        }

        return [
            'path' => $path,
            'kind' => $row->kind,
            'depth' => $row->depth,
            'name' => $name === null ? null : self::cut($name, self::MAX_NAME),
            'typeLabel' => $field instanceof FieldDef ? ($types[$field->key] ?? $view->text('type.' . $field->type)) : null,
            'required' => $field instanceof FieldDef && $field->required,
            'label' => $screen->typed['label-' . $path] ?? ($row->label ?? ''),
            'legend' => $screen->typed['legend-' . $path] ?? ($row->legend ?? ''),
            'width' => $row->width,
            'span' => $row->span,
            'placed' => $row->placed,
            'summary' => $summary,
            'imageHidden' => $row->attachmentId !== null && in_array($row->attachmentId, $imagesHidden, true),
            'canMoveUp' => $row->canMoveUp,
            'canMoveDown' => $row->canMoveDown,
            'children' => [],
            'errors' => $rowErrors,
            'focus' => $rowFocus,
        ];
    }

    /**
     * Type labels of the table's fields as the Fields page shows them
     * ("Link to Rooms" for a relation). Called only after FormOperations
     * has allowed the person, who is then an Admin.
     *
     * @return array<string, string>
     */
    private function typeLabels(string $projectKey, string $tableKey): array
    {
        $view = $this->context->view();
        $design = $this->context->services()->operations()->schema()->design($this->context->actor(), $projectKey, $tableKey);
        $labels = [];
        foreach ($design->fields as $summary) {
            $labels[$summary->field->key] = $summary->linkedTableName === null
                ? $view->text('type.' . $summary->field->type)
                : $view->text('type.relation.to', ['table' => $summary->linkedTableName]);
        }

        return $labels;
    }

    /**
     * The string entries of a posted array, keys kept; [] for anything else.
     *
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $key => $entry) {
            if (is_string($entry)) {
                $strings[(string) $key] = $entry;
            }
        }

        return $strings;
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') <= $max ? $text : mb_substr($text, 0, $max - 1, 'UTF-8') . "\u{2026}";
    }

    /**
     * @param array<string, string> $params
     * @return array{0: string, 1: string}
     */
    private static function keys(array $params): array
    {
        return [$params['project'] ?? '', $params['table'] ?? ''];
    }

    private static function base(string $projectKey, string $tableKey): string
    {
        return '/projects/' . $projectKey . '/tables/' . $tableKey;
    }
}
