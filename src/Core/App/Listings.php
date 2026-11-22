<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Query\Collection;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\NormalisedQuery;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\ResolvedRules;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\Sort;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\System\ProjectRecord;

/**
 * What the table list, the search page, and the collection view share
 * (M2 spec section 7.2, M4 spec section 10.4): normalising the view
 * state, building a RecordListing, the sidebar's tables and collections,
 * and collection counts.
 *
 * Takes no permission decision: every caller is an operation method that
 * has already passed Context::project for the project it hands in.
 *
 * Collection counts (P181, security review L-6): each is one COUNT query
 * (no rows read), and the summaries of a project are kept for the rest of
 * the request until the project file changes (SQLite's data_version for
 * other connections, total_changes() for this one), so a page that needs
 * them twice counts once.
 *
 * @internal Built by Context only.
 */
final class Listings
{
    /** Columns of a list besides the title field (ADR 0013 decision 9.1). */
    public const MAX_EXTRA_COLUMNS = 6;
    /** Field types never shown as list columns. */
    private const NOT_COLUMNS = ['long_text'];

    /** @var array<string, array{0: string, 1: list<CollectionSummary>}> project key => [change marker, summaries] */
    private array $summaries = [];

    public function __construct(private readonly Context $c)
    {
    }

    // ------------------------------------------------------------------ view state

    /** The M2 list query from raw view state; invalid parts dropped and reported. */
    public function normalise(Records $records, TableDef $table, ListRequest $request): NormalisedQuery
    {
        return $records->query(
            $table->key,
            $request->sort,
            $request->filter,
            $request->op,
            [$request->v ?? '', $request->v2 ?? ''],
            $request->q,
            $request->page,
        );
    }

    /**
     * The list query of a collection (P162): its resolved rules, the
     * request's search, the request's sort or else the collection's
     * resolved sort or else the table's, and the page; the request's filter
     * is ignored.
     */
    public function collectionQuery(Records $records, TableDef $table, ResolvedRules $resolved, ListRequest $request): NormalisedQuery
    {
        $offered = array_map(static fn (Sort $sort): string => $sort->token(), $records->sorts($table->key));
        $sort = $request->sort !== null && in_array($request->sort, $offered, true) ? $request->sort : $resolved->sort?->token();
        $normalised = $records->query($table->key, $sort, null, null, [], $request->q, $request->page);
        $query = $normalised->query;

        return new NormalisedQuery(new ListQuery($query->sort, null, $query->search, $query->page, $resolved->set), null, $normalised->searchProblem);
    }

    /**
     * The view state rebuilt from a normalised query: invalid parts
     * dropped, the default sort (the collection's when $resolved is given)
     * left out, and the collection key kept when given.
     */
    public function request(Records $records, TableDef $table, NormalisedQuery $normalised, int $page, ?string $collection = null, ?ResolvedRules $resolved = null): ListRequest
    {
        $query = $normalised->query;
        $default = $resolved?->sort?->token() ?? $records->query($table->key, null, null, null, [], null, 1)->query->sort->token();
        $sort = $query->sort->token();
        $filter = $query->filter;
        $first = $filter?->operands[0] ?? null;
        $second = $filter?->operands[1] ?? null;

        return new ListRequest(
            $query->search,
            $sort === $default ? null : $sort,
            $filter?->fieldKey,
            $filter?->operator->value,
            $first === '' ? null : $first,
            $second === '' ? null : $second,
            max(1, min($page, ListRequest::MAX_PAGE)),
            $collection,
        );
    }

    // ------------------------------------------------------------------ the list

    /**
     * One page of a table list or a collection view. $resolved is the
     * collection's rules when $collection is given (its key goes into the
     * rebuilt request, so record links keep the collection).
     */
    public function listing(
        Actor $actor,
        ProjectRecord $project,
        Database $db,
        Schema $schema,
        Records $records,
        Trash $trash,
        TableDef $table,
        NormalisedQuery $normalised,
        ?CollectionFacts $collection = null,
        ?ResolvedRules $resolved = null,
    ): RecordListing {
        $query = $normalised->query;
        $page = $records->page($table->key, $query);

        $fields = $schema->fields($table->key);
        $columns = self::columns($schema, $table, $fields);
        $rows = $this->rows($schema, $table, $columns, $page->records);

        $sortOptions = [];
        foreach ($records->sorts($table->key) as $sort) {
            $isField = $sort->by !== 'added' && $sort->by !== 'changed';
            $sortOptions[] = new SortOption(
                $sort->token(),
                $isField ? $schema->field($sort->by) : null,
                $isField ? 'field' : $sort->by,
                $sort->descending,
            );
        }

        $filter = null;
        if ($query->filter !== null) {
            $field = $schema->field($query->filter->fieldKey);
            $linkTitle = null;
            if ($query->filter->operator === Operator::LinksTo) {
                $relation = $schema->relationOf($field);
                $target = (int) $query->filter->operands[0];
                if ($relation !== null) {
                    $linkTitle = $records->titles($relation->otherTable($field->key), [$target])[$target] ?? null;
                }
            }
            $filter = new ActiveFilter($field, $query->filter->operator->value, $query->filter->operands, $linkTitle);
        }

        $from = $page->total === 0 ? 0 : ($page->page - 1) * Records::PAGE_SIZE + 1;
        $to = $page->total === 0 ? 0 : $from + count($rows) - 1;

        return new RecordListing(
            $project,
            $table,
            $this->tables($schema, $records),
            self::trashCount($schema, $trash),
            $columns,
            $rows,
            $page->total,
            $page->tableTotal,
            $page->page,
            $page->pages,
            $from,
            $to,
            $sortOptions,
            $page->sort->token(),
            $filter,
            $normalised->filterProblem,
            $normalised->searchProblem,
            $query->search,
            $this->request($records, $table, $normalised, $page->page, $collection?->key, $resolved),
            $fields !== [],
            self::filterable($fields) !== [],
            $this->c->authorizer->can($actor, Permission::ChangeSchema, $project),
            $this->c->authorizer->can($actor, Permission::ImportExport, $project),
            $collection,
            $this->summaries($project, $db, $schema),
        );
    }

    /**
     * @param list<FieldDef> $columns
     * @param list<RecordData> $records
     * @return list<ListRow>
     */
    public function rows(Schema $schema, TableDef $table, array $columns, array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[$column->key] = self::cell($column, $record);
            }
            $rows[] = new ListRow($record->id, $this->title($schema, $table, $record), $cells);
        }

        return $rows;
    }

    /**
     * @param list<FieldDef> $fields
     * @return list<FieldDef> the title field (when set), then up to $extra more in field order, skipping long text
     */
    public static function columns(Schema $schema, TableDef $table, array $fields, int $extra = self::MAX_EXTRA_COLUMNS): array
    {
        $columns = [];
        if ($table->titleFieldKey !== null) {
            $columns[] = $schema->field($table->titleFieldKey);
        }
        $added = 0;
        foreach ($fields as $field) {
            if ($added >= $extra) {
                break;
            }
            if ($field->key === $table->titleFieldKey || in_array($field->type, self::NOT_COLUMNS, true)) {
                continue;
            }
            $columns[] = $field;
            $added++;
        }

        return $columns;
    }

    public static function cell(FieldDef $field, RecordData $record): Cell
    {
        if ($field->type === 'relation') {
            $linked = $record->links[$field->key] ?? [];
            $links = [];
            foreach (array_slice($linked, 0, Cell::MAX_LINKS) as $item) {
                $links[] = new LinkItem($item->tableKey, $item->id, $item->title);
            }

            return new Cell($field, null, $links, max(0, count($linked) - Cell::MAX_LINKS), 0);
        }
        if ($field->type === 'attachment') {
            return new Cell($field, null, [], 0, count($record->attachments[$field->key] ?? []));
        }

        return new Cell($field, $record->values[$field->key] ?? null, [], 0, 0);
    }

    /**
     * @param list<FieldDef> $fields
     * @return list<FieldDef>
     */
    public static function filterable(array $fields): array
    {
        return array_values(array_filter($fields, static fn (FieldDef $field): bool => isset(ListSql::FILTERS[$field->type])));
    }

    /** The title field's canonical text, or null when there is no title field or it is empty. */
    public function title(Schema $schema, TableDef $table, RecordData $record): ?string
    {
        if ($table->titleFieldKey === null) {
            return null;
        }
        $field = $schema->field($table->titleFieldKey);
        $value = $record->values[$field->key] ?? null;

        return $value === null ? null : $this->c->types->get($field->type)->toText($value, $field->options);
    }

    /** @return list<TableSummary> by position, with field counts and live record counts */
    public function tables(Schema $schema, Records $records): array
    {
        $list = [];
        foreach ($schema->tables() as $table) {
            $list[] = new TableSummary($table, count($schema->fields($table->key)), $records->count($table->key));
        }

        return $list;
    }

    public static function trashCount(Schema $schema, Trash $trash): int
    {
        $count = 0;
        foreach ($schema->tables() as $table) {
            $count += $trash->count($table);
        }

        return $count;
    }

    // ------------------------------------------------------------------ collections

    /** A stored collection's rules checked against its table as it is now; NotFound when the table is gone. */
    public function resolve(Database $db, Schema $schema, Collection $collection): ResolvedRules
    {
        return $this->c->collectionRules($db, $schema)->resolve($collection->tableKey, $collection->match, $collection->rules, $collection->sort);
    }

    /** Live records of the table matching the rules (every live record for null), with one COUNT query. */
    public function count(Schema $schema, TableDef $table, Database $db, ?RuleSet $rules): int
    {
        return (new Records($db, $schema, $this->c->types, $this->c->clock))
            ->countMatching($table->key, new ListQuery(new Sort('added', false), null, null, 1, $rules));
    }

    /**
     * Every collection of the project by position, with its count and
     * broken rules. A collection whose table is missing (possible only in
     * a file another tool edited) is left out, so one bad row cannot fail
     * the page (P181, review L-4).
     *
     * @return list<CollectionSummary>
     */
    public function summaries(ProjectRecord $project, Database $db, Schema $schema): array
    {
        $marker = spl_object_id($db) . ':' . (string) $db->value('PRAGMA data_version') . ':' . (string) $db->value('SELECT total_changes()');
        $cached = $this->summaries[$project->key] ?? null;
        if ($cached !== null && $cached[0] === $marker) {
            return $cached[1];
        }
        $list = [];
        foreach ($this->c->collectionStore($db, $schema)->all() as $collection) {
            try {
                $table = $schema->table($collection->tableKey);
                $resolved = $this->resolve($db, $schema, $collection);
            } catch (NotFound) {
                continue;
            }
            $list[] = new CollectionSummary($collection->key, $table->key, $collection->name, $this->count($schema, $table, $db, $resolved->set), $resolved->broken);
        }
        $this->summaries[$project->key] = [$marker, $list];

        return $list;
    }
}
