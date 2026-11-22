<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\TrashOperations;
use TiffinDB\Core\App\TrashScreen;
use TiffinDB\Core\App\TrashSection;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The trash screen and the empty-the-trash confirmation
 * (docs/design/m4/trash.md; M4 spec part 2 section 4 rules 6 and 7).
 *
 * Every decision about access is made by the core: TrashOperations::screen
 * (records.view) says whether the person may restore and empty, restoring
 * goes through RecordOperations::restore and TrashOperations::restoreAll
 * (records.trash), and both empty pages start with a trash.empty call
 * (emptyImpact or empty), so a Member gets the 403 page before anything
 * is shown or deleted. The confirmation posts back the record count it
 * showed; when more records wait, the core changes nothing and the page
 * asks again with the new counts (P82 pattern).
 */
final class TrashController
{
    /** Digits of a posted page number or record count; longer is treated as absent. */
    private const NUMBER = '/^[0-9]{1,9}\z/';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $tableKey = null;
        if (array_key_exists('table', $request->query)) {
            $tableKey = is_string($request->query['table']) ? $request->query['table'] : '';
        }
        $screen = $this->context->services()->operations()->trash()->screen(
            $this->context->actor(),
            $params['project'] ?? '',
            $tableKey,
            self::number($request->query['page'] ?? null, 1),
        );

        return $this->screenPage($screen, $tableKey !== null);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        $project = $params['project'] ?? '';
        $tableKey = $request->input('table');
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $ref = $operations->records()->restore($actor, $project, $tableKey, $request->input('record'));
        $item = '';
        foreach ($operations->schema()->tables($actor, $project) as $summary) {
            if ($summary->table->key === $tableKey) {
                $item = $summary->table->itemName;
            }
        }
        $this->context->flash('notice.record_restored', ['title' => $ref->title ?? $this->untitled($item, $ref->id)]);
        if ($request->input('view') !== 'table') {
            return $this->context->redirect(self::trashPath($project));
        }

        return $this->context->redirect(self::trashPath($project), [
            'table' => $tableKey,
            'page' => (string) self::number($request->input('page'), 1),
        ]);
    }

    /** @param array<string, string> $params */
    public function restoreAll(Request $request, array $params): Response
    {
        $project = $params['project'] ?? '';
        $restored = $this->context->services()->operations()->trash()->restoreAll($this->context->actor(), $project, $request->input('table'));
        $this->context->flash('notice.trash_restored_all', ['count' => $restored->count, 'table' => $restored->table->name]);

        return $this->context->redirect(self::trashPath($project));
    }

    /** @param array<string, string> $params */
    public function emptyForm(Request $request, array $params): Response
    {
        $project = $params['project'] ?? '';
        $impact = $this->context->services()->operations()->trash()->emptyImpact($this->context->actor(), $project);
        if ($impact->total === 0) {
            return $this->context->redirect(self::trashPath($project));
        }

        return $this->emptyPage($project, $impact->records, $impact->total, $impact->files, false);
    }

    /** @param array<string, string> $params */
    public function empty(Request $request, array $params): Response
    {
        $project = $params['project'] ?? '';
        $trash = $this->context->services()->operations()->trash();
        $actor = $this->context->actor();
        try {
            $emptied = $trash->empty($actor, $project, null, self::number($request->input('expected'), 0));
        } catch (ConfirmationRequired) {
            $impact = $trash->emptyImpact($actor, $project);

            return $this->emptyPage($project, $impact->records, $impact->total, $impact->files, true);
        }
        if ($emptied->records > 0) {
            $this->context->flash('notice.trash_emptied', ['count' => $emptied->records, 'files' => $emptied->files]);
        }

        return $this->context->redirect(self::trashPath($project));
    }

    // ------------------------------------------------------------------ pages

    /** The trash, or one table's trash (trash.md section 2). */
    private function screenPage(TrashScreen $screen, bool $oneTable): Response
    {
        $view = $this->context->view();
        $project = $screen->project;
        $path = self::trashPath($project->key);

        $sections = [];
        foreach ($screen->sections as $section) {
            $sections[] = $this->section($section, $path, $oneTable, $screen->canRestore);
        }

        return $this->context->page('trash/index', [
            'title' => $view->text('trash.title'),
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $view->text('trash.crumb'), 'path' => null],
            ],
            'lead' => $view->text('trash.lead', ['count' => $screen->total]),
            'empty' => $screen->total > 0 ? null : [
                'title' => 'trash.empty_state',
                'body' => 'trash.empty_body',
                'link' => ['label' => 'trash.back', 'labelParams' => ['project' => $project->name], 'path' => '/projects/' . $project->key],
            ],
            'allTablesPath' => $oneTable && $screen->total > 0 ? $path : null,
            'sections' => $screen->total > 0 ? $sections : [],
            'emptyPath' => $screen->canEmpty && $screen->total > 0 ? $path . '/empty' : null,
        ], 200, 'projects');
    }

    /** @return array<string, mixed> */
    private function section(TrashSection $section, string $path, bool $oneTable, bool $canRestore): array
    {
        $view = $this->context->view();
        $format = $view->format();
        $table = $section->table;
        $rows = [];
        foreach ($section->records as $record) {
            $date = $this->day($record->trashedAt);
            $rows[] = [
                'id' => (string) $record->id,
                'title' => $record->title ?? $this->untitled($table->itemName, $record->id),
                'meta' => $record->trashedByName === null
                    ? $view->text('trash.row.meta_unknown', ['date' => $date])
                    : $view->text('trash.row.meta', ['date' => $date, 'name' => $record->trashedByName]),
            ];
        }
        $from = ($section->page - 1) * TrashOperations::PAGE_SIZE + 1;

        return [
            'key' => $table->key,
            'name' => $table->name,
            'count' => $section->count,
            'records' => $rows,
            'canRestore' => $canRestore,
            'restorePath' => $path . '/restore',
            'restoreAllPath' => $path . '/restore-all',
            'view' => $oneTable ? 'table' : 'all',
            'page' => (string) $section->page,
            'more' => !$oneTable && $section->count > count($rows)
                ? ['path' => $path, 'query' => ['table' => $table->key], 'count' => $format->number($section->count), 'items' => $table->name]
                : null,
            'pager' => $oneTable && $section->count > 0 ? [
                'from' => $from,
                'to' => $from + count($rows) - 1,
                'total' => $section->count,
                'page' => $section->page,
                'pages' => $section->pages,
                'path' => $path,
                'query' => ['table' => $table->key],
            ] : null,
        ];
    }

    /**
     * The confirmation (trash.md section 4), first shown or asked again,
     * from TrashOperations::emptyImpact: records per table key, their
     * total, and the files that would go.
     *
     * @param array<string, int> $records
     */
    private function emptyPage(string $projectKey, array $records, int $total, int $files, bool $changed): Response
    {
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $project = $operations->projects()->get($actor, $projectKey);
        $view = $this->context->view();
        $tables = [];
        foreach ($operations->schema()->tables($actor, $project->key) as $summary) {
            $count = $records[$summary->table->key] ?? 0;
            if ($count > 0) {
                $tables[] = ['name' => $summary->table->name, 'count' => $count];
            }
        }
        $path = self::trashPath($project->key);

        return $this->context->page('trash/empty', [
            'title' => $view->text('trash.empty.title'),
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $view->text('trash.crumb'), 'path' => $path],
                ['label' => $view->text('trash.empty.title'), 'path' => null],
            ],
            'changed' => $changed,
            'total' => $total,
            'files' => $files,
            'tables' => $tables,
            'expected' => (string) $total,
            'action' => $path . '/empty',
            'cancel' => $path,
        ], 200, 'projects');
    }

    // ------------------------------------------------------------------ helpers

    private static function trashPath(string $projectKey): string
    {
        return '/projects/' . $projectKey . '/trash';
    }

    /** A page number or count posted or in the query; $default when absent or not plain digits. */
    private static function number(mixed $value, int $default): int
    {
        return is_string($value) && preg_match(self::NUMBER, $value) === 1 ? (int) $value : $default;
    }

    private function untitled(string $itemName, int $id): string
    {
        $first = mb_strtoupper(mb_substr($itemName, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($itemName, 1, null, 'UTF-8');

        return $this->context->view()->text('records.untitled', ['Item' => $first, 'id' => (string) $id]);
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
}
