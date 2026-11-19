<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\PluginStates;
use TiffinDB\Core\System\SystemSchema;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-PLG-6: PluginStates (set, all, refuses outside a transaction) and the
 * system schema version 2 migration from version 1 (copy taken, table
 * created, SystemSchema::VERSION 2).
 */
#[CoversClass(PluginStates::class)]
#[CoversClass(SystemSchema::class)]
final class PluginStatesTest extends TestCase
{
    private CoreFixture $fixture;
    private PluginStates $states;
    private int $admin;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $this->states = new PluginStates($this->fixture->system);
        $this->admin = $this->fixture->person('admin')->id;
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    private function write(\Closure $change): void
    {
        $this->fixture->system->db()->write(static function (Database $db) use ($change): void {
            $change();
        });
    }

    public function testNoRowsMeansNoOverrides(): void
    {
        $this->assertSame([], $this->states->all());
    }

    public function testSetAndAll(): void
    {
        $this->write(function (): void {
            $this->states->set('import', 'csv', false, $this->admin, '2026-03-02T10:00:00Z');
            $this->states->set('export', 'example-format', true, $this->admin, '2026-03-02T10:01:00Z');
            $this->states->set('import', 'another', true, $this->admin, '2026-03-02T10:02:00Z');
        });
        $this->assertSame(['export/example-format' => true, 'import/another' => true, 'import/csv' => false], $this->states->all());

        $this->write(function (): void {
            $this->states->set('import', 'csv', true, $this->admin, '2026-03-02T11:00:00Z');
        });
        $this->assertTrue($this->states->all()['import/csv']);
        $row = $this->fixture->system->db()->one("SELECT * FROM plugins WHERE kind = 'import' AND id = 'csv'");
        $this->assertSame(['kind' => 'import', 'id' => 'csv', 'enabled' => 1, 'changed_at' => '2026-03-02T11:00:00Z', 'changed_by' => $this->admin], $row);
        $this->assertSame(3, $this->fixture->system->db()->value('SELECT COUNT(*) FROM plugins'));
    }

    public function testSetRefusesToRunOutsideATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->states->set('import', 'csv', false, $this->admin, '2026-03-02T10:00:00Z');
    }

    public function testSetRefusesValuesThatAreNotValid(): void
    {
        $cases = [
            ['other', 'csv', $this->admin, '2026-03-02T10:00:00Z'],
            ['Import', 'csv', $this->admin, '2026-03-02T10:00:00Z'],
            ['import', 'Csv', $this->admin, '2026-03-02T10:00:00Z'],
            ['import', '../csv', $this->admin, '2026-03-02T10:00:00Z'],
            ['import', 'c', $this->admin, '2026-03-02T10:00:00Z'],
            ['import', str_repeat('a', 33), $this->admin, '2026-03-02T10:00:00Z'],
            ['import', 'csv', 0, '2026-03-02T10:00:00Z'],
            ['import', 'csv', $this->admin, 'yesterday'],
        ];
        foreach ($cases as [$kind, $id, $user, $now]) {
            try {
                $this->write(function () use ($kind, $id, $user, $now): void {
                    $this->states->set($kind, $id, true, $user, $now);
                });
                $this->fail('Accepted ' . $kind . '/' . $id);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->states->all());
    }

    public function testRowsThatBreakTheRulesAreLeftOut(): void
    {
        $db = $this->fixture->system->db();
        $db->run("INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES ('import', 'good', 1, '2026-03-02T10:00:00Z', NULL)");
        $db->run("INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES ('other', 'bad', 1, '2026-03-02T10:00:00Z', NULL)");
        $db->run("INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES ('import', '../bad', 1, '2026-03-02T10:00:00Z', NULL)");
        $db->run("INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES ('import', 'two', 2, '2026-03-02T10:00:00Z', NULL)");
        $db->run("INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES ('import', 'text', 'yes', '2026-03-02T10:00:00Z', NULL)");
        $this->assertSame(['import/good' => true], $this->states->all());
    }

    public function testDeletingTheUserKeepsTheState(): void
    {
        $member = $this->fixture->person('member')->id;
        $this->write(function () use ($member): void {
            $this->states->set('import', 'csv', false, $member, '2026-03-02T10:00:00Z');
        });
        $this->fixture->system->db()->run('DELETE FROM users WHERE id = ?', [$member]);
        $this->assertSame(['import/csv' => false], $this->states->all());
        $this->assertNull($this->fixture->system->db()->value('SELECT changed_by FROM plugins'));
    }

    public function testVersionOneUpgradesToVersionTwoWithACopyFirst(): void
    {
        $temp = TempDir::create();
        $db = Sqlite::create($temp->path('system.sqlite'));
        try {
            SystemSchema::migrate($db, [1 => SystemSchema::STEPS[1]]);
            $db->run("INSERT INTO meta (key, value) VALUES ('installation_key', 'aaaaaaaaaaaa')");
            $this->assertSame(1, SystemSchema::currentVersion($db));

            SystemSchema::migrate($db, null, new FixedClock('2026-05-01T12:34:56Z'));
            $this->assertSame(2, SystemSchema::VERSION);
            $this->assertSame(2, SystemSchema::currentVersion($db));
            $this->assertSame(
                ['kind', 'id', 'enabled', 'changed_at', 'changed_by'],
                array_column($db->all("SELECT name FROM pragma_table_info('plugins')"), 'name'),
            );
            $this->assertSame([['kind', 1], ['id', 2]], array_map(
                static fn (array $row): array => [$row['name'], $row['pk']],
                $db->all("SELECT name, pk FROM pragma_table_info('plugins') WHERE pk > 0 ORDER BY pk"),
            ));
            $this->assertStringContainsString('WITHOUT ROWID', (string) $db->value("SELECT sql FROM sqlite_master WHERE name = 'plugins'"));
            $this->assertSame('ok', $db->value('PRAGMA integrity_check'));

            $copies = glob($temp->path('system-before-v2-*.sqlite')) ?: [];
            $this->assertSame([$temp->path('system-before-v2-20260501T123456Z.sqlite')], $copies);
            $copy = Sqlite::open($copies[0]);
            try {
                $this->assertSame(1, SystemSchema::currentVersion($copy));
                $this->assertNull($copy->value("SELECT name FROM sqlite_master WHERE name = 'plugins'"));
                $this->assertSame('aaaaaaaaaaaa', $copy->value("SELECT value FROM meta WHERE key = 'installation_key'"));
            } finally {
                $copy->close();
            }
        } finally {
            $db->close();
            $temp->remove();
        }
    }
}
