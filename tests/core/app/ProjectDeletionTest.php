<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\App\ProjectDeleted;
use TiffinDB\Core\App\ProjectDeleteScreen;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\App\ProjectSummary;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\LockHolder;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-ADM-13 to T-ADM-19, T-ADM-22, T-ADM-23 (M7 spec sections 6 and 16.1):
 * deleting a project, hostile folder shapes, a second process using the
 * project, interrupted deletions finished by the sweep, and a project
 * whose folder is missing.
 */
#[CoversClass(ProjectOperations::class)]
#[CoversClass(ProjectDeleteScreen::class)]
#[CoversClass(ProjectDeleted::class)]
final class ProjectDeletionTest extends AppTestCase
{
    private ProjectRecord $garden;
    private ProjectRecord $kitchen;
    private string $table;
    private string $name;
    private string $pans;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        parent::setUp();
        ProjectLock::$waitScaleForTests = 0.02;
        $projects = $this->ops->projects();
        $this->garden = $projects->create($this->admin, 'Example Garden', 'Plants');
        $this->kitchen = $projects->create($this->admin, 'Example Kitchen', 'Pans');
        $schema = $this->ops->schema();
        $design = $schema->design($this->admin, $this->garden->key, $schema->tables($this->admin, $this->garden->key)[0]->table->key);
        $this->table = $design->table->key;
        $this->name = $design->fields[0]->field->key;
        $this->pans = $schema->tables($this->admin, $this->kitchen->key)[0]->table->key;
        $projects->setSharing($this->admin, $this->garden->key, [$this->member->userKey]);
        $projects->setSharing($this->admin, $this->kitchen->key, [$this->member->userKey]);
        foreach (['Example Basil', 'Example Thyme', 'Example Sage'] as $plant) {
            $this->ops->records()->create($this->admin, $this->garden->key, $this->table, [$this->name => $plant]);
        }
        $this->ops->trash()->moveToTrash($this->admin, $this->garden->key, $this->table, [3]);
        $photos = $schema->addField($this->admin, $this->garden->key, $this->table, 'Photos', 'attachment', [], false, '')->key;
        $this->ops->attachments()->add($this->admin, $this->garden->key, $this->table, '1', $photos, $this->fx->upload(FileFixture::png()), 'synthetic.png', null);
        $this->ops->snapshots()->take($this->admin, $this->garden->key);
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        ProjectLock::$waitScaleForTests = 1.0;
        parent::tearDown();
    }

    private function folder(?ProjectRecord $project = null): string
    {
        return $this->fx->projectFolder(($project ?? $this->garden)->key);
    }

    private function deletedEntry(): string
    {
        return $this->fx->data->path() . '/staging/deleted/' . $this->garden->key;
    }

    private function state(): ?string
    {
        $state = $this->fx->system->db()->value('SELECT state FROM projects WHERE key = ?', [$this->garden->key]);

        return $state === null ? null : (string) $state;
    }

    private function grants(int $projectId): int
    {
        return (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM project_grants WHERE project_id = ?', [$projectId]);
    }

    /** @return list<string> every path below a folder, relative, sorted; links listed, never followed */
    private static function tree(string $root): array
    {
        $found = [];
        $walk = static function (string $dir, string $prefix) use (&$walk, &$found): void {
            foreach (array_diff((array) scandir($dir), ['.', '..']) as $name) {
                $path = $dir . '/' . $name;
                $found[] = $prefix . $name . (is_link($path) ? ' -> link' : '');
                if (is_dir($path) && !is_link($path)) {
                    $walk($path, $prefix . $name . '/');
                }
            }
        };
        $walk($root, '');
        sort($found);

        return $found;
    }

    private static function removeTree(string $root): void
    {
        foreach (array_diff((array) scandir($root), ['.', '..']) as $name) {
            $path = $root . '/' . $name;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($root);
    }

    private function delete(?Operations $ops = null, string $typed = 'Example Garden', int $expected = 3): ProjectDeleted
    {
        return ($ops ?? $this->ops)->projects()->delete($this->admin, $this->garden->key, $typed, $expected);
    }

    // ------------------------------------------------------------ T-ADM-13

    public function testADeletionRemovesExactlyTheProject(): void
    {
        $kitchenBefore = self::tree($this->folder($this->kitchen));
        $auditBefore = (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log');
        $ofProject = (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log WHERE project_id = ?', [$this->garden->id]);
        self::assertGreaterThan(0, $ofProject);
        self::assertSame(1, $this->grants($this->garden->id));

        $screen = $this->ops->projects()->deleteScreen($this->admin, $this->garden->key);
        self::assertSame([2, 1, 1, strlen(FileFixture::png()), 1, 1, false], [$screen->records, $screen->trashed, $screen->files, $screen->fileBytes, $screen->snapshots, $screen->members, $screen->folderMissing]);

        $deleted = $this->delete();

        self::assertSame(['Example Garden', 2, 1, 1, 1], [$deleted->name, $deleted->records, $deleted->trashed, $deleted->files, $deleted->snapshots]);
        self::assertFileDoesNotExist($this->folder());
        self::assertFileDoesNotExist($this->deletedEntry(), 'The moved folder is removed as well.');
        self::assertNull($this->state());
        self::assertSame(0, $this->grants($this->garden->id));
        self::assertSame(0, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log WHERE project_id = ?', [$this->garden->id]));
        self::assertSame($auditBefore + 1, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM audit_log'), 'Audit rows are kept, with no project.');
        $entry = $this->lastAudit(AuditAction::PROJECT_DELETED);
        self::assertSame(
            [$this->admin->userId, null, 'project', $this->garden->key, ['name' => 'Example Garden', 'records' => 2, 'trashed' => 1, 'files' => 1, 'snapshots' => 1]],
            [$entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey, $entry->detail],
        );

        // The other project is untouched.
        self::assertSame($kitchenBefore, self::tree($this->folder($this->kitchen)));
        self::assertSame(['Example Kitchen'], array_map(static fn (ProjectSummary $s): string => $s->project->name, $this->ops->projects()->listFor($this->admin)));
        self::assertSame(1, $this->grants($this->kitchen->id));
        self::assertSame('Example Kitchen', $this->ops->projects()->get($this->member, $this->kitchen->key)->name);
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->get($this->admin, $this->garden->key));

        // The name is free again.
        $again = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        self::assertNotSame($this->garden->key, $again->key);
    }

    // ------------------------------------------------------------ T-ADM-14

    public function testTheTypedNameAndTheRecordCountAreChecked(): void
    {
        foreach (['Example Gardens', '', 'Example', "Example Garden\0", "\xff\xfe"] as $typed) {
            self::assertSame([['confirm-name', 'problem.project.delete_name', []]], self::problems(fn () => $this->delete(null, $typed)), $typed);
        }
        $this->assertThrows(ConfirmationRequired::class, fn () => $this->delete(null, 'Example Garden', 2));
        self::assertDirectoryExists($this->folder());
        self::assertSame('active', $this->state());
        self::assertNotContains(AuditAction::PROJECT_DELETED, $this->auditActions());

        $this->delete(null, "  example GARDEN \t");
        self::assertFileDoesNotExist($this->folder());
    }

    // ------------------------------------------------------------ T-ADM-15

    public function testALinkedProjectFolderIsRefusedAndWhatItPointsToIsUntouched(): void
    {
        $this->ops->close();
        $outside = $this->fx->temp->path('outside-project');
        rename($this->folder(), $outside);
        symlink($outside, $this->folder());
        $before = self::tree($outside);
        $this->captureLog();

        self::assertSame([[null, 'problem.project.delete_failed', []]], self::problems(fn () => $this->delete()));

        self::assertSame($before, self::tree($outside));
        self::assertTrue(is_link($this->folder()));
        self::assertSame('active', $this->state());
        self::assertFileDoesNotExist($this->deletedEntry());
        self::assertNotContains(AuditAction::PROJECT_DELETED, $this->auditActions());
    }

    // ------------------------------------------------------------ T-ADM-16

    public function testALinkInsideTheFolderIsRemovedAndItsTargetKept(): void
    {
        $outside = $this->fx->temp->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'synthetic');
        symlink($outside, $this->folder() . '/attachments/elsewhere');
        symlink($outside . '/keep.txt', $this->folder() . '/keep-link');

        $this->delete();

        self::assertFileDoesNotExist($this->folder());
        self::assertFileDoesNotExist($this->deletedEntry());
        self::assertSame(['keep.txt'], self::tree($outside));
        self::assertSame('synthetic', file_get_contents($outside . '/keep.txt'));
    }

    // ------------------------------------------------------------ T-ADM-17

    public function testADeletionWhileAnotherProcessUsesTheProjectIsBusy(): void
    {
        $this->ops->close();
        $before = self::tree($this->folder());
        $holder = LockHolder::start('shared', $this->folder());
        $this->holders[] = $holder;
        self::assertSame('held', $holder->answer);

        $this->assertThrows(Busy::class, fn () => $this->delete());

        self::assertSame('active', $this->state());
        self::assertSame($before, self::tree($this->folder()));
        self::assertFileDoesNotExist($this->deletedEntry());
        self::assertSame('Example Garden', $this->ops->projects()->get($this->admin, $this->garden->key)->name);
        $holder->stop();
        $this->delete();
        self::assertFileDoesNotExist($this->folder());
    }

    public function testADeletionRefusesWhereLocksDoNotWork(): void
    {
        $this->ops->close();
        unlink($this->folder() . '/project.lock');
        symlink($this->fx->temp->path('elsewhere.lock'), $this->folder() . '/project.lock');
        $this->captureLog();

        self::assertSame([[null, 'problem.lock.unavailable', []]], self::problems(fn () => $this->delete()));

        self::assertSame('active', $this->state());
        self::assertDirectoryExists($this->folder());
        self::assertFileDoesNotExist($this->fx->temp->path('elsewhere.lock'));
    }

    // ------------------------------------------------------------ T-ADM-18 and T-ADM-19

    public function testWhileMarkedTheProjectIsFoundByNobodyAndTheDeletionGoesOn(): void
    {
        $seen = [];
        $watching = $this->fx->operations(null, null, function (string $at) use (&$seen): void {
            if ($at !== 'delete.marked') {
                return;
            }
            $other = $this->fx->operations();
            foreach ([$this->admin, $this->member] as $actor) {
                try {
                    $other->projects()->get($actor, $this->garden->key);
                    $seen[] = 'found';
                } catch (NotFound) {
                    $seen[] = 'not found';
                }
                $seen[] = array_map(static fn (ProjectSummary $s): string => $s->project->key, $other->projects()->listFor($actor));
            }
            $other->close();
        });
        $this->ops->close();

        $this->delete($watching);

        self::assertSame(['not found', [$this->kitchen->key], 'not found', [$this->kitchen->key]], $seen);
        self::assertFileDoesNotExist($this->folder());
    }

    public function testADeletionInterruptedAfterMarkingIsFinishedByTheNextCreate(): void
    {
        $this->interrupt('delete.marked');
        self::assertSame('deleting', $this->state());
        self::assertDirectoryExists($this->folder());
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->get($this->admin, $this->garden->key));

        $this->ops->projects()->create($this->admin, 'Example Shed', null);

        self::assertNull($this->state());
        self::assertFileDoesNotExist($this->folder());
        self::assertFileDoesNotExist($this->deletedEntry());
        self::assertSame(0, $this->grants($this->garden->id));
        self::assertDirectoryExists($this->folder($this->kitchen));
    }

    public function testADeletionInterruptedAfterTheMoveIsFinishedAndAHeldEntryWaits(): void
    {
        $this->interrupt('delete.moved');
        self::assertSame('deleting', $this->state());
        self::assertFileDoesNotExist($this->folder());
        self::assertDirectoryExists($this->deletedEntry());
        $holder = LockHolder::start('flock', $this->deletedEntry() . '/project.lock');
        $this->holders[] = $holder;
        self::assertSame('held', $holder->answer);

        $this->ops->projects()->create($this->admin, 'Example Shed', null);
        self::assertNull($this->state(), 'The row goes.');
        $this->ops->exports()->export($this->admin, $this->kitchen->key, 'export/csv', $this->pans, []);
        self::assertDirectoryExists($this->deletedEntry(), 'An entry whose lock is held is skipped by the staging sweep.');

        $holder->stop();
        $this->ops->exports()->export($this->admin, $this->kitchen->key, 'export/csv', $this->pans, []);
        self::assertFileDoesNotExist($this->deletedEntry(), 'Removed once released.');
        self::assertNotContains(AuditAction::PROJECT_DELETED, $this->auditActions(), 'The sweep writes no audit row.');
    }

    /** Runs a deletion that fails at a seam point, then ends that request. */
    private function interrupt(string $point): void
    {
        $failing = $this->fx->operations(null, null, static function (string $at) use ($point): void {
            if ($at === $point) {
                throw new \RuntimeException('Injected failure at ' . $at . '.');
            }
        });
        $this->ops->close();
        try {
            $this->delete($failing);
            self::fail('The injected failure was not reported.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected failure at ' . $point . '.', $error->getMessage());
        }
        $failing->close();
    }

    // ------------------------------------------------------------ T-ADM-22 (P239)

    public function testAProjectWhoseFolderIsMissingCanStillBeDeleted(): void
    {
        $this->ops->close();
        self::removeTree($this->folder());

        $screen = $this->ops->projects()->deleteScreen($this->admin, $this->garden->key);
        self::assertSame([0, 0, 0, 0, 0, 1, true], [$screen->records, $screen->trashed, $screen->files, $screen->fileBytes, $screen->snapshots, $screen->members, $screen->folderMissing]);
        self::assertFileDoesNotExist($this->folder(), 'The project is not opened: nothing is made.');

        $deleted = $this->delete(null, 'Example Garden', 0);

        self::assertSame(['Example Garden', 0, 0, 0, 0], [$deleted->name, $deleted->records, $deleted->trashed, $deleted->files, $deleted->snapshots]);
        self::assertNull($this->state());
        self::assertSame(0, $this->grants($this->garden->id));
        self::assertSame(['name' => 'Example Garden', 'records' => 0, 'trashed' => 0, 'files' => 0, 'snapshots' => 0], $this->lastAudit(AuditAction::PROJECT_DELETED)->detail);
        self::assertFileDoesNotExist($this->folder());
        self::assertFileDoesNotExist($this->deletedEntry(), 'No staging entry is made.');
    }

    public function testADanglingLinkAtTheFolderPathIsNotMissing(): void
    {
        $this->ops->close();
        self::removeTree($this->folder());
        symlink($this->fx->temp->path('nothing-here'), $this->folder());
        $this->captureLog();

        self::assertFalse($this->ops->projects()->deleteScreen($this->admin, $this->garden->key)->folderMissing);
        self::assertSame([[null, 'problem.project.delete_failed', []]], self::problems(fn () => $this->delete(null, 'Example Garden', 0)));

        self::assertSame('active', $this->state());
        self::assertTrue(is_link($this->folder()));
        self::assertFileDoesNotExist($this->fx->temp->path('nothing-here'));
    }

    public function testAFileAtTheFolderPathIsRefused(): void
    {
        $this->ops->close();
        self::removeTree($this->folder());
        file_put_contents($this->folder(), 'synthetic');
        $this->captureLog();

        self::assertSame([[null, 'problem.project.delete_failed', []]], self::problems(fn () => $this->delete(null, 'Example Garden', 0)));
        self::assertSame('active', $this->state());
        self::assertSame('synthetic', file_get_contents($this->folder()));
    }

    // ------------------------------------------------------------ T-ADM-23 (P238)

    public function testTheStagingSweepOfAnExportFinishesAnInterruptedDeletion(): void
    {
        $this->interrupt('delete.marked');
        self::assertSame('deleting', $this->state());

        $this->ops->exports()->export($this->admin, $this->kitchen->key, 'export/csv', $this->pans, []);

        self::assertNull($this->state());
        self::assertFileDoesNotExist($this->folder());
        self::assertFileDoesNotExist($this->deletedEntry());
    }

    public function testASweepFailureIsLoggedAndTheExportGoesOn(): void
    {
        $this->interrupt('delete.marked');
        mkdir($this->fx->data->path() . '/staging/deleted', 0750, true);
        file_put_contents($this->deletedEntry(), 'synthetic: something already at the staging path');
        $this->captureLog();

        $file = $this->ops->exports()->export($this->admin, $this->kitchen->key, 'export/csv', $this->pans, []);

        self::assertFileExists($file->path);
        self::assertSame('deleting', $this->state(), 'The deletion waits for the next sweep.');
        self::assertDirectoryExists($this->folder(), 'Nothing was moved.');
        self::assertStringContainsString('interrupted project deletion could not be finished yet', $this->errorLog());
        self::assertStringNotContainsString($this->garden->key, $this->errorLog(), 'No key or path is logged.');

        unlink($this->deletedEntry());
        $this->ops->exports()->export($this->admin, $this->kitchen->key, 'export/csv', $this->pans, []);
        self::assertNull($this->state());
    }
}
