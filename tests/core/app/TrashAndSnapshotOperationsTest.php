<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\SnapshotOperations;
use TiffinDB\Core\App\TrashedRecord;
use TiffinDB\Core\App\TrashOperations;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\SnapshotReason;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-40 and T-APP-50: trash and snapshots. */
#[CoversClass(TrashOperations::class)]
#[CoversClass(SnapshotOperations::class)]
final class TrashAndSnapshotOperationsTest extends AppTestCase
{
    private string $project;
    private string $table;
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants')->key;
        $design = $this->ops->schema()->design($this->admin, $this->project, $this->ops->schema()->tables($this->admin, $this->project)[0]->table->key);
        $this->table = $design->table->key;
        $this->name = $design->fields[0]->field->key;
        $this->ops->projects()->setSharing($this->admin, $this->project, [$this->member->userKey]);
    }

    public function testAGrantedMemberMovesToTrashAndRestores(): void
    {
        $trash = $this->ops->trash();
        $a = $this->fx->insertRecord($this->project, $this->table, [$this->name => 'Example Basil']);
        $b = $this->fx->insertRecord($this->project, $this->table, [$this->name => 'Example Thyme']);
        $this->fx->insertRecord($this->project, $this->table, [$this->name => 'Example Sage']);

        self::assertSame(2, $trash->moveToTrash($this->member, $this->project, $this->table, [$a, $b, 999]));
        self::assertSame(2, $trash->count($this->member, $this->project, $this->table));
        self::assertSame(2, $trash->count($this->member, $this->project, null));
        $listed = $trash->list($this->member, $this->project, $this->table, 10, 0);
        self::assertContainsOnlyInstancesOf(TrashedRecord::class, $listed);
        self::assertSame([[$b, 'Example Thyme', $this->member->userKey], [$a, 'Example Basil', $this->member->userKey]], array_map(static fn (TrashedRecord $r): array => [$r->id, $r->title, $r->trashedBy], $listed));
        self::assertSame(1, $trash->restore($this->member, $this->project, $this->table, [$a]));
        self::assertSame(1, $trash->count($this->member, $this->project, $this->table));
        self::assertSame(['project.created', 'schema.table_created', 'project.sharing_changed'], $this->auditActions(), 'Record-level trash is not audited.');

        $this->assertThrows(NotFound::class, fn () => $trash->moveToTrash($this->member2, $this->project, $this->table, [$a]));
        $this->assertThrows(NotFound::class, fn () => $trash->list($this->member2, $this->project, $this->table, 10, 0));
        $this->assertThrows(NotFound::class, fn () => $trash->moveToTrash($this->member, $this->project, 'zzzzzzzzzzzz', [$a]));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $trash->list($this->member, $this->project, $this->table, 201, 0));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $trash->list($this->member, $this->project, $this->table, 0, 0));
    }

    public function testOnlyAdminsEmptyTheTrash(): void
    {
        $trash = $this->ops->trash();
        $this->fx->insertRecord($this->project, $this->table, [$this->name => 'Example Basil'], true);
        $this->fx->insertRecord($this->project, $this->table, [$this->name => 'Example Sage']);

        $this->assertThrows(AccessDenied::class, fn () => $trash->empty($this->member, $this->project, $this->table));
        $this->assertThrows(AccessDenied::class, fn () => $trash->empty($this->member, $this->project, null));
        self::assertSame(1, $trash->count($this->admin, $this->project, null));
        self::assertSame([], $this->ops->snapshots()->list($this->admin, $this->project));

        self::assertSame(1, $trash->empty($this->admin, $this->project, $this->table));

        self::assertSame(0, $trash->count($this->admin, $this->project, null));
        self::assertSame(1, $this->ops->schema()->tables($this->admin, $this->project)[0]->recordCount);
        $snapshots = $this->ops->snapshots()->list($this->admin, $this->project);
        self::assertCount(1, $snapshots);
        self::assertSame(SnapshotReason::EmptyTrash, $snapshots[0]->reason);
        self::assertSame(['snapshot.taken', 'trash.emptied'], array_slice($this->auditActions(), -2));
        $snapshot = $this->lastAudit('snapshot.taken');
        self::assertSame(['snapshot', $snapshots[0]->file, ['reason' => 'empty-trash']], [$snapshot->targetKind, $snapshot->targetKey, $snapshot->detail]);
        $emptied = $this->lastAudit('trash.emptied');
        self::assertSame(['table', $this->table, ['records' => 1]], [$emptied->targetKind, $emptied->targetKey, $emptied->detail]);

        // Nothing to delete: no snapshot.
        self::assertSame(0, $trash->empty($this->admin, $this->project, null));
        self::assertCount(1, $this->ops->snapshots()->list($this->admin, $this->project));
        $emptied = $this->lastAudit('trash.emptied');
        self::assertSame([null, null, ['records' => 0]], [$emptied->targetKind, $emptied->targetKey, $emptied->detail]);
    }

    public function testManualSnapshotsAreAdminOnly(): void
    {
        $snapshots = $this->ops->snapshots();
        $this->assertThrows(AccessDenied::class, fn () => $snapshots->take($this->member, $this->project));
        $this->assertThrows(AccessDenied::class, fn () => $snapshots->list($this->member, $this->project));
        $this->assertThrows(NotFound::class, fn () => $snapshots->take($this->member2, $this->project));
        self::assertSame([], glob($this->fx->projectFolder($this->project) . '/snapshots/*') ?: []);

        $info = $snapshots->take($this->admin, $this->project);

        self::assertSame(SnapshotReason::Manual, $info->reason);
        self::assertFileExists($this->fx->projectFolder($this->project) . '/snapshots/' . $info->file);
        self::assertSame([$info->file], array_map(static fn ($s) => $s->file, $snapshots->list($this->admin, $this->project)));
        $entry = $this->lastAudit('snapshot.taken');
        self::assertSame([$info->file, ['reason' => 'manual']], [$entry->targetKey, $entry->detail]);
    }

    public function testDeletingAFieldRecordsTheSnapshotFirst(): void
    {
        $field = $this->ops->schema()->addField($this->admin, $this->project, $this->table, 'Notes', 'long_text', [], false, '');

        $this->ops->schema()->deleteField($this->admin, $this->project, $this->table, $field->key);

        self::assertSame(['snapshot.taken', 'schema.field_deleted'], array_slice($this->auditActions(), -2));
        self::assertSame(['reason' => 'field-delete'], $this->lastAudit('snapshot.taken')->detail);
        $deleted = $this->lastAudit('schema.field_deleted');
        self::assertSame(['field', $field->key, ['type' => 'long_text']], [$deleted->targetKind, $deleted->targetKey, $deleted->detail]);
        self::assertSame(SnapshotReason::FieldDelete, $this->ops->snapshots()->list($this->admin, $this->project)[0]->reason);
    }
}
