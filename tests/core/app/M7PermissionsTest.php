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
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\App\SnapshotOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\System\AuditAction;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-ADM-30 to T-ADM-32 (M7 spec sections 11.1 and 16.1): every new
 * SnapshotOperations, ProjectOperations, and AuditOperations method is for
 * Admins. A granted Member is AccessDenied before anything is read (shown
 * with the project folder moved away: the Member is still refused and
 * nothing is made where the folder was; the Admin then reaches the
 * missing folder); an ungranted person and an unknown key are NotFound
 * alike; an Admin made a Member after the session was resumed is refused
 * by requireCurrentActor, and by requireCurrentAdmin when the role changes
 * between that check and the write.
 */
#[CoversClass(SnapshotOperations::class)]
#[CoversClass(ProjectOperations::class)]
#[CoversClass(AuditOperations::class)]
final class M7PermissionsTest extends AppTestCase
{
    private const UNKNOWN_KEY = 'k2xq7vn4mz3t';

    private string $p;
    private SnapshotInfo $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants')->key;
        $this->ops->projects()->setSharing($this->admin, $this->p, [$this->member->userKey]);
        $this->snapshot = $this->ops->snapshots()->take($this->admin, $this->p);
    }

    /** @return iterable<string, array{string}> every new project-scoped method */
    public static function projectCalls(): iterable
    {
        foreach (['screen', 'take', 'list', 'restoreScreen', 'restore', 'deleteScreen', 'delete'] as $name) {
            yield $name => [$name];
        }
    }

    private function call(Operations $ops, string $name, Actor $actor, string $projectKey): mixed
    {
        $id = substr($this->snapshot->file, 0, -strlen('.tiffin'));

        return match ($name) {
            'screen' => $ops->snapshots()->screen($actor, $projectKey),
            'take' => $ops->snapshots()->take($actor, $projectKey),
            'list' => $ops->snapshots()->list($actor, $projectKey),
            'restoreScreen' => $ops->snapshots()->restoreScreen($actor, $projectKey, $id),
            'restore' => $ops->snapshots()->restore($actor, $projectKey, $id),
            'deleteScreen' => $ops->projects()->deleteScreen($actor, $projectKey),
            'delete' => $ops->projects()->delete($actor, $projectKey, 'Example Garden', 0),
        };
    }

    public function testTheCallsCoverEveryNewMethod(): void
    {
        $public = static function (string $class): array {
            $names = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isConstructor()) {
                    $names[] = $method->getName();
                }
            }

            return $names;
        };
        self::assertEqualsCanonicalizing(['screen', 'take', 'list', 'restoreScreen', 'restore'], $public(SnapshotOperations::class));
        self::assertContains('deleteScreen', $public(ProjectOperations::class));
        self::assertContains('delete', $public(ProjectOperations::class));
        self::assertEqualsCanonicalizing(['recent', 'page'], $public(AuditOperations::class));
    }

    // ------------------------------------------------------------ T-ADM-30

    #[DataProvider('projectCalls')]
    public function testAGrantedMemberIsRefusedBeforeAnythingIsRead(string $name): void
    {
        $this->ops->close();
        $folder = $this->fx->projectFolder($this->p);
        $away = $this->fx->temp->path('away');
        rename($folder, $away);
        $rows = $this->fx->systemRows();
        $this->captureLog();

        $this->assertThrows(AccessDenied::class, fn () => $this->call($this->ops, $name, $this->member, $this->p));
        self::assertFileDoesNotExist($folder, 'Nothing is made where the project was.');
        self::assertFileDoesNotExist($this->fx->data->path() . '/staging/restores');
        self::assertSame($rows, $this->fx->systemRows(), 'Nothing is written.');

        // The Admin gets past the permission check and reaches the missing folder.
        try {
            $this->call($this->ops, $name, $this->admin, $this->p);
            self::assertContains($name, ['list', 'deleteScreen', 'delete', 'restoreScreen', 'restore'], 'Only these need no open file.');
        } catch (Unavailable | NotFound) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMembersAreRefusedTheAuditScreen(): void
    {
        $rows = $this->fx->systemRows();
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->audit()->page($this->member, []));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->audit()->page($this->member2, ['person' => $this->admin->userKey]));
        self::assertSame($rows, $this->fx->systemRows());
        self::assertSame(AuditAction::SNAPSHOT_TAKEN, $this->ops->audit()->page($this->admin, [])->rows[0]->action);
    }

    // ------------------------------------------------------------ T-ADM-31

    #[DataProvider('projectCalls')]
    public function testAnUngrantedPersonAndAnUnknownKeyAreNotFoundAlike(string $name): void
    {
        $rows = $this->fx->systemRows();
        foreach ([[$this->member2, $this->p], [$this->member, self::UNKNOWN_KEY], [$this->admin, self::UNKNOWN_KEY], [$this->admin, '../' . $this->p], [$this->member2, self::UNKNOWN_KEY]] as [$actor, $key]) {
            $this->assertThrows(NotFound::class, fn () => $this->call($this->ops, $name, $actor, $key));
        }
        self::assertSame($rows, $this->fx->systemRows());
    }

    // ------------------------------------------------------------ T-ADM-32

    /** @return iterable<string, array{string}> */
    public static function guardedCalls(): iterable
    {
        foreach (['restoreScreen', 'restore', 'deleteScreen', 'delete', 'page'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('guardedCalls')]
    public function testAnAdminMadeAMemberAfterTheSessionResumedIsRefused(string $name): void
    {
        $former = $this->fx->addPerson('example.former', 'Example Former', Role::Admin);
        $this->fx->system->db()->run('UPDATE users SET role = ? WHERE id = ?', [Role::Member->value, $former->userId]);
        $this->ops->close();
        $rows = $this->fx->systemRows();
        $before = hash_file('sha256', $this->fx->projectFolder($this->p) . '/project.tiffin');

        $this->assertThrows(AccessDenied::class, fn () => $name === 'page' ? $this->ops->audit()->page($former, []) : $this->call($this->ops, $name, $former, $this->p));

        self::assertSame($rows, $this->fx->systemRows());
        self::assertSame($before, hash_file('sha256', $this->fx->projectFolder($this->p) . '/project.tiffin'));
        self::assertFileDoesNotExist($this->fx->data->path() . '/staging/restores');
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        yield 'restore' => ['restore', 'restore.admin'];
        yield 'delete' => ['delete', 'delete.mark'];
    }

    #[DataProvider('writes')]
    public function testARoleChangedBeforeTheWriteIsRefusedInsideIt(string $name, string $point): void
    {
        $system = $this->fx->system;
        $adminId = $this->admin->userId;
        $demoting = $this->fx->operations(null, null, static function (string $at) use ($point, $system, $adminId): void {
            if ($at === $point) {
                $system->db()->run('UPDATE users SET role = ? WHERE id = ?', [Role::Member->value, $adminId]);
            }
        });
        $this->ops->close();
        $before = hash_file('sha256', $this->fx->projectFolder($this->p) . '/project.tiffin');

        $this->assertThrows(AccessDenied::class, fn () => $this->call($demoting, $name, $this->admin, $this->p));
        $demoting->close();

        self::assertSame($before, hash_file('sha256', $this->fx->projectFolder($this->p) . '/project.tiffin'));
        self::assertSame('active', $this->fx->system->db()->value('SELECT state FROM projects WHERE key = ?', [$this->p]));
        self::assertSame(1, count($this->ops->snapshots()->list($this->admin, $this->p)), 'No pre-restore snapshot.');
        self::assertDirectoryExists($this->fx->projectFolder($this->p));
    }
}
