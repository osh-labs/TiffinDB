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
use TiffinDB\Core\Forms\BindData;
use TiffinDB\Core\Forms\Block;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Forms\BoundNode;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Core\Forms\FileItem;
use TiffinDB\Core\Forms\FormBinder;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\ListSql;
use TiffinDB\Core\Query\NormalisedQuery;
use TiffinDB\Core\Query\Picker;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Query\ResolvedRules;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Records of a project (M2 spec section 7.2, M4 spec section 10.1):
 * lists, the record screen, create, update, conflicts, trash and restore,
 * links and the relation picker, and search.
 *
 * Authorization lives here: every method starts with Context::project for
 * its permission, so a Member without a grant on the project gets NotFound
 * from every method, as does an unknown or inactive project. A table key
 * that is not a table of the project, a field key that is not a field of
 * that table, a record id that is not canonical digits (ids arrive as
 * strings from addresses), and an absent record are NotFound too. The
 * permission is checked before any key is looked at.
 *
 * No method writes an audit entry: record-level edits, links included, are
 * not logged (ADR 0012 decision 4).
 */
final class RecordOperations
{
    /** Columns of a list besides the title field (ADR 0013 decision 9.1). */
    public const MAX_EXTRA_COLUMNS = Listings::MAX_EXTRA_COLUMNS;
    /** Field types shown on a form but never edited there (their controls are separate actions). */
    private const NOT_EDITABLE = ['relation', 'attachment'];
    /** `from` of a new record added from a picker: `<field key>-<record id>` (M4 spec section 10.1). */
    private const FROM = '/^([a-z2-7]{12})-([1-9][0-9]{0,18})\z/';

    public function __construct(private readonly Context $c)
    {
    }

    // ------------------------------------------------------------------ lists

    public function listing(Actor $actor, string $projectKey, string $tableKey, ListRequest $request): RecordListing
    {
        [$project, $db, $schema, $records, $trash] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $listings = $this->c->listings();

        return $listings->listing($actor, $project, $db, $schema, $records, $trash, $table, $listings->normalise($records, $table, $request));
    }

    /** What the filter page offers for the table and, when $fieldKey names a filterable field, for that field. */
    public function filterOptions(Actor $actor, string $projectKey, string $tableKey, ?string $fieldKey): FilterOptions
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $fields = Listings::filterable($schema->fields($table->key));
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

        return new FilterOptions($table, $fields, $chosen, $choices, $linkable, $more, $this->c->listings()->tables($schema, $records));
    }

    // ------------------------------------------------------------------ screens

    /**
     * The screen for a new record: the effective form with empty values.
     * With $from (`<field key>-<record id>`, from a picker) the screen
     * carries its LinkOrigin; a `from` that linkOrigin refuses is NotFound.
     */
    public function blank(Actor $actor, string $projectKey, string $tableKey, ListRequest $request, ?string $from = null): RecordScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $origin = $from === null ? null : $this->origin($schema, $records, $table, $from);

        return $this->newScreen($project, $db, $schema, $records, $table, [], [], $request, $origin);
    }

    /**
     * A record's screen; for a record in the trash, `trashed` is true and
     * there is no form. With `collection` in the request naming a
     * collection of this table, the position and Previous/Next follow it
     * (P162); an unknown collection is dropped silently.
     */
    public function screen(Actor $actor, string $projectKey, string $tableKey, string $recordId, ListRequest $request): RecordScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::ViewRecords);
        $table = $schema->table($tableKey);
        $record = self::found($records->get($table->key, self::recordId($recordId)));
        $canEdit = $this->c->authorizer->can($actor, Permission::EditRecords, $project);

        return $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, [], [], $canEdit);
    }

    /**
     * The screen showing what the person typed and the problems found
     * (the 422 page): a new record when $recordId is null (with its
     * LinkOrigin when $from is given), else the live record.
     *
     * @param array<mixed> $input field key => text
     * @param list<Problem> $problems from ValidationFailed
     */
    public function redisplay(Actor $actor, string $projectKey, string $tableKey, ?string $recordId, array $input, array $problems, ListRequest $request, ?string $from = null): RecordScreen
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
            $origin = $from === null ? null : $this->origin($schema, $records, $table, $from);

            return $this->newScreen($project, $db, $schema, $records, $table, $submitted, $byField, $request, $origin);
        }
        $record = self::live($records->get($table->key, $id));

        return $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, $submitted, $byField, true);
    }

    /**
     * Where a new record of $tableKey is being added from (P154): $from is
     * `<field key>-<record id>`, naming a relation field whose other table
     * is $tableKey and a live record of the field's table. Anything else
     * is NotFound.
     */
    public function linkOrigin(Actor $actor, string $projectKey, string $tableKey, string $from): LinkOrigin
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);

        return $this->origin($schema, $records, $schema->table($tableKey), $from);
    }

    /**
     * The relation picker of a live record (ADR 0019 decision 9): the
     * field must be a relation field of the table. Search and paging as
     * Picker::page (50 per page, pages clamp).
     */
    public function picker(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, ?string $q, int $page): PickerScreen
    {
        [$project, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $field = self::relationField($schema, $table, $fieldKey);
        $record = self::live($records->get($table->key, $id));
        $relation = $schema->relationOf($field);
        if ($relation === null) {
            throw new NotFound('No such relation field in this table.');
        }
        $other = $schema->table($relation->otherTable($field->key));
        $candidates = (new Picker($db, $schema, $this->c->types))->page($field->key, $record->id, $q, $page);

        return new PickerScreen(
            $project,
            $table,
            new RecordRef($record->id, $this->c->listings()->title($schema, $table, $record)),
            $field,
            $other,
            $candidates,
            $this->c->authorizer->can($actor, Permission::EditRecords, $project),
        );
    }

    // ------------------------------------------------------------------ writes

    /**
     * Creates a record from text typed in a form; returns its id. The
     * table's effective form is bound first: required fields it does not
     * show are not enforced (a stored edited form may leave one out), and
     * required relation and attachment fields never are on create (P157).
     *
     * With $from (P154): the origin is checked first, then the record is
     * created and linked from the origin's field in one transaction; a link
     * problem rolls the create back and is reported at the field `link`.
     *
     * @param array<mixed> $input field key => text
     * @throws ValidationFailed
     */
    public function create(Actor $actor, string $projectKey, string $tableKey, array $input, ?string $from = null): int
    {
        [, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $origin = $from === null ? null : $this->origin($schema, $records, $table, $from);
        $values = self::input($input);
        // ADR 0017 decision 4 (P118): only the required fields the effective form shows are required here.
        $shown = $this->bind(null, $db, $schema, $records, $table, null, [], [], true)->editableKeys();
        $required = [];
        foreach ($schema->fields($table->key) as $field) {
            if ($field->required && in_array($field->key, $shown, true)) {
                $required[] = $field->key;
            }
        }
        if ($origin === null) {
            return $records->create($table->key, $values, InputMode::Form, $actor->userKey, $required);
        }

        return $db->write(static function () use ($records, $table, $values, $actor, $required, $origin): int {
            $joined = $records->within();
            $id = $joined->create($table->key, $values, InputMode::Form, $actor->userKey, $required);
            try {
                $joined->addLink($origin->fieldKey, (int) $origin->recordId, $id, $actor->userKey);
            } catch (ValidationFailed $failed) {
                throw new ValidationFailed(array_map(static fn (Problem $p): Problem => new Problem('link', $p->key, $p->params), $failed->problems));
            }

            return $id;
        });
    }

    /**
     * Changes the given fields of a live record. Only the keys passed are
     * read (the web layer passes BoundForm::editableKeys()). The required
     * relation and attachment fields the effective form shows must hold a
     * live link or a file (P157): their problems join the value problems
     * in one ValidationFailed, and nothing is written.
     *
     * @param array<mixed> $input field key => text
     * @throws ValidationFailed
     * @throws \TiffinDB\Core\Error\Conflict when $version is not the record's current version
     */
    public function update(Actor $actor, string $projectKey, string $tableKey, string $recordId, array $input, string $version): void
    {
        [, $db, $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $values = self::input($input);
        self::live($records->get($table->key, $id));
        $shown = [];
        foreach (self::boundFields($this->bind(null, $db, $schema, $records, $table, null, [], [], false)->nodes) as $bound) {
            if ($bound->field->type === 'relation' || $bound->field->type === 'attachment') {
                $shown[] = $bound->field->key;
            }
        }
        $db->write(static function () use ($records, $table, $id, $values, $version, $actor, $shown): void {
            $joined = $records->within();
            $linkProblems = $shown === [] ? [] : $joined->requiredLinkProblems($table->key, $id, $shown);
            try {
                $joined->update($table->key, $id, $values, InputMode::Form, $version, $actor->userKey);
            } catch (ValidationFailed $failed) {
                throw $linkProblems === [] ? $failed : new ValidationFailed([...$failed->problems, ...$linkProblems]);
            }
            if ($linkProblems !== []) {
                throw new ValidationFailed($linkProblems);
            }
        });
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
        $screen = $this->recordScreen($project, $db, $schema, $records, $table, $record, $request, [], [], true);
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
        $ref = new RecordRef($record->id, $this->c->listings()->title($schema, $table, $record));
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

        return new RecordRef($record->id, $this->c->listings()->title($schema, $table, $record));
    }

    /**
     * Makes $targetIds exactly the record's live links in a relation field
     * of the table (core only in M2).
     *
     * @param list<int> $targetIds
     * @throws ValidationFailed
     */
    public function setLinks(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, array $targetIds): void
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $field = self::relationField($schema, $table, $fieldKey);
        self::live($records->get($table->key, $id));
        $records->setLinks($field->key, $id, $targetIds, $actor->userKey);
    }

    /**
     * Makes the live record link $targetId through a relation field of the
     * table (ADR 0019 decision 9): idempotent, no version token; a field
     * allowing one link replaces its live link. Returns the target with its
     * title.
     *
     * @throws ValidationFailed problem.link.* from Records::addLink (field = $fieldKey)
     */
    public function link(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, string $targetId): RecordRef
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $target = self::recordId($targetId);
        $field = self::relationField($schema, $table, $fieldKey);
        $records->addLink($field->key, $id, $target, $actor->userKey);

        return new RecordRef($target, $this->targetTitle($schema, $records, $field, $target));
    }

    /**
     * Makes the live record not link $targetId through a relation field of
     * the table; a target that is not linked is no error. Returns the
     * target with its title, or null when it has none or is absent.
     */
    public function unlink(Actor $actor, string $projectKey, string $tableKey, string $recordId, string $fieldKey, string $targetId): RecordRef
    {
        [, , $schema, $records] = $this->open($actor, $projectKey, Permission::EditRecords);
        $table = $schema->table($tableKey);
        $id = self::recordId($recordId);
        $target = self::recordId($targetId);
        $field = self::relationField($schema, $table, $fieldKey);
        $records->removeLink($field->key, $id, $target, $actor->userKey);

        return new RecordRef($target, $this->targetTitle($schema, $records, $field, $target));
    }

    // ------------------------------------------------------------------ search

    /** Searches every table of the project; with a problem or empty text there are no groups. */
    public function search(Actor $actor, string $projectKey, string $q): SearchResults
    {
        [$project, $db, $schema, $records, $trash] = $this->open($actor, $projectKey, Permission::ViewRecords);
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
        $listings = $this->c->listings();

        return new SearchResults(
            $project,
            $search,
            $problem,
            $total,
            $groups,
            $listings->tables($schema, $records),
            Listings::trashCount($schema, $trash),
            $this->c->authorizer->can($actor, Permission::ImportExport, $project),
            $listings->summaries($project, $db, $schema),
        );
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

    /** A relation field of the table; NotFound for an unknown key, a field of another table, or another type. */
    private static function relationField(Schema $schema, TableDef $table, string $fieldKey): FieldDef
    {
        $field = $schema->field($fieldKey);
        if ($field->tableKey !== $table->key || $field->type !== 'relation') {
            throw new NotFound('No such relation field in this table.');
        }

        return $field;
    }

    /** The LinkOrigin of a new record of $table added from $from (P154); NotFound for anything that does not fit. */
    private function origin(Schema $schema, Records $records, TableDef $table, string $from): LinkOrigin
    {
        if (preg_match(self::FROM, $from, $match) !== 1) {
            throw new NotFound('No such origin.');
        }
        $field = $schema->field($match[1]);
        $relation = $schema->relationOf($field);
        if ($relation === null || $relation->otherTable($field->key) !== $table->key) {
            throw new NotFound('No such origin.');
        }
        $originTable = $schema->table($field->tableKey);
        $record = self::live($records->get($originTable->key, self::recordId($match[2])));

        return new LinkOrigin(
            $originTable->key,
            (string) $record->id,
            $field->key,
            $this->c->listings()->title($schema, $originTable, $record),
            $table->itemName,
            $relation->maxFor($field->key) === Cardinality::One,
        );
    }

    private function targetTitle(Schema $schema, Records $records, FieldDef $field, int $target): ?string
    {
        $relation = $schema->relationOf($field);
        if ($relation === null) {
            return null;
        }

        return $records->titles($relation->otherTable($field->key), [$target])[$target] ?? null;
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

    /**
     * The list query a record screen follows: the request's collection
     * when it names a collection of this table whose rules resolve (P162),
     * else the M2 list query (an unknown collection is dropped silently).
     *
     * @return array{0: NormalisedQuery, 1: ?string, 2: ?ResolvedRules}
     */
    private function viewQuery(Database $db, Schema $schema, Records $records, TableDef $table, ListRequest $request): array
    {
        $listings = $this->c->listings();
        if ($request->collection !== null) {
            $collection = $this->c->collectionStore($db, $schema)->get($request->collection);
            if ($collection !== null && $collection->tableKey === $table->key) {
                try {
                    $resolved = $listings->resolve($db, $schema, $collection);

                    return [$listings->collectionQuery($records, $table, $resolved, $request), $collection->key, $resolved];
                } catch (NotFound) {
                    // The collection's table is gone: drop it like an unknown key.
                }
            }
        }

        return [$listings->normalise($records, $table, $request), null, null];
    }

    private function isEditable(FieldDef $field): bool
    {
        return $field->hasColumn
            && !in_array($field->type, self::NOT_EDITABLE, true)
            && $this->c->types->get($field->type)->isEditable();
    }

    /**
     * @param array<string, string> $submitted
     * @param array<string, list<Problem>> $problems
     */
    private function newScreen(
        ProjectRecord $project,
        Database $db,
        Schema $schema,
        Records $records,
        TableDef $table,
        array $submitted,
        array $problems,
        ListRequest $request,
        ?LinkOrigin $origin,
    ): RecordScreen {
        $form = $this->bind($project, $db, $schema, $records, $table, null, $submitted, $problems, true);
        [$normalised, $collection, $resolved] = $this->viewQuery($db, $schema, $records, $table, $request);
        $state = $this->c->listings()->request($records, $table, $normalised, 1, $collection, $resolved);

        return new RecordScreen($project, $table, null, true, false, null, null, null, $form, null, null, null, null, null, $state, true, $origin);
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
        bool $canEdit,
    ): RecordScreen {
        [$normalised, $collection, $resolved] = $this->viewQuery($db, $schema, $records, $table, $request);
        $trashed = $record->trashedAt !== null;
        $form = null;
        $position = null;
        if (!$trashed) {
            $form = $this->bind($project, $db, $schema, $records, $table, $record, $submitted, $problems, false);
            $position = $this->position($records, $table, $normalised->query, $record->id);
        }
        $listings = $this->c->listings();

        return new RecordScreen(
            $project,
            $table,
            $record->id,
            false,
            $trashed,
            $record->trashedAt,
            $listings->title($schema, $table, $record),
            $record->version,
            $form,
            $position,
            $record->createdAt,
            $this->displayName($record->createdBy),
            $record->updatedAt,
            $this->displayName($record->updatedBy),
            $listings->request($records, $table, $normalised, $normalised->query->page, $collection, $resolved),
            $canEdit,
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
     * The effective form bound with the record's values, its live links
     * (with LinkDetail on many-link fields, P158), its files with ids, and
     * the form images that may be shown (P173; none without $project,
     * which only the binds that read the form's slots pass).
     *
     * @param array<string, string> $submitted
     * @param array<string, list<Problem>> $problems
     */
    private function bind(
        ?ProjectRecord $project,
        Database $db,
        Schema $schema,
        Records $records,
        TableDef $table,
        ?RecordData $record,
        array $submitted,
        array $problems,
        bool $isNew,
    ): BoundForm {
        $document = $this->c->forms($db)->effective($schema, $table->key);
        $links = [];
        $files = [];
        if ($record !== null) {
            foreach ($record->links as $fieldKey => $linked) {
                $field = $schema->field($fieldKey);
                $relation = $schema->relationOf($field);
                $details = [];
                if ($relation !== null && $relation->maxFor($field->key) === Cardinality::Many && $linked !== []) {
                    // Only the record's live links are passed, so no detail of a trashed record is read.
                    $details = $records->linkDetails($relation->otherTable($field->key), array_map(static fn ($item): int => $item->id, $linked));
                }
                $links[$fieldKey] = [];
                foreach ($linked as $item) {
                    $detail = $details[$item->id] ?? null;
                    $links[$fieldKey][] = new LinkItem($item->tableKey, $item->id, $item->title, $detail?->field, $detail?->value);
                }
            }
            foreach ($record->attachments as $fieldKey => $attached) {
                $files[$fieldKey] = [];
                foreach ($attached as $file) {
                    $files[$fieldKey][] = new FileItem($file->id, $file->originalName, $file->mediaType, $file->size);
                }
            }
        }
        $servable = $project === null ? [] : $this->servableImages($project, $db, $schema, $document);

        return (new FormBinder())->bind(
            $document,
            $table->key,
            $schema->fields($table->key),
            $this->c->types,
            new BindData($record === null ? [] : $record->values, $submitted, $problems, $links, $files, $isNew, $servable),
        );
    }

    /**
     * The attachment ids of the form's images that may be shown (M4 spec
     * section 9, P173): the row exists, its type is one of the four image
     * types, and a live record links it through an attachment field.
     *
     * @return list<int>
     */
    private function servableImages(ProjectRecord $project, Database $db, Schema $schema, FormDocument $document): array
    {
        $ids = self::imageIds($document->blocks);
        if ($ids === []) {
            return [];
        }
        $attachments = $this->c->attachments($project, $db, $schema);
        $servable = [];
        foreach ($ids as $id) {
            $row = $attachments->get($id);
            if ($row !== null && MediaType::isInline($row->mediaType) && $attachments->isServable($id)) {
                $servable[] = $id;
            }
        }

        return $servable;
    }

    /**
     * @param list<Block> $blocks
     * @return list<int> distinct, in document order
     */
    private static function imageIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if ($block instanceof Image) {
                $ids[$block->attachmentId] = $block->attachmentId;
            } elseif ($block instanceof Section) {
                foreach (self::imageIds($block->children) as $id) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    private function displayName(?string $userKey): ?string
    {
        return $userKey === null ? null : $this->c->users->byKey($userKey)?->displayName;
    }

    /**
     * @param list<BoundNode> $nodes
     * @return list<BoundField>
     */
    private static function boundFields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if ($node instanceof BoundField) {
                $fields[] = $node;
            } elseif ($node instanceof BoundSection) {
                $fields = [...$fields, ...self::boundFields($node->children)];
            }
        }

        return $fields;
    }

    /**
     * @param list<BoundNode> $nodes
     * @return array<string, string> field key => label shown on the form
     */
    private static function labels(array $nodes): array
    {
        $labels = [];
        foreach (self::boundFields($nodes) as $field) {
            $labels[$field->field->key] = $field->label;
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
