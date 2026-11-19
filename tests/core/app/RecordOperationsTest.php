<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\ConflictRow;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordOperations;
use TiffinDB\Core\App\RecordRef;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Error\Conflict;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Forms\BoundNode;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-70, T-APP-73, T-APP-74, T-APP-75, T-APP-79: record operations. */
#[CoversClass(RecordOperations::class)]
final class RecordOperationsTest extends AppTestCase
{
    private string $project;
    private string $items;
    private string $rooms;
    private string $otherProject;
    private string $otherTable;
    /** @var array<string, FieldDef> by short name */
    private array $f = [];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->ops->schema();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Household', 'Items')->key;
        $this->items = $schema->tables($this->admin, $this->project)[0]->table->key;
        $this->f['name'] = $schema->design($this->admin, $this->project, $this->items)->fields[0]->field;
        $this->f['count'] = $schema->addField($this->admin, $this->project, $this->items, 'Count', 'number', [], false, '');
        $this->f['room'] = $schema->addField($this->admin, $this->project, $this->items, 'Room', 'choice', ['choices' => ['Kitchen', 'Hall']], false, '');
        $this->f['notes'] = $schema->addField($this->admin, $this->project, $this->items, 'Notes', 'long_text', [], false, '');
        $this->rooms = $schema->createTable($this->admin, $this->project, 'Rooms', 'room', true)->key;
        $this->f['located'] = $schema->addRelationField($this->admin, $this->project, $this->items, 'Located in', $this->rooms, Cardinality::Many, Cardinality::Many, 'Holds', false, '');
        $this->ops->projects()->setSharing($this->admin, $this->project, [$this->member->userKey]);
        $this->otherProject = $this->ops->projects()->create($this->admin, 'Example Workshop', 'Tools')->key;
        $this->otherTable = $schema->tables($this->admin, $this->otherProject)[0]->table->key;
    }

    /** @param array<string, string> $text short name => text */
    private function input(array $text): array
    {
        $input = [];
        foreach ($text as $short => $value) {
            $input[$this->f[$short]->key] = $value;
        }

        return $input;
    }

    private function auditCount(): int
    {
        return (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log');
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

    // ------------------------------------------------------------ T-APP-70

    public function testAGrantedMemberAddsFindsAndEditsARecord(): void
    {
        $records = $this->ops->records();
        $blank = $records->blank($this->member, $this->project, $this->items, ListRequest::none());
        self::assertTrue($blank->isNew);
        self::assertNull($blank->id);
        self::assertInstanceOf(BoundForm::class, $blank->form);
        self::assertSame([$this->f['name']->key, $this->f['count']->key, $this->f['room']->key, $this->f['notes']->key], $blank->form->editableKeys());

        $id = $records->create($this->member, $this->project, $this->items, $this->input(['name' => 'Example Lamp', 'count' => '3', 'room' => 'Kitchen', 'notes' => '']));
        $this->fx->addRecord($this->project, $this->rooms, [$this->schemaField($this->rooms)->key => 'Example Study']);

        $listing = $records->listing($this->member, $this->project, $this->items, ListRequest::none());
        self::assertSame(1, $listing->total);
        self::assertSame(1, $listing->tableTotal);
        self::assertSame([1, 1, 1, 1], [$listing->page, $listing->pages, $listing->from, $listing->to]);
        self::assertSame(0, $listing->trashCount);
        self::assertSame([['Items', 1], ['Rooms', 1]], array_map(static fn ($t): array => [$t->table->name, $t->recordCount], $listing->tables));
        self::assertCount(1, $listing->rows);
        $row = $listing->rows[0];
        self::assertSame([$id, 'Example Lamp'], [$row->id, $row->title]);
        self::assertSame(3, $row->cells[$this->f['count']->key]->value);
        self::assertSame('Kitchen', $row->cells[$this->f['room']->key]->value);
        self::assertArrayNotHasKey($this->f['notes']->key, $row->cells, 'Long text is not a list column.');
        self::assertSame([], $row->cells[$this->f['located']->key]->links);
        self::assertTrue($listing->hasFields);
        self::assertTrue($listing->hasFilterable);
        self::assertFalse($listing->canChangeSchema, 'A Member cannot change the schema.');
        self::assertTrue($records->listing($this->admin, $this->project, $this->items, ListRequest::none())->canChangeSchema);

        $screen = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertSame([$id, false, false, 'Example Lamp'], [$screen->id, $screen->isNew, $screen->trashed, $screen->title]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', (string) $screen->version);
        self::assertSame('Example Member', $screen->createdByName);
        self::assertInstanceOf(BoundForm::class, $screen->form);
        self::assertSame('Example Lamp', self::boundFields($screen->form->nodes)[$this->f['name']->key]->inputText);

        $records->update($this->member, $this->project, $this->items, (string) $id, $this->input(['name' => 'Example Desk Lamp', 'count' => '4']), (string) $screen->version);

        $after = $records->listing($this->member, $this->project, $this->items, ListRequest::none())->rows[0];
        self::assertSame('Example Desk Lamp', $after->title);
        self::assertSame(4, $after->cells[$this->f['count']->key]->value);
        self::assertSame('Kitchen', $after->cells[$this->f['room']->key]->value, 'Fields not posted keep their values.');
    }

    private function schemaField(string $tableKey): FieldDef
    {
        return $this->ops->schema()->design($this->admin, $this->project, $tableKey)->fields[0]->field;
    }

    // ------------------------------------------------------------ T-APP-73

    public function testStaleVersionsConflictsAndRedisplay(): void
    {
        $records = $this->ops->records();
        $id = $records->create($this->member, $this->project, $this->items, $this->input(['name' => 'Example Lamp', 'count' => '3', 'room' => 'Kitchen']));
        $old = (string) $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->version;
        $records->update($this->admin, $this->project, $this->items, (string) $id, $this->input(['count' => '5']), $old);

        try {
            $records->update($this->member, $this->project, $this->items, (string) $id, $this->input(['name' => 'Example Torch']), $old);
            self::fail('Conflict was expected.');
        } catch (Conflict $conflict) {
            $current = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->version;
            self::assertSame($current, $conflict->currentVersion);
        }
        self::assertSame('Example Lamp', $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->title);

        $posted = $this->input(['name' => 'Example Torch', 'count' => 'many', 'room' => 'Kitchen', 'notes' => '']);
        $view = $records->conflict($this->member, $this->project, $this->items, (string) $id, $posted, ListRequest::none());
        self::assertSame($current, $view->screen->version);
        self::assertSame('Example Lamp', $view->screen->title);
        self::assertSame(
            [[$this->f['name']->key, 'Name', 'Example Lamp', 'Example Torch'], [$this->f['count']->key, 'Count', 5, 'many']],
            array_map(static fn (ConflictRow $r): array => [$r->field->key, $r->label, $r->saved, $r->yours], $view->rows),
        );
        $same = $records->conflict($this->member, $this->project, $this->items, (string) $id, $this->input(['count' => '5', 'room' => 'Kitchen']), ListRequest::none());
        self::assertSame([], $same->rows, 'Equal canonical values are not listed.');
        $unknown = $records->conflict($this->member, $this->project, $this->items, (string) $id, ['zzzzzzzzzzzz' => 'x', $this->f['located']->key => 'y'], ListRequest::none());
        self::assertSame([], $unknown->rows, 'Unknown and non-editable fields are not listed.');

        $typed = $this->input(['name' => '', 'count' => 'lots']);
        try {
            $records->create($this->member, $this->project, $this->items, $typed);
            self::fail('ValidationFailed was expected.');
        } catch (ValidationFailed $failed) {
            $problems = $failed->problems;
        }
        $screen = $records->redisplay($this->member, $this->project, $this->items, null, $typed, $problems, ListRequest::none());
        self::assertTrue($screen->isNew);
        self::assertInstanceOf(BoundForm::class, $screen->form);
        $count = self::boundFields($screen->form->nodes)[$this->f['count']->key];
        self::assertSame(['lots', true], [$count->inputText, $count->submitted]);
        self::assertSame(['problem.number.invalid'], array_map(static fn ($p): string => $p->key, $count->problems));

        $edit = $records->redisplay($this->member, $this->project, $this->items, (string) $id, $typed, $problems, ListRequest::none());
        self::assertFalse($edit->isNew);
        self::assertSame($id, $edit->id);
        self::assertInstanceOf(BoundForm::class, $edit->form);
        $fields = self::boundFields($edit->form->nodes);
        self::assertSame('lots', $fields[$this->f['count']->key]->inputText);
        self::assertSame('Kitchen', $fields[$this->f['room']->key]->inputText, 'Fields not typed show the saved value.');
    }

    // ------------------------------------------------------------ T-APP-75

    public function testTrashAndRestoreReturnTheTitleAndRepeatHarmlessly(): void
    {
        $records = $this->ops->records();
        $id = $records->create($this->member, $this->project, $this->items, $this->input(['name' => 'Example Lamp']));

        $ref = $records->trash($this->member, $this->project, $this->items, (string) $id);
        self::assertEquals(new RecordRef($id, 'Example Lamp'), $ref);
        $trashed = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertTrue($trashed->trashed);
        self::assertNull($trashed->form);
        self::assertNull($trashed->position);
        self::assertSame(1, $records->listing($this->member, $this->project, $this->items, ListRequest::none())->trashCount);
        $version = $trashed->version;

        $this->fx->clock->advance(60);
        self::assertEquals(new RecordRef($id, 'Example Lamp'), $records->trash($this->member, $this->project, $this->items, (string) $id));
        self::assertSame($version, $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->version);

        self::assertEquals(new RecordRef($id, 'Example Lamp'), $records->restore($this->member, $this->project, $this->items, (string) $id));
        $live = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertFalse($live->trashed);
        self::assertSame(1, $records->listing($this->member, $this->project, $this->items, ListRequest::none())->total);

        $this->fx->clock->advance(60);
        $records->restore($this->member, $this->project, $this->items, (string) $id);
        self::assertSame($live->version, $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->version);
        self::assertSame(0, $records->listing($this->member, $this->project, $this->items, ListRequest::none())->trashCount);
    }

    // ------------------------------------------------------------ T-APP-79

    public function testNoRecordOperationWritesTheAuditLog(): void
    {
        $records = $this->ops->records();
        $before = $this->auditCount();
        $room = $records->create($this->member, $this->project, $this->rooms, [$this->schemaField($this->rooms)->key => 'Example Study']);
        $id = $records->create($this->member, $this->project, $this->items, $this->input(['name' => 'Example Lamp']));
        $version = (string) $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none())->version;
        $records->update($this->member, $this->project, $this->items, (string) $id, $this->input(['name' => 'Example Torch']), $version);
        $records->setLinks($this->member, $this->project, $this->items, (string) $id, $this->f['located']->key, [$room]);
        $records->trash($this->member, $this->project, $this->items, (string) $id);
        $records->restore($this->member, $this->project, $this->items, (string) $id);
        $records->search($this->member, $this->project, 'Example');

        self::assertSame($before, $this->auditCount());
        $screen = $records->screen($this->member, $this->project, $this->items, (string) $id, ListRequest::none());
        self::assertInstanceOf(BoundForm::class, $screen->form);
        self::assertSame(['Example Study'], array_map(static fn ($l): ?string => $l->title, self::boundFields($screen->form->nodes)[$this->f['located']->key]->links));
    }

    // ------------------------------------------------------------ T-APP-74

    /** @return iterable<string, array{0: string}> */
    public static function methods(): iterable
    {
        foreach (['listing', 'filterOptions', 'blank', 'screen', 'redisplay', 'create', 'update', 'conflict', 'trash', 'restore', 'setLinks', 'search'] as $method) {
            yield $method => [$method];
        }
    }

    /** @return \Closure(Actor, string, string, string): mixed calls the method as the actor on a project, table, and record id */
    private function call(string $method): \Closure
    {
        $records = $this->ops->records();
        $none = ListRequest::none();
        $name = $this->f['name']->key;

        return match ($method) {
            'listing' => static fn (Actor $a, string $p, string $t, string $r) => $records->listing($a, $p, $t, $none),
            'filterOptions' => fn (Actor $a, string $p, string $t, string $r) => $records->filterOptions($a, $p, $t, $this->f['room']->key),
            'blank' => static fn (Actor $a, string $p, string $t, string $r) => $records->blank($a, $p, $t, $none),
            'screen' => static fn (Actor $a, string $p, string $t, string $r) => $records->screen($a, $p, $t, $r, $none),
            'redisplay' => static fn (Actor $a, string $p, string $t, string $r) => $records->redisplay($a, $p, $t, $r, [$name => 'Example Typed'], [], $none),
            'create' => static fn (Actor $a, string $p, string $t, string $r) => $records->create($a, $p, $t, [$name => 'Example New']),
            'update' => function (Actor $a, string $p, string $t, string $r) use ($records, $name): void {
                $records->update($a, $p, $t, $r, [$name => 'Example Changed ' . $a->userKey], $this->currentVersion($r));
            },
            'conflict' => static fn (Actor $a, string $p, string $t, string $r) => $records->conflict($a, $p, $t, $r, [$name => 'Example Other'], $none),
            'trash' => static fn (Actor $a, string $p, string $t, string $r) => $records->trash($a, $p, $t, $r),
            'restore' => static fn (Actor $a, string $p, string $t, string $r) => $records->restore($a, $p, $t, $r),
            'setLinks' => fn (Actor $a, string $p, string $t, string $r) => $records->setLinks($a, $p, $t, $r, $this->f['located']->key, []),
            'search' => static fn (Actor $a, string $p, string $t, string $r) => $records->search($a, $p, 'Example'),
        };
    }

    /** The version of a record of the items table as an Admin sees it, or a dummy for an id that is not a record. */
    private function currentVersion(string $recordId): string
    {
        try {
            return (string) $this->ops->records()->screen($this->admin, $this->project, $this->items, $recordId, ListRequest::none())->version;
        } catch (NotFound) {
            return '0000000000000000';
        }
    }

    #[DataProvider('methods')]
    public function testAuthorizationAndKeysForEveryMethod(string $method): void
    {
        $call = $this->call($method);
        $id = (string) $this->ops->records()->create($this->admin, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);

        $call($this->admin, $this->project, $this->items, $id);
        $call($this->member, $this->project, $this->items, $id);
        $this->assertThrows(NotFound::class, fn () => $call($this->member2, $this->project, $this->items, $id));
        $this->assertThrows(NotFound::class, fn () => $call($this->member, 'zzzzzzzzzzzz', $this->items, $id));
        $this->assertThrows(NotFound::class, fn () => $call($this->member, $this->otherProject, $this->otherTable, $id));
        $this->assertThrows(NotFound::class, fn () => $call($this->admin, 'Not a key', $this->items, $id));
        if ($method === 'search') {
            return;
        }

        foreach (['Not a key', 'zzzzzzzzzzzz', $this->otherTable, ''] as $table) {
            $this->assertThrows(NotFound::class, fn () => $call($this->admin, $this->project, $table, $id));
        }
        if (in_array($method, ['listing', 'filterOptions', 'blank', 'create'], true)) {
            return;
        }
        foreach (['0', '-1', '1e3', 'x', '', ' 1', '1 ', '01', '12345678901234567890', '9223372036854775808', '999'] as $bad) {
            $this->assertThrows(NotFound::class, fn () => $call($this->admin, $this->project, $this->items, $bad));
        }
    }

    public function testAMemberWithoutAGrantLearnsNothingFromRecordsThatExist(): void
    {
        $records = $this->ops->records();
        $id = (string) $records->create($this->admin, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);
        $this->assertThrows(NotFound::class, fn () => $records->screen($this->member2, $this->project, $this->items, $id, ListRequest::none()));
        $this->assertThrows(NotFound::class, fn () => $records->screen($this->member2, $this->project, $this->items, '999', ListRequest::none()));
        $this->ops->projects()->setSharing($this->admin, $this->project, []);
        $this->assertThrows(NotFound::class, fn () => $records->screen($this->member, $this->project, $this->items, $id, ListRequest::none()));
    }

    public function testRecordsInTheTrashCannotBeEditedOrLinked(): void
    {
        $records = $this->ops->records();
        $id = (string) $records->create($this->admin, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);
        $version = (string) $records->screen($this->admin, $this->project, $this->items, $id, ListRequest::none())->version;
        $records->trash($this->admin, $this->project, $this->items, $id);
        $name = $this->f['name']->key;

        $this->assertThrows(NotFound::class, fn () => $records->update($this->admin, $this->project, $this->items, $id, [$name => 'Example Torch'], $version));
        $this->assertThrows(NotFound::class, fn () => $records->update($this->admin, $this->project, $this->items, $id, [$name => ''], $version));
        $this->assertThrows(NotFound::class, fn () => $records->conflict($this->admin, $this->project, $this->items, $id, [], ListRequest::none()));
        $this->assertThrows(NotFound::class, fn () => $records->redisplay($this->admin, $this->project, $this->items, $id, [], [], ListRequest::none()));
        $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->admin, $this->project, $this->items, $id, $this->f['located']->key, []));
        $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->admin, $this->project, $this->items, $id, $name, []));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $records->create($this->admin, $this->project, $this->items, [$name => ['array']]));
    }

    public function testARelationFieldOfAnotherTableIsNotFound(): void
    {
        $records = $this->ops->records();
        $item = $records->create($this->admin, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);
        $room = $records->create($this->admin, $this->project, $this->rooms, [$this->schemaField($this->rooms)->key => 'Example Study']);
        self::assertSame($item, $room, 'Both tables hold a live record with the same id, so only the table check can refuse.');

        $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->admin, $this->project, $this->rooms, (string) $room, $this->f['located']->key, []));
        $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->member, $this->project, $this->rooms, (string) $room, $this->f['located']->key, [$room]));
        $records->setLinks($this->admin, $this->project, $this->items, (string) $item, $this->f['located']->key, [$room]);
    }

    public function testTrashedOrAbsentRecordsAreNotFoundBeforeInputIsChecked(): void
    {
        $records = $this->ops->records();
        $id = (string) $records->create($this->admin, $this->project, $this->items, [$this->f['name']->key => 'Example Lamp']);
        $version = (string) $records->screen($this->admin, $this->project, $this->items, $id, ListRequest::none())->version;
        $records->trash($this->admin, $this->project, $this->items, $id);
        $invalid = $this->input(['name' => '', 'count' => 'lots']);

        foreach ([$id, '999'] as $target) {
            $this->assertThrows(NotFound::class, fn () => $records->update($this->admin, $this->project, $this->items, $target, $invalid, $version));
            $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->admin, $this->project, $this->items, $target, $this->f['located']->key, [0]));
            $this->assertThrows(NotFound::class, fn () => $records->setLinks($this->admin, $this->project, $this->items, $target, $this->f['located']->key, [999]));
        }
        $records->restore($this->admin, $this->project, $this->items, $id);
        $this->assertThrows(ValidationFailed::class, fn () => $records->update($this->admin, $this->project, $this->items, $id, $invalid, $version));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $records->setLinks($this->admin, $this->project, $this->items, $id, $this->f['located']->key, [0]));
    }

    public function testABadRecordIdIsNotFoundBeforeTheInputIsChecked(): void
    {
        $records = $this->ops->records();
        $bad = [$this->f['name']->key => ['not text']];
        foreach (['0', 'x', '12345678901234567890'] as $id) {
            $this->assertThrows(NotFound::class, fn () => $records->conflict($this->admin, $this->project, $this->items, $id, $bad, ListRequest::none()));
            $this->assertThrows(NotFound::class, fn () => $records->redisplay($this->admin, $this->project, $this->items, $id, $bad, [], ListRequest::none()));
            $this->assertThrows(NotFound::class, fn () => $records->redisplay($this->admin, $this->project, $this->items, $id, [], ['not a problem'], ListRequest::none()));
            $this->assertThrows(NotFound::class, fn () => $records->update($this->admin, $this->project, $this->items, $id, $bad, '0000000000000000'));
        }
        $this->assertThrows(\InvalidArgumentException::class, fn () => $records->redisplay($this->admin, $this->project, $this->items, null, $bad, [], ListRequest::none()));
    }
}
