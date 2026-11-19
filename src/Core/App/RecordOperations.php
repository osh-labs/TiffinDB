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
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Forms\BindData;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Forms\BoundNode;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Core\Forms\FileItem;
use TiffinDB\Core\Forms\FormBinder;
use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\NormalisedQuery;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Records of a project (M2 spec section 7.2): lists, the record screen,
 * create, update, conflicts, trash and restore, links, and search.
 *
 * Authorization lives here: every method starts with Context::project for
 * its permission, so a Member without a grant on the project gets NotFound
 * from every method, as does an unknown or inactive project. A table key
 * that is not a table of the project, a field key that is not a field of
 * that table, a record id that is not canonical digits (ids arrive as
 * strings from addresses), and an absent record are NotFound too.
 *
 * No method writes an audit entry: record-level edits are not logged (ADR
 * 0012 decision 4).
 */
final class RecordOperations
{
    /** Columns of a list besides the title field (ADR 0013 decision 9.1). */
    public const MAX_EXTRA_COLUMNS = 6;
    /** Field types never shown as list columns. */
    private const NOT_COLUMNS = ['long_text'];
    /** Field types shown on a form but never edited there in M2. */
    private const NOT_EDITABLE = ['relation', 'attachment'];

    public function __construct(private readonly Context $c)
    {
    }

    // ------------------------------------------------------------------ lists

    public function listing(Actor $actor, string $projectKey, string $tableKey, ListRequest $request): RecordListing
    {
        [$project, , $schema, $records, $trash] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $normalised = $this->normalise($records, $table, $request);
        $query = $normalised->query;
        $page = $records->page($table->key, $query);

        $fields = $schema->fields($table->key);
        $columns = self::columns($schema, $table, $fields);
        $rows = [];
        foreach ($page->records as $record) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[$column->key] = self::cell($column, $record);
            }
            $rows[] = new ListRow($record->id, $this->title($schema, $table, $record), $cells);
        }

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
            $this->request($records, $table, $normalised, $page->page),
            $fields !== [],
            self::filterable($fields) !== [],
            $this->c->authorizer->can($actor, Permission::ChangeSchema, $project),
        );
    }

    /** What the filter page offers for the table and, when $fieldKey names a filterable field, for that field. */
    public function filterOptions(Actor $actor, string $projectKey, string $tableKey, ?string $fieldKey): FilterOptions
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $fields = self::filterable($schema->fields($table->key));
        $chosen = null;
        foreach ($fields as $field) {
            if ($field->key === $fieldKey) {
                $chosen = $field;
            }
        }
        $choices = [];
        $linkable = [];
        $more = false;
        if ($chosen !== null && $chosen->type === 'choice') {
            foreach ($chosen->options['choices'] ?? [] as $choice) {
                if (is_array($choice) && is_string($choice['label'] ?? null)) {
                    $choices[] = $choice['label'];
                }
            }
        }
        if ($chosen !== null && $chosen->type === 'relation') {
            $relation = $schema->relationOf($chosen);
            if ($relation !== null) {
                $other = $relation->otherTable($chosen->key);
                foreach ($records->pickList($other, Records::PICKER_LIMIT) as $linked) {
                    $linkable[] = new LinkItem($linked->tableKey, $linked->id, $linked->title);
                }
                $more = $records->count($other) > Records::PICKER_LIMIT;
            }
        }

        return new FilterOptions($table, $fields, $chosen, $choices, $linkable, $more, $this->tables($schema, $records));
    }

    // ------------------------------------------------------------------ screens

    /** The screen for a new record: the effective form with empty values. */
    public function blank(Actor $actor, string $projectKey, string $tableKey, ListRequest $request): RecordScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $normalised = $this->normalise($records, $table, $request);

        return $this->newScreen($project, $db, $schema, $table, [], [], $this->request($records, $table, $normalised, 1));
    }

    /** A record's screen; for a record in the trash, `trashed` is true and there is no form. */
    public function screen(Actor $actor, string $projectKey, string $tableKey, string $recordId, ListRequest $request): RecordScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $record = self::found($records->get($table->key, self::recordId($recordId)));

        return $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, [], []);
    }

    /**
     * The screen showing what the person typed and the problems found
     * (the 422 page): a new record when $recordId is null, else the live
     * record.
     *
     * @param array<mixed> $input field key => text
     * @param list<Problem> $problems from ValidationFailed
     */
    public function redisplay(Actor $actor, string $projectKey, string $tableKey, ?string $recordId, array $input, array $problems, ListRequest $request): RecordScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = $recordId === null ? null : self::recordId($recordId);
        $submitted = self::input($input);
        $byField = [];
        foreach ($problems as $problem) {
            if (!$problem instanceof Problem) {
                throw new \InvalidArgumentException('Problems must be Problem values.');
            }
            if ($problem->field !== null) {
                $byField[$problem->field][] = $problem;
            }
        }
        if ($id === null) {
            $normalised = $this->normalise($records, $table, $request);

            return $this->newScreen($project, $db, $schema, $table, $submitted, $byField, $this->request($records, $table, $normalised, 1));
        }
        $record = self::live($records->get($table->key, $id));

        return $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, $submitted, $byField);
    }

    // ------------------------------------------------------------------ writes

    /**
     * Creates a record from text typed in a form; returns its id.
     *
     * @param array<mixed> $input field key => text
     * @throws \TiffinDB\Core\Error\ValidationFailed
     */
    public function create(Actor $actor, string $projectKey, string $tableKey, array $input): int
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);

        return $records->create($table->key, self::input($input), InputMode::Form, $actor->userKey);
    }

    /**
     * Changes the given fields of a live record. Only the keys passed are
     * read (the web layer passes BoundForm::editableKeys()).
     *
     * @param array<mixed> $input field key => text
     * @throws \TiffinDB\Core\Error\ValidationFailed
     * @throws \TiffinDB\Core\Error\Conflict when $version is not the record's current version
     */
    public function update(Actor $actor, string $projectKey, string $tableKey, string $recordId, array $input, string $version): void
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $values = self::input($input);
        self::live($records->get($table->key, $id));
        $records->update($table->key, $id, $values, InputMode::Form, $version, $actor->userKey);
    }

    /**
     * The saved record and the posted fields that differ from it: for each
     * posted key whose field still exists and is editable, a row when the
     * text fails to parse or its canonical value differs from the stored one.
     *
     * @param array<mixed> $input field key => text
     */
    public function conflict(Actor $actor, string $projectKey, string $tableKey, string $recordId, array $input, ListRequest $request): ConflictView
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $posted = self::input($input);
        $record = self::live($records->get($table->key, $id));
        $screen = $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, [], []);
        $labels = $screen->form === null ? [] : self::labels($screen->form->nodes);

        $rows = [];
        foreach ($schema->fields($table->key) as $field) {
            if (!array_key_exists($field->key, $posted) || !$this->isEditable($field)) {
                continue;
            }
            $type = $this->c->types->get($field->type);
            $saved = $record->values[$field->key] ?? null;
            $parsed = $type->parse($posted[$field->key], $field->options, false);
            if (!$parsed->isOk() || $type->toStorage($parsed->value(), $field->options) !== $type->toStorage($saved, $field->options)) {
                $rows[] = new ConflictRow($field, $labels[$field->key] ?? $field->name, $saved, $posted[$field->key]);
            }
        }

        return new ConflictView($screen, $rows);
    }

    /** Moves a record to the trash; returns it with its title as it was. A record already in the trash is left as it is. */
    public function trash(Actor $actor, string $projectKey, string $tableKey, string $recordId): RecordRef
    {
        [, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::TrashRecords);
        $table = $schema->table($tableKey);
        $record = self::found($records->get($table->key, self::recordId($recordId)));
        $ref = new RecordRef($record->id, $this->title($schema, $table, $record));
        if ($record->trashedAt === null) {
            (new Trash($db, $this->c->clock))->moveToTrash($table, [$record->id], $actor->userKey);
        }

        return $ref;
    }

    /** Restores a record from the trash; a live record is left as it is. */
    public function restore(Actor $actor, string $projectKey, string $tableKey, string $recordId): RecordRef
    {
        [, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::TrashRecords);
        $table = $schema->table($tableKey);
        $record = self::found($records->get($table->key, self::recordId($recordId)));
        if ($record->trashedAt !== null) {
            (new Trash($db, $this->c->clock))->restore($table, [$record->id], $actor->userKey);
        }

        return new RecordRef($record->id, $this->title($schema, $table, $record));
    }

    /**
     * Makes $targetIds exactly the record's live links in a relation field
     * of the table (core only in M2).
     *
     * @param list<int> $targetIds
     * @throws \TiffinDB\Core\Error\ValidationFailed
     */
    public function setLinks(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, array $targetIds): void
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $field = $schema->field($fieldKey);
        if ($field->tableKey !== $table->key || $field->type !== 'relation') {
            throw new NotFound('No such relation field in this table.');
        }
        self::live($records->get($table->key, $id));
        $records->setLinks($field->key, $id, $targetIds, $actor->userKey);
    }

    // ------------------------------------------------------------------ search

    /** Searches every table of the project; with a problem or empty text there are no groups. */
    public function search(Actor $actor, string $projectKey, string $q): SearchResults
    {
        [$project, , $schema, $records, $trash] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $text = self::searchText($q);
        $problem = $text instanceof Problem ? $text : null;
        $search = is_string($text) ? $text : null;
        $groups = [];
        $total = 0;
        if ($search !== null) {
            foreach ($records->search($search) as $group) {
                $hits = [];
                foreach ($group->hits as $hit) {
                    $hits[] = new SearchResultHit($hit->id, $hit->title, $hit->fieldKey === null ? null : $schema->field($hit->fieldKey), $hit->text);
                }
                $groups[] = new SearchResultGroup($schema->table($group->tableKey), $group->total, $hits);
                $total += $group->total;
            }
        }

        return new SearchResults($project, $search, $problem, $total, $groups, $this->tables($schema, $records), self::trashCount($schema, $trash));
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ProjectRecord, 1: Database, 2: Schema, 3: Records, 4: Trash} */
    private function open(Actor $actor, string $projectKey, Permission $permission): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        $db = $this->c->opener->open($project);
        $schema = new Schema($db, $this->c->types);

        return [$project, $db, $schema, $this->c->records($project, $db, $schema), new Trash($db, $this->c->clock)];
    }

    /** A record id from an address: canonical digits, 1 to PHP_INT_MAX; NotFound otherwise. */
    private static function recordId(string $text): int
    {
        try {
            return ListSql::recordId($text);
        } catch (\InvalidArgumentException) {
            throw new NotFound('No such record.');
        }
    }

    private static function found(?RecordData $record): RecordData
    {
        if ($record === null) {
            throw new NotFound('No such record.');
        }

        return $record;
    }

    private static function live(?RecordData $record): RecordData
    {
        if ($record === null || $record->trashedAt !== null) {
            throw new NotFound('No such live record.');
        }

        return $record;
    }

    /**
     * Form input as field key => text; anything else is a caller error.
     *
     * @param array<mixed> $input
     * @return array<string, string>
     */
    private static function input(array $input): array
    {
        $checked = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('Record input is field key => text.');
            }
            $checked[$key] = $value;
        }

        return $checked;
    }

    private function normalise(Records $records, TableDef $table, ListRequest $request): NormalisedQuery
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

    /** The view state rebuilt from a normalised query: invalid parts dropped, the default sort left out. */
    private function request(Records $records, TableDef $table, NormalisedQuery $normalised, int $page): ListRequest
    {
        $query = $normalised->query;
        $default = $records->query($table->key, null, null, null, [], null, 1)->query->sort->token();
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
        );
    }

    /**
     * @param list<FieldDef> $fields
     * @return list<FieldDef> the title field (when set), then up to MAX_EXTRA_COLUMNS more in field order, skipping long text
     */
    private static function columns(Schema $schema, TableDef $table, array $fields): array
    {
        $columns = [];
        if ($table->titleFieldKey !== null) {
            $columns[] = $schema->field($table->titleFieldKey);
        }
        $extra = 0;
        foreach ($fields as $field) {
            if ($extra >= self::MAX_EXTRA_COLUMNS) {
                break;
            }
            if ($field->key === $table->titleFieldKey || in_array($field->type, self::NOT_COLUMNS, true)) {
                continue;
            }
            $columns[] = $field;
            $extra++;
        }

        return $columns;
    }

    private static function cell(FieldDef $field, RecordData $record): Cell
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
    private static function filterable(array $fields): array
    {
        return array_values(array_filter($fields, static fn (FieldDef $field): bool => isset(ListSql::FILTERS[$field->type])));
    }

    /** The title field's canonical text, or null when there is no title field or it is empty. */
    private function title(Schema $schema, TableDef $table, RecordData $record): ?string
    {
        if ($table->titleFieldKey === null) {
            return null;
        }
        $field = $schema->field($table->titleFieldKey);
        $value = $record->values[$field->key] ?? null;

        return $value === null ? null : $this->c->types->get($field->type)->toText($value, $field->options);
    }

    private function isEditable(FieldDef $field): bool
    {
        return $field->hasColumn
            && !in_array($field->type, self::NOT_EDITABLE, true)
            && $this->c->types->get($field->type)->isEditable();
    }

    /** @return list<TableSummary> by position, with field counts and live record counts */
    private function tables(Schema $schema, Records $records): array
    {
        $list = [];
        foreach ($schema->tables() as $table) {
            $list[] = new TableSummary($table, count($schema->fields($table->key)), $records->count($table->key));
        }

        return $list;
    }

    private static function trashCount(Schema $schema, Trash $trash): int
    {
        $count = 0;
        foreach ($schema->tables() as $table) {
            $count += $trash->count($table);
        }

        return $count;
    }

    /**
     * @param array<string, string> $submitted
     * @param array<string, list<Problem>> $problems
     */
    private function newScreen(ProjectRecord $project, Database $db, Schema $schema, TableDef $table, array $submitted, array $problems, ListRequest $request): RecordScreen
    {
        $form = $this->bind($db, $schema, $table, null, $submitted, $problems, true);

        return new RecordScreen($project, $table, null, true, false, null, null, null, $form, null, null, null, null, null, $request);
    }

    /**
     * @param array<string, string> $submitted
     * @param array<string, list<Problem>> $problems
     */
    private function recordScreen(
        ProjectRecord $project,
        Database $db,
        Schema $schema,
        Records $records,
        TableDef $table,
        RecordData $record,
        ListRequest $request,
        array $submitted,
        array $problems,
    ): RecordScreen {
        $normalised = $this->normalise($records, $table, $request);
        $trashed = $record->trashedAt !== null;
        $form = null;
        $position = null;
        if (!$trashed) {
            $form = $this->bind($db, $schema, $table, $record, $submitted, $problems, false);
            $position = $this->position($records, $table, $normalised->query, $record->id);
        }

        return new RecordScreen(
            $project,
            $table,
            $record->id,
            false,
            $trashed,
            $record->trashedAt,
            $this->title($schema, $table, $record),
            $record->version,
            $form,
            $position,
            $record->createdAt,
            $this->displayName($record->createdBy),
            $record->updatedAt,
            $this->displayName($record->updatedBy),
            $this->request($records, $table, $normalised, $normalised->query->page),
        );
    }

    private function position(Records $records, TableDef $table, ListQuery $query, int $id): ?RecordPosition
    {
        $position = $records->position($table->key, $query, $id);
        if ($position === null) {
            return null;
        }
        $neighbours = array_values(array_filter([$position->previous, $position->next], static fn (?int $n): bool => $n !== null));
        $titles = $neighbours === [] ? [] : $records->titles($table->key, $neighbours);

        return new RecordPosition(
            $position->index,
            $position->total,
            $position->previous,
            $position->previous === null ? null : ($titles[$position->previous] ?? null),
            $position->next,
            $position->next === null ? null : ($titles[$position->next] ?? null),
        );
    }

    /**
     * @param array<string, string> $submitted
     * @param array<string, list<Problem>> $problems
     */
    private function bind(Database $db, Schema $schema, TableDef $table, ?RecordData $record, array $submitted, array $problems, bool $isNew): BoundForm
    {
        $document = $this->c->forms($db)->effective($schema, $table->key);
        $links = [];
        $files = [];
        if ($record !== null) {
            foreach ($record->links as $fieldKey => $linked) {
                $links[$fieldKey] = [];
                foreach ($linked as $item) {
                    $links[$fieldKey][] = new LinkItem($item->tableKey, $item->id, $item->title);
                }
            }
            foreach ($record->attachments as $fieldKey => $attached) {
                $files[$fieldKey] = [];
                foreach ($attached as $file) {
                    $files[$fieldKey][] = new FileItem($file->originalName, $file->mediaType, $file->size);
                }
            }
        }

        return (new FormBinder())->bind(
            $document,
            $table->key,
            $schema->fields($table->key),
            $this->c->types,
            new BindData($record === null ? [] : $record->values, $submitted, $problems, $links, $files, $isNew),
        );
    }

    private function displayName(?string $userKey): ?string
    {
        return $userKey === null ? null : $this->c->users->byKey($userKey)?->displayName;
    }

    /**
     * @param list<BoundNode> $nodes
     * @return array<string, string> field key => label shown on the form
     */
    private static function labels(array $nodes): array
    {
        $labels = [];
        foreach ($nodes as $node) {
            if ($node instanceof BoundField) {
                $labels[$node->field->key] = $node->label;
            } elseif ($node instanceof BoundSection) {
                $labels += self::labels($node->children);
            }
        }

        return $labels;
    }

    /**
     * Search text normalised as Records::query does (M2 spec section 3.6):
     * trimmed; null when empty; a problem for invalid UTF-8, control
     * characters, or more than Records::MAX_SEARCH_LENGTH characters.
     */
    private static function searchText(string $q): string|Problem|null
    {
        if (!Text::isUtf8($q)) {
            return new Problem(null, 'problem.text.control');
        }
        $q = Text::trim($q);
        if ($q === '') {
            return null;
        }
        if (Text::hasControl($q)) {
            return new Problem(null, 'problem.text.control');
        }
        if (Text::length($q) > Records::MAX_SEARCH_LENGTH) {
            return new Problem(null, 'problem.text.too_long', ['max' => Records::MAX_SEARCH_LENGTH]);
        }

        return $q;
    }
}
