<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\App\ProjectOpener;
use TiffinDB\Core\App\RestoreResult;
use TiffinDB\Core\App\RestoreScreen;
use TiffinDB\Core\App\SnapshotOperations;
use TiffinDB\Core\App\SnapshotScreen;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\LockHolder;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-ADM-5 to T-ADM-12 (M7 spec sections 5 and 16.1): snapshot restore,
 * with failure injection at each step, hostile snapshot ids, damaged and
 * foreign snapshots, a second process using the project, and a stale WAL
 * file. Records are written through the operations (never through a
 * connection left open), so the live file's bytes can be compared.
 */
#[CoversClass(SnapshotOperations::class)]
#[CoversClass(SnapshotScreen::class)]
#[CoversClass(RestoreScreen::class)]
#[CoversClass(RestoreResult::class)]
#[CoversClass(ProjectOpener::class)]
final class SnapshotRestoreTest extends AppTestCase
{
    private string $p;
    private string $table;
    private string $name;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        parent::setUp();
        ProjectLock::$waitScaleForTests = 0.02;
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants');
        $this->p = $project->key;
        $design = $this->ops->schema()->design($this->admin, $this->p, $this->ops->schema()->tables($this->admin, $this->p)[0]->table->key);
        $this->table = $design->table->key;
        $this->name = $design->fields[0]->field->key;
        $this->ops->projects()->setSharing($this->admin, $this->p, [$this->member->userKey]);
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        ProjectLock::$waitScaleForTests = 1.0;
        ProjectLock::$flockDisabledForTests = false;
        parent::tearDown();
    }

    private function add(string $name, ?Operations $ops = null): int
    {
        return ($ops ?? $this->ops)->records()->create($this->admin, $this->p, $this->table, [$this->name => $name]);
    }

    private function live(): string
    {
        return $this->fx->projectFolder($this->p) . '/' . ProjectOpener::FILE;
    }

    /** Runs SQL on the live file through a connection closed at once. */
    private function sql(string $statement, array $params = []): void
    {
        $db = Sqlite::open($this->live());
        try {
            $db->run($statement, $params);
        } finally {
            $db->close();
        }
    }

    /** @return list<string> live plant names by id, read through a fresh connection */
    private function names(?string $path = null): array
    {
        $db = $path === null ? Sqlite::open($this->live()) : Sqlite::openUntrusted($path);
        try {
            return array_map(static fn (array $r): string => (string) $r['n'], $db->all('SELECT "f_' . $this->name . '" AS n FROM "t_' . $this->table . '" WHERE "_trashed_at" IS NULL ORDER BY "id"'));
        } finally {
            $db->close();
        }
    }

    private function meta(string $key): ?string
    {
        $db = Sqlite::open($this->live());
        try {
            $value = $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = ?', [$key]);

            return $value === null ? null : (string) $value;
        } finally {
            $db->close();
        }
    }

    private static function id(SnapshotInfo $snapshot): string
    {
        return substr($snapshot->file, 0, -strlen('.tiffin'));
    }

    private function snapshotPath(SnapshotInfo $snapshot): string
    {
        return $this->fx->projectFolder($this->p) . '/snapshots/' . $snapshot->file;
    }

    /** @return list<SnapshotInfo> */
    private function snapshotsOf(SnapshotReason $reason): array
    {
        return array_values(array_filter($this->ops->snapshots()->list($this->admin, $this->p), static fn (SnapshotInfo $s): bool => $s->reason === $reason));
    }

    /** @return list<string> entries of staging/restores */
    private function restoreJobs(): array
    {
        $folder = $this->fx->data->path() . '/staging/restores';

        return is_dir($folder) ? array_values(array_diff((array) scandir($folder), ['.', '..'])) : [];
    }

    // ------------------------------------------------------------ T-ADM-5

    public function testARestoreBringsBackTheSnapshotAfterAPreRestoreSnapshot(): void
    {
        foreach (['Example Basil', 'Example Thyme', 'Example Sage'] as $plant) {
            $this->add($plant);
        }
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Mint');
        $this->add('Example Dill');
        $this->sql('UPDATE "t_' . $this->table . '" SET "f_' . $this->name . '" = ? WHERE "id" = 1', ['Example Lovage']);
        $this->ops->projects()->rename($this->admin, $this->p, 'Example Allotment');
        $this->fx->clock->advance(60);

        $screen = $this->ops->snapshots()->restoreScreen($this->admin, $this->p, self::id($manual));
        self::assertSame([5, 3, ProjectFile::VERSION, 0, 0], [$screen->recordsNow, $screen->recordsThen, $screen->formatVersion, $screen->filesMissing, $screen->filesRemoved]);
        self::assertSame($manual->file, $screen->snapshot->file);
        self::assertSame([], $this->restoreJobs(), 'The screen leaves no working copy.');

        $result = $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual));

        self::assertSame(['Example Basil', 'Example Thyme', 'Example Sage'], $this->names());
        self::assertSame($manual->file, $result->restored->file);
        self::assertSame(SnapshotReason::PreRestore, $result->preRestore->reason);
        self::assertNull($result->upgradedFrom);
        self::assertSame(['Example Lovage', 'Example Thyme', 'Example Sage', 'Example Mint', 'Example Dill'], $this->names($this->snapshotPath($result->preRestore)), 'The pre-restore snapshot holds the state just before.');
        self::assertSame('Example Allotment', $this->meta('project_name'), 'The registry name wins.');
        self::assertSame($this->admin->userKey, $this->meta('updated_by'));
        self::assertSame(Timestamp::format($this->fx->clock->now()), $this->meta('updated_at'));
        self::assertSame([], $this->restoreJobs());
        self::assertFileDoesNotExist($this->fx->projectFolder($this->p) . '/' . SnapshotOperations::RESTORING);

        $entry = $this->lastAudit(AuditAction::SNAPSHOT_RESTORED);
        self::assertSame(['snapshot', $manual->file, ['pre_restore' => $result->preRestore->file, 'files_missing' => 0, 'files_removed' => 0, 'from_version' => ProjectFile::VERSION]], [$entry->targetKind, $entry->targetKey, $entry->detail]);
        $taken = $this->lastAudit(AuditAction::SNAPSHOT_TAKEN);
        self::assertSame([$result->preRestore->file, ['reason' => 'pre-restore']], [$taken->targetKey, $taken->detail]);

        // The project works as before: the same operations write to the restored file.
        $this->add('Example Chive');
        self::assertSame(['Example Basil', 'Example Thyme', 'Example Sage', 'Example Chive'], $this->names());
        self::assertSame('Example Allotment', $this->ops->projects()->get($this->admin, $this->p)->name);

        // The restore itself can be undone by restoring the pre-restore snapshot.
        $this->ops->snapshots()->restore($this->admin, $this->p, self::id($result->preRestore));
        self::assertSame(['Example Lovage', 'Example Thyme', 'Example Sage', 'Example Mint', 'Example Dill'], $this->names());
    }

    public function testTheScreenListsSnapshotsAndSaysWhetherLocksWork(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $screen = $this->ops->snapshots()->screen($this->admin, $this->p);
        self::assertSame($this->p, $screen->project->key);
        self::assertSame([$manual->file], array_map(static fn (SnapshotInfo $s): string => $s->file, $screen->snapshots));
        self::assertTrue($screen->canRestore);
    }

    // ------------------------------------------------------------ T-ADM-6

    public function testAnOlderFormatSnapshotIsUpgradedAfterItsOwnSnapshot(): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Thyme');
        // A version 3 file, as M3 wrote it: no collections table (docs/formats/tiffin.md section 11.3).
        $pdo = new \PDO('sqlite:' . $this->snapshotPath($manual));
        $pdo->exec('DROP TABLE "_tiffin_collections"');
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->exec('UPDATE "_tiffin_meta" SET "value" = \'3\' WHERE "key" = \'format_version\'');
        $pdo = null;

        self::assertSame(3, $this->ops->snapshots()->restoreScreen($this->admin, $this->p, self::id($manual))->formatVersion);
        $result = $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual));

        self::assertSame(3, $result->upgradedFrom);
        self::assertCount(1, $this->snapshotsOf(SnapshotReason::FormatUpgrade));
        self::assertCount(1, $this->snapshotsOf(SnapshotReason::PreRestore));
        self::assertSame((string) ProjectFile::VERSION, $this->meta('format_version'));
        self::assertSame(['Example Basil'], $this->names());
        self::assertSame(3, $this->lastAudit(AuditAction::SNAPSHOT_RESTORED)->detail['from_version']);
    }

    // ------------------------------------------------------------ T-ADM-7

    public function testFilesNoSnapshotRowNamesAreRemovedAndMissingOnesCounted(): void
    {
        $schema = $this->ops->schema();
        $photos = $schema->addField($this->admin, $this->p, $this->table, 'Photos', 'attachment', [], false, '')->key;
        $plant = (string) $this->add('Example Basil');
        $attach = fn (string $label) => $this->ops->attachments()->add($this->admin, $this->p, $this->table, $plant, $photos, $this->fx->upload(FileFixture::png(1, 1, $label)), $label . '.png', null);
        $a = $attach('synthetic-a');
        $b = $attach('synthetic-b');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->ops->attachments()->remove($this->admin, $this->p, $this->table, $plant, $photos, (string) $b->id);
        $c = $attach('synthetic-c');
        $store = fn (string $hash): string => $this->fx->projectFolder($this->p) . '/attachments/' . substr($hash, 0, 2) . '/' . $hash;
        self::assertFileDoesNotExist($store($b->sha256), 'B was removed since the snapshot.');
        self::assertFileExists($store($c->sha256));

        $screen = $this->ops->snapshots()->restoreScreen($this->admin, $this->p, self::id($manual));
        self::assertSame([1, 1], [$screen->filesMissing, $screen->filesRemoved]);
        $result = $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual));

        self::assertSame([$screen->filesMissing, $screen->filesRemoved], [$result->filesMissing, $result->filesRemoved]);
        self::assertFileExists($store($a->sha256));
        self::assertFileDoesNotExist($store($c->sha256), "C's file is deleted: no row of the restored file names it.");
        self::assertFileDoesNotExist($store($b->sha256), 'B is not brought back: snapshots hold no files.');
        self::assertSame(['files_missing' => 1, 'files_removed' => 1], array_intersect_key($this->lastAudit(AuditAction::SNAPSHOT_RESTORED)->detail, ['files_missing' => 0, 'files_removed' => 0]));
    }

    // ------------------------------------------------------------ T-ADM-8

    /** @return iterable<string, array{string, bool}> seam point, whether the swap had happened */
    public static function seams(): iterable
    {
        yield 'checking the copy' => ['restore.check', false];
        yield 'after the pre-restore snapshot' => ['restore.snapshot', false];
        yield 'before the rename' => ['restore.swap', false];
        yield 'after the rename' => ['restore.swapped', true];
        yield 'after reopening' => ['restore.reopen', true];
        yield 'after the file sweep' => ['restore.files', true];
    }

    #[DataProvider('seams')]
    public function testAFailureAtEachStep(string $point, bool $swapped): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Thyme');
        $this->ops->close();
        $before = hash_file('sha256', $this->live());
        $failing = $this->fx->operations(null, null, static function (string $at) use ($point): void {
            if ($at === $point) {
                throw new \RuntimeException('Injected failure at ' . $at . '.');
            }
        });

        try {
            $failing->snapshots()->restore($this->admin, $this->p, self::id($manual));
            self::fail('The injected failure was not reported.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected failure at ' . $point . '.', $error->getMessage());
        }
        $failing->close();

        self::assertFileDoesNotExist($this->fx->projectFolder($this->p) . '/' . SnapshotOperations::RESTORING);
        self::assertSame([], $this->restoreJobs());
        $restored = array_filter($this->auditActions(), static fn (string $a): bool => $a === AuditAction::SNAPSHOT_RESTORED);
        if ($swapped) {
            self::assertSame(['Example Basil'], $this->names(), 'The restore happened.');
            self::assertCount(1, $restored, 'And it is audited.');
        } else {
            self::assertSame($before, hash_file('sha256', $this->live()), 'The live file is unchanged.');
            self::assertSame(['Example Basil', 'Example Thyme'], $this->names());
            self::assertCount(0, $restored);
        }
        // Either way the project still opens and a second attempt succeeds.
        $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual));
        self::assertSame(['Example Basil'], $this->names());
    }

    // ------------------------------------------------------------ T-ADM-9

    /** @return iterable<string, array{string}> */
    public static function damage(): iterable
    {
        yield 'another project key' => ['key'];
        yield 'user_version 0' => ['version0'];
        yield 'user_version 5' => ['version5'];
        yield 'format_version different from user_version' => ['meta-version'];
        yield 'WAL header bytes' => ['wal'];
        yield 'a truncated file' => ['truncated'];
        yield 'not an SQLite file' => ['text'];
        yield 'another application id' => ['application'];
        yield 'an empty file' => ['empty'];
    }

    #[DataProvider('damage')]
    public function testADamagedOrForeignSnapshotIsRefused(string $kind): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Thyme');
        $path = $this->snapshotPath($manual);
        $edit = static function (string $sql) use ($path): void {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec($sql);
            $pdo = null;
        };
        match ($kind) {
            'key' => $edit('UPDATE "_tiffin_meta" SET "value" = \'b7xk2mq4nz3p\' WHERE "key" = \'project_key\''),
            'version0' => $edit('PRAGMA user_version = 0'),
            'version5' => $edit('PRAGMA user_version = 5'),
            'meta-version' => $edit('UPDATE "_tiffin_meta" SET "value" = \'3\' WHERE "key" = \'format_version\''),
            'application' => $edit('PRAGMA application_id = 7'),
            'wal' => (static function () use ($path): void {
                $bytes = (string) file_get_contents($path);
                file_put_contents($path, substr_replace($bytes, "\x02\x02", 18, 2));
            })(),
            'truncated' => file_put_contents($path, substr((string) file_get_contents($path), 0, 3000)),
            'text' => file_put_contents($path, 'Synthetic text that is not a database at all, long enough to have a header.'),
            'empty' => file_put_contents($path, ''),
        };
        $this->ops->close();
        $before = hash_file('sha256', $this->live());

        foreach (['restoreScreen', 'restore'] as $method) {
            self::assertSame([[null, 'problem.snapshot.unreadable', []]], self::problems(fn () => $this->ops->snapshots()->{$method}($this->admin, $this->p, self::id($manual))), $method);
            self::assertSame([], $this->restoreJobs(), 'The working copy is removed.');
        }
        $this->ops->close();
        self::assertSame($before, hash_file('sha256', $this->live()));
        self::assertSame([], $this->snapshotsOf(SnapshotReason::PreRestore), 'Nothing else happened.');
        self::assertNotContains(AuditAction::SNAPSHOT_RESTORED, $this->auditActions());
    }

    // ------------------------------------------------------------ T-ADM-10

    public function testSnapshotIdsThatNameNoListedSnapshotAreNotFound(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $folder = $this->fx->projectFolder($this->p) . '/snapshots';
        copy($folder . '/' . $manual->file, $folder . '/20261112T031500Z-unknown-abcd.tiffin');
        $outside = $this->fx->temp->path('outside.tiffin');
        copy($folder . '/' . $manual->file, $outside);
        symlink($outside, $folder . '/20261112T031500Z-manual-zzzz.tiffin');
        $ids = [
            '../x', '../../system', '20261112T031500Z-unknown-abcd', self::id($manual) . '.tiffin', $manual->file,
            $folder . '/' . self::id($manual), '/' . self::id($manual), '20261112T031500Z-manual-zzzz', '', self::id($manual) . "\0",
            'snapshots/' . self::id($manual), strtoupper(self::id($manual)),
        ];
        foreach ($ids as $id) {
            foreach (['restoreScreen', 'restore'] as $method) {
                $this->assertThrows(NotFound::class, fn () => $this->ops->snapshots()->{$method}($this->admin, $this->p, $id));
            }
        }
        self::assertSame([], $this->restoreJobs());
        self::assertSame([], $this->snapshotsOf(SnapshotReason::PreRestore));
    }

    // ------------------------------------------------------------ T-ADM-11

    public function testARestoreWhileAnotherProcessUsesTheProjectIsBusy(): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Thyme');
        $this->ops->close();
        $before = hash_file('sha256', $this->live());
        $holder = LockHolder::start('shared', $this->fx->projectFolder($this->p));
        $this->holders[] = $holder;
        self::assertSame('held', $holder->answer);

        $this->assertThrows(Busy::class, fn () => $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual)));
        $this->ops->close();
        self::assertSame($before, hash_file('sha256', $this->live()));
        self::assertSame([], $this->snapshotsOf(SnapshotReason::PreRestore), 'No pre-restore snapshot.');
        self::assertSame([], $this->restoreJobs());

        $holder->stop();
        $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual));
        self::assertSame(['Example Basil'], $this->names());
    }

    public function testARestoreRefusesWhereLocksDoNotWork(): void
    {
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->ops->close();
        $lock = $this->fx->projectFolder($this->p) . '/project.lock';
        unlink($lock);
        symlink($this->fx->temp->path('elsewhere.lock'), $lock);
        $this->captureLog();

        self::assertFalse($this->ops->snapshots()->screen($this->admin, $this->p)->canRestore);
        self::assertSame([[null, 'problem.lock.unavailable', []]], self::problems(fn () => $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual))));
        self::assertFileDoesNotExist($this->fx->temp->path('elsewhere.lock'));
        self::assertSame([], $this->restoreJobs());
        self::assertSame([], $this->snapshotsOf(SnapshotReason::PreRestore));
        self::assertSame(1, substr_count($this->errorLog(), 'cannot lock files'), 'Logged once.');
    }

    // ------------------------------------------------------------ T-ADM-12

    public function testAStaleWalFileBesideTheLiveFileIsRemoved(): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->add('Example Thyme');
        $this->ops->close();
        $live = $this->live();
        $planting = $this->fx->operations(null, null, static function (string $at) use ($live): void {
            if ($at === 'restore.swap') {
                file_put_contents($live . '-wal', str_repeat("\x37", 4096));
                file_put_contents($live . '-shm', str_repeat("\x00", 4096));
            }
        });

        $planting->snapshots()->restore($this->admin, $this->p, self::id($manual));
        $planting->close();

        self::assertSame(['Example Basil'], $this->names());
        $db = Sqlite::open($live);
        self::assertSame('ok', $db->value('PRAGMA integrity_check'));
        $db->close();
        self::assertFalse(is_file($live . '-wal') && file_get_contents($live . '-wal') === str_repeat("\x37", 4096), 'The planted WAL file was removed.');
    }

    public function testWithFlockDisabledTheProjectWorksButARestoreRefuses(): void
    {
        $this->add('Example Basil');
        $manual = $this->ops->snapshots()->take($this->admin, $this->p);
        $this->ops->close();
        ProjectLock::$flockDisabledForTests = true;
        $this->captureLog();

        $this->add('Example Thyme');
        self::assertFalse($this->ops->snapshots()->screen($this->admin, $this->p)->canRestore);
        self::assertSame([[null, 'problem.lock.unavailable', []]], self::problems(fn () => $this->ops->snapshots()->restore($this->admin, $this->p, self::id($manual))));
        self::assertSame(['Example Basil', 'Example Thyme'], $this->names());
        self::assertSame([], $this->snapshotsOf(SnapshotReason::PreRestore));
        self::assertSame([], $this->restoreJobs());
    }
}
