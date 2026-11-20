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
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Query\Collection;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionRules;
use TiffinDB\Core\Query\CollectionStore;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Saved collections (M4 spec section 10.4, ADR 0019 decisions 1 to 7):
 * the sidebar summaries, the collection view, the editor with its
 * preview, save, and delete.
 *
 * Authorization: every method starts with Context::project for its
 * permission (records.view to see, collections.manage to change; P161),
 * so a Member without a grant, an unknown project, and an inactive
 * project are NotFound. A collection key that is not a collection of this
 * project (each project is its own file) is NotFound, and so is a table
 * key that is not one of its tables.
 *
 * Drafts arrive as posted (`name`, `match`, `sort`, `rules[<n>][field|
 * test|value|record]`): strings only, arrays where a string is expected
 * are dropped, at most MAX_RULES rows are read. Nothing a draft holds can
 * make these methods throw anything but ValidationFailed (save) or
 * NotFound for the keys in the address.
 *
 * Audits `collection.saved` and `collection.deleted` with the table key,
 * the rule count, and the match mode; never a rule's value.
 */
final class CollectionOperations
{
    /** Records the editor's preview lists (P163). */
    public const PREVIEW_ROWS = 5;
    /** Columns of the preview besides the title (P163). */
    public const PREVIEW_COLUMNS = 3;
    private const ROW_PARTS = ['field', 'test', 'value', 'record'];
    private const REMOVE = '/^remove-([1-9]|10)\z/';

    /** @var array<string, array{0: list<LinkItem>, 1: bool}> records a relation rule offers, by table key, for one editor call */
    private array $picks = [];

    public function __construct(private readonly Context $c)
    {
    }

    /**
     * Every collection of the project by position, with the live records
     * matching its working rules and the count of broken rules (for the
     * sidebar). A collection whose table is missing is left out.
     *
     * @return list<CollectionSummary>
     */
    public function summaries(Actor $actor, string $projectKey): array
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewRecords);

        return $this->c->listings()->summaries($project, $db, $schema);
    }

    /**
     * The collection's view: its table's list with the collection's
     * resolved rules, the request's search, sort (default: the
     * collection's, else the table's), and page; the request's filter is
     * ignored (P162).
     */
    public function listing(Actor $actor, string $projectKey, string $collectionKey, ListRequest $request): RecordListing
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $collection = $this->collection($db, $schema, $collectionKey);
        $table = $schema->table($collection->tableKey);
        $records = $this->c->records($project, $db, $schema);
        $listings = $this->c->listings();
        $resolved = $listings->resolve($db, $schema, $collection);
        $facts = new CollectionFacts($collection->key, $collection->name, $resolved->broken, $this->c->authorizer->can($actor, Permission::ManageCollections, $project));

        return $listings->listing(
            $actor,
            $project,
            $db,
            $schema,
            $records,
            new Trash($db, $this->c->clock),
            $table,
            $listings->collectionQuery($records, $table, $resolved, $request),
            $facts,
            $resolved,
        );
    }

    /**
     * The editor of a new collection of $tableKey, or of the collection
     * $collectionKey (exactly one of them is given). With $input null the
     * stored collection is shown (a new one: an empty name and one empty
     * rule); else the posted draft, after $action: `show`, `preview`,
     * `add` (one empty rule while fewer than MAX_RULES), or `remove-<n>`
     * (n 1 to 10; any other action is `preview`). Fields the table does
     * not offer for rules are dropped, a test that does not fit the field
     * resets to its first. The preview is computed from the rows that
     * resolve. $problems are those save() refused the draft with, shown
     * as they are.
     *
     * @param array<mixed>|null $input
     * @param list<Problem> $problems
     */
    public function editor(
        Actor $actor,
        string $projectKey,
        ?string $tableKey,
        ?string $collectionKey,
        ?array $input,
        string $action,
        array $problems = [],
    ): CollectionEditor {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ManageCollections);
        [$collection, $table] = $this->target($db, $schema, $tableKey, $collectionKey);
        $this->picks = [];
        foreach ($problems as $problem) {
            if (!$problem instanceof Problem) {
                throw new \InvalidArgumentException('Problems must be Problem values.');
            }
        }
        $records = $this->c->records($project, $db, $schema);
        $rules = $this->c->collectionRules($db, $schema);
        $fields = self::usable($rules, $schema->fields($table->key));
        $sorts = $this->sorts($schema, $records, $table);

        $broken = [];
        if ($input === null) {
            $draft = $collection === null ? self::emptyDraft() : self::storedDraft($collection, $fields);
            if ($collection !== null) {
                foreach ($rules->resolve($table->key, $collection->match, $collection->rules, $collection->sort)->states as $i => $state) {
                    $broken[$i] = $state->brokenReason;
                }
            }
        } else {
            $draft = self::draft($input);
            if ($action === 'add' && count($draft['rows']) < CollectionStore::MAX_RULES) {
                $draft['rows'][] = self::emptyRow();
            } elseif (preg_match(self::REMOVE, $action, $match) === 1 && isset($draft['rows'][(int) $match[1] - 1])) {
                array_splice($draft['rows'], (int) $match[1] - 1, 1);
            }
        }
        $sort = in_array($draft['sort'], array_map(static fn (SortOption $o): string => $o->token, $sorts), true) ? $draft['sort'] : null;

        $editorRules = [];
        $working = [];
        foreach ($draft['rows'] as $i => $row) {
            $field = $fields[$row['field']] ?? null;
            $tests = $field === null ? [] : $rules->testsFor($field);
            $ops = array_column($tests, 'op');
            $test = in_array($row['test'], $ops, true) ? $row['test'] : (string) ($ops[0] ?? '');
            $row['test'] = $test;
            $result = $field === null ? [] : $rules->fromInput($table->key, $row, $i + 1);
            if ($result instanceof CollectionRule) {
                $working[] = $result;
            }
            [$linkable, $more] = $field !== null && $field->type === 'relation' ? $this->linkable($schema, $records, $field) : [[], false];
            $editorRules[] = new EditorRule(
                $i + 1,
                $field,
                $test,
                $tests,
                $row['value'],
                $row['record'] === '' ? null : $row['record'],
                $linkable,
                $more,
                $field !== null && $field->type === 'choice' ? self::choices($field) : [],
                $broken[$i] ?? null,
            );
        }

        $resolved = $rules->resolve($table->key, $draft['match'], $working, $sort);
        $query = $records->query($table->key, $resolved->sort?->token(), null, null, [], null, 1)->query;
        $page = $records->page($table->key, new ListQuery($query->sort, null, null, 1, $resolved->set));
        $listings = $this->c->listings();
        $columns = Listings::columns($schema, $table, $schema->fields($table->key), self::PREVIEW_COLUMNS);

        return new CollectionEditor(
            $project,
            $table,
            $collection?->key,
            $draft['name'],
            $draft['match'],
            $editorRules,
            $sorts,
            $sort,
            array_values($fields),
            $page->total,
            $listings->rows($schema, $table, $columns, array_slice($page->records, 0, self::PREVIEW_ROWS)),
            $columns,
            array_values($problems),
            count($editorRules) < CollectionStore::MAX_RULES,
        );
    }

    /**
     * Saves the posted draft as a new collection of $tableKey or over the
     * collection $collectionKey (exactly one is given); returns its key.
     * Every problem is reported at once: the name (`name`), each rule
     * (`rule-<n>`), and too many collections.
     *
     * @param array<mixed> $input
     * @throws ValidationFailed
     */
    public function save(Actor $actor, string $projectKey, ?string $tableKey, ?string $collectionKey, array $input): string
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ManageCollections);
        [$collection, $table] = $this->target($db, $schema, $tableKey, $collectionKey);
        $store = $this->c->collectionStore($db, $schema);
        $rules = $this->c->collectionRules($db, $schema);
        $draft = self::draft($input);

        $problems = Text::nameProblems($draft['name'], 'name', CollectionStore::MAX_NAME);
        $all = $store->all();
        if ($problems === []) {
            $name = strtolower(Text::trim($draft['name']));
            foreach ($all as $other) {
                if ($other->key !== $collection?->key && strtolower($other->name) === $name) {
                    $problems[] = new Problem('name', 'problem.collection.name_taken');
                }
            }
        }
        $stored = [];
        foreach ($draft['rows'] as $i => $row) {
            $result = $rules->fromInput($table->key, $row, $i + 1);
            if ($result instanceof CollectionRule) {
                $stored[] = $result;
            } else {
                $problems = [...$problems, ...$result];
            }
        }
        if ($collection === null && count($all) >= CollectionStore::MAX_COLLECTIONS) {
            $problems[] = new Problem(null, 'problem.collection.too_many', ['max' => CollectionStore::MAX_COLLECTIONS]);
        }
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }
        $records = $this->c->records($project, $db, $schema);
        $offered = array_map(static fn (SortOption $o): string => $o->token, $this->sorts($schema, $records, $table));
        $sort = in_array($draft['sort'], $offered, true) ? $draft['sort'] : null;

        if ($collection === null) {
            $key = $store->create($table->key, $draft['name'], $draft['match'], $stored, $sort, $actor->userKey);
        } else {
            $key = $collection->key;
            $store->update($key, $draft['name'], $draft['match'], $stored, $sort, $actor->userKey);
        }
        $this->audit(AuditAction::COLLECTION_SAVED, $actor, $project, $key, $table->key, count($stored), $draft['match']);

        return $key;
    }

    /** The collection to delete, with its count (0 when its table is missing). */
    public function deleteScreen(Actor $actor, string $projectKey, string $collectionKey): CollectionSummary
    {
        [, $db, $schema] = $this->open($actor, $projectKey, Permission::ManageCollections);
        $collection = $this->collection($db, $schema, $collectionKey);
        $listings = $this->c->listings();
        try {
            $table = $schema->table($collection->tableKey);
            $resolved = $listings->resolve($db, $schema, $collection);

            return new CollectionSummary($collection->key, $table->key, $collection->name, $listings->count($schema, $table, $db, $resolved->set), $resolved->broken);
        } catch (NotFound) {
            return new CollectionSummary($collection->key, $collection->tableKey, $collection->name, 0, 0);
        }
    }

    /** Deletes the collection; its records are untouched. */
    public function delete(Actor $actor, string $projectKey, string $collectionKey): void
    {
        [$project, $db, $schema] = $this->open($actor, $projectKey, Permission::ManageCollections);
        $collection = $this->collection($db, $schema, $collectionKey);
        $this->c->collectionStore($db, $schema)->delete($collection->key, $actor->userKey);
        $this->audit(AuditAction::COLLECTION_DELETED, $actor, $project, $collection->key, $collection->tableKey, count($collection->rules), $collection->match);
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: ProjectRecord, 1: Database, 2: Schema} */
    private function open(Actor $actor, string $projectKey, Permission $permission): array
    {
        $project = $this->c->project($actor, $permission, $projectKey);
        $db = $this->c->opener->open($project);

        return [$project, $db, new Schema($db, $this->c->types)];
    }

    private function collection(Database $db, Schema $schema, string $collectionKey): Collection
    {
        $collection = $this->c->collectionStore($db, $schema)->get($collectionKey);
        if ($collection === null) {
            throw new NotFound('No such collection.');
        }

        return $collection;
    }

    /**
     * The collection (null for a new one) and its table.
     *
     * @return array{0: ?Collection, 1: TableDef}
     */
    private function target(Database $db, Schema $schema, ?string $tableKey, ?string $collectionKey): array
    {
        if ($collectionKey !== null) {
            $collection = $this->collection($db, $schema, $collectionKey);
            if ($tableKey !== null && $tableKey !== $collection->tableKey) {
                throw new NotFound('No such collection in this table.');
            }

            return [$collection, $schema->table($collection->tableKey)];
        }
        if ($tableKey === null) {
            throw new NotFound('No table for a new collection.');
        }

        return [null, $schema->table($tableKey)];
    }

    /**
     * Fields rules may use (section 5.2): those the rule tests cover.
     *
     * @param list<FieldDef> $fields
     * @return array<string, FieldDef> by key, in field order
     */
    private static function usable(CollectionRules $rules, array $fields): array
    {
        $usable = [];
        foreach ($fields as $field) {
            if ($rules->testsFor($field) !== []) {
                $usable[$field->key] = $field;
            }
        }

        return $usable;
    }

    /** @return list<SortOption> the table's sorts, in menu order */
    private function sorts(Schema $schema, Records $records, TableDef $table): array
    {
        $options = [];
        foreach ($records->sorts($table->key) as $sort) {
            $isField = $sort->by !== 'added' && $sort->by !== 'changed';
            $options[] = new SortOption($sort->token(), $isField ? $schema->field($sort->by) : null, $isField ? 'field' : $sort->by, $sort->descending);
        }

        return $options;
    }

    /**
     * Up to Records::PICKER_LIMIT live records of the field's other table,
     * and whether there are more (read once per table per editor).
     *
     * @return array{0: list<LinkItem>, 1: bool}
     */
    private function linkable(Schema $schema, Records $records, FieldDef $field): array
    {
        $relation = $schema->relationOf($field);
        if ($relation === null) {
            return [[], false];
        }
        $other = $relation->otherTable($field->key);
        if (!isset($this->picks[$other])) {
            $items = [];
            foreach ($records->pickList($other, Records::PICKER_LIMIT) as $linked) {
                $items[] = new LinkItem($linked->tableKey, $linked->id, $linked->title);
            }
            $this->picks[$other] = [$items, $records->count($other) > Records::PICKER_LIMIT];
        }

        return $this->picks[$other];
    }

    /** @return list<array{key: string, label: string}> */
    private static function choices(FieldDef $field): array
    {
        $choices = [];
        foreach ($field->options['choices'] ?? [] as $choice) {
            if (is_array($choice) && is_string($choice['key'] ?? null) && is_string($choice['label'] ?? null)) {
                $choices[] = ['key' => $choice['key'], 'label' => $choice['label']];
            }
        }

        return $choices;
    }

    /** @return array{field: string, test: string, value: string, record: string} */
    private static function emptyRow(): array
    {
        return ['field' => '', 'test' => '', 'value' => '', 'record' => ''];
    }

    /** @return array{name: string, match: MatchMode, sort: ?string, rows: list<array{field: string, test: string, value: string, record: string}>} */
    private static function emptyDraft(): array
    {
        return ['name' => '', 'match' => MatchMode::All, 'sort' => null, 'rows' => [self::emptyRow()]];
    }

    /**
     * A stored collection as a draft: each rule as the editor posts it
     * (checkbox `is` 1 or 0 as `ticked` or `not-ticked`, a relation's
     * record id in `record`).
     *
     * @param array<string, FieldDef> $fields
     * @return array{name: string, match: MatchMode, sort: ?string, rows: list<array{field: string, test: string, value: string, record: string}>}
     */
    private static function storedDraft(Collection $collection, array $fields): array
    {
        $rows = [];
        foreach ($collection->rules as $rule) {
            $field = $fields[$rule->field] ?? null;
            $first = $rule->values[0] ?? '';
            $test = $rule->op;
            if ($field !== null && $field->type === 'checkbox') {
                $test = $first === '1' ? 'ticked' : ($first === '0' ? 'not-ticked' : '');
            }
            $isRelation = $field !== null && $field->type === 'relation';
            $rows[] = ['field' => $rule->field, 'test' => $test, 'value' => $isRelation ? '' : $first, 'record' => $isRelation ? $first : ''];
        }

        return ['name' => $collection->name, 'match' => $collection->match, 'sort' => $collection->sort, 'rows' => $rows];
    }

    /**
     * A posted draft, read defensively: strings only (anything else reads
     * as empty), `match` is `any` or else all, at most MAX_RULES rows in
     * posted order.
     *
     * @param array<mixed> $input
     * @return array{name: string, match: MatchMode, sort: ?string, rows: list<array{field: string, test: string, value: string, record: string}>}
     */
    private static function draft(array $input): array
    {
        $sort = $input['sort'] ?? null;
        $rows = [];
        $posted = $input['rules'] ?? [];
        if (is_array($posted)) {
            foreach ($posted as $entry) {
                if (count($rows) >= CollectionStore::MAX_RULES) {
                    break;
                }
                $row = self::emptyRow();
                if (is_array($entry)) {
                    foreach (self::ROW_PARTS as $part) {
                        $row[$part] = is_string($entry[$part] ?? null) ? $entry[$part] : '';
                    }
                }
                $rows[] = $row;
            }
        }

        return [
            'name' => is_string($input['name'] ?? null) ? $input['name'] : '',
            'match' => ($input['match'] ?? null) === 'any' ? MatchMode::Any : MatchMode::All,
            'sort' => is_string($sort) && $sort !== '' ? $sort : null,
            'rows' => $rows,
        ];
    }

    private function audit(string $action, Actor $actor, ProjectRecord $project, string $key, string $tableKey, int $rules, MatchMode $match): void
    {
        $this->c->audit->record($action, $actor->userId, $project->id, 'collection', $key, [
            'table' => $tableKey,
            'rules' => $rules,
            'match' => $match->value,
        ]);
    }
}
