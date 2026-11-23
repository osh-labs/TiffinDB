<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemSchema;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-SYS-3: forward-only steps with an injected step after the shipped ones,
 * the copy before it, and a failing step. The shipped steps end at
 * version 2 (M3 spec section 12.2; T-PLG-6 is in PluginStatesTest).
 */
#[CoversClass(SystemSchema::class)]
final class SystemSchemaTest extends TestCase
{
    private TempDir $temp;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->temp->remove();
    }

    private const NEXT = SystemSchema::VERSION + 1;

    private function current(): Database
    {
        $this->db = Sqlite::create($this->temp->path('system.sqlite'));
        SystemSchema::migrate($this->db);
        $this->db->run("INSERT INTO meta (key, value) VALUES ('installation_key', 'aaaaaaaaaaaa')");

        return $this->db;
    }

    /** @return list<string> */
    private function tables(Database $db): array
    {
        return array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name');
    }

    public function testTheCurrentVersionIsTwo(): void
    {
        $this->assertSame(2, SystemSchema::VERSION);
        $this->assertSame([1, 2], array_keys(SystemSchema::STEPS));
    }

    public function testAllStepsOnAnEmptyDatabaseTakeNoCopy(): void
    {
        $db = $this->current();
        $this->assertSame(SystemSchema::VERSION, SystemSchema::currentVersion($db));
        $this->assertSame([], glob($this->temp->path('system-before-*')));
    }

    public function testMigrateIsIdempotentAtTheCurrentVersion(): void
    {
        $db = $this->current();
        SystemSchema::migrate($db);
        $this->assertSame(SystemSchema::VERSION, SystemSchema::currentVersion($db));
        $this->assertSame([], glob($this->temp->path('system-before-*')));
    }

    public function testAnInjectedStepRunsOnceWithACopyBeforeIt(): void
    {
        $db = $this->current();
        $steps = SystemSchema::STEPS + [self::NEXT => ['CREATE TABLE extra (id INTEGER PRIMARY KEY)', "INSERT INTO extra (id) VALUES (7)"]];
        $clock = new FixedClock('2026-05-01T12:34:56Z');

        SystemSchema::migrate($db, $steps, $clock);
        $this->assertSame(self::NEXT, SystemSchema::currentVersion($db));
        $this->assertSame(1, $db->value('SELECT COUNT(*) FROM extra'));
        $copies = glob($this->temp->path('system-before-v' . self::NEXT . '-*.sqlite'));
        $this->assertSame([$this->temp->path('system-before-v' . self::NEXT . '-20260501T123456Z.sqlite')], $copies);

        $copy = Sqlite::open($copies[0]);
        try {
            $this->assertSame(SystemSchema::VERSION, SystemSchema::currentVersion($copy));
            $this->assertNotContains('extra', $this->tables($copy));
            $this->assertSame('aaaaaaaaaaaa', $copy->value("SELECT value FROM meta WHERE key = 'installation_key'"));
        } finally {
            $copy->close();
        }

        $clock->advance(60);
        SystemSchema::migrate($db, $steps, $clock);
        $this->assertSame(1, $db->value('SELECT COUNT(*) FROM extra'), 'The step ran only once.');
        $this->assertCount(1, glob($this->temp->path('system-before-*')));
    }

    public function testAStepAppliedByAnotherProcessAfterTheVersionWasReadRunsOnlyOnce(): void
    {
        $db = $this->current();
        $other = Sqlite::open($this->temp->path('system.sqlite'));
        $steps = SystemSchema::STEPS + [self::NEXT => [
            'CREATE TABLE IF NOT EXISTS extra (id INTEGER PRIMARY KEY)',
            'INSERT INTO extra (id) VALUES (NULL)',
        ]];
        try {
            // The clock is read for the copy's name after this connection has read version 1 and before
            // its step transaction begins: exactly where the other process gets in.
            $hook = new class ($other, $steps) implements Clock {
                private bool $done = false;

                /** @param array<int, list<string>> $steps */
                public function __construct(private readonly Database $other, private readonly array $steps)
                {
                }

                public function now(): \DateTimeImmutable
                {
                    $now = new \DateTimeImmutable('2026-05-01T12:34:56Z');
                    if (!$this->done) {
                        $this->done = true;
                        SystemSchema::migrate($this->other, $this->steps, new FixedClock($now));
                    }

                    return $now;
                }
            };
            SystemSchema::migrate($db, $steps, $hook);
            $this->assertSame(self::NEXT, SystemSchema::currentVersion($db));
            $this->assertSame(1, $db->value('SELECT COUNT(*) FROM extra'), 'The step ran once, not once per process.');
            $this->assertCount(1, glob($this->temp->path('system-before-v' . self::NEXT . '-*.sqlite')));
        } finally {
            $other->close();
        }
    }

    public function testAFailingStepLeavesTheVersionAndTheTablesUnchanged(): void
    {
        $db = $this->current();
        $before = $this->tables($db);
        $steps = SystemSchema::STEPS + [self::NEXT => ['CREATE TABLE extra (id INTEGER PRIMARY KEY)', 'ALTER TABLE missing ADD COLUMN x TEXT']];
        try {
            SystemSchema::migrate($db, $steps, new FixedClock());
            $this->fail('The failing step did not throw.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(SystemSchema::VERSION, SystemSchema::currentVersion($db));
        $this->assertSame($before, $this->tables($db));
        $this->assertFalse($db->inTransaction());
    }

    public function testAHigherVersionIsRefused(): void
    {
        $db = $this->current();
        $db->run("UPDATE meta SET value = ? WHERE key = 'schema_version'", [(string) self::NEXT]);
        try {
            SystemSchema::migrate($db);
            $this->fail('No exception.');
        } catch (Unavailable $error) {
            // T-CF7-6: the system reason, not the project one.
            $this->assertSame(Unavailable::SYSTEM_NEWER, $error->reason);
        }
        $this->assertSame(self::NEXT, SystemSchema::currentVersion($db));
    }

    public function testMigrateRefusesToRunInsideATransaction(): void
    {
        $db = $this->current();
        $this->expectException(\LogicException::class);
        $db->write(static fn (Database $db) => SystemSchema::migrate($db));
    }

    public function testStepsMustBeNumberedFromOne(): void
    {
        $this->db = Sqlite::memory();
        $this->expectException(\InvalidArgumentException::class);
        SystemSchema::migrate($this->db, [2 => ['CREATE TABLE t (x)']]);
    }
}
