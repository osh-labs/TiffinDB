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
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemSchema;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\TempDir;

/** T-SYS-3: forward-only steps with an injected step 2, the copy before it, and a failing step. */
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

    private function versionOne(): Database
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

    public function testVersionOneOnAnEmptyDatabaseTakesNoCopy(): void
    {
        $db = $this->versionOne();
        $this->assertSame(1, SystemSchema::currentVersion($db));
        $this->assertSame([], glob($this->temp->path('system-before-*')));
    }

    public function testMigrateIsIdempotentAtTheCurrentVersion(): void
    {
        $db = $this->versionOne();
        SystemSchema::migrate($db);
        $this->assertSame(1, SystemSchema::currentVersion($db));
        $this->assertSame([], glob($this->temp->path('system-before-*')));
    }

    public function testAnInjectedStepRunsOnceWithACopyBeforeIt(): void
    {
        $db = $this->versionOne();
        $steps = SystemSchema::STEPS + [2 => ['CREATE TABLE extra (id INTEGER PRIMARY KEY)', "INSERT INTO extra (id) VALUES (7)"]];
        $clock = new FixedClock('2026-05-01T12:34:56Z');

        SystemSchema::migrate($db, $steps, $clock);
        $this->assertSame(2, SystemSchema::currentVersion($db));
        $this->assertSame(1, $db->value('SELECT COUNT(*) FROM extra'));
        $copies = glob($this->temp->path('system-before-v2-*.sqlite'));
        $this->assertSame([$this->temp->path('system-before-v2-20260501T123456Z.sqlite')], $copies);

        $copy = Sqlite::open($copies[0]);
        try {
            $this->assertSame(1, SystemSchema::currentVersion($copy));
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

    public function testAFailingStepLeavesVersionOneAndTheTablesUnchanged(): void
    {
        $db = $this->versionOne();
        $before = $this->tables($db);
        $steps = SystemSchema::STEPS + [2 => ['CREATE TABLE extra (id INTEGER PRIMARY KEY)', 'ALTER TABLE missing ADD COLUMN x TEXT']];
        try {
            SystemSchema::migrate($db, $steps, new FixedClock());
            $this->fail('The failing step did not throw.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, SystemSchema::currentVersion($db));
        $this->assertSame($before, $this->tables($db));
        $this->assertFalse($db->inTransaction());
    }

    public function testAHigherVersionIsRefused(): void
    {
        $db = $this->versionOne();
        $db->run("UPDATE meta SET value = '2' WHERE key = 'schema_version'");
        try {
            SystemSchema::migrate($db);
            $this->fail('No exception.');
        } catch (Unavailable $error) {
            $this->assertSame(Unavailable::NEWER, $error->reason);
        }
    }

    public function testMigrateRefusesToRunInsideATransaction(): void
    {
        $db = $this->versionOne();
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
