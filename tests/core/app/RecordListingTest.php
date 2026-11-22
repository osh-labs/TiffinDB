<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordListing;
use TiffinDB\Core\App\RecordOperations;
use TiffinDB\Core\App\SearchResultGroup;
use TiffinDB\Core\App\SearchResultHit;
use TiffinDB\Core\App\SortOption;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Forms\BoundHeading;
use TiffinDB\Core\Forms\BoundNode;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-71, T-APP-72, T-APP-76: lists, the record screen, and search through the operations. */
#[CoversClass(RecordOperations::class)]
#[CoversClass(ListRequest::class)]
final class RecordListingTest extends AppTestCase
{
    private string $project;
    private string $items;
    private string $rooms;
    /** @var array<string, FieldDef> by short name */
    private array $f = [];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->ops->schema();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Household', 'Items')->key;
        $this->items = $schema->tables($this->admin, $this->project)[0]->table->key;
        $this->f['name'] = $schema->design($this->admin, $this->project, $this->items)->fields[0]->field;
        $this->f['room'] = $schema->addField($this->admin, $this->project, $this->items, 'Room', 'choice', ['choices' => ['Kitchen', 'Hall']], false, '');
        $this->f['notes'] = $schema->addField($this->admin, $this->project, $this->items, 'Notes', 'long_text', [], false, '');
        $this->f['count'] = $schema->addField($this->admin, $this->project, $this->items, 'Count', 'number', [], false, '');
        $this->rooms = $schema->createTable($this->admin, $this->project, 'Rooms', 'room', true)->key;
        $this->f['room_name'] = $schema->design($this->admin, $this->project, $this->rooms)->fields[0]->field;
        $this->f['located'] = $schema->addRelationField($this->admin, $this->project, $this->items, 'Located in', $this->rooms, Cardinality::Many, Cardinality::Many, 'Holds', false, '');
        $this->ops->projects()->setSharing($this->admin, $this->project, [$this->member->userKey]);
    }

    /** @param array<string, string> $text short name => text */
    private function add(array $text, string $table = ''): int
    {
        $input = [];
        foreach ($text as $short => $value) {
            $input[$this->f[$short]->key] = $value;
        }

        return $this->fx->addRecord($this->project, $table === '' ? $this->items : $table, $input);
    }

    /** @param array<string, mixed> $query */
    private function listing(array $query): RecordListing
    {
        return $this->ops->records()->listing($this->member, $this->project, $this->items, ListRequest::fromQuery($query));
    }

    /**
     * @param list<BoundNode> $nodes
     * @return array<string, BoundField>
     */
    private static function boundFields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if ($node instanceof BoundField) {
                $fields[$node->field->key] = $node;
            } elseif ($node instanceof BoundSection) {
                $fields += self::boundFields($node->children);
            }
        }

        return $fields;
    }

    // ------------------------------------------------------------ ListRequest (section 4)

    public function testListRequestReadsAndWritesTheQueryString(): void
    {
        $request = ListRequest::fromQuery(['q' => 'lamp', 'sort' => ['x'], 'filter' => '', 'op' => 'is', 'v' => 'Hall', 'v2' => 7, 'page' => '3', 'other' => 'y']);
        self::assertSame(['lamp', null, null, 'is', 'Hall', null, 3], [$request->q, $request->sort, $request->filter, $request->op, $request->v, $request->v2, $request->page]);
        self::assertSame(['q' => 'lamp', 'op' => 'is', 'v' => 'Hall', 'page' => '3'], $request->toQuery());
        foreach (['0', '-1', '1000001', '1e3', ' 2', '2 ', 'x', ''] as $page) {
            self::assertSame(1, ListRequest::fromQuery(['page' => $page])->page, $page);
        }
        self::assertSame(1000000, ListRequest::fromQuery(['page' => '1000000'])->page);
        self::assertSame([], ListRequest::none()->toQuery());
        self::assertSame(['q' => 'lamp', 'op' => 'is', 'v' => 'Hall'], $request->withoutPage()->toQuery());
        self::assertSame(['q' => 'lamp', 'op' => 'is', 'v' => 'Hall', 'page' => '9'], $request->withPage(9)->toQuery());
        $full = ListRequest::fromQuery(['q' => 'a', 'sort' => 's', 'filter' => 'f', 'op' => 'between', 'v' => '1', 'v2' => '2', 'collection' => 'k2xq7vn4mz3t', 'page' => '2']);
        self::assertSame(ListRequest::PARAMS, array_keys($full->toQuery()));
        // M4 (P162): the collection a record screen follows stays through the other changes.
        self::assertSame(['q' => 'a', 'sort' => 's', 'collection' => 'k2xq7vn4mz3t'], $full->withoutFilter()->toQuery());
        self::assertSame(['sort' => 's', 'collection' => 'k2xq7vn4mz3t'], $full->withoutSearchAndFilter()->toQuery());
        self::assertSame('k2xq7vn4mz3t', $full->withPage(4)->collection);
        self::assertNull(ListRequest::fromQuery(['collection' => ['x']])->collection);
        $this->assertThrows(\InvalidArgumentException::class, fn () => $full->withPage(0));
    }

    // ------------------------------------------------------------ T-APP-71

    public function testListingSortsFiltersSearchesAndPages(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->add(['name' => sprintf('Example Item %02d', $i), 'room' => $i % 5 === 0 ? 'Hall' : 'Kitchen', 'count' => (string) $i]);
        }

        $default = $this->listing([]);
        self::assertSame([55, 55, 1, 2, 1, 50], [$default->total, $default->tableTotal, $default->page, $default->pages, $default->from, $default->to]);
        self::assertSame($this->f['name']->key . '.asc', $default->sort);
        self::assertSame('Example Item 01', $default->rows[0]->title);
        self::assertSame([], $default->request->toQuery(), 'The default sort is left out.');

        $tokens = array_map(static fn (SortOption $o): string => $o->token, $default->sortOptions);
        self::assertSame([
            $this->f['name']->key . '.asc', $this->f['name']->key . '.desc',
            $this->f['room']->key . '.asc', $this->f['room']->key . '.desc',
            $this->f['notes']->key . '.asc', $this->f['notes']->key . '.desc',
            $this->f['count']->key . '.asc', $this->f['count']->key . '.desc',
            $this->f['located']->key . '.asc', $this->f['located']->key . '.desc',
            'added.desc', 'added.asc', 'changed.desc',
        ], $tokens);
        self::assertSame(['field', 'added', 'changed'], array_values(array_unique(array_map(static fn (SortOption $o): string => $o->kind, $default->sortOptions))));
        self::assertNull($default->sortOptions[10]->field);
        self::assertSame($this->f['count']->key, $default->sortOptions[7]->field?->key);
        foreach ($tokens as $token) {
            $listing = $this->listing(['sort' => $token]);
            self::assertSame($token, $listing->sort);
            self::assertSame($token === $this->f['name']->key . '.asc' ? [] : ['sort' => $token], $listing->request->toQuery());
        }
        self::assertSame('Example Item 55', $this->listing(['sort' => $this->f['count']->key . '.desc'])->rows[0]->title);
        self::assertSame('Example Item 55', $this->listing(['sort' => 'added.desc'])->rows[0]->title);
        $bogus = $this->listing(['sort' => 'bogus.asc']);
        self::assertSame($this->f['name']->key . '.asc', $bogus->sort);
        self::assertSame([], $bogus->request->toQuery());

        $hall = $this->listing(['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall', 'v2' => 'ignored']);
        self::assertSame([11, 55, 1], [$hall->total, $hall->tableTotal, $hall->pages]);
        self::assertNull($hall->filterProblem);
        self::assertSame([$this->f['room']->key, 'is', ['Hall'], null], [$hall->filter?->field->key, $hall->filter?->operator, $hall->filter?->operands, $hall->filter?->linkTitle]);
        self::assertSame(['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall'], $hall->request->toQuery());

        foreach ([
            [['filter' => $this->f['room']->key, 'op' => 'between', 'v' => 'Hall'], 'problem.filter.invalid'],
            [['filter' => $this->f['notes']->key, 'op' => 'is', 'v' => 'x'], 'problem.filter.invalid'],
            [['filter' => 'zzzzzzzzzzzz', 'op' => 'is', 'v' => 'x'], 'problem.filter.invalid'],
            [['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Attic'], 'problem.filter.value'],
            [['filter' => $this->f['room']->key, 'op' => 'is'], 'problem.filter.value'],
            [['filter' => $this->f['room']->key], 'problem.filter.invalid'],
        ] as [$query, $key]) {
            $refused = $this->listing($query + ['q' => 'Item']);
            self::assertSame($key, $refused->filterProblem?->key);
            self::assertNull($refused->filter);
            self::assertSame(55, $refused->total, 'The list shows unfiltered.');
            self::assertSame(['q' => 'Item'], $refused->request->toQuery(), 'The invalid filter is dropped.');
        }

        $search = $this->listing(['q' => '  item 1 ']);
        self::assertSame(['item 1', 10, null], [$search->search, $search->total, $search->searchProblem]);
        self::assertSame(['q' => 'item 1'], $search->request->toQuery());
        $long = $this->listing(['q' => str_repeat('a', 201), 'filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall']);
        self::assertSame(['problem.text.too_long', ['max' => 200]], [$long->searchProblem?->key, $long->searchProblem?->params]);
        self::assertNull($long->search);
        self::assertSame(11, $long->total, 'The list shows unsearched, still filtered.');
        self::assertSame(['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall'], $long->request->toQuery());

        $last = $this->listing(['page' => '9']);
        self::assertSame([2, 2, 51, 55], [$last->page, $last->pages, $last->from, $last->to]);
        self::assertCount(5, $last->rows);
        self::assertSame(['page' => '2'], $last->request->toQuery());

        $empty = $this->listing(['q' => 'nothing matches this']);
        self::assertSame([0, 55, 1, 1, 0, 0, []], [$empty->total, $empty->tableTotal, $empty->page, $empty->pages, $empty->from, $empty->to, $empty->rows]);
    }

    public function testListingFiltersByLinkedRecordAndShowsLinkCells(): void
    {
        $rooms = [];
        foreach (['Example Study', 'Example Den', 'Example Loft', 'Example Porch'] as $name) {
            $rooms[] = $this->add(['room_name' => $name], $this->rooms);
        }
        $lamp = $this->add(['name' => 'Example Lamp']);
        $this->add(['name' => 'Example Rug']);
        $this->ops->records()->setLinks($this->admin, $this->project, $this->items, (string) $lamp, $this->f['located']->key, $rooms);

        $listing = $this->listing(['filter' => $this->f['located']->key, 'op' => 'links-to', 'v' => (string) $rooms[1]]);
        self::assertSame(1, $listing->total);
        self::assertSame('Example Den', $listing->filter?->linkTitle);
        $cell = $listing->rows[0]->cells[$this->f['located']->key];
        self::assertSame(['Example Study', 'Example Den', 'Example Loft'], array_map(static fn ($l): ?string => $l->title, $cell->links));
        self::assertSame(1, $cell->moreLinks);
        self::assertNull($cell->value);

        $options = $this->ops->records()->filterOptions($this->member, $this->project, $this->items, $this->f['located']->key);
        self::assertSame([$this->f['room']->key, $this->f['located']->key], array_map(static fn (FieldDef $f): string => $f->key, $options->fields));
        self::assertSame($this->f['located']->key, $options->field?->key);
        self::assertSame(['Example Den', 'Example Loft', 'Example Porch', 'Example Study'], array_map(static fn ($l): ?string => $l->title, $options->records));
        self::assertFalse($options->more);
        self::assertCount(2, $options->tables);
        $choice = $this->ops->records()->filterOptions($this->member, $this->project, $this->items, $this->f['room']->key);
        self::assertSame([['Kitchen', 'Hall'], []], [$choice->choices, $choice->records]);
        $none = $this->ops->records()->filterOptions($this->member, $this->project, $this->items, $this->f['notes']->key);
        self::assertNull($none->field);
        self::assertNull($this->ops->records()->filterOptions($this->member, $this->project, $this->items, null)->field);
    }

    public function testColumnsAreTheTitleThenSixMoreSkippingLongText(): void
    {
        $schema = $this->ops->schema();
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $schema->addField($this->admin, $this->project, $this->items, 'Extra ' . $name, 'text', [], false, '');
        }
        $listing = $this->listing([]);
        self::assertCount(7, $listing->columns);
        self::assertSame($this->f['name']->key, $listing->columns[0]->key);
        self::assertSame(['Name', 'Room', 'Count', 'Located in', 'Extra A', 'Extra B', 'Extra C'], array_map(static fn (FieldDef $f): string => $f->name, $listing->columns));

        $schema->updateTable($this->admin, $this->project, $this->items, 'Items', 'item', null);
        $untitled = $this->listing([]);
        self::assertSame(['Name', 'Room', 'Count', 'Located in', 'Extra A', 'Extra B'], array_map(static fn (FieldDef $f): string => $f->name, $untitled->columns));
        $this->add(['name' => 'Example Lamp']);
        self::assertNull($this->listing([])->rows[0]->title, 'Without a title field rows have no title.');
        self::assertSame('added.asc', $this->listing([])->sort);
    }

    public function testATableWithoutFieldsAndTheEmptyStates(): void
    {
        $bare = $this->ops->schema()->createTable($this->admin, $this->project, 'Notes', 'note', false)->key;
        $listing = $this->ops->records()->listing($this->member, $this->project, $bare, ListRequest::none());
        self::assertSame([false, false, false, [], 0], [$listing->hasFields, $listing->hasFilterable, $listing->canChangeSchema, $listing->columns, $listing->total]);
        self::assertSame(['added.desc', 'added.asc', 'changed.desc'], array_map(static fn (SortOption $o): string => $o->token, $listing->sortOptions));
    }

    // ------------------------------------------------------------ T-APP-72

    public function testTheScreenBindsTheStoredOrTheGeneratedForm(): void
    {
        $this->captureLog();
        $id = $this->add(['name' => 'Example Lamp', 'room' => 'Hall']);
        $records = $this->ops->records();
        $db = $this->fx->projectDb($this->project);
        $name = $this->f['name']->key;

        $generated = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertInstanceOf(BoundForm::class, $generated->form);
        self::assertSame('Name', self::boundFields($generated->form->nodes)[$name]->label);

        $db->write(fn (Database $d) => $d->run(
            'UPDATE "_tiffin_forms" SET "generated" = 0, "html" = ? WHERE "table_key" = ?',
            ['<div data-tiffin-form="1"><h2>Example heading</h2><div data-tiffin-field="' . $name . '" data-tiffin-label="What it is"/></div>', $this->items],
        ));
        $stored = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertInstanceOf(BoundForm::class, $stored->form);
        self::assertInstanceOf(BoundHeading::class, $stored->form->nodes[0]);
        self::assertSame(['What it is', 'Example Lamp'], [$stored->form->nodes[1]->label ?? null, $stored->form->nodes[1]->inputText ?? null]);
        self::assertSame([$name], $stored->form->editableKeys());

        $db->write(fn (Database $d) => $d->run(
            'UPDATE "_tiffin_forms" SET "html" = ? WHERE "table_key" = ?',
            ['<div data-tiffin-form="1"><script>alert(1)</script></div>', $this->items],
        ));
        $rejected = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertInstanceOf(BoundForm::class, $rejected->form);
        self::assertSame($generated->form->editableKeys(), $rejected->form->editableKeys());
        self::assertStringContainsString('stored form not used', $this->errorLog());
        self::assertStringNotContainsString('alert', $this->errorLog());

        $db->write(fn (Database $d) => $d->run('DELETE FROM "_tiffin_forms" WHERE "table_key" = ?', [$this->items]));
        $missing = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertInstanceOf(BoundForm::class, $missing->form);
        self::assertSame($generated->form->editableKeys(), $missing->form->editableKeys());
        self::assertSame('Hall', self::boundFields($missing->form->nodes)[$this->f['room']->key]->inputText);
    }

    public function testThePositionFollowsTheListRequest(): void
    {
        $a = $this->add(['name' => 'Example Apple', 'room' => 'Hall']);
        $b = $this->add(['name' => 'Example Berry', 'room' => 'Kitchen']);
        $c = $this->add(['name' => 'Example Cherry', 'room' => 'Hall']);
        $records = $this->ops->records();

        $middle = $records->screen($this->member, $this->project, $this->items, (string) $b, ListRequest::none())->position;
        self::assertNotNull($middle);
        self::assertSame([2, 3, $a, 'Example Apple', $c, 'Example Cherry'], [$middle->index, $middle->total, $middle->previousId, $middle->previousTitle, $middle->nextId, $middle->nextTitle]);

        $desc = $records->screen($this->member, $this->project, $this->items, (string) $a, ListRequest::fromQuery(['sort' => $this->f['name']->key . '.desc']));
        self::assertNotNull($desc->position);
        self::assertSame([3, 3, $b, 'Example Berry', null, null], [$desc->position->index, $desc->position->total, $desc->position->previousId, $desc->position->previousTitle, $desc->position->nextId, $desc->position->nextTitle]);
        self::assertSame(['sort' => $this->f['name']->key . '.desc'], $desc->request->toQuery());

        $filtered = $records->screen($this->member, $this->project, $this->items, (string) $c, ListRequest::fromQuery(['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall', 'q' => 'cherry']));
        self::assertNotNull($filtered->position);
        self::assertSame([1, 1, null, null], [$filtered->position->index, $filtered->position->total, $filtered->position->previousId, $filtered->position->nextId]);

        $outside = $records->screen($this->member, $this->project, $this->items, (string) $b, ListRequest::fromQuery(['filter' => $this->f['room']->key, 'op' => 'is', 'v' => 'Hall']));
        self::assertNull($outside->position, 'A record outside the list has no position.');
        self::assertInstanceOf(BoundForm::class, $outside->form);
    }

    public function testTrashedRecordsAndDisplayNames(): void
    {
        $id = $this->ops->records()->create($this->member, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);
        $screen = $this->ops->records()->screen($this->admin, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertSame(['Example Member', 'Example Member', CoreFixture::START, CoreFixture::START], [$screen->createdByName, $screen->updatedByName, $screen->createdAt, $screen->updatedAt]);

        $this->fx->clock->advance(30);
        $this->ops->records()->trash($this->admin, $this->project, $this->items, (string) $id);
        $trashed = $this->ops->records()->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertSame([true, '2026-03-02T10:00:30Z', null, null, 'Example Admin'], [$trashed->trashed, $trashed->trashedAt, $trashed->form, $trashed->position, $trashed->updatedByName]);
        self::assertSame('Example Lamp', $trashed->title);

        $db = $this->fx->projectDb($this->project);
        $other = $this->fx->insertRecord($this->project, $this->items, [$this->f['name']->key => 'Example Rug']);
        $db->run('UPDATE ' . Identifier::table($this->items) . ' SET "_created_by" = ? WHERE "id" = ?', ['zzzzzzzzzzzz', $other]);
        $unknown = $this->ops->records()->screen($this->member, $this->project, $this->items, (string) $other, ListRequest::none());
        self::assertSame([null, null], [$unknown->createdByName, $unknown->updatedByName], 'An unknown or missing person gives null.');
    }

    // ------------------------------------------------------------ T-APP-76

    public function testSearchCoversTheGrantedProjectOnly(): void
    {
        $study = $this->add(['room_name' => 'Example Study'], $this->rooms);
        $lamp = $this->add(['name' => 'Example Lamp', 'notes' => 'Bought for the study desk']);
        $this->add(['name' => 'Study Chair']);
        $this->add(['name' => 'Example Rug']);
        $this->ops->records()->setLinks($this->admin, $this->project, $this->items, (string) $lamp, $this->f['located']->key, [$study]);
        $hidden = $this->ops->projects()->create($this->admin, 'Example Shed', 'Things')->key;
        $hiddenTable = $this->ops->schema()->tables($this->admin, $hidden)[0]->table->key;
        $hiddenName = $this->ops->schema()->design($this->admin, $hidden, $hiddenTable)->fields[0]->field->key;
        $this->fx->addRecord($hidden, $hiddenTable, [$hiddenName => 'Study Lamp']);

        $results = $this->ops->records()->search($this->member, $this->project, '  STUDY ');
        self::assertSame(['STUDY', 3], [$results->search, $results->total]);
        self::assertNull($results->problem);
        self::assertSame($this->project, $results->project->key);
        self::assertSame([[$this->items, 2], [$this->rooms, 1]], array_map(static fn (SearchResultGroup $g): array => [$g->table->key, $g->total], $results->groups));
        self::assertSame(
            [['Example Lamp', $this->f['notes']->key, 'Bought for the study desk'], ['Study Chair', null, null]],
            array_map(static fn (SearchResultHit $h): array => [$h->title, $h->field?->key, $h->text], $results->groups[0]->hits),
        );
        self::assertSame([[$study, 'Example Study', null]], array_map(static fn (SearchResultHit $h): array => [$h->id, $h->title, $h->field], $results->groups[1]->hits));
        self::assertCount(2, $results->tables);
        self::assertSame(0, $results->trashCount);

        $this->assertThrows(NotFound::class, fn () => $this->ops->records()->search($this->member, $hidden, 'Study'));
        self::assertSame(1, $this->ops->records()->search($this->admin, $hidden, 'Study')->total);

        $long = $this->ops->records()->search($this->member, $this->project, str_repeat('s', 201));
        self::assertSame(['problem.text.too_long', null, 0, []], [$long->problem?->key, $long->search, $long->total, $long->groups]);
        $control = $this->ops->records()->search($this->member, $this->project, "study\x07");
        self::assertSame('problem.text.control', $control->problem?->key);
        $blank = $this->ops->records()->search($this->member, $this->project, '   ');
        self::assertSame([null, null, 0, []], [$blank->problem, $blank->search, $blank->total, $blank->groups]);
        $percent = $this->ops->records()->search($this->member, $this->project, '%');
        self::assertSame(0, $percent->total, 'Wildcards match literally.');
    }
}
