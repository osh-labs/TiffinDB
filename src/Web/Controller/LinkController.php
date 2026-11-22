<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\PickerScreen;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The relation picker (docs/design/m4/picker.md; ADR 0019 decision 9):
 * the record's current links with Unlink, a find box, and a paged list of
 * the other table's records with Link or Choose. Each press is its own
 * POST that answers 303 to the picker (find text and page kept) or, for
 * an Unlink from the record form (`back=record`), to the record.
 *
 * Every decision about access, the field, the record, and the target is
 * made by RecordOperations (picker, link, unlink): a record in the trash,
 * a field that is not a relation field of the table, a target id that is
 * not canonical, and an ungranted person are NotFound there. This
 * controller only reads the posted strings and builds addresses from the
 * keys and ids the core returned. The picker screen is read before each
 * press, so a person who cannot use the picker learns nothing more from
 * a POST.
 */
final class LinkController
{
    /** The candidates per page (Picker, ADR 0019 decision 9). */
    private const PER_PAGE = 50;

    /** A page number in an address or a posted form: 1 to 9,999,999. */
    private const PAGE = '/^[1-9][0-9]{0,6}\z/';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function picker(Request $request, array $params): Response
    {
        [$q, $page] = self::state($request->query);
        $screen = $this->screen($params, $q, $page);

        return $this->page($screen, [], 200, $q);
    }

    /** POST .../links/{field}/add: link (or, for a one-link field, choose) `target`. */
    public function add(Request $request, array $params): Response
    {
        [$q, $page] = self::state($request->post);
        $screen = $this->screen($params, $q, $page);
        $actor = $this->context->actor();
        try {
            $ref = $this->context->services()->operations()->records()->link(
                $actor,
                $screen->project->key,
                $screen->table->key,
                (string) $screen->record->id,
                $screen->field->key,
                $request->input('target'),
            );
        } catch (ValidationFailed $failed) {
            return $this->page($this->screen($params, $q, $page), $failed->problems, 422, $q);
        }
        $this->context->flash($screen->page->onlyOne ? 'notice.link_chosen' : 'notice.link_added', [
            'title' => $ref->title ?? $this->untitled($screen->other->itemName, $ref->id),
        ]);

        return $this->back($request, $screen, $q, $page);
    }

    /** POST .../links/{field}/remove: make the record not link `target`. */
    public function remove(Request $request, array $params): Response
    {
        [$q, $page] = self::state($request->post);
        $screen = $this->screen($params, $q, $page);
        $ref = $this->context->services()->operations()->records()->unlink(
            $this->context->actor(),
            $screen->project->key,
            $screen->table->key,
            (string) $screen->record->id,
            $screen->field->key,
            $request->input('target'),
        );
        $this->context->flash('notice.link_removed', [
            'title' => $ref->title ?? $this->untitled($screen->other->itemName, $ref->id),
        ]);

        return $this->back($request, $screen, $q, $page);
    }

    // ------------------------------------------------------------------ page

    /**
     * The picker page (picker.md section 2).
     *
     * @param list<Problem> $problems from a refused link (422), else []
     * @param string|null   $typed    the find text as given (shown in the box even when refused)
     */
    private function page(PickerScreen $screen, array $problems, int $status, ?string $typed): Response
    {
        $view = $this->context->view();
        $format = $view->format();
        $project = $screen->project->key;
        $other = $screen->other;
        $candidates = $screen->page;
        $recordPath = self::recordPath($project, $screen->table->key, $screen->record->id);
        $path = $recordPath . '/links/' . $screen->field->key;
        $title = $screen->record->title ?? $this->untitled($screen->table->itemName, $screen->record->id);
        $q = $candidates->search;
        $blocked = $candidates->onlyOne && $candidates->trashedLinks > 0;

        $linked = [];
        foreach ($candidates->linked as $link) {
            $linked[] = [
                'path' => self::recordPath($project, $link->tableKey, $link->id),
                'title' => $link->title ?? $this->untitled($other->itemName, $link->id),
                'target' => (string) $link->id,
            ];
        }
        $list = [];
        foreach ($candidates->candidates as $candidate) {
            $taken = $candidate->takenBy;
            $list[] = [
                'path' => self::recordPath($project, $other->key, $candidate->id),
                'title' => $candidate->title ?? $this->untitled($other->itemName, $candidate->id),
                'target' => (string) $candidate->id,
                'taken' => $taken === null ? null : ($taken->title ?? $this->untitled($screen->table->itemName, $taken->id)),
            ];
        }
        $query = $q === null ? [] : ['q' => $q];
        $from = ($candidates->page - 1) * self::PER_PAGE + 1;

        $summary = [];
        foreach ($problems as $problem) {
            $summary[] = ['field' => null, 'text' => $format->problem($problem)];
        }

        return $this->context->page('links/picker', [
            'title' => $view->text('links.title', ['field' => $screen->field->name, 'title' => $title]),
            'layout' => 'record-narrow',
            'breadcrumb' => [
                ['label' => $screen->project->name, 'path' => '/projects/' . $project],
                ['label' => $screen->table->name, 'path' => self::listPath($project, $screen->table->key)],
                ['label' => $title, 'path' => $recordPath],
                ['label' => $view->text('links.crumb', ['items' => $other->name]), 'path' => null],
            ],
            'problems' => $summary,
            'field' => $screen->field->name,
            'record' => $title,
            'item' => $other->itemName,
            'items' => $other->name,
            'onlyOne' => $candidates->onlyOne,
            'linked' => $linked,
            'trashed' => $candidates->trashedLinks,
            'blocked' => $blocked,
            'path' => $path,
            'add' => $path . '/add',
            'remove' => $path . '/remove',
            'q' => $q ?? '',
            'typed' => $typed ?? '',
            'page' => (string) $candidates->page,
            'searchProblem' => $candidates->searchProblem === null ? null : $format->problem($candidates->searchProblem),
            'candidates' => $list,
            'total' => $candidates->total,
            'pager' => $candidates->pages > 1 ? [
                'from' => $from,
                'to' => $from + count($list) - 1,
                'total' => $candidates->total,
                'page' => $candidates->page,
                'pages' => $candidates->pages,
                'path' => $path,
                'query' => $query,
            ] : null,
            'back' => $recordPath,
            'addNew' => $screen->canAdd ? self::listPath($project, $other->key) . '/records/new' : null,
            'addNewQuery' => ['from' => $screen->field->key . '-' . $screen->record->id],
        ], $status);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The picker screen from the core (authorization, the record, the
     * field). Reading it first keeps every refusal NotFound or
     * AccessDenied before a press changes anything.
     *
     * @param array<string, string> $params
     */
    private function screen(array $params, ?string $q, int $page): PickerScreen
    {
        return $this->context->services()->operations()->records()->picker(
            $this->context->actor(),
            $params['project'] ?? '',
            $params['table'] ?? '',
            $params['record'] ?? '',
            $params['field'] ?? '',
            $q,
            $page,
        );
    }

    /**
     * The find text and the page from a query or a posted form: `q` a
     * string (empty for none; the core checks it), `page` digits (else 1).
     *
     * @param array<mixed> $values
     * @return array{0: string|null, 1: int}
     */
    private static function state(array $values): array
    {
        $q = $values['q'] ?? null;
        $page = $values['page'] ?? null;

        return [
            is_string($q) && $q !== '' ? $q : null,
            is_string($page) && preg_match(self::PAGE, $page) === 1 ? (int) $page : 1,
        ];
    }

    /**
     * The 303 after a press: to the record for `back=record`, else to the
     * picker with the find text and the page (picker.md section 3; M4 spec
     * part 2 section 4 rule 3). No other parameter is copied.
     */
    private function back(Request $request, PickerScreen $screen, ?string $q, int $page): Response
    {
        $recordPath = self::recordPath($screen->project->key, $screen->table->key, $screen->record->id);
        if ($request->input('back') === 'record') {
            return $this->context->redirect($recordPath);
        }
        $query = [];
        if ($q !== null) {
            $query['q'] = $q;
        }
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return $this->context->redirect($recordPath . '/links/' . $screen->field->key, $query);
    }

    private static function listPath(string $project, string $table): string
    {
        return '/projects/' . $project . '/tables/' . $table;
    }

    private static function recordPath(string $project, string $table, int $id): string
    {
        return self::listPath($project, $table) . '/records/' . $id;
    }

    /** "{Item} {id}" for a record without a title. */
    private function untitled(string $itemName, int $id): string
    {
        $item = mb_strtoupper(mb_substr($itemName, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($itemName, 1, null, 'UTF-8');

        return $this->context->view()->text('records.untitled', ['Item' => $item, 'id' => (string) $id]);
    }
}
