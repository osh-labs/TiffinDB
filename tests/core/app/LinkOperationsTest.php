<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\LinkOrigin;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordOperations;
use TiffinDB\Core\App\RecordScreen;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundImage;
use TiffinDB\Core\Forms\BoundNode;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-100 to T-APP-106 (M4 spec section 10.1): the relation picker,
 * link and unlink, the link origin of a new record, create with `from`,
 * required relation and attachment fields on update, the record screen's
 * link details, files, form images and canEdit, and a record screen
 * following a collection.
 */
#[CoversClass(RecordOperations::class)]
final class LinkOperationsTest extends M4AppTestCase
{
    // ------------------------------------------------------------ T-APP-100

    public function testThePickerShowsItsFactsAndRefusesWhatDoesNotFit(): void
    {
        $records = $this->ops->records();
        $lamp = (string) $this->ids['Example lamp'];
        $screen = $records->picker($this->member, $this->p, $this->items, $lamp, $this->f['room'], null, 1);
        self::assertSame([$this->ids['Example lamp'], 'Example lamp'], [$screen->record->id, $screen->record->title]);
        self::assertSame([$this->items, $this->f['room'], $this->rooms], [$screen->table->key, $screen->field->key, $screen->other->key]);
        self::assertTrue($screen->page->onlyOne);
        self::assertTrue($screen->canAdd);
        self::assertSame(['Example attic', 'Example cellar', 'Example hall'], array_map(static fn ($c): ?string => $c->title, $screen->page->candidates));
        self::assertFalse($records->picker($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], 'attic', 1)->page->onlyOne);
        self::assertCount(1, $records->picker($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], 'attic', 1)->page->candidates);

        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        $chair = (string) $this->ids['Example chair'];
        $back = $this->fieldKey($this->rooms, 'Items');
        foreach ([
            'record in the trash' => fn () => $records->picker($this->admin, $this->p, $this->items, $chair, $this->f['room'], null, 1),
            'not a relation field' => fn () => $records->picker($this->admin, $this->p, $this->items, $lamp, $this->f['count'], null, 1),
            'field of another table' => fn () => $records->picker($this->admin, $this->p, $this->items, $lamp, $back, null, 1),
            'unknown field' => fn () => $records->picker($this->admin, $this->p, $this->items, $lamp, 'k2xq7vn4mz3t', null, 1),
            'id not canonical' => fn () => $records->picker($this->admin, $this->p, $this->items, '0' . $lamp, $this->f['room'], null, 1),
            'absent record' => fn () => $records->picker($this->admin, $this->p, $this->items, '999', $this->f['room'], null, 1),
            'ungranted Member' => fn () => $records->picker($this->member2, $this->p, $this->items, $lamp, $this->f['room'], null, 1),
        ] as $case => $work) {
            $this->assertThrows(NotFound::class, $work);
            $this->addToAssertionCount(1);
            unset($case);
        }
    }

    // ------------------------------------------------------------ T-APP-101

    public function testLinkAndUnlinkAreIdempotentAndCheckTheirIds(): void
    {
        $records = $this->ops->records();
        $lamp = (string) $this->ids['Example lamp'];
        $hall = (string) $this->ids['Example hall'];
        $attic = (string) $this->ids['Example attic'];

        $ref = $records->link($this->member, $this->p, $this->items, $lamp, $this->f['stored'], $hall);
        self::assertSame([$this->ids['Example hall'], 'Example hall'], [$ref->id, $ref->title]);
        $records->link($this->member, $this->p, $this->items, $lamp, $this->f['stored'], $hall);
        $records->link($this->member, $this->p, $this->items, $lamp, $this->f['stored'], $attic);
        self::assertSame(['Example hall', 'Example attic'], $this->linked('Example lamp', 'stored'));

        // One link allowed: choosing another replaces it.
        $records->link($this->member, $this->p, $this->items, $lamp, $this->f['room'], $hall);
        $records->link($this->member, $this->p, $this->items, $lamp, $this->f['room'], $attic);
        self::assertSame(['Example attic'], $this->linked('Example lamp', 'room'));

        $ref = $records->unlink($this->member, $this->p, $this->items, $lamp, $this->f['stored'], $hall);
        self::assertSame([$this->ids['Example hall'], 'Example hall'], [$ref->id, $ref->title]);
        $records->unlink($this->member, $this->p, $this->items, $lamp, $this->f['stored'], $hall);
        self::assertSame(['Example attic'], $this->linked('Example lamp', 'stored'));
        self::assertNull($records->unlink($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], '999')->title, 'Not linked, absent: no error.');

        foreach (['0', '01', ' 1', '1 ', '1e2', '-1', 'x', '', '99999999999999999999'] as $bad) {
            $this->assertThrows(NotFound::class, fn () => $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], $bad));
            $this->assertThrows(NotFound::class, fn () => $records->unlink($this->admin, $this->p, $this->items, $bad, $this->f['stored'], $hall));
        }
        $this->assertThrows(NotFound::class, fn () => $records->link($this->admin, $this->p, $this->rooms, $hall, $this->f['stored'], $hall));
        $this->assertThrows(NotFound::class, fn () => $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['count'], $hall));

        self::assertSame([[$this->f['stored'], 'problem.link.target_missing', []]], self::problems(
            fn () => $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], '999'),
        ));
        // A one-link field holding a record in the trash says why it cannot choose another.
        $chair = (string) $this->ids['Example chair'];
        $cellar = (string) $this->ids['Example cellar'];
        $records->link($this->admin, $this->p, $this->items, $chair, $this->f['room'], $cellar);
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->rooms, [$this->ids['Example cellar']]);
        self::assertSame([[$this->f['room'], 'problem.link.trashed_holds', ['item' => 'item']]], self::problems(
            fn () => $records->link($this->admin, $this->p, $this->items, $chair, $this->f['room'], $hall),
        ));
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        $this->assertThrows(NotFound::class, fn () => $records->link($this->admin, $this->p, $this->items, $chair, $this->f['stored'], $hall));
    }

    // ------------------------------------------------------------ T-APP-102

    public function testLinkOriginAcceptsOnlyARelationFieldOfALiveRecord(): void
    {
        $records = $this->ops->records();
        $lamp = $this->ids['Example lamp'];
        $origin = $records->linkOrigin($this->member, $this->p, $this->rooms, $this->f['room'] . '-' . $lamp);
        self::assertEquals(new LinkOrigin($this->items, (string) $lamp, $this->f['room'], 'Example lamp', 'room', true), $origin);
        self::assertFalse($records->linkOrigin($this->admin, $this->p, $this->rooms, $this->f['stored'] . '-' . $lamp)->onlyOne);

        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        foreach ([
            $this->f['room'],
            $this->f['room'] . '-',
            $this->f['room'] . '-0',
            $this->f['room'] . '-0' . $lamp,
            $this->f['room'] . '-' . $lamp . 'x',
            strtoupper($this->f['room']) . '-' . $lamp,
            $this->f['room'] . '-' . $lamp . "\n",
            $this->f['name'] . '-' . $lamp,
            $this->f['room'] . '-' . $this->ids['Example chair'],
            $this->f['room'] . '-999',
            'k2xq7vn4mz3t-' . $lamp,
        ] as $from) {
            $this->assertThrows(NotFound::class, fn () => $records->linkOrigin($this->admin, $this->p, $this->rooms, $from));
        }
        // A relation whose other table is not the new record's table.
        $this->assertThrows(NotFound::class, fn () => $records->linkOrigin($this->admin, $this->p, $this->items, $this->f['room'] . '-' . $lamp));
        $this->assertThrows(NotFound::class, fn () => $records->blank($this->admin, $this->p, $this->items, ListRequest::none(), $this->f['room'] . '-' . $lamp));
        self::assertEquals($origin, $records->blank($this->admin, $this->p, $this->rooms, ListRequest::none(), $this->f['room'] . '-' . $lamp)->origin);
    }

    // ------------------------------------------------------------ T-APP-103

    public function testCreateFromAPickerCreatesAndLinksInOneTransaction(): void
    {
        $records = $this->ops->records();
        $lamp = $this->ids['Example lamp'];
        $from = $this->f['room'] . '-' . $lamp;
        $id = $records->create($this->member, $this->p, $this->rooms, [$this->f['roomName'] => 'Example pantry'], $from);
        self::assertSame(['Example pantry'], $this->linked('Example lamp', 'room'));
        self::assertSame(4, $this->roomCount());
        self::assertGreaterThan($this->ids['Example cellar'], $id);

        // A failure injected in the link step leaves no record.
        $db = $this->fx->projectDb($this->p);
        $join = (string) $db->value('SELECT "join_table" FROM "_tiffin_relations" WHERE "left_field" = ? OR "right_field" = ?', [$this->f['stored'], $this->f['stored']]);
        $db->run('CREATE TRIGGER "fail_link" BEFORE INSERT ON "' . $join . '" BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END');
        try {
            $records->create($this->admin, $this->p, $this->rooms, [$this->f['roomName'] => 'Example loft'], $this->f['stored'] . '-' . $lamp);
            self::fail('The injected failure did not happen.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('synthetic failure', $e->getMessage());
        }
        $db->run('DROP TRIGGER "fail_link"');
        self::assertSame(4, $this->roomCount());

        // A link problem rolls back too, and is reported at `link`.
        $chair = $this->ids['Example chair'];
        $records->link($this->admin, $this->p, $this->items, (string) $chair, $this->f['room'], (string) $this->ids['Example cellar']);
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->rooms, [$this->ids['Example cellar']]);
        self::assertSame([['link', 'problem.link.trashed_holds', ['item' => 'item']]], self::problems(
            fn () => $records->create($this->admin, $this->p, $this->rooms, [$this->f['roomName'] => 'Example porch'], $this->f['room'] . '-' . $chair),
        ));
        self::assertSame(3, $this->roomCount(), 'Four live rooms less the cellar in the trash; no porch.');
        // Value problems come before the link and leave nothing either.
        self::assertSame($this->f['roomName'], self::problems(
            fn () => $records->create($this->admin, $this->p, $this->rooms, [$this->f['roomName'] => "Example\x01pantry"], $from),
        )[0][0]);
        $this->assertThrows(NotFound::class, fn () => $records->create($this->admin, $this->p, $this->rooms, [$this->f['roomName'] => 'Example yard'], 'bad'));
        self::assertSame(3, $this->roomCount());
    }

    // ------------------------------------------------------------ T-APP-104

    public function testRequiredLinksAndFilesTheFormShowsAreEnforcedOnUpdateOnly(): void
    {
        $db = $this->fx->projectDb($this->p);
        $db->run('UPDATE "_tiffin_fields" SET "required" = 1 WHERE "key" IN (?, ?)', [$this->f['room'], $this->f['photos']]);
        $records = $this->ops->records();
        $lamp = (string) $this->ids['Example lamp'];
        $version = fn (): string => (string) $records->screen($this->admin, $this->p, $this->items, $lamp, ListRequest::none())->version;

        $problems = self::problems(fn () => $records->update($this->member, $this->p, $this->items, $lamp, [$this->f['name'] => 'Renamed', $this->f['count'] => 'many'], $version()));
        self::assertSame([
            [$this->f['count'], 'problem.number.invalid', []],
            [$this->f['room'], 'problem.link.required', []],
            [$this->f['photos'], 'problem.attachment.required', []],
        ], array_map(static fn (array $p): array => [$p[0], $p[1], []], $problems));
        $problems = self::problems(fn () => $records->update($this->member, $this->p, $this->items, $lamp, [$this->f['name'] => 'Renamed'], $version()));
        self::assertSame([[$this->f['room'], 'problem.link.required', []], [$this->f['photos'], 'problem.attachment.required', []]], $problems);
        self::assertSame('Example lamp', $records->screen($this->admin, $this->p, $this->items, $lamp, ListRequest::none())->title, 'Nothing was written.');

        $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['room'], (string) $this->ids['Example hall']);
        $this->attach($this->f['photos'], 'Example lamp', self::png());
        $records->update($this->member, $this->p, $this->items, $lamp, [$this->f['name'] => 'Renamed'], $version());
        self::assertSame('Renamed', $records->screen($this->admin, $this->p, $this->items, $lamp, ListRequest::none())->title);

        // Not shown on the form: not enforced.
        $kettle = (string) $this->ids['Example kettle'];
        $this->setForm($this->items, '<div data-tiffin-field="' . $this->f['name'] . '"></div>');
        $records->update($this->member, $this->p, $this->items, $kettle, [$this->f['name'] => 'Example pot'], (string) $records->screen($this->admin, $this->p, $this->items, $kettle, ListRequest::none())->version);
        // Create never enforces them.
        $this->setForm($this->items, '<div data-tiffin-field="' . $this->f['name'] . '"></div><div data-tiffin-field="' . $this->f['room'] . '"></div><div data-tiffin-field="' . $this->f['photos'] . '"></div>');
        self::assertGreaterThan(0, $records->create($this->member, $this->p, $this->items, [$this->f['name'] => 'Example stool']));
    }

    // ------------------------------------------------------------ T-APP-105

    public function testTheScreenCarriesLinkDetailsFilesImagesAndCanEdit(): void
    {
        $records = $this->ops->records();
        $lamp = (string) $this->ids['Example lamp'];
        $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], (string) $this->ids['Example hall']);
        $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['stored'], (string) $this->ids['Example cellar']);
        $records->link($this->admin, $this->p, $this->items, $lamp, $this->f['room'], (string) $this->ids['Example attic']);
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $pdf = $this->attach($this->f['files'], 'Example lamp', FileFixture::pdf(), 'manual.pdf');
        $gone = $this->attach($this->f['photos'], 'Example chair', self::png('chair'));

        $screen = $records->screen($this->member, $this->p, $this->items, $lamp, ListRequest::none());
        self::assertTrue($screen->canEdit);
        $fields = self::fields($screen);
        $stored = $fields[$this->f['stored']]->links;
        self::assertSame(['Example hall', 'Example cellar'], array_map(static fn ($l): ?string => $l->title, $stored));
        self::assertSame($this->f['floor'], $stored[0]->detailField?->key);
        self::assertSame('Ground', $stored[0]->detailValue);
        self::assertNull($stored[1]->detailField, 'No value, no detail.');
        $room = $fields[$this->f['room']]->links;
        self::assertSame([$this->ids['Example attic'], null], [$room[0]->id, $room[0]->detailField], 'Details only on many-link fields.');
        self::assertSame([[$photo->id, 'example.png', 'image/png']], array_map(static fn ($f): array => [$f->id, $f->name, $f->mediaType], $fields[$this->f['photos']]->files));
        self::assertSame($pdf->id, $fields[$this->f['files']]->files[0]->id);

        // Form images: shown only when an image linked from a live record (P173).
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        $img = static fn (int $id): string => '<img src="attachment:' . $id . '" alt="Picture ' . $id . '"/>';
        $this->setForm($this->items, '<div data-tiffin-field="' . $this->f['name'] . '"></div>' . $img($photo->id) . $img($pdf->id) . $img($gone->id) . $img(999));
        $images = self::images($records->screen($this->member, $this->p, $this->items, $lamp, ListRequest::none()));
        self::assertEquals([
            new BoundImage($photo->id, 'Picture ' . $photo->id, true),
            new BoundImage($pdf->id, 'Picture ' . $pdf->id, false),
            new BoundImage($gone->id, 'Picture ' . $gone->id, false),
            new BoundImage(999, 'Picture 999', false),
        ], $images);
        self::assertTrue(self::images($records->blank($this->member, $this->p, $this->items, ListRequest::none()))[0]->servable);
        self::assertTrue($records->blank($this->member, $this->p, $this->items, ListRequest::none())->canEdit);
    }

    // ------------------------------------------------------------ T-APP-106

    public function testTheScreenFollowsACollectionAndDropsAnUnknownOne(): void
    {
        $collections = $this->ops->collections();
        $key = $collections->save($this->admin, $this->p, $this->items, null, [
            'name' => 'Example many', 'match' => 'all', 'sort' => $this->f['name'] . '.asc',
            'rules' => [['field' => $this->f['count'], 'test' => 'more', 'value' => '5']],
        ]);
        $rooms = $collections->save($this->admin, $this->p, $this->rooms, null, ['name' => 'Example rooms', 'rules' => []]);
        $records = $this->ops->records();
        $kettle = (string) $this->ids['Example kettle'];
        $in = fn (?string $collection): RecordScreen => $records->screen($this->member, $this->p, $this->items, $kettle, new ListRequest(null, null, null, null, null, null, 1, $collection));

        $screen = $in($key);
        self::assertSame([2, 2, $this->ids['Example chair'], null], [$screen->position?->index, $screen->position?->total, $screen->position?->previousId, $screen->position?->nextId]);
        self::assertSame($key, $screen->request->collection);
        self::assertNull($screen->request->sort, 'The collection\'s own sort is its default.');

        foreach ([null, 'k2xq7vn4mz3t', 'not a key', $rooms] as $collection) {
            $screen = $in($collection);
            self::assertSame(3, $screen->position?->total);
            self::assertNull($screen->request->collection);
        }
        self::assertNull($records->screen($this->member, $this->p, $this->items, (string) $this->ids['Example lamp'], new ListRequest(null, null, null, null, null, null, 1, $key))->position, 'Not in the collection.');
    }

    // ------------------------------------------------------------ helpers

    /** @return list<?string> titles linked from the item through the field, in order */
    private function linked(string $item, string $field): array
    {
        $screen = $this->ops->records()->screen($this->admin, $this->p, $this->items, (string) $this->ids[$item], ListRequest::none());

        return array_map(static fn ($l): ?string => $l->title, self::fields($screen)[$this->f[$field]]->links);
    }

    private function roomCount(): int
    {
        return $this->ops->records()->listing($this->admin, $this->p, $this->rooms, ListRequest::none())->total;
    }

    /** @return array<string, BoundField> */
    private static function fields(RecordScreen $screen): array
    {
        $fields = [];
        foreach (self::walk($screen->form?->nodes ?? []) as $node) {
            if ($node instanceof BoundField) {
                $fields[$node->field->key] = $node;
            }
        }

        return $fields;
    }

    /** @return list<BoundImage> */
    private static function images(RecordScreen $screen): array
    {
        return array_values(array_filter(self::walk($screen->form?->nodes ?? []), static fn (BoundNode $n): bool => $n instanceof BoundImage));
    }

    /**
     * @param list<BoundNode> $nodes
     * @return list<BoundNode>
     */
    private static function walk(array $nodes): array
    {
        $all = [];
        foreach ($nodes as $node) {
            $all[] = $node;
            if ($node instanceof BoundSection) {
                $all = [...$all, ...self::walk($node->children)];
            }
        }

        return $all;
    }
}
