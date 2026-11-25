<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\AuditOperations;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\App\SnapshotOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Tests\Support\LockHolder;

require_once __DIR__ . '/AppTestCase.php';

/**
 * Independent review of M7-WP1 (design-tier security review): every
 * public Actor-taking method refuses a Member and an ungranted person
 * with nothing written (found by reflection, not a hand list); a second
 * process asking for a shared lock is kept out during each step of a
 * restore; the audit filters treat SQL and LIKE text as plain values;
 * a snapshot copied from another project is refused; a linked staging
 * folder is never moved into; hostile project keys are NotFound.
 * Synthetic data only.
 */
#[CoversClass(SnapshotOperations::class)]
#[CoversClass(ProjectOperations::class)]
#[CoversClass(AuditOperations::class)]
final class M7Wp1ReviewTest extends AppTestCase
{
    private ProjectRecord $garden;
    private ProjectRecord $kitchen;
    private string $table;
    private string $name;
    private SnapshotInfo $manual;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        parent::setUp();
        ProjectLock::$waitScaleForTests = 0.02;
        $projects = $this->ops->projects();
        $this->garden = $projects->create($this->admin, 'Example Garden', 'Plants');
        $this->kitchen = $projects->create($this->admin, 'Example Kitchen', 'Pans');
        $projects->setSharing($this->admin, $this->garden->key, [$this->member->userKey]);
        $design = $this->ops->schema()->design($this->admin, $this->garden->key, $this->ops->schema()->tables($this->admin, $this->garden->key)[0]->table->key);
        $this->table = $design->table->key;
        $this->name = $design->fields[0]->field->key;
        $this->ops->records()->create($this->admin, $this->garden->key, $this->table, [$this->name => 'Example Basil']);
        $this->manual = $this->ops->snapshots()->take($this->admin, $this->garden->key);
        $this->ops->records()->create($this->admin, $this->garden->key, $this->table, [$this->name => 'Example Thyme']);
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        ProjectLock::$waitScaleForTests = 1.0;
        parent::tearDown();
    }

    private function id(): string
    {
        return substr($this->manual->file, 0, -strlen('.tiffin'));
    }

    /** @return list<array{string, string}> class short name and method of every public method whose first parameter is an Actor */
    private static function actorMethods(): array
    {
        $found = [];
        foreach ([SnapshotOperations::class, ProjectOperations::class, AuditOperations::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $first = $method->getParameters()[0] ?? null;
                if ($first !== null && (string) $first->getType() === Actor::class) {
                    $found[] = [$class, $method->getName()];
                }
            }
        }

        return $found;
    }

    /** Arguments for each method after the actor (and project key where it takes one). */
    private function argsFor(string $method, string $projectKey): array
    {
        return match ($method) {
            'restoreScreen', 'restore' => [$projectKey, $this->id()],
            'delete' => [$projectKey, 'Example Garden', 10],
            'rename' => [$projectKey, 'Example Renamed'],
            'setSharing' => [$projectKey, []],
            'create' => ['Example New', null],
            'listFor' => [],
            'recent' => [10, 0],
            'page' => [[]],
            default => [$projectKey],
        };
    }

    // ---------------------------------------------------- authorization by reflection

    public function testEveryNewAdminMethodRefusesAMemberAndAnUngrantedPersonWithNothingChanged(): void
    {
        $new = ['screen', 'restoreScreen', 'restore', 'deleteScreen', 'delete', 'page'];
        $covered = [];
        $stranger = $this->member2;
        foreach (self::actorMethods() as [$class, $method]) {
            if (!in_array($method, $new, true)) {
                continue;
            }
            $covered[] = $method;
            $target = match ($class) {
                SnapshotOperations::class => $this->ops->snapshots(),
                ProjectOperations::class => $this->ops->projects(),
                AuditOperations::class => $this->ops->audit(),
            };
            $this->ops->close();
            $before = $this->fx->systemRows();
            $file = hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin');
            $this->assertThrows(AccessDenied::class, fn () => $target->{$method}($this->member, ...$this->argsFor($method, $this->garden->key)));
            $expected = $method === 'page' ? AccessDenied::class : NotFound::class;
            $this->assertThrows($expected, fn () => $target->{$method}($stranger, ...$this->argsFor($method, $this->garden->key)));
            self::assertSame($before, $this->fx->systemRows(), $method . ': nothing written');
            $this->ops->close();
            self::assertSame($file, hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin'), $method);
        }
        self::assertEqualsCanonicalizing($new, $covered);
        // Refused before the name or the record count is looked at, so neither leaks.
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->delete($this->member, $this->garden->key, 'Example Garden', 0));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->delete($this->member, $this->garden->key, 'Not The Name', 10));
        self::assertDirectoryExists($this->fx->projectFolder($this->garden->key));
        self::assertFileDoesNotExist($this->fx->data->path() . '/staging/restores');
    }

    /** @return iterable<string, array{string}> */
    public static function hostileKeys(): iterable
    {
        foreach (['../' . 'x', '..', '.', '/etc', 'staging', 'k2xq7vn4mz3t/..', "k2xq7vn4mz3t\0", '%', ''] as $key) {
            yield bin2hex($key) => [$key];
        }
    }

    #[DataProvider('hostileKeys')]
    public function testHostileProjectKeysAreNotFound(string $key): void
    {
        $before = $this->fx->systemRows();
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->delete($this->admin, $key, 'Example Garden', 10));
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->deleteScreen($this->admin, $key));
        $this->assertThrows(NotFound::class, fn () => $this->ops->snapshots()->restore($this->admin, $key, $this->id()));
        self::assertSame($before, $this->fx->systemRows());
    }

    // ---------------------------------------------------- restore and other processes

    /** @return iterable<string, array{string}> */
    public static function restoreSteps(): iterable
    {
        foreach (['restore.snapshot', 'restore.swap'] as $point) {
            yield $point => [$point];
        }
    }

    #[DataProvider('restoreSteps')]
    public function testAnotherProcessCannotGetASharedLockWhileTheFileIsReplaced(string $point): void
    {
        $this->ops->close();
        $answers = [];
        $folder = $this->fx->projectFolder($this->garden->key);
        $watching = $this->fx->operations(null, null, static function (string $at) use ($point, $folder, &$answers): void {
            if ($at === $point) {
                $holder = LockHolder::start('shared', $folder);
                $answers[] = $holder->answer;
                $holder->stop();
            }
        });

        $watching->snapshots()->restore($this->admin, $this->garden->key, $this->id());
        $watching->close();

        self::assertSame(['busy'], $answers);
        $after = LockHolder::start('shared', $folder);
        $this->holders[] = $after;
        self::assertSame('held', $after->answer, 'Released after the restore.');
    }

    public function testASnapshotCopiedFromAnotherProjectIsRefused(): void
    {
        $foreign = $this->ops->snapshots()->take($this->admin, $this->kitchen->key);
        $this->ops->close();
        $own = $this->fx->projectFolder($this->garden->key) . '/snapshots';
        $name = '20991231T235959Z-manual-zzzz.tiffin';
        copy($this->fx->projectFolder($this->kitchen->key) . '/snapshots/' . $foreign->file, $own . '/' . $name);
        $live = hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin');

        foreach (['restoreScreen', 'restore'] as $method) {
            self::assertSame([[null, 'problem.snapshot.unreadable', []]], self::problems(fn () => $this->ops->snapshots()->{$method}($this->admin, $this->garden->key, substr($name, 0, -7))));
        }
        $this->ops->close();
        self::assertSame($live, hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin'));
        self::assertNotContains(AuditAction::SNAPSHOT_RESTORED, $this->auditActions());
    }

    /** @return iterable<string, array{string}> */
    public static function damagedButReadable(): iterable
    {
        yield 'an index that disagrees with its table' => ['index'];
        yield 'a newer version in both pragma and meta' => ['newer'];
    }

    /** Damage the cheaper checks do not see: only the integrity check or the version bound refuses it. */
    #[DataProvider('damagedButReadable')]
    public function testDamageOnlyTheDeeperChecksSeeIsRefused(string $kind): void
    {
        $this->ops->close();
        $path = $this->fx->projectFolder($this->garden->key) . '/snapshots/' . $this->manual->file;
        $pdo = new \PDO('sqlite:' . $path);
        if ($kind === 'index') {
            $pdo->exec('CREATE TABLE "review_extra" ("x" TEXT); CREATE INDEX "review_extra_x" ON "review_extra" ("x")');
            for ($i = 0; $i < 50; $i++) {
                $pdo->exec("INSERT INTO \"review_extra\" VALUES ('synthetic-" . $i . "')");
            }
            $root = (int) $pdo->query("SELECT rootpage FROM sqlite_master WHERE name = 'review_extra'")->fetchColumn();
            $pdo->exec('PRAGMA writable_schema = ON');
            $pdo->exec("UPDATE sqlite_master SET rootpage = " . $root . " WHERE name = 'review_extra_x'");
        } else {
            $pdo->exec('PRAGMA user_version = 99');
            $pdo->exec("UPDATE \"_tiffin_meta\" SET \"value\" = '99' WHERE \"key\" = 'format_version'");
        }
        $pdo = null;
        $live = hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin');

        self::assertSame([[null, 'problem.snapshot.unreadable', []]], self::problems(fn () => $this->ops->snapshots()->restore($this->admin, $this->garden->key, $this->id())));
        $this->ops->close();
        self::assertSame($live, hash_file('sha256', $this->fx->projectFolder($this->garden->key) . '/project.tiffin'));
    }

    /** A real WAL file of the old live file, planted after the checkpoint, must not be applied to the restored file. */
    public function testAValidStaleWalOfTheOldFileIsNotApplied(): void
    {
        $this->ops->close();
        $live = $this->fx->projectFolder($this->garden->key) . '/project.tiffin';
        $saved = $this->fx->temp->path('saved-wal');
        $pdo = new \PDO('sqlite:' . $live);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA wal_autocheckpoint = 0');
        $pdo->exec('UPDATE "t_' . $this->table . '" SET "f_' . $this->name . '" = \'Example Stale\'');
        copy($live . '-wal', $saved);
        $pdo = null;
        $planting = $this->fx->operations(null, null, static function (string $at) use ($live, $saved): void {
            if ($at === 'restore.swap') {
                copy($saved, $live . '-wal');
            }
        });

        $planting->snapshots()->restore($this->admin, $this->garden->key, $this->id());
        $planting->close();

        $db = new \PDO('sqlite:' . $live);
        $names = $db->query('SELECT "f_' . $this->name . '" FROM "t_' . $this->table . '" WHERE "_trashed_at" IS NULL ORDER BY "id"')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Example Basil'], $names);
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
    }

    public function testARestoreIsAuditedExactlyOnce(): void
    {
        $this->ops->snapshots()->restore($this->admin, $this->garden->key, $this->id());
        $rows = array_values(array_filter($this->auditEntries(), static fn ($e): bool => $e->action === AuditAction::SNAPSHOT_RESTORED));
        self::assertCount(1, $rows);
        self::assertSame($this->manual->file, $rows[0]->targetKey);
    }

    // ---------------------------------------------------- deletion

    public function testALinkedDeletedStagingFolderIsNeverMovedInto(): void
    {
        $this->ops->close();
        $outside = $this->fx->temp->path('outside-deleted');
        mkdir($outside);
        $staging = $this->fx->data->path() . '/staging';
        if (!is_dir($staging)) {
            mkdir($staging, 0750);
        }
        if (is_dir($staging . '/deleted')) {
            rmdir($staging . '/deleted');
        }
        symlink($outside, $staging . '/deleted');
        $this->captureLog();

        self::assertSame([[null, 'problem.project.delete_failed', []]], self::problems(fn () => $this->ops->projects()->delete($this->admin, $this->garden->key, 'Example Garden', 10)));

        self::assertSame([], array_values(array_diff((array) scandir($outside), ['.', '..'])));
        self::assertDirectoryExists($this->fx->projectFolder($this->garden->key));
        self::assertSame('active', $this->fx->system->db()->value('SELECT state FROM projects WHERE key = ?', [$this->garden->key]));
    }

    /**
     * A staging sweep (another request's export) that runs while a live
     * deletion is between marking and locking finishes that deletion; the
     * live request must still report success and write its one
     * `project.deleted` row (the sweep writes none).
     */
    public function testASweepRacingALiveDeletionLeavesExactlyOneAuditRow(): void
    {
        $this->ops->close();
        $racing = $this->fx->operations(null, null, function (string $at): void {
            if ($at === 'delete.marked') {
                $other = $this->fx->operations();
                $other->projects()->clearAbandoned();
                $other->close();
            }
        });

        try {
            $racing->projects()->delete($this->admin, $this->garden->key, 'Example Garden', 10);
        } catch (\Throwable) {
            // Reported below through the audit count.
        }
        $racing->close();

        self::assertNull($this->fx->system->db()->value('SELECT state FROM projects WHERE key = ?', [$this->garden->key]), 'The project is gone.');
        self::assertCount(1, array_filter($this->auditActions(), static fn (string $a): bool => $a === AuditAction::PROJECT_DELETED), 'A deletion that happened is audited once.');
    }

    // ---------------------------------------------------- audit filters

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function hostileFilters(): iterable
    {
        $values = ["' OR 1=1 --", '%', '_', '*', "project.%", "snapshot.restored' OR '1'='1", '1 OR 1=1', "\0", 'DELETED', ' deleted'];
        foreach ($values as $value) {
            foreach (['person', 'project', 'action'] as $filter) {
                yield $filter . ' ' . bin2hex($value) => [[$filter => $value]];
            }
        }
        foreach (['0', '-1', '1e2', ' 2', '2 ', '0x2', '9999999', '1.5'] as $page) {
            yield 'page ' . bin2hex($page) => [['page' => $page]];
        }
        yield 'arrays' => [['person' => ['x'], 'project' => ['deleted'], 'action' => [AuditAction::PROJECT_DELETED], 'page' => ['2']]];
        yield 'ints' => [['person' => 1, 'project' => 1, 'page' => 2]];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('hostileFilters')]
    public function testHostileAuditFilterValuesAreIgnored(array $query): void
    {
        $all = $this->ops->audit()->page($this->admin, []);
        $page = $this->ops->audit()->page($this->admin, $query);
        self::assertSame([$all->total, 1, null, null, null], [$page->total, $page->page, $page->person, $page->project, $page->action]);
        self::assertSame(array_map(static fn ($r): int => $r->id, $all->rows), array_map(static fn ($r): int => $r->id, $page->rows));
    }

    public function testAnActionFilterMatchesExactlyNotByPattern(): void
    {
        $page = $this->ops->audit()->page($this->admin, ['action' => AuditAction::SNAPSHOT_TAKEN]);
        self::assertGreaterThan(0, $page->total);
        foreach ($page->rows as $row) {
            self::assertSame(AuditAction::SNAPSHOT_TAKEN, $row->action);
        }
        $none = $this->ops->audit()->page($this->admin, ['project' => AuditOperations::DELETED]);
        self::assertSame(0, $none->total, 'No deleted project yet.');
    }
}
