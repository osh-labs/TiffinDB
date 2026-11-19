<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-CF-4: ProjectRegistry::abandoned lists old `creating` rows only. Synthetic data only. */
#[CoversClass(ProjectRegistry::class)]
final class ProjectRegistryAbandonedTest extends TestCase
{
    private const NOW = '2026-02-01T10:00:00Z';

    private TempDir $temp;
    private SystemDatabase $system;
    private ProjectRegistry $registry;
    private int $admin;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->system = SystemDatabase::create(
            new DataDirectory($this->temp->path(), 'data'),
            new FixedClock(self::NOW),
            new SeededRandom('tiffindb-registry-abandoned-test-seed-1'),
        );
        $this->registry = new ProjectRegistry($this->system);
        $this->system->db()->run(
            "INSERT INTO users (key, username, username_norm, display_name, role, password_hash, created_at, updated_at, password_changed_at)
             VALUES ('adminaaaaaaa', 'admin', 'admin', 'Example Admin', 'admin', 'x', ?, ?, ?)",
            [self::NOW, self::NOW, self::NOW],
        );
        $this->admin = $this->system->db()->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function add(string $key, string $createdAt, bool $activate): ProjectRecord
    {
        return $this->system->db()->write(function () use ($key, $createdAt, $activate): ProjectRecord {
            $record = $this->registry->add($key, 'Example ' . $key, $this->admin, $createdAt);
            if ($activate) {
                $this->registry->activate($record->id);
            }

            return $record;
        });
    }

    /**
     * @param list<ProjectRecord> $records
     * @return list<string>
     */
    private static function keys(array $records): array
    {
        return array_map(static fn (ProjectRecord $r): string => $r->key, $records);
    }

    public function testOnlyCreatingRowsOlderThanTheGivenTimeAreListedById(): void
    {
        $this->add('cccccccccccc', '2026-02-01T08:00:00Z', false);
        $this->add('aaaaaaaaaaaa', '2026-02-01T08:59:59Z', false);
        $this->add('bbbbbbbbbbbb', '2026-02-01T09:00:00Z', false);
        $this->add('dddddddddddd', '2026-02-01T09:00:01Z', false);
        $this->add('eeeeeeeeeeee', '2026-02-01T07:00:00Z', true);

        $abandoned = $this->registry->abandoned('2026-02-01T09:00:00Z');

        self::assertSame(['cccccccccccc', 'aaaaaaaaaaaa'], self::keys($abandoned), 'By id; the cut-off itself is not older; active rows never.');
        foreach ($abandoned as $record) {
            self::assertSame(ProjectRecord::STATE_CREATING, $record->state);
            self::assertSame($this->admin, $record->createdBy);
        }
        self::assertSame('Example cccccccccccc', $abandoned[0]->name);
        self::assertSame('2026-02-01T08:00:00Z', $abandoned[0]->createdAt);
    }

    public function testNothingAbandonedGivesAnEmptyList(): void
    {
        self::assertSame([], $this->registry->abandoned(self::NOW));
        $this->add('aaaaaaaaaaaa', self::NOW, false);
        self::assertSame([], $this->registry->abandoned(self::NOW));
        self::assertSame(['aaaaaaaaaaaa'], self::keys($this->registry->abandoned('2026-02-01T10:00:01Z')));
    }

    public function testWorksOutsideATransaction(): void
    {
        $this->add('aaaaaaaaaaaa', '2026-02-01T08:00:00Z', false);
        self::assertFalse($this->system->db()->inTransaction());
        self::assertCount(1, $this->registry->abandoned(self::NOW));
    }

    public function testAMalformedTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->abandoned("2026-02-01' OR 1=1 --");
    }
}
