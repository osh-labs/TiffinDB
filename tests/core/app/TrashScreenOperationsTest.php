<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\RestoredAll;
use TiffinDB\Core\App\SchemaOperations;
use TiffinDB\Core\App\TrashOperations;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\System\AuditAction;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-140 to T-APP-145 (M4 spec sections 10.5 and 10.6): the trash
 * screen, restore all, the empty-trash impact and confirmation, files
 * deleted and swept after emptying and after schema deletes, and what
 * Members may do. Also the P175 sweep with the staging sweep and the
 * case-insensitive hash compare of the sweep (P180 item 3).
 */
#[CoversClass(TrashOperations::class)]
#[CoversClass(SchemaOperations::class)]
final class TrashScreenOperationsTest extends M4AppTestCase
{
    // ------------------------------------------------------------ T-APP-140

    public function testTheScreenWithAndWithoutATable(): void
    {
        $trash = $this->ops->trash();
        $ids = [];
        for ($i = 1; $i <= 53; $i++) {
            $this->fx->clock->advance(60);
            $ids[] = $this->fx->addRecord($this->p, $this->items, [$this->f['name'] => sprintf('Example old %02d', $i)]);
        }
        $this->ops->records()->trash($this->member, $this->p, $this->items, (string) $ids[0]);
        $this->fx->clock->advance(60);
        $trash->moveToTrash($this->admin, $this->p, $this->items, array_slice($ids, 1));

        $all = $trash->screen($this->member, $this->p, null, 7);
        self::assertSame([53, 1, true, false], [$all->total, count($all->sections), $all->canRestore, $all->canEmpty]);
        $section = $all->sections[0];
        self::assertSame([$this->items, 53, 1, 2, 50], [$section->table->key, $section->count, $section->page, $section->pages, count($section->records)]);
        self::assertSame(['Example old 53', 'Example Admin'], [$section->records[0]->title, $section->records[0]->trashedByName]);

        $paged = $trash->screen($this->member, $this->p, $this->items, 2);
        self::assertSame([2, 3], [$paged->sections[0]->page, count($paged->sections[0]->records)]);
        $last = $paged->sections[0]->records[2];
        self::assertSame(['Example old 01', 'Example Member'], [$last->title, $last->trashedByName], 'Newest first; the Member trashed the first.');
        self::assertSame(2, $trash->screen($this->member, $this->p, $this->items, 99)->sections[0]->page, 'Pages clamp.');
        self::assertSame(1, $trash->screen($this->member, $this->p, $this->items, -3)->sections[0]->page);
        $rooms = $trash->screen($this->admin, $this->p, $this->rooms, 1);
        self::assertSame([53, 0, [], true], [$rooms->total, $rooms->sections[0]->count, $rooms->sections[0]->records, $rooms->canEmpty]);
        $this->assertThrows(NotFound::class, fn () => $trash->screen($this->member, $this->p, 'k2xq7vn4mz3t', 1));
        $this->assertThrows(NotFound::class, fn () => $trash->screen($this->member2, $this->p, null, 1));
    }

    // ------------------------------------------------------------ T-APP-141

    public function testRestoreAll(): void
    {
        $trash = $this->ops->trash();
        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example lamp'], $this->ids['Example kettle']]);
        $trash->moveToTrash($this->admin, $this->p, $this->rooms, [$this->ids['Example hall']]);
        $restored = $trash->restoreAll($this->member, $this->p, $this->items);
        self::assertInstanceOf(RestoredAll::class, $restored);
        self::assertSame([$this->items, 2], [$restored->table->key, $restored->count]);
        self::assertSame(1, $trash->count($this->admin, $this->p, null));
        self::assertSame(0, $trash->restoreAll($this->member, $this->p, $this->items)->count);
    }

    // ------------------------------------------------------------ T-APP-142

    public function testEmptyAsksAgainWhenMoreRecordsWait(): void
    {
        $trash = $this->ops->trash();
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example lamp']]);
        $impact = $trash->emptyImpact($this->admin, $this->p);
        self::assertSame([[$this->items => 1, $this->rooms => 0], 1, 1], [$impact->records, $impact->total, $impact->files]);

        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example kettle']]);
        try {
            $trash->empty($this->admin, $this->p, null, 1);
            self::fail('A larger count must ask again.');
        } catch (ConfirmationRequired $ask) {
            self::assertSame([2, 1], [$ask->records, $ask->values]);
        }
        self::assertSame(2, $trash->count($this->admin, $this->p, null), 'Nothing happened.');
        self::assertFileExists($this->storedPath($photo->sha256));

        $emptied = $trash->empty($this->admin, $this->p, null, 3);
        self::assertSame([2, 1], [$emptied->records, $emptied->files], 'A smaller count proceeds.');
        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        self::assertSame(1, $trash->empty($this->admin, $this->p, null, 1)->records, 'An equal count proceeds.');
    }

    /** P187: the count that decides is read inside the deleting write, after the snapshot. */
    public function testARecordTrashedAfterTheSnapshotMakesItAskAgain(): void
    {
        $lamp = $this->ids['Example lamp'];
        $kettle = $this->ids['Example kettle'];
        $items = $this->items;
        $other = $this->fx->projectDb($this->p);
        $table = '"t_' . $items . '"';
        $seamed = $this->fx->operations(seam: static function (string $point) use ($other, $table, $kettle): void {
            if ($point === 'trash.after_snapshot') {
                $other->run('UPDATE ' . $table . ' SET "_trashed_at" = ? WHERE "id" = ? AND "_trashed_at" IS NULL', ['2026-03-02T10:00:00Z', $kettle]);
            }
        });
        $trash = $seamed->trash();
        $trash->moveToTrash($this->admin, $this->p, $items, [$lamp]);

        foreach ([1, null] as $expected) {
            try {
                $trash->empty($this->admin, $this->p, null, $expected);
                self::fail('A record trashed after the snapshot must make it ask again.');
            } catch (ConfirmationRequired $ask) {
                self::assertSame(2, $ask->records, var_export($expected, true));
            }
            self::assertSame(2, $trash->count($this->admin, $this->p, null), 'Nothing was deleted.');
            self::assertNotContains(AuditAction::TRASH_EMPTIED, $this->auditActions());
            $trash->restore($this->admin, $this->p, $items, [$kettle]);
        }
        // Asked again with the new count, both go (the seam has nothing left to add).
        $trash->moveToTrash($this->admin, $this->p, $items, [$kettle]);
        self::assertSame(2, $trash->empty($this->admin, $this->p, null, 2)->records);
    }

    /** P187: nothing deleted, no `trash.emptied` row. */
    public function testEmptyingAnEmptyTrashWritesNoAuditRow(): void
    {
        $trash = $this->ops->trash();
        $before = $this->auditActions();
        self::assertSame([0, 0], [$trash->empty($this->admin, $this->p, null)->records, $trash->empty($this->admin, $this->p, $this->items, 0)->files]);
        self::assertSame($before, $this->auditActions());
        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example lamp']]);
        $trash->empty($this->admin, $this->p, null, 1);
        self::assertSame(1, count(array_keys($this->auditActions(), AuditAction::TRASH_EMPTIED)));
        $trash->empty($this->admin, $this->p, null, 0);
        self::assertSame(1, count(array_keys($this->auditActions(), AuditAction::TRASH_EMPTIED)));
    }

    // ------------------------------------------------------------ T-APP-143

    public function testEmptyDeletesFreedFilesSweepsAndKeepsSharedOnes(): void
    {
        $trash = $this->ops->trash();
        $shared = self::png('shared');
        $only = $this->attach($this->f['photos'], 'Example lamp', self::png('only'));
        $this->attach($this->f['files'], 'Example lamp', $shared, 'a.png');
        $kept = $this->attach($this->f['files'], 'Example kettle', $shared, 'b.png');
        // An orphan file no row names, as a crash might leave: the sweep removes it.
        $orphan = hash('sha256', 'orphan bytes');
        mkdir(dirname($this->storedPath($orphan)), 0750, true);
        file_put_contents($this->storedPath($orphan), 'orphan bytes');

        $trash->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example lamp']]);
        $emptied = $trash->empty($this->admin, $this->p, null);
        self::assertSame([1, 2], [$emptied->records, $emptied->files]);
        self::assertSame(['records' => 1, 'files' => 2], $this->lastAudit(AuditAction::TRASH_EMPTIED)->detail);
        self::assertFileDoesNotExist($this->storedPath($only->sha256));
        self::assertFileDoesNotExist($this->storedPath($orphan));
        self::assertFileExists($this->storedPath($kept->sha256), 'Shared with a live record.');
        self::assertTrue($this->ops->attachments()->serve($this->member, $this->p, (string) $kept->id, false)->inline);
    }

    public function testTheSweepKeepsAFileWhoseRowNamesItInAnotherCase(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $this->fx->projectDb($this->p)->run('UPDATE "_tiffin_attachments" SET "sha256" = upper("sha256") WHERE "id" = ?', [$photo->id]);
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        $this->ops->trash()->empty($this->admin, $this->p, null);
        self::assertFileExists($this->storedPath($photo->sha256), 'P180 item 3: the row still names the file.');
    }

    public function testTheStagingSweepAlsoSweepsOrphanFiles(): void
    {
        $orphan = hash('sha256', 'left by a crash');
        mkdir(dirname($this->storedPath($orphan)), 0750, true);
        file_put_contents($this->storedPath($orphan), 'left by a crash');
        $kept = $this->attach($this->f['photos'], 'Example lamp', self::png());
        self::assertFileDoesNotExist($this->storedPath($orphan), 'P175: the upload ran the staging sweep.');
        file_put_contents($this->storedPath($orphan), 'left by a crash');
        $this->ops->exports()->archive($this->admin, $this->p);
        self::assertFileDoesNotExist($this->storedPath($orphan), 'P175: so does an export.');
        self::assertFileExists($this->storedPath($kept->sha256));
    }

    // ------------------------------------------------------------ T-APP-144

    public function testDeletingAnAttachmentFieldOrATableSweepsTheirFiles(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png('p'));
        $file = $this->attach($this->f['files'], 'Example lamp', self::png('f'));
        $this->ops->schema()->deleteField($this->admin, $this->p, $this->items, $this->f['photos']);
        self::assertFileDoesNotExist($this->storedPath($photo->sha256));
        self::assertFileExists($this->storedPath($file->sha256));
        $this->ops->schema()->deleteTable($this->admin, $this->p, $this->items);
        self::assertSame([], $this->storedHashes());
        self::assertSame(0, (int) $this->fx->projectDb($this->p)->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
    }

    // ------------------------------------------------------------ T-APP-145

    public function testMembersSeeAndRestoreButNeverEmpty(): void
    {
        $trash = $this->ops->trash();
        $trash->moveToTrash($this->member, $this->p, $this->items, [$this->ids['Example lamp']]);
        self::assertSame(1, $trash->screen($this->member, $this->p, null, 1)->total);
        $this->ops->records()->restore($this->member, $this->p, $this->items, (string) $this->ids['Example lamp']);
        $trash->moveToTrash($this->member, $this->p, $this->items, [$this->ids['Example lamp']]);
        self::assertSame(1, $trash->restoreAll($this->member, $this->p, $this->items)->count);
        $this->assertThrows(AccessDenied::class, fn () => $trash->emptyImpact($this->member, $this->p));
        $this->assertThrows(AccessDenied::class, fn () => $trash->empty($this->member, $this->p, null, 0));
        $this->assertThrows(NotFound::class, fn () => $trash->emptyImpact($this->member2, $this->p));
    }
}
