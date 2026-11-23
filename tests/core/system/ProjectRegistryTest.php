<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-SYS-4: the project registry, with the M7 state `deleting` (M7 spec section 6.2). */
#[CoversClass(ProjectRegistry::class)]
#[CoversClass(ProjectRecord::class)]
final class ProjectRegistryTest extends TestCase
{
    private const NOW = '2026-02-01T10:00:00Z';

    private TempDir $temp;
    private SystemDatabase $system;
    private ProjectRegistry $registry;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->system = SystemDatabase::create(
            new DataDirectory($this->temp->path(), 'data'),
            new FixedClock(self::NOW),
            new SeededRandom('tiffindb-project-registry-test-seed-1'),
        );
        $this->registry = new ProjectRegistry($this->system);
        $this->admin = $this->person('adminaaaaaaa', 'admin', 'admin');
        $this->member = $this->person('memberaaaaaa', 'member', 'member');
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function person(string $key, string $username, string $role): int
    {
        $this->system->db()->run(
            "INSERT INTO users (key, username, username_norm, display_name, role, password_hash, created_at, updated_at, password_changed_at)
             VALUES (?, ?, ?, 'Example Person', ?, 'x', ?, ?, ?)",
            [$key, $username, $username, $role, self::NOW, self::NOW, self::NOW],
        );

        return $this->system->db()->lastInsertId();
    }

    private function add(string $key, string $name, bool $activate = true): ProjectRecord
    {
        return $this->system->db()->write(function () use ($key, $name, $activate): ProjectRecord {
            $record = $this->registry->add($key, $name, $this->admin, self::NOW);
            if ($activate) {
                $this->registry->activate($record->id);
            }

            return $record;
        });
    }

    private function grant(ProjectRecord $project, int $userId): void
    {
        $this->system->db()->run(
            'INSERT INTO project_grants (project_id, user_id, granted_at) VALUES (?, ?, ?)',
            [$project->id, $userId, self::NOW],
        );
    }

    /** @param list<ProjectRecord> $records @return list<string> */
    private static function keys(array $records): array
    {
        return array_map(static fn (ProjectRecord $r): string => $r->key, $records);
    }

    public function testAddCreatesACreatingProjectAndActivateMakesItActive(): void
    {
        $record = $this->add('recipesaaaaa', 'Recipes', false);
        $this->assertSame('creating', $record->state);
        $this->assertFalse($record->isActive());
        $this->assertSame('Recipes', $record->name);
        $this->assertSame(self::NOW, $record->createdAt);
        $this->assertSame($this->admin, $record->createdBy);

        $this->system->db()->write(fn () => $this->registry->activate($record->id));
        $active = $this->registry->byKey('recipesaaaaa');
        $this->assertNotNull($active);
        $this->assertSame('active', $active->state);
        $this->assertSame($record->id, $active->id);
    }

    public function testByKey(): void
    {
        $this->add('recipesaaaaa', 'Recipes');
        $this->assertNull($this->registry->byKey('missingaaaaa'));
        $this->assertNull($this->registry->byKey('NOT A KEY'));
        $this->assertSame('Recipes', $this->registry->byKey('recipesaaaaa')?->name);
    }

    public function testNameTakenIgnoresAsciiCase(): void
    {
        $record = $this->add('recipesaaaaa', 'Recipes');
        $this->assertTrue($this->registry->nameTaken('recipes'));
        $this->assertTrue($this->registry->nameTaken('RECIPES'));
        $this->assertFalse($this->registry->nameTaken('Recipes', $record->id));
        $this->assertFalse($this->registry->nameTaken('Books'));
        $this->add('creatingaaaa', 'Garden', false);
        $this->assertTrue($this->registry->nameTaken('garden'), 'A project being created holds its name.');
    }

    public function testRenameAndRemove(): void
    {
        $record = $this->add('recipesaaaaa', 'Recipes');
        $this->system->db()->write(fn () => $this->registry->rename($record->id, 'Cookbook'));
        $this->assertSame('Cookbook', $this->registry->byKey('recipesaaaaa')?->name);
        $this->system->db()->write(fn () => $this->registry->remove($record->id));
        $this->assertNull($this->registry->byKey('recipesaaaaa'));
    }

    public function testAllAndForMemberOrderByNameThenKeyAndExcludeCreating(): void
    {
        $zeta = $this->add('zzzzzzzzzzzz', 'zeta');
        $alphaB = $this->add('bbbbbbbbbbbb', 'Alpha two');
        $alpha = $this->add('cccccccccccc', 'alpha');
        $creating = $this->add('dddddddddddd', 'Beta', false);
        $this->assertSame(['cccccccccccc', 'bbbbbbbbbbbb', 'zzzzzzzzzzzz'], self::keys($this->registry->all()));

        $this->grant($zeta, $this->member);
        $this->grant($alpha, $this->member);
        $this->grant($creating, $this->member);
        $this->assertSame(['cccccccccccc', 'zzzzzzzzzzzz'], self::keys($this->registry->forMember($this->member)));
        $this->assertSame([], $this->registry->forMember($this->admin));
        $this->assertSame('Alpha two', $alphaB->name);
    }

    public function testChangesNeedTheCallersTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->registry->add('recipesaaaaa', 'Recipes', $this->admin, self::NOW);
    }

    public function testAddRefusesAnInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->add('Not-A-Key', 'Recipes');
    }

    public function testSetStateByIdAndDeleting(): void
    {
        $recipes = $this->add('recipesaaaaa', 'Recipes');
        $garden = $this->add('gardenaaaaaa', 'Garden');
        $this->grant($recipes, $this->member);
        $this->assertSame([], $this->registry->deleting());

        $this->system->db()->write(fn () => $this->registry->setState($recipes->id, ProjectRecord::STATE_DELETING));
        $deleting = $this->registry->byKey('recipesaaaaa');
        $this->assertSame('deleting', $deleting?->state);
        $this->assertFalse($deleting?->isActive());
        $this->assertSame(['recipesaaaaa'], self::keys($this->registry->deleting()));
        $this->assertSame(['gardenaaaaaa'], self::keys($this->registry->all()), 'No list shows a project being deleted.');
        $this->assertSame([], $this->registry->forMember($this->member));
        $this->assertTrue($this->registry->nameTaken('recipes'), 'Its name stays taken until the row goes.');
        $this->assertSame([], $this->registry->abandoned('2099-01-01T00:00:00Z'), 'A deletion is not an abandoned creation.');

        $this->assertSame('Recipes', $this->registry->byId($recipes->id)?->name);
        $this->assertSame('deleting', $this->registry->byId($recipes->id)?->state);
        $this->assertSame('Garden', $this->registry->byId($garden->id)?->name);
        $this->assertNull($this->registry->byId(999));

        $this->system->db()->write(fn () => $this->registry->setState($recipes->id, ProjectRecord::STATE_ACTIVE));
        $this->assertSame(['gardenaaaaaa', 'recipesaaaaa'], self::keys($this->registry->all()));
        $this->assertSame(['recipesaaaaa'], self::keys($this->registry->forMember($this->member)));

        $this->system->db()->write(fn () => $this->registry->remove($recipes->id));
        $this->assertNull($this->registry->byId($recipes->id));
        $this->assertSame(0, (int) $this->system->db()->value('SELECT COUNT(*) FROM project_grants WHERE project_id = ?', [$recipes->id]), 'Grants cascade.');
    }

    public function testSetStateRefusesAnUnknownStateAndNeedsATransaction(): void
    {
        $record = $this->add('recipesaaaaa', 'Recipes');
        try {
            $this->system->db()->write(fn () => $this->registry->setState($record->id, 'gone'));
            $this->fail('An unknown state was stored.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('active', $this->registry->byKey('recipesaaaaa')?->state);
        }
        $this->expectException(LogicException::class);
        $this->registry->setState($record->id, ProjectRecord::STATE_DELETING);
    }

    public function testARecordRefusesAnUnknownState(): void
    {
        $this->assertSame(['creating', 'active', 'deleting'], ProjectRecord::STATES);
        $this->expectException(\UnexpectedValueException::class);
        new ProjectRecord(1, 'recipesaaaaa', 'Recipes', 'gone', self::NOW, null);
    }
}
