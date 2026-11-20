<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\CollectionOperations;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\System\AuditAction;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-130 to T-APP-137 (M4 spec section 10.4): summaries, the
 * collection view, the editor's actions, save, delete, keys of another
 * project, preview counts, and hostile drafts.
 */
#[CoversClass(CollectionOperations::class)]
final class CollectionOperationsTest extends M4AppTestCase
{
    private CollectionOperations $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->ops->collections();
    }

    /** @param list<array<string, string>> $rules */
    private function save(string $name, array $rules, string $match = 'all', string $sort = '', ?string $key = null): string
    {
        return $this->c->save($this->member, $this->p, $key === null ? $this->items : null, $key, ['name' => $name, 'match' => $match, 'sort' => $sort, 'rules' => $rules]);
    }

    private function more(string $value): array
    {
        return ['field' => $this->f['count'], 'test' => 'more', 'value' => $value];
    }

    private function choiceKey(string $label): string
    {
        foreach ($this->ops->schema()->field($this->admin, $this->p, $this->items, $this->f['kind'])->field->options['choices'] as $choice) {
            if ($choice['label'] === $label) {
                return $choice['key'];
            }
        }
        self::fail('No choice ' . $label);
    }

    // ------------------------------------------------------------ T-APP-130

    public function testSummariesCountMatchesAndBrokenRules(): void
    {
        $many = $this->save('Example many', [$this->more('5')]);
        $lamps = $this->save('Example lamps', [['field' => $this->f['kind'], 'test' => 'is', 'value' => $this->choiceKey('Lamp')], $this->more('1')], 'all');
        $this->ops->schema()->deleteField($this->admin, $this->p, $this->items, $this->f['kind']);
        // A row whose table is gone (only with foreign keys off, as another tool might leave it) is skipped.
        $db = $this->fx->projectDb($this->p);
        $db->run('PRAGMA foreign_keys = OFF');
        $db->run(
            'INSERT INTO "_tiffin_collections" ("key", "table_key", "name", "match_mode", "rules", "position", "created_at", "updated_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['k2xq7vn4mz3t', 'b7xk2mq4nz3p', 'Example orphan', 'all', '[]', 9, '2026-03-02T10:00:00Z', '2026-03-02T10:00:00Z'],
        );
        $summaries = $this->c->summaries($this->member, $this->p);
        self::assertSame(
            [[$many, 'Example many', 2, 0], [$lamps, 'Example lamps', 3, 1]],
            array_map(static fn ($s): array => [$s->key, $s->name, $s->count, $s->broken], $summaries),
        );
        self::assertSame($this->items, $summaries[0]->tableKey);
        self::assertSame($summaries, $this->ops->records()->listing($this->member, $this->p, $this->items, ListRequest::none())->collections, 'The sidebar gets them.');
        self::assertSame($summaries, $this->ops->records()->search($this->member, $this->p, 'lamp')->collections);
        self::assertSame(0, $this->c->deleteScreen($this->admin, $this->p, 'k2xq7vn4mz3t')->count);

        // Counts follow changes made through the same operations (the per-request cache is invalidated).
        $this->fx->addRecord($this->p, $this->items, [$this->f['name'] => 'Example heater', $this->f['count'] => '40']);
        self::assertSame(3, $this->c->summaries($this->member, $this->p)[0]->count);
        $this->ops->records()->trash($this->admin, $this->p, $this->items, (string) $this->ids['Example chair']);
        self::assertSame(2, $this->c->summaries($this->member, $this->p)[0]->count);
    }

    // ------------------------------------------------------------ T-APP-131

    public function testTheCollectionViewUsesRulesSearchSortAndPages(): void
    {
        $key = $this->save('Example many', [$this->more('5')], 'all', $this->f['name'] . '.desc');
        $view = fn (array $query) => $this->c->listing($this->member, $this->p, $key, ListRequest::fromQuery($query));

        $all = $view([]);
        self::assertSame(['Example kettle', 'Example chair'], array_map(static fn ($r): ?string => $r->title, $all->rows));
        self::assertSame([2, 3, $this->f['name'] . '.desc'], [$all->total, $all->tableTotal, $all->sort]);
        self::assertSame([$key, 'Example many', 0, true], [$all->collection?->key, $all->collection?->name, $all->collection?->broken, $all->collection?->canManage]);
        self::assertSame(['collection' => $key], $all->request->toQuery(), 'Record links keep the collection; its own sort is the default.');
        self::assertSame(['Example chair', 'Example kettle'], array_map(static fn ($r): ?string => $r->title, $view(['sort' => $this->f['name'] . '.asc'])->rows));
        self::assertSame(['Example kettle'], array_map(static fn ($r): ?string => $r->title, $view(['q' => 'kettle'])->rows));
        $filtered = $view(['filter' => $this->f['done'], 'op' => 'is', 'v' => '1']);
        self::assertSame([2, null], [$filtered->total, $filtered->filter], 'The request\'s filter is ignored.');
        self::assertSame(1, $view(['page' => '9'])->page);
        self::assertSame($this->f['name'] . '.desc', $view(['sort' => 'nonsense'])->sort);
        $this->assertThrows(NotFound::class, fn () => $this->c->listing($this->member, $this->p, 'k2xq7vn4mz3t', ListRequest::none()));
        $this->assertThrows(NotFound::class, fn () => $this->c->listing($this->member2, $this->p, $key, ListRequest::none()));
    }

    // ------------------------------------------------------------ T-APP-132

    public function testTheEditorActions(): void
    {
        $new = $this->c->editor($this->member, $this->p, $this->items, null, null, 'show');
        self::assertSame([null, '', MatchMode::All, 1, true, 3], [$new->key, $new->name, $new->match, count($new->rules), $new->canAddRule, $new->matchCount]);
        self::assertNull($new->rules[0]->field);
        self::assertNotContains($this->f['photos'], array_map(static fn ($f): string => $f->key, $new->fields), 'Attachment fields cannot be used.');
        self::assertContains($this->f['done'], array_map(static fn ($f): string => $f->key, $new->fields));

        $draft = ['name' => 'Draft', 'match' => 'any', 'rules' => [$this->more('5'), ['field' => $this->f['count'], 'test' => 'contains', 'value' => '1'], ['field' => 'k2xq7vn4mz3t', 'test' => 'is']]];
        $preview = $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'preview');
        self::assertSame(['more', 'is', ''], array_map(static fn ($r): string => $r->test, $preview->rules), 'An unfitting test resets to the field\'s first; an unknown field is dropped.');
        self::assertNull($preview->rules[2]->field);
        self::assertSame([1, 2, 3], array_map(static fn ($r): int => $r->index, $preview->rules));
        self::assertSame(MatchMode::Any, $preview->match);
        self::assertSame(2, $preview->matchCount, 'From the rows that resolve: count more 5 (the second row has value 1, which `is` 1 matches none).');
        self::assertSame(['Example chair', 'Example kettle'], array_map(static fn ($r): ?string => $r->title, $preview->preview));
        self::assertLessThanOrEqual(4, count($preview->previewColumns));

        $added = $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'add');
        self::assertCount(4, $added->rules);
        $ten = $draft;
        $ten['rules'] = array_fill(0, 10, $this->more('1'));
        $full = $this->c->editor($this->member, $this->p, $this->items, null, $ten, 'add');
        self::assertSame([10, false], [count($full->rules), $full->canAddRule]);
        $removed = $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'remove-2');
        self::assertSame(['more', ''], array_map(static fn ($r): string => $r->test, $removed->rules));
        self::assertCount(3, $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'remove-9')->rules);
        self::assertCount(3, $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'remove-0')->rules);

        // A relation rule offers records; a choice rule offers choices; a stored rule shows how it was saved.
        $key = $this->save('Stored', [
            ['field' => $this->f['room'], 'test' => 'links-to', 'record' => (string) $this->ids['Example hall']],
            ['field' => $this->f['done'], 'test' => 'not-ticked'],
            ['field' => $this->f['kind'], 'test' => 'is', 'value' => $this->choiceKey('Tool')],
        ]);
        $stored = $this->c->editor($this->member, $this->p, null, $key, null, 'show');
        self::assertSame(['links-to', 'not-ticked', 'is'], array_map(static fn ($r): string => $r->test, $stored->rules));
        self::assertSame((string) $this->ids['Example hall'], $stored->rules[0]->record);
        self::assertSame(['Example attic', 'Example cellar', 'Example hall'], array_map(static fn ($l): ?string => $l->title, $stored->rules[0]->records));
        self::assertSame(['Tool', 'Lamp'], array_column($stored->rules[2]->choices, 'label'));
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->rooms, [$this->ids['Example hall']]);
        self::assertSame('collection.broken.record', $this->c->editor($this->member, $this->p, null, $key, null, 'show')->rules[0]->brokenReason);
        $problem = new Problem('name', 'problem.collection.name_taken');
        self::assertSame([$problem], $this->c->editor($this->member, $this->p, null, $key, ['name' => 'x'], 'show', [$problem])->problems);
        $this->assertThrows(NotFound::class, fn () => $this->c->editor($this->member, $this->p, $this->rooms, $key, null, 'show'));
        $this->assertThrows(NotFound::class, fn () => $this->c->editor($this->member, $this->p, null, null, null, 'show'));
    }

    // ------------------------------------------------------------ T-APP-133

    public function testSaveNewAndExistingWithEveryProblemAtOnce(): void
    {
        $key = $this->save('Example many', [$this->more('5')], 'any', $this->f['name'] . '.asc');
        self::assertSame(['collection', $key, ['table' => $this->items, 'rules' => 1, 'match' => 'any']], [$this->lastAudit(AuditAction::COLLECTION_SAVED)->targetKind, $this->lastAudit(AuditAction::COLLECTION_SAVED)->targetKey, $this->lastAudit(AuditAction::COLLECTION_SAVED)->detail]);
        $editor = $this->c->editor($this->member, $this->p, null, $key, null, 'show');
        self::assertSame(['Example many', MatchMode::Any, $this->f['name'] . '.asc'], [$editor->name, $editor->match, $editor->sort]);

        self::assertSame($key, $this->save('Example lots', [$this->more('10'), $this->more('2')], 'all', 'bogus', $key));
        $editor = $this->c->editor($this->member, $this->p, null, $key, null, 'show');
        self::assertSame(['Example lots', MatchMode::All, null, 2], [$editor->name, $editor->match, $editor->sort, count($editor->rules)]);
        self::assertSame(['table' => $this->items, 'rules' => 2, 'match' => 'all'], $this->lastAudit(AuditAction::COLLECTION_SAVED)->detail);

        $problems = self::problems(fn () => $this->save('', [['field' => '', 'test' => '', 'value' => ''], $this->more('many'), $this->more('1')]));
        self::assertSame(['name', 'rule-1', 'rule-2'], array_column($problems, 0));
        self::assertSame(['problem.collection.field', 'problem.collection.number'], array_slice(array_column($problems, 1), 1));
        self::assertSame([['name', 'problem.collection.name_taken', []]], self::problems(fn () => $this->save('EXAMPLE LOTS', [])));
        self::assertNotSame($key, $this->save('Example lots 2', []));
        self::assertCount(2, $this->c->summaries($this->member, $this->p));
        $this->assertThrows(NotFound::class, fn () => $this->c->save($this->member2, $this->p, $this->items, null, ['name' => 'Example ungranted']));
    }

    // ------------------------------------------------------------ T-APP-134

    public function testDelete(): void
    {
        $key = $this->save('Example many', [$this->more('5')]);
        $screen = $this->c->deleteScreen($this->member, $this->p, $key);
        self::assertSame([$key, 'Example many', 2, 0], [$screen->key, $screen->name, $screen->count, $screen->broken]);
        $this->c->delete($this->member, $this->p, $key);
        self::assertSame([], $this->c->summaries($this->member, $this->p));
        $entry = $this->lastAudit(AuditAction::COLLECTION_DELETED);
        self::assertSame([$this->member->userId, 'collection', $key, ['table' => $this->items, 'rules' => 1, 'match' => 'all']], [$entry->actorUserId, $entry->targetKind, $entry->targetKey, $entry->detail]);
        $this->assertThrows(NotFound::class, fn () => $this->c->delete($this->member, $this->p, $key));
        self::assertSame(3, $this->ops->records()->listing($this->member, $this->p, $this->items, ListRequest::none())->total, 'Records are untouched.');
    }

    // ------------------------------------------------------------ T-APP-135

    public function testCollectionsOfAnotherProjectAreNotFound(): void
    {
        $key = $this->save('Example many', [$this->more('5')]);
        $other = $this->ops->projects()->create($this->admin, 'Example Shed', 'Tools')->key;
        $this->ops->projects()->setSharing($this->admin, $other, [$this->member->userKey]);
        foreach ([
            fn () => $this->c->listing($this->member, $other, $key, ListRequest::none()),
            fn () => $this->c->editor($this->member, $other, null, $key, null, 'show'),
            fn () => $this->c->save($this->member, $other, null, $key, ['name' => 'x']),
            fn () => $this->c->deleteScreen($this->member, $other, $key),
            fn () => $this->c->delete($this->member, $other, $key),
        ] as $work) {
            $this->assertThrows(NotFound::class, $work);
        }
        self::assertCount(1, $this->c->summaries($this->member, $this->p));
    }

    // ------------------------------------------------------------ T-APP-136

    public function testPreviewCountsEqualListingTotals(): void
    {
        foreach ([
            ['all', [$this->more('5')]],
            ['any', [$this->more('10'), ['field' => $this->f['name'], 'test' => 'contains', 'value' => 'lamp']]],
            ['all', [['field' => $this->f['room'], 'test' => 'empty']]],
            ['all', []],
        ] as $i => [$match, $rules]) {
            $draft = ['name' => 'Example ' . $i, 'match' => $match, 'rules' => $rules];
            $preview = $this->c->editor($this->member, $this->p, $this->items, null, $draft, 'preview')->matchCount;
            $key = $this->c->save($this->member, $this->p, $this->items, null, $draft);
            self::assertSame($preview, $this->c->listing($this->member, $this->p, $key, ListRequest::none())->total, (string) $i);
        }
    }

    // ------------------------------------------------------------ T-APP-137

    public function testHostileDraftsNeverThrowAnythingElse(): void
    {
        $drafts = [
            ['name' => ['x'], 'match' => ['any'], 'sort' => ['x'], 'rules' => 'nope'],
            ['rules' => [['field' => ['x'], 'test' => ['y'], 'value' => ['z'], 'record' => [[1]]]]],
            ['rules' => array_fill(0, 1000, $this->more('1'))],
            ['rules' => [[[[[['deep']]]]]]],
            ['rules' => [1, 2.5, true, null, 'text']],
            ['name' => "\xFF\xFE", 'rules' => [['field' => $this->f['name'], 'test' => 'contains', 'value' => "\xFF"]]],
            ['name' => str_repeat('n', 5000), 'rules' => [['field' => $this->f['room'], 'test' => 'links-to', 'record' => '1 OR 1=1']]],
            ['rules' => [['field' => $this->f['done'], 'test' => 'ticked', 'value' => '2']], 'match' => "any' OR 1"],
        ];
        foreach ($drafts as $i => $draft) {
            foreach (['show', 'preview', 'add', 'remove-1', 'remove-11', 'bogus'] as $action) {
                $editor = $this->c->editor($this->member, $this->p, $this->items, null, $draft, $action);
                self::assertLessThanOrEqual(10, count($editor->rules), $i . ' ' . $action);
            }
            try {
                $this->c->save($this->member, $this->p, $this->items, null, $draft);
            } catch (ValidationFailed) {
            }
            $this->addToAssertionCount(1);
        }
    }
}
