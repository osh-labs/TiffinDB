<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ActiveFilter;
use TiffinDB\Core\App\Cell;
use TiffinDB\Core\App\FilterOptions;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordListing;
use TiffinDB\Core\App\SortOption;
use TiffinDB\Core\App\TableSummary;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\Sidebar;

/**
 * The list view and the filter page (docs/design/m2/list-and-search.md
 * sections 2 and 3; M2 spec part 2 section 2.1).
 *
 * View state is read only through ListRequest::fromQuery() and every link
 * carries the normalised state the core returns (ListRequest::toQuery()).
 * Every decision about who may see what is made in Core\App: an unknown
 * project or table, or a Member without a grant, is NotFound there and
 * reaches the kernel as a 404.
 *
 * Templates receive plain values only: text is plain (escaped once, at
 * output), paths are application paths, and queries are string maps.
 */
final class RecordListController
{
    /** Field types whose list cells and headers are right-aligned (`num`). */
    private const NUMERIC = ['number', 'currency', 'duration', 'auto_number'];

    /** Sort option group of each sortable field type (M2 spec part 2 section 5.3). */
    private const SORT_GROUPS = [
        'text' => 'text', 'long_text' => 'text', 'url' => 'text', 'email' => 'text', 'phone' => 'text', 'relation' => 'text',
        'number' => 'number', 'currency' => 'number', 'rating' => 'number', 'auto_number' => 'number',
        'duration' => 'duration',
        'date' => 'date', 'date_time' => 'date',
        'time' => 'time',
        'checkbox' => 'checkbox',
        'choice' => 'choice',
    ];

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $asked = ListRequest::fromQuery($request->query);
        $listing = $this->context->services()->operations()->records()->listing(
            $this->context->actor(),
            $params['project'] ?? '',
            $params['table'] ?? '',
            $asked,
        );

        return $this->render($listing, $asked);
    }

    /**
     * The list page of a table listing, or of a collection view when the
     * listing names a collection (docs/design/m4/collections.md section 2):
     * the collection is the current sidebar item, its name is the heading,
     * there is no Filter link, ignored rules are reported, and the view's
     * own links (find, sort, pager) go to the collection's address without
     * repeating its key, while record links keep it so the record page
     * follows the collection.
     *
     * @internal Shared with CollectionController::show.
     */
    public function render(RecordListing $listing, ListRequest $asked): Response
    {
        $view = $this->context->view();
        $project = $listing->project;
        $table = $listing->table;
        $collection = $listing->collection;
        $base = '/projects/' . $project->key . '/tables/' . $table->key;
        $state = $listing->request->toQuery();
        $itemNames = self::itemNames($listing->tables);
        $format = $view->format();

        $current = null;
        $sortOptions = [];
        foreach ($listing->sortOptions as $option) {
            $label = $this->sortLabel($option);
            $sortOptions[] = ['token' => $option->token, 'label' => $label, 'selected' => $option->token === $listing->sort];
            if ($option->token === $listing->sort) {
                $current = $option;
            }
        }
        $sortText = $current === null ? '' : ($current->kind === 'field' ? $this->sortLabel($current) : $this->sortShort($current));

        if ($collection !== null) {
            $lead = $view->text('lists.collection.lead', ['count' => $listing->total, 'table' => $table->name, 'sort' => $sortText]);
        } elseif ($listing->search !== null || $listing->filter !== null) {
            $lead = $view->text('lists.lead_matching', ['count' => $listing->total, 'total' => $format->number($listing->tableTotal), 'sort' => $sortText]);
        } else {
            $lead = $view->text('lists.lead', ['count' => $listing->total, 'sort' => $sortText]);
        }

        $hidden = [];
        foreach (['filter' => $listing->request->filter, 'op' => $listing->request->op, 'v' => $listing->request->v, 'v2' => $listing->request->v2] as $name => $value) {
            if ($collection === null && $listing->filter !== null && $value !== null) {
                $hidden[] = ['name' => $name, 'value' => $value];
            }
        }

        $canManage = $collection !== null ? $collection->canManage : $this->can($project->key, Permission::ManageCollections);
        $listPath = $collection === null ? $base : '/projects/' . $project->key . '/collections/' . $collection->key;
        $editPath = $collection !== null && $collection->canManage ? $listPath . '/edit' : null;
        $sidebarCollections = [];
        foreach ($listing->collections as $summary) {
            $sidebarCollections[] = ['key' => $summary->key, 'name' => $summary->name, 'count' => $summary->count];
        }

        $data = [
            'title' => $view->text('lists.page_title', ['table' => $collection?->name ?? $table->name, 'project' => $project->name]),
            'layout' => 'list',
            'sidebar' => Sidebar::build(
                $project->key,
                $project->name,
                $listing->tables,
                $listing->trashCount,
                $collection === null ? $table->key : null,
                $this->can($project->key, Permission::ImportExport),
                $sidebarCollections,
                $collection?->key,
                $canManage ? $table->key : null,
            ),
            'search' => ['project' => $project->key, 'projectName' => $project->name, 'q' => null],
            'tableName' => $collection?->name ?? $table->name,
            'itemName' => $table->itemName,
            'lead' => $lead,
            'addPath' => $listing->hasFields ? $base . '/records/new' : null,
            'editPath' => $editPath,
            'broken' => $collection?->broken ?? 0,
            'state' => $state,
            'listPath' => $listPath,
            'filterPath' => $collection === null ? $base . '/filter' : null,
            'controls' => $listing->tableTotal > 0,
            'hidden' => $hidden,
            'find' => $listing->searchProblem !== null ? ($asked->q ?? '') : ($listing->search ?? ''),
            'findError' => $listing->searchProblem === null ? null : $format->problem($listing->searchProblem),
            'sortOptions' => $sortOptions,
            'condition' => $collection === null && $listing->filter !== null ? $this->condition($listing->filter, $listing, $itemNames) : null,
            'withoutFilter' => $listing->request->withoutFilter()->toQuery(),
            'filterProblem' => $collection === null && $listing->filterProblem !== null ? $format->problem($listing->filterProblem) : null,
            'empty' => null,
            'table' => null,
            'pager' => null,
        ];
        if (!$listing->hasFields) {
            $data['empty'] = $listing->canChangeSchema
                ? ['title' => 'lists.nofields.title', 'body' => 'lists.nofields.admin', 'link' => ['label' => 'lists.nofields.link', 'path' => $base . '/fields/new', 'query' => []]]
                : ['title' => 'lists.nofields.title', 'body' => 'lists.nofields.member'];
        } elseif ($collection !== null && ($listing->tableTotal === 0 || ($listing->total === 0 && $listing->search === null))) {
            $data['empty'] = [
                'title' => 'lists.none.title',
                'body' => 'lists.collection.empty',
                'bodyParams' => ['items' => mb_strtolower($table->name)],
                'link' => $editPath === null ? null : ['label' => 'lists.collection.edit', 'path' => $editPath, 'query' => []],
            ];
        } elseif ($listing->tableTotal === 0) {
            $data['empty'] = ['title' => 'lists.empty.title', 'body' => 'lists.empty.body', 'bodyParams' => ['item' => $table->itemName]];
        } elseif ($listing->total === 0) {
            $body = match (true) {
                $listing->search !== null && $listing->filter !== null => ['lists.none.both', ['q' => $listing->search]],
                $listing->search !== null => ['lists.none.search', ['q' => $listing->search]],
                default => ['lists.none.filter', []],
            };
            $data['empty'] = [
                'title' => 'lists.none.title',
                'body' => $body[0],
                'bodyParams' => $body[1],
                'link' => ['label' => 'lists.none.clear', 'path' => $listPath, 'query' => self::own($listing->request->withoutSearchAndFilter()->toQuery(), $collection !== null)],
            ];
        } else {
            $data['table'] = $this->table($listing, $base, $state, $itemNames);
            $data['pager'] = [
                'from' => $listing->from,
                'to' => $listing->to,
                'total' => $listing->total,
                'page' => $listing->page,
                'pages' => $listing->pages,
                'path' => $listPath,
                'query' => self::own($listing->request->withoutPage()->toQuery(), $collection !== null),
            ];
        }

        return $this->context->page('records/list', $data);
    }

    /**
     * A query for one of the list page's own links: on a collection view
     * the collection key is the address already, so it is left out.
     *
     * @param array<string, string> $query
     * @return array<string, string>
     */
    private static function own(array $query, bool $isCollection): array
    {
        if ($isCollection) {
            unset($query['collection']);
        }

        return $query;
    }

    /** @param array<string, string> $params */
    public function filter(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $records = $this->context->services()->operations()->records();
        $projectKey = $params['project'] ?? '';
        $tableKey = $params['table'] ?? '';
        $listing = $records->listing($actor, $projectKey, $tableKey, ListRequest::fromQuery($request->query));
        $fieldKey = $request->query['field'] ?? null;
        $options = $records->filterOptions($actor, $projectKey, $tableKey, is_string($fieldKey) ? $fieldKey : null);

        $view = $this->context->view();
        $project = $listing->project;
        $table = $listing->table;
        $base = '/projects/' . $project->key . '/tables/' . $table->key;
        $state = $listing->request->toQuery();
        $active = $listing->filter;

        $data = [
            'title' => $view->text('lists.filter.title', ['table' => $table->name]),
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $table->name, 'path' => $base, 'query' => $state],
                ['label' => $view->text('lists.filter.crumb'), 'path' => null],
            ],
            'search' => ['project' => $project->key, 'projectName' => $project->name, 'q' => null],
            'tableName' => $table->name,
            'listPath' => $base,
            'filterPath' => $base . '/filter',
            'state' => $state,
            'step' => $options->fields === [] ? 0 : ($options->field === null ? 1 : 2),
        ];
        if ($data['step'] === 1) {
            $fields = [];
            foreach ($options->fields as $field) {
                $fields[] = [
                    'key' => $field->key,
                    'label' => $view->text('lists.filter.field_option', ['field' => $field->name, 'type' => $view->text('type.' . $field->type)]),
                    'selected' => $active !== null && $active->field->key === $field->key,
                ];
            }
            $data['fields'] = $fields;
            $data['hidden'] = self::hidden($state, ['q', 'sort', 'filter', 'op', 'v', 'v2']);
        } elseif ($data['step'] === 2 && $options->field !== null) {
            $data += $this->stepTwo($options, $options->field, $active !== null && $active->field->key === $options->field->key ? $active : null);
            $data['hidden'] = self::hidden($state, ['q', 'sort']);
            $data['hidden'][] = ['name' => 'filter', 'value' => $options->field->key];
            $data['otherQuery'] = $state;
        }

        return $this->context->page('records/filter', $data);
    }

    /**
     * The controls of filter step 2 for one field.
     *
     * @return array<string, mixed>
     */
    private function stepTwo(FilterOptions $options, FieldDef $field, ?ActiveFilter $active): array
    {
        $operator = $active?->operator;
        $first = $active?->operands[0] ?? null;
        $second = $active?->operands[1] ?? null;
        $data = [
            'field' => ['key' => $field->key, 'name' => $field->name, 'type' => $field->type],
            'empty' => $operator === 'empty',
            'choices' => [],
            'records' => [],
            'more' => false,
            'ticked' => !($operator === 'is' && $first === '0'),
            'from' => '',
            'to' => '',
        ];
        if ($field->type === 'choice') {
            foreach ($options->choices as $choice) {
                $data['choices'][] = ['value' => $choice, 'selected' => $operator === 'is' && $first === $choice];
            }
        } elseif ($field->type === 'relation') {
            $itemNames = self::itemNames($options->tables);
            foreach ($options->records as $item) {
                $id = (string) $item->id;
                $data['records'][] = [
                    'id' => $id,
                    'label' => $item->title ?? $this->untitled($itemNames[$item->tableKey] ?? '', $item->id),
                    'selected' => $operator === 'links-to' && $first === $id,
                ];
            }
            $data['more'] = $options->more;
        } elseif ($operator === 'between') {
            $data['from'] = $first ?? '';
            $data['to'] = $second ?? '';
        }

        return $data;
    }

    /**
     * The record-table data of a listing (shared `record-table` partial):
     * the title column (or "{Item}" when the table has no title field),
     * then the other columns; each row's cells match the columns after the
     * first.
     *
     * @param array<string, string> $state
     * @param array<string, string> $itemNames
     * @return array<string, mixed>
     */
    private function table(RecordListing $listing, string $base, array $state, array $itemNames): array
    {
        $view = $this->context->view();
        $format = $view->format();
        $table = $listing->table;
        $item = self::capital($table->itemName);
        $columns = [];
        $cellColumns = $listing->columns;
        if ($table->titleFieldKey === null) {
            $columns[] = ['field' => null, 'header' => $view->text('lists.col.record', ['Item' => $item]), 'numeric' => false, 'sort' => null];
        } else {
            array_shift($cellColumns);
        }
        foreach ($listing->columns as $field) {
            $head = $this->columnHeader($field, $listing->sort);
            $columns[] = ['field' => $field, 'header' => $head['header'], 'numeric' => self::isNumeric($field), 'sort' => $head['sort']];
        }

        $rows = [];
        foreach ($listing->rows as $row) {
            $cells = [];
            foreach ($cellColumns as $field) {
                $cell = $row->cells[$field->key] ?? null;
                $cells[] = $cell === null ? ['field' => $field, 'value' => null, 'links' => null, 'moreLinks' => 0, 'fileCount' => null] : self::cell($listing, $cell);
            }
            $title = $row->title === null || $row->title === '' ? $this->untitled($table->itemName, $row->id) : $row->title;
            $rows[] = ['path' => $base . '/records/' . $row->id, 'query' => $state, 'title' => $title, 'cells' => $cells];
        }

        return [
            'caption' => $view->text('lists.caption', [
                'table' => $table->name,
                'from' => $format->number($listing->from),
                'to' => $format->number($listing->to),
                'total' => $format->number($listing->total),
            ]),
            'columns' => $columns,
            'rows' => $rows,
            'itemNames' => $itemNames,
        ];
    }

    /** @return array{field: FieldDef, value: mixed, links: list<array{path: string, table: string, id: int, title: string|null}>|null, moreLinks: int, fileCount: int|null} */
    private static function cell(RecordListing $listing, Cell $cell): array
    {
        return self::cellData($listing->project->key, $cell);
    }

    /**
     * The record-table data of one cell of a project's record (shared with
     * the collection editor's preview).
     *
     * @return array{field: FieldDef, value: mixed, links: list<array{path: string, table: string, id: int, title: string|null}>|null, moreLinks: int, fileCount: int|null}
     * @internal
     */
    public static function cellData(string $projectKey, Cell $cell): array
    {
        $links = null;
        $fileCount = null;
        if ($cell->field->type === 'relation') {
            $links = [];
            foreach ($cell->links as $link) {
                $links[] = [
                    'path' => '/projects/' . $projectKey . '/tables/' . $link->tableKey . '/records/' . $link->id,
                    'table' => $link->tableKey,
                    'id' => $link->id,
                    'title' => $link->title === '' ? null : $link->title,
                ];
            }
        } elseif ($cell->field->type === 'attachment') {
            $fileCount = $cell->fileCount;
        }

        return ['field' => $cell->field, 'value' => $cell->value, 'links' => $links, 'moreLinks' => $cell->moreLinks, 'fileCount' => $fileCount];
    }

    /**
     * The text of an active filter's condition (section 2 item 4), plain.
     *
     * @param array<string, string> $itemNames
     */
    private function condition(ActiveFilter $filter, RecordListing $listing, array $itemNames): string
    {
        $view = $this->context->view();
        $format = $view->format();
        $field = $filter->field->name;
        $first = $filter->operands[0] ?? '';
        $second = $filter->operands[1] ?? '';

        if ($filter->operator === 'empty') {
            return $view->text('lists.cond.empty', ['field' => $field]);
        }
        if ($filter->field->type === 'checkbox') {
            return $view->text($first === '1' ? 'lists.cond.ticked' : 'lists.cond.not_ticked', ['field' => $field]);
        }
        if ($filter->operator === 'links-to') {
            $title = $filter->linkTitle;
            if ($title === null || $title === '') {
                $title = $this->untitled($this->linkedItemName($listing, $filter, $itemNames), (int) $first);
            }

            return $view->text('lists.cond.links_to', ['field' => $field, 'title' => $title]);
        }
        if ($filter->operator === 'between') {
            if ($first !== '' && $second !== '') {
                return $view->text('lists.cond.between', ['field' => $field, 'from' => $format->date($first), 'to' => $format->date($second)]);
            }
            if ($first !== '') {
                return $view->text('lists.cond.from', ['field' => $field, 'from' => $format->date($first)]);
            }

            return $view->text('lists.cond.to', ['field' => $field, 'to' => $format->date($second)]);
        }

        return $view->text('lists.cond.is', ['field' => $field, 'value' => $first]);
    }

    /**
     * The item name of the table a relation filter links to, for an
     * untitled linked record. ActiveFilter does not name that table, so it
     * is read from the filter options of the field (the linked record is
     * live, so the pick list is not empty).
     *
     * @param array<string, string> $itemNames
     */
    private function linkedItemName(RecordListing $listing, ActiveFilter $filter, array $itemNames): string
    {
        $options = $this->context->services()->operations()->records()->filterOptions(
            $this->context->actor(),
            $listing->project->key,
            $listing->table->key,
            $filter->field->key,
        );
        $first = $options->records[0] ?? null;

        return $first === null ? '' : ($itemNames[$first->tableKey] ?? '');
    }

    /**
     * The header text of a list column and its `aria-sort` value
     * (`ascending`, `descending`, or null) under the sort token given.
     *
     * @return array{header: string, sort: string|null}
     * @internal Shared with the collection editor's preview.
     */
    public function columnHeader(FieldDef $field, ?string $sortToken): array
    {
        if ($sortToken !== $field->key . '.asc' && $sortToken !== $field->key . '.desc') {
            return ['header' => $field->name, 'sort' => null];
        }
        $descending = $sortToken === $field->key . '.desc';
        $header = $this->context->view()->text('lists.col.sorted', ['field' => $field->name, 'direction' => $this->context->view()->text(self::sortKey($field, $descending) . '_short')]);

        return ['header' => $header, 'sort' => $descending ? 'descending' : 'ascending'];
    }

    /** Whether list cells of the field are right-aligned (`num`). */
    public static function isNumeric(FieldDef $field): bool
    {
        return in_array($field->type, self::NUMERIC, true);
    }

    /**
     * The menu label of a sort option.
     *
     * @internal Public for the collection editor's sort menu.
     */
    public function sortLabel(SortOption $option): string
    {
        $view = $this->context->view();
        if ($option->field === null) {
            return $view->text('sort.' . $option->kind . ($option->descending ? '.desc' : '.asc'));
        }

        return $view->text(self::sortKey($option->field, $option->descending), ['field' => $option->field->name]);
    }

    /** The short direction words of a sort option ("newest added first"). */
    private function sortShort(SortOption $option): string
    {
        $view = $this->context->view();
        if ($option->field === null) {
            return $view->text('sort.' . $option->kind . ($option->descending ? '.desc_short' : '.asc_short'));
        }

        return $view->text(self::sortKey($option->field, $option->descending) . '_short');
    }

    private static function sortKey(FieldDef $field, bool $descending): string
    {
        return 'sort.' . (self::SORT_GROUPS[$field->type] ?? 'text') . ($descending ? '.desc' : '.asc');
    }

    /** "{Item} {id}" for a record with no title. */
    private function untitled(string $itemName, int $id): string
    {
        return $this->context->view()->text('lists.untitled', ['Item' => self::capital($itemName), 'id' => (string) $id]);
    }

    /**
     * @param list<TableSummary> $tables
     * @return array<string, string> table key => item name with a capital first letter
     */
    private static function itemNames(array $tables): array
    {
        $names = [];
        foreach ($tables as $summary) {
            $names[$summary->table->key] = self::capital($summary->table->itemName);
        }

        return $names;
    }

    /** The text with its first character in upper case ("item" to "Item"). */
    public static function capital(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Hidden inputs for the named parameters of the state that are present.
     *
     * @param array<string, string> $state
     * @param list<string> $names
     * @return list<array{name: string, value: string}>
     */
    private static function hidden(array $state, array $names): array
    {
        $hidden = [];
        foreach ($names as $name) {
            if (isset($state[$name])) {
                $hidden[] = ['name' => $name, 'value' => $state[$name]];
            }
        }

        return $hidden;
    }

    /**
     * The core's answer for the person on the project: `io.use` shows the
     * sidebar's Data group (M3 spec part 2 section 4), `collections.manage`
     * its "New collection" link (M4 spec part 2 section 5).
     */
    private function can(string $projectKey, Permission $permission): bool
    {
        return $this->context->services()->operations()->auth()->can($this->context->actor(), $permission, $projectKey);
    }
}
