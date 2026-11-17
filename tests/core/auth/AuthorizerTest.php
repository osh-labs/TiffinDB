<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Authorizer;
use TiffinDB\Core\Auth\Grants;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUTH-9: the permission table of section 6.9. */
#[CoversClass(Authorizer::class)]
#[CoversClass(Permission::class)]
final class AuthorizerTest extends TestCase
{
    private const YES = 'yes';
    private const DENIED = 'denied';
    private const NOT_FOUND = 'not-found';

    private const INSTANCE_WIDE = ['people.manage', 'project.create', 'audit.view', 'plugins.manage'];
    private const MEMBER_OPEN = ['project.view', 'records.view', 'records.edit', 'records.trash', 'collections.manage'];
    private const ADMIN_ONLY = ['project.manage', 'schema.change', 'trash.empty', 'snapshots.manage', 'io.use', 'forms.edit'];

    private TempDir $temp;
    private SystemDatabase $system;
    private Authorizer $authorizer;
    /** @var array<string, Actor> */
    private array $actors = [];
    private ProjectRecord $granted;
    private ProjectRecord $creating;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $clock = new FixedClock('2026-06-01T12:00:00Z');
        $random = new SeededRandom('tiffindb-authorizer-test-seed-1');
        $this->system = SystemDatabase::create(new DataDirectory($this->temp->path(), 'data'), $clock, $random);
        $users = new Users($this->system, new Keys($random), $clock);
        $grants = new Grants($this->system, $clock);
        $registry = new ProjectRegistry($this->system);
        $this->authorizer = new Authorizer($grants);

        $this->system->db()->write(function () use ($users, $grants, $registry): void {
            $admin = $users->create('admin', 'Example Admin', Role::Admin, 'x', false);
            $member = $users->create('member', 'Example Member', Role::Member, 'x', false);
            $other = $users->create('member2', 'Another Member', Role::Member, 'x', false);
            foreach (['admin' => $admin, 'member-with-grant' => $member, 'member-without-grant' => $other] as $label => $person) {
                $this->actors[$label] = new Actor($person->id, $person->key, $person->role, $person->displayName);
            }
            $granted = $registry->add('recipesaaaaa', 'Recipes', $admin->id, '2026-06-01T12:00:00Z');
            $registry->activate($granted->id);
            $creating = $registry->add('booksaaaaaaa', 'Books', $admin->id, '2026-06-01T12:00:00Z');
            $grants->setUsersFor($granted->id, [$member->id], $admin->id);
            $grants->setUsersFor($creating->id, [$member->id], $admin->id);
        });
        $this->granted = $registry->byKey('recipesaaaaa') ?? throw new \LogicException('missing');
        $this->creating = $registry->byKey('booksaaaaaaa') ?? throw new \LogicException('missing');
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function table(): iterable
    {
        foreach (Permission::cases() as $permission) {
            $name = $permission->value;
            if (in_array($name, self::INSTANCE_WIDE, true)) {
                $row = ['admin' => self::YES, 'member-with-grant' => self::DENIED, 'member-without-grant' => self::DENIED];
                $creating = $row;
            } elseif (in_array($name, self::MEMBER_OPEN, true)) {
                $row = ['admin' => self::YES, 'member-with-grant' => self::YES, 'member-without-grant' => self::NOT_FOUND];
                $creating = array_fill_keys(array_keys($row), self::NOT_FOUND);
            } elseif (in_array($name, self::ADMIN_ONLY, true)) {
                $row = ['admin' => self::YES, 'member-with-grant' => self::DENIED, 'member-without-grant' => self::NOT_FOUND];
                $creating = array_fill_keys(array_keys($row), self::NOT_FOUND);
            } else {
                throw new \LogicException('Permission missing from the test table: ' . $name);
            }
            foreach ($row as $actor => $expected) {
                yield $name . ' / ' . $actor => [$name, $actor, 'active', $expected];
            }
            foreach ($creating as $actor => $expected) {
                yield $name . ' / ' . $actor . ' / creating project' => [$name, $actor, 'creating', $expected];
            }
        }
    }

    public function testTheTableCoversEveryPermissionOnce(): void
    {
        $listed = [...self::INSTANCE_WIDE, ...self::MEMBER_OPEN, ...self::ADMIN_ONLY];
        $all = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        sort($listed);
        sort($all);
        $this->assertSame($all, $listed);
        $this->assertCount(15, $all);
    }

    #[DataProvider('table')]
    public function testPermission(string $permissionName, string $actorLabel, string $projectState, string $expected): void
    {
        $permission = Permission::from($permissionName);
        $actor = $this->actors[$actorLabel];
        $project = $projectState === 'active' ? $this->granted : $this->creating;

        $this->assertSame($expected === self::YES, $this->authorizer->can($actor, $permission, $project));
        try {
            $this->authorizer->require($actor, $permission, $project);
            $outcome = self::YES;
        } catch (AccessDenied) {
            $outcome = self::DENIED;
        } catch (NotFound) {
            $outcome = self::NOT_FOUND;
        }
        $this->assertSame($expected, $outcome);
    }

    public function testInstanceWidePermissionsIgnoreTheProject(): void
    {
        $this->assertTrue($this->authorizer->can($this->actors['admin'], Permission::ManagePeople));
        $this->assertFalse($this->authorizer->can($this->actors['member-with-grant'], Permission::CreateProject));
        $this->expectException(AccessDenied::class);
        $this->authorizer->require($this->actors['member-with-grant'], Permission::ViewAudit);
    }

    public function testProjectScopedPermissionsNeedAProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->authorizer->can($this->actors['admin'], Permission::ViewProject);
    }

    public function testAnUnknownStoredGrantLevelIsNoGrant(): void
    {
        $member = $this->actors['member-with-grant'];
        $this->system->db()->run("UPDATE project_grants SET level = 'owner' WHERE user_id = ?", [$member->userId]);
        $this->assertFalse($this->authorizer->can($member, Permission::ViewRecords, $this->granted));
        $this->expectException(NotFound::class);
        $this->authorizer->require($member, Permission::ManageProject, $this->granted);
    }
}
