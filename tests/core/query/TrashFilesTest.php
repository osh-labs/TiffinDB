<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\EmptiedTrash;
use TiffinDB\Core\Query\Trash;
use TiffinDB\Core\Query\TrashedRow;
use TiffinDB\Core\Query\TrashImpact;
use TiffinDB\Core\Schema\FieldDef;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-TRASH-4 to T-TRASH-6: the typed trash list, restoring a whole table,
 * what emptying would delete, and the attachment rows emptying frees.
 * All data is synthetic.
 */
#[CoversClass(Trash::class)]
#[CoversClass(TrashedRow::class)]
#[CoversClass(TrashImpact::class)]
#[CoversClass(EmptiedTrash::class)]
final class TrashFilesTest extends TestCase
{
    use RecordsFixture;

    private Trash $trash;
    private FieldDef $photos;
    private FieldDef $extra;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-trash-files-test-1');
        $this->trash = new Trash($this->db, $this->clock);
        $this->photos = $this->editor()->addField($this->rooms->key, 'Photos', 'attachment', [], false, '');
        $this->extra = $this->editor()->addField($this->items->key, 'Extra', 'attachment', [], false, '');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function link(FieldDef $field, int $recordId, int $attachmentId): void
    {
        $this->db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, 9)', [$field->key, $recordId, $attachmentId]);
    }

    /** @return array<string, list<FieldDef>> every attachment field by table key */
    private function everyField(): array
    {
        return [$this->items->key => [$this->f['files'], $this->extra], $this->rooms->key => [$this->photos]];
    }

    /**
     * Rows linked: A only from a trashed item; B from a trashed and a live
     * item; C from a trashed item and a trashed room; D1 (freed) and D2
     * (live) sharing content; E1 and E2 (both freed) sharing content; F
     * with no link; G from a trashed item through a second field.
     *
     * @return array<string, int> attachment ids by letter
     */
    private function scene(): array
    {
        $live = $this->item(['name' => 'Live']);
        $gone = $this->item(['name' => 'Gone']);
        $room = $this->room('Gone room');
        $ids = [];
        $ids['A'] = $this->attach($gone, 'only trashed');
        $ids['B'] = $this->attach($gone, 'also live');
        $this->link($this->f['files'], $live, $ids['B']);
        $ids['C'] = $this->attach($gone, 'two tables');
        $this->link($this->photos, $room, $ids['C']);
        $ids['D1'] = $this->attach($gone, 'twin kept');
        $ids['D2'] = $this->attach($live, 'twin kept');
        $ids['E1'] = $this->attach($gone, 'twin freed');
        $ids['E2'] = $this->attach($gone, 'twin freed');
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [hash('sha256', 'no link'), 'text/plain', 'f', self::START]);
        $ids['F'] = $this->db->lastInsertId();
        $ids['G'] = $this->attach($gone, 'second field', 'g', $this->extra);
        $this->trashRecord($this->items, $gone);
        $this->trashRecord($this->rooms, $room);

        return $ids;
    }

    // ------------------------------------------------------------ T-TRASH-4

    public function testListReturnsTrashedRows(): void
    {
        $lamp = $this->item(['name' => 'Lamp']);
        $this->clock->advance(60);
        $this->trash->moveToTrash($this->items, [$lamp], self::OTHER_USER_KEY);
        self::assertEquals([new TrashedRow($lamp, '2026-11-12T03:16:00Z', self::OTHER_USER_KEY, 'Lamp')], $this->trash->list($this->items, $this->f['name'], $this->types, 10, 0));
    }

    public function testRestoreAllRestoresTheWholeTable(): void
    {
        $ids = [];
        for ($n = 0; $n < 1200; $n++) {
            $ids[] = $this->insert($this->items->key, [], true);
        }
        $live = $this->item(['name' => 'Live']);
        $room = $this->room('Shed');
        $this->trashRecord($this->rooms, $room);
        $this->clock->advance(60);
        $this->db->run('CREATE TEMP TABLE "meta_writes" ("n")');
        $this->db->run('CREATE TEMP TRIGGER "count_touch" AFTER UPDATE ON "_tiffin_meta" WHEN NEW."key" = \'updated_at\' BEGIN INSERT INTO "meta_writes" VALUES (1); END');

        self::assertSame(1200, $this->trash->restoreAll($this->items, self::OTHER_USER_KEY), 'More than Trash::MAX_IDS at once.');

        $metaWrites = (int) $this->db->value('SELECT COUNT(*) FROM "temp"."meta_writes"');
        self::assertSame(1, $metaWrites, 'The meta is touched once.');
        self::assertSame(0, $this->trash->count($this->items));
        self::assertSame(1, $this->trash->count($this->rooms), 'Other tables keep their trash.');
        $row = $this->rowOf($this->items, $ids[0]);
        self::assertNull($row['_trashed_by']);
        self::assertSame('2026-11-12T03:16:00Z', $row['_updated_at']);
        self::assertSame(self::OTHER_USER_KEY, $row['_updated_by']);
        self::assertSame(self::START, $this->rowOf($this->items, $live)['_updated_at'], 'Live records are not touched.');
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));

        $this->clock->advance(60);
        self::assertSame(0, $this->trash->restoreAll($this->items, self::USER_KEY));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM "temp"."meta_writes"'), 'Nothing restored, no touch.');
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));

        $this->expectException(\InvalidArgumentException::class);
        $this->trash->restoreAll($this->rooms, 'Example Person');
    }

    // ------------------------------------------------------------ T-TRASH-5

    public function testImpactCountsRecordsAndTheFilesEmptyingWouldFree(): void
    {
        $this->scene();
        $this->insert($this->items->key, [], true);

        $all = $this->trash->impact([$this->items, $this->rooms], $this->everyField());
        self::assertSame([$this->items->key => 2, $this->rooms->key => 1], $all->records);
        self::assertSame(3, $all->total);
        // A, C, E (one file for two rows), G; not B (a live link), D (the content stays for D2), F (no link).
        self::assertSame(4, $all->files);

        $itemsOnly = $this->trash->impact([$this->items], [$this->items->key => [$this->f['files'], $this->extra]]);
        self::assertSame([$this->items->key => 2], $itemsOnly->records);
        self::assertSame(3, $itemsOnly->files, 'C stays linked from the room.');

        $oneField = $this->trash->impact([$this->items, $this->rooms], [$this->items->key => [$this->f['files']]]);
        self::assertSame(2, $oneField->files, 'A and E; C keeps a link through Photos, G through Extra.');

        $none = $this->trash->impact([], []);
        self::assertSame([], $none->records);
        self::assertSame(0, $none->total);
        self::assertSame(0, $none->files);
    }

    public function testImpactChecksItsArguments(): void
    {
        foreach ([[[$this->items->key], []], [[$this->items], [$this->items->key => [$this->f['name']]]], [[$this->items], [$this->rooms->key => [$this->f['files']]]]] as [$tables, $fields]) {
            try {
                $this->trash->impact($tables, $fields);
                self::fail('Bad arguments were accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------ T-TRASH-6

    public function testEmptyFreesRowsNothingLinksAndReturnsTheirHashes(): void
    {
        $ids = $this->scene();
        $impact = $this->trash->impact([$this->items, $this->rooms], $this->everyField());
        $this->clock->advance(60);

        $emptied = $this->trash->empty([$this->items, $this->rooms], $this->everyField(), self::USER_KEY);

        self::assertSame(2, $emptied->records);
        $expected = array_map(static fn (string $c): string => hash('sha256', $c), ['only trashed', 'two tables', 'twin kept', 'twin freed', 'second field']);
        $freed = $emptied->freedHashes;
        sort($expected);
        sort($freed);
        self::assertSame($expected, $freed, 'Each deleted row\'s hash once.');
        $left = array_map('intval', array_column($this->db->all('SELECT "id" FROM "_tiffin_attachments" ORDER BY "id"'), 'id'));
        self::assertSame([$ids['B'], $ids['D2'], $ids['F']], $left, 'Rows linked from live records survive, and so does the row with no link (the sweep\'s).');
        foreach (['only trashed', 'two tables'] as $content) {
            self::assertNotNull($this->storedFile(hash('sha256', $content)), 'Files go only after the write.');
        }

        self::assertSame($impact->files, $this->attachments()->deleteFiles($emptied->freedHashes), 'The confirmation count matches the files deleted.');
        self::assertNull($this->storedFile(hash('sha256', 'only trashed')));
        self::assertNotNull($this->storedFile(hash('sha256', 'twin kept')));
        self::assertNotNull($this->storedFile(hash('sha256', 'also live')));
    }

    public function testEmptyLeavesLinksThroughFieldsItWasNotGiven(): void
    {
        $ids = $this->scene();
        $emptied = $this->trash->empty([$this->items], [$this->items->key => [$this->f['files']]], self::USER_KEY);
        self::assertSame(1, $emptied->records);
        $expected = [hash('sha256', 'only trashed'), hash('sha256', 'twin freed'), hash('sha256', 'twin kept')];
        $freed = $emptied->freedHashes;
        sort($expected);
        sort($freed);
        self::assertSame($expected, $freed);
        self::assertNotNull($this->attachments()->get($ids['C']), 'Still linked from the trashed room.');
        self::assertNotNull($this->attachments()->get($ids['G']), 'Its link was not given: left for the sweep.');
    }

    public function testAFailedEmptyChangesNothing(): void
    {
        $this->scene();
        $before = $this->dump();
        $this->db->run('CREATE TEMP TRIGGER "fail_free" BEFORE DELETE ON "_tiffin_attachments" BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END');
        try {
            $this->trash->empty([$this->items, $this->rooms], $this->everyField(), self::USER_KEY);
            self::fail('The injected failure did not happen.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('synthetic failure', $e->getMessage());
        }
        $this->db->run('DROP TRIGGER "temp"."fail_free"');
        self::assertSame($before, $this->dump());
    }

    public function testEmptyWithNoAttachmentFieldsFreesNothing(): void
    {
        $this->scene();
        $emptied = $this->trash->empty([$this->items, $this->rooms], [], self::USER_KEY);
        self::assertSame(2, $emptied->records);
        self::assertSame([], $emptied->freedHashes);
    }
}
