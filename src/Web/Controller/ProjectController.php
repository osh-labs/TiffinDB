<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ProjectDeleteScreen;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The project list, new project, and project settings screens
 * (docs/design/m1/sign-in-and-projects.md section 3,
 * docs/design/m1/new-project.md, docs/design/m1/schema-editor.md
 * section 2), and deleting a project (docs/design/m7/project-delete.md;
 * M7 screens spec section 9). Every decision about who may do what is made in Core\App:
 * the controller asks AuthOperations::can() which controls to show and
 * AuthOperations::require() before rendering an Admin-only form.
 *
 * The static helpers at the end (breadcrumb and problem mapping) are
 * shared with TableController and FieldController.
 */
final class ProjectController
{
    /** New project form: core field name to element id. */
    private const NEW_FIELDS = ['name' => 'project-name', 'first_table' => 'project-first-table'];

    /** Project settings form. */
    private const SETTINGS_FIELDS = ['name' => 'project-name'];

    /** Delete a project: the typed name. */
    private const DELETE_FIELDS = ['confirm-name' => 'confirm-name'];

    /** Digits of the posted record count; anything else (or longer) is 0 (P184). */
    private const NUMBER = '/^[0-9]{1,9}\z/';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $admin = $operations->auth()->can($actor, Permission::CreateProject);
        $format = $this->context->view()->format();

        $cards = [];
        foreach ($operations->projects()->listFor($actor) as $summary) {
            $changed = null;
            if ($summary->updatedAt !== null) {
                $changed = $format->relativeDate($summary->updatedAt);
            }
            $cards[] = [
                'key' => $summary->project->key,
                'name' => $summary->project->name,
                'available' => $summary->available,
                'tables' => $summary->tableNames === [] ? null : implode(', ', $summary->tableNames),
                'records' => $format->number($summary->recordCount),
                'changed' => $changed,
                'changedBy' => $summary->updatedByName,
                'members' => $summary->memberCount,
            ];
        }

        return $this->context->page('projects/index', [
            'title' => $this->context->view()->text('projects.title'),
            'lead' => $this->context->view()->text('projects.lead'),
            'admin' => $admin,
            'projects' => $cards,
        ], 200, 'projects');
    }

    /** @param array<string, string> $params */
    public function newForm(Request $request, array $params): Response
    {
        $operations = $this->context->services()->operations();
        $operations->auth()->require($this->context->actor(), Permission::CreateProject);

        return $this->newPage(['name' => '', 'first_table' => ''], [], 200);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $values = ['name' => $request->input('name'), 'first_table' => $request->input('first_table')];
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        try {
            $project = $operations->projects()->create($actor, $values['name'], $values['first_table'] === '' ? null : $values['first_table']);
        } catch (ValidationFailed $failed) {
            return $this->newPage($values, $failed->problems, 422);
        }

        $tables = $operations->schema()->tables($actor, $project->key);
        if ($tables === []) {
            $this->context->flash('notice.project_created', ['name' => $project->name]);
        } else {
            $this->context->flash('notice.project_created_table', ['name' => $project->name, 'table' => $tables[0]->table->name]);
        }

        return $this->context->redirect('/projects/' . $project->key . '/tables');
    }

    /** @param array<string, string> $params */
    public function open(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $project = $operations->projects()->get($actor, $params['project'] ?? '');
        $tables = $operations->schema()->tables($actor, $project->key);
        if ($tables === []) {
            return $this->context->redirect('/projects/' . $project->key . '/tables');
        }

        // The first table by position (M2 spec part 2 section 2), with no list state.
        return $this->context->redirect('/projects/' . $project->key . '/tables/' . $tables[0]->table->key);
    }

    /** @param array<string, string> $params */
    public function settingsForm(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $operations->auth()->require($actor, Permission::ManageProject, $key);
        $project = $operations->projects()->get($actor, $key);

        return $this->settingsPage($project->key, $project->name, ['name' => $project->name], [], 200);
    }

    /** @param array<string, string> $params */
    public function settings(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $values = ['name' => $request->input('name')];
        try {
            $project = $operations->projects()->rename($actor, $key, $values['name']);
        } catch (ValidationFailed $failed) {
            $project = $operations->projects()->get($actor, $key);

            return $this->settingsPage($project->key, $project->name, $values, $failed->problems, 422);
        }
        $this->context->flash('notice.project_renamed', ['name' => $project->name]);

        return $this->context->redirect('/projects/' . $project->key . '/tables');
    }

    /** @param array<string, string> $params */
    public function deleteForm(Request $request, array $params): Response
    {
        $screen = $this->context->services()->operations()->projects()->deleteScreen($this->context->actor(), $params['project'] ?? '');

        return $this->deletePage($screen, '', [], false, 200);
    }

    /**
     * Deletes the project: the core checks the typed name and the record
     * count the page showed (`expected`); the counts grew meanwhile, the
     * page is shown again with the new numbers (P82 pattern).
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $typed = $request->input('confirm_name');
        $expected = $request->input('expected');
        $actor = $this->context->actor();
        $projects = $this->context->services()->operations()->projects();
        try {
            $deleted = $projects->delete($actor, $key, $typed, preg_match(self::NUMBER, $expected) === 1 ? (int) $expected : 0);
        } catch (ValidationFailed $failed) {
            return $this->deletePage($projects->deleteScreen($actor, $key), $typed, $failed->problems, false, 422);
        } catch (ConfirmationRequired) {
            return $this->deletePage($projects->deleteScreen($actor, $key), $typed, [], true, 200);
        }
        $this->context->flash('notice.project_deleted', ['name' => $deleted->name]);

        return $this->context->redirect('/projects');
    }

    /**
     * The deletion page (project-delete.md section 2), first shown, asked
     * again with new counts, or after a refusal with the typed text kept.
     *
     * @param list<Problem> $problems
     */
    private function deletePage(ProjectDeleteScreen $screen, string $typed, array $problems, bool $changed, int $status): Response
    {
        [$list, $errors] = self::problems($this->context, $problems, self::DELETE_FIELDS);
        $view = $this->context->view();
        $project = $screen->project;
        $base = '/projects/' . $project->key;

        return $this->context->page('projects/delete', [
            'title' => $view->text('projects.delete.title', ['project' => $project->name]),
            'layout' => 'record-narrow',
            'breadcrumb' => [
                ['label' => $project->name, 'path' => $base],
                ['label' => $view->text('projects.delete.crumb'), 'path' => null],
            ],
            'problems' => $list,
            'errors' => $errors,
            'project' => $project->name,
            'changed' => $changed,
            'folderMissing' => $screen->folderMissing,
            'counts' => [
                'records' => $screen->records,
                'trashed' => $screen->trashed,
                'files' => $screen->files,
                'size' => $view->format()->fileSize(max(0, $screen->fileBytes)),
                'snapshots' => $screen->snapshots,
                'members' => $screen->members,
            ],
            'expected' => (string) ($screen->records + $screen->trashed),
            'typed' => $typed,
            'exportPath' => $base . '/export',
            'action' => $base . '/delete',
            'cancel' => $base . '/settings',
        ], $status, 'projects');
    }

    /**
     * @param array<string, string> $values
     * @param list<Problem>         $problems
     */
    private function newPage(array $values, array $problems, int $status): Response
    {
        [$list, $errors] = self::problems($this->context, $problems, self::NEW_FIELDS);
        $view = $this->context->view();

        return $this->context->page('projects/new', [
            'title' => $view->text('projects.new.title'),
            'breadcrumb' => [
                ['label' => $view->text('shell.nav.projects'), 'path' => '/projects'],
                ['label' => $view->text('projects.new.title'), 'path' => null],
            ],
            'problems' => $list,
            'errors' => $errors,
            'values' => $values,
        ], $status, 'projects');
    }

    /**
     * @param array<string, string> $values
     * @param list<Problem>         $problems
     */
    private function settingsPage(string $key, string $name, array $values, array $problems, int $status): Response
    {
        [$list, $errors] = self::problems($this->context, $problems, self::SETTINGS_FIELDS);
        $view = $this->context->view();
        $totals = $this->context->services()->operations()->projects()->attachmentTotals($this->context->actor(), $key);

        return $this->context->page('projects/settings', [
            'title' => $view->text('schema.settings.title'),
            'breadcrumb' => self::crumbs($this->context, $key, $name, null, [['label' => $view->text('schema.settings.crumb'), 'path' => null]]),
            'problems' => $list,
            'errors' => $errors,
            'values' => $values,
            'action' => '/projects/' . $key . '/settings',
            'cancel' => '/projects/' . $key . '/tables',
            'files' => ['count' => $totals->files, 'size' => $view->format()->fileSize(max(0, $totals->bytes))],
            'snapshotsPath' => '/projects/' . $key . '/snapshots',
            'deletePath' => '/projects/' . $key . '/delete',
        ], $status, 'projects');
    }

    /**
     * The breadcrumb of the project screens: Projects, the project (a link
     * to its tables), optionally the table (a link to its fields), then
     * any further items. The last item is the current page.
     *
     * @param array{0: string, 1: string}|null                $table [key, name]
     * @param list<array{label: string, path: string|null}> $more  further items (plain-text labels)
     * @return list<array{label: string, path: string|null}>
     * @internal Shared by the project and schema controllers.
     */
    public static function crumbs(Context $context, string $projectKey, string $projectName, ?array $table, array $more): array
    {
        $items = [['label' => $context->view()->text('shell.nav.projects'), 'path' => '/projects']];
        $items[] = ['label' => $projectName, 'path' => '/projects/' . $projectKey . '/tables'];
        if ($table !== null) {
            $items[] = ['label' => $table[1], 'path' => '/projects/' . $projectKey . '/tables/' . $table[0] . '/fields'];
        }
        foreach ($more as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Problems for the error summary and the field errors (M1 spec part 2
     * section 4.5 item 5): each problem's text, linked to the element id
     * its core field maps to; a problem with no field, or with a field the
     * page does not show, goes only in the summary. The first problem of
     * an element is the one shown at that element.
     *
     * @param list<Problem>         $problems
     * @param array<string, string> $map core field name => element id
     * @return array{0: list<array{field: string|null, text: string}>, 1: array<string, string>}
     * @internal Shared by the project and schema controllers.
     */
    public static function problems(Context $context, array $problems, array $map): array
    {
        $format = $context->view()->format();
        $list = [];
        $errors = [];
        foreach ($problems as $problem) {
            $text = $format->problem($problem);
            $id = $problem->field === null ? null : ($map[$problem->field] ?? null);
            $list[] = ['field' => $id, 'text' => $text];
            if ($id !== null && !isset($errors[$id])) {
                $errors[$id] = $text;
            }
        }

        return [$list, $errors];
    }
}
