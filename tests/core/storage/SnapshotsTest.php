<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-STO-8: snapshot names, consistency (WAL included), transactions, pruning, listing. */
#[CoversClass(Snapshots::class)]
#[CoversClass(SnapshotInfo::class)]
#[CoversClass(SnapshotReason::class)]
final class SnapshotsTest extends TestCase
{
    private const SEED = 'tiffindb-snapshots-test-seed-1';

    private TempDir $temp;
    private FixedClock $clock;
    private Snapshots $snapshots;
    private string $folder;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-11-12T03:15:00Z');
        $this->snapshots = new Snapshots($this->clock, new Keys(new SeededRandom(self::SEED)));
        $this->folder = $this->temp->path('projects/q7mzk2wbx4ae');
        mkdir($this->folder, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->db = null;
        $this->temp->remove();
    }

    private function project(): Database
    {
        if ($this->db === null) {
            $this->db = Sqlite::create($this->folder . '/project.tiffin');
            $this->db->run('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        }

        return $this->db;
    }

    public function testReasonValues(): void
    {
        self::assertSame(
            ['format-upgrade', 'table-delete', 'field-delete', 'field-type-change', 'field-options', 'empty-trash', 'manual'],
            array_map(static fn (SnapshotReason $r): string => $r->value, SnapshotReason::cases()),
        );
        self::assertFalse(SnapshotReason::Manual->isAutomatic());
        self::assertTrue(SnapshotReason::EmptyTrash->isAutomatic());
        self::assertSame(20, Snapshots::KEEP_AUTOMATIC);
    }

    public function testNamePattern(): void
    {
        $info = $this->snapshots->take($this->project(), $this->folder, SnapshotReason::FieldDelete);

        self::assertMatchesRegularExpression(Snapshots::NAME, $info->file);
        self::assertMatchesRegularExpression('/^20261112T031500Z-field-delete-[a-z2-7]{4}\.tiffin\z/', $info->file);
        self::assertSame('2026-11-12T03:15:00+00:00', $info->takenAt->format(DATE_ATOM));
        self::assertSame(SnapshotReason::FieldDelete, $info->reason);
        self::assertFileExists($this->folder . '/snapshots/' . $info->file);
        self::assertSame(filesize($this->folder . '/snapshots/' . $info->file), $info->size);
        self::assertGreaterThan(0, $info->size);

        // The same seed gives the same name.
        $again = new Snapshots(new FixedClock('2026-11-12T03:15:00Z'), new Keys(new SeededRandom(self::SEED)));
        $otherFolder = $this->temp->path('other');
        mkdir($otherFolder);
        self::assertSame($info->file, $again->take($this->project(), $otherFolder, SnapshotReason::FieldDelete)->file);
    }

    public function testSnapshotsFolderIsCreatedWhenMissing(): void
    {
        self::assertDirectoryDoesNotExist($this->folder . '/snapshots');
        $this->snapshots->take($this->project(), $this->folder, SnapshotReason::Manual);
        self::assertDirectoryExists($this->folder . '/snapshots');
    }

    public function testAnExistingSnapshotsFolderIsUsed(): void
    {
        mkdir($this->folder . '/snapshots', 0750);
        $info = $this->snapshots->take($this->project(), $this->folder, SnapshotReason::Manual);
        self::assertFileExists($this->folder . '/snapshots/' . $info->file);
    }

    public function testAFileWhereTheSnapshotsFolderShouldBeIsRefused(): void
    {
        file_put_contents($this->folder . '/snapshots', 'synthetic');
        $this->expectException(\RuntimeException::class);
        $this->snapshots->take($this->project(), $this->folder, SnapshotReason::Manual);
    }

    public function testSnapshotFileHasMode0640(): void
    {
        $info = $this->snapshots->take($this->project(), $this->folder, SnapshotReason::Manual);
        $path = $this->folder . '/snapshots/' . $info->file;
        clearstatcache(true, $path);
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This file system does not keep POSIX permission bits (non-POSIX platform).');
        }
        self::assertSame(0640, fileperms($path) & 0777);
    }

    public function testCopyHoldsDataCommittedJustBeforeIncludingTheWal(): void
    {
        $db = $this->project();
        $db->write(fn (Database $tx): int => $tx->run('INSERT INTO items (name) VALUES (?)', ['Example lamp']));
        $db->write(fn (Database $tx): int => $tx->run('INSERT INTO items (name) VALUES (?)', ['Example chair']));
        clearstatcache();
        self::assertSame('wal', $db->journalMode());
        self::assertFileExists($this->folder . '/project.tiffin-wal');
        self::assertGreaterThan(0, filesize($this->folder . '/project.tiffin-wal'), 'The newest data is still in the WAL.');

        $info = $this->snapshots->take($db, $this->folder, SnapshotReason::Manual);
        $path = $this->folder . '/snapshots/' . $info->file;
        self::assertFileDoesNotExist($path . '-wal');

        $copy = Sqlite::open($path);
        try {
            self::assertSame(
                ['Example lamp', 'Example chair'],
                array_column($copy->all('SELECT name FROM items ORDER BY id'), 'name'),
            );
        } finally {
            $copy->close();
        }
    }

    public function testAFailedCopyLeavesNoSnapshotBehind(): void
    {
        // A synthetic database of 400 rows of 200 bytes, page size 4096, closed before it is damaged.
        $path = $this->folder . '/project.tiffin';
        $setup = Sqlite::create($path);
        $setup->run('PRAGMA journal_mode = DELETE');
        $setup->run('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $setup->write(function (Database $tx): void {
            for ($i = 0; $i < 400; $i++) {
                $tx->run('INSERT INTO items (name) VALUES (?)', [str_repeat(chr(65 + $i % 26), 200)]);
            }
        });
        self::assertSame(4096, $setup->value('PRAGMA page_size'));
        $setup->close();

        // Overwrite 2000 bytes inside page 6 so that VACUUM INTO fails after creating its output file.
        $handle = fopen($path, 'r+');
        self::assertIsResource($handle);
        fseek($handle, 4096 * 5 + 8);
        fwrite($handle, str_repeat("\xff", 2000));
        fclose($handle);

        $this->db = Sqlite::open($path);
        try {
            $this->snapshots->take($this->db, $this->folder, SnapshotReason::Manual);
            self::fail('A snapshot of a damaged database must fail.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('malformed', $e->getMessage());
        }

        self::assertDirectoryExists($this->folder . '/snapshots');
        self::assertSame([], glob($this->folder . '/snapshots/*.tiffin'), 'The partial output file is removed.');
        self::assertSame([], $this->snapshots->list($this->folder));
    }

    public function testRefusedInsideATransaction(): void
    {
        $db = $this->project();
        try {
            $db->write(function () use ($db): void {
                $this->snapshots->take($db, $this->folder, SnapshotReason::TableDelete);
            });
            self::fail('A snapshot inside a transaction must be refused.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([], $this->snapshots->list($this->folder));
    }

    public function testPruningKeepsTwentyAutomaticAndEveryManual(): void
    {
        $db = $this->project();
        $manual = [];
        for ($i = 0; $i < 3; $i++) {
            $manual[] = $this->snapshots->take($db, $this->folder, SnapshotReason::Manual)->file;
            $this->clock->advance(60);
        }
        $automatic = [];
        $reasons = [SnapshotReason::FieldDelete, SnapshotReason::EmptyTrash, SnapshotReason::FieldTypeChange];
        for ($i = 0; $i < 25; $i++) {
            $automatic[] = $this->snapshots->take($db, $this->folder, $reasons[$i % 3])->file;
            $this->clock->advance(60);
        }

        $listed = array_map(static fn (SnapshotInfo $s): string => $s->file, $this->snapshots->list($this->folder));
        self::assertCount(23, $listed);
        $expected = array_merge(array_reverse(array_slice($automatic, 5)), array_reverse($manual));
        self::assertSame($expected, $listed, 'Newest first; the five oldest automatic snapshots are gone.');
        foreach (array_slice($automatic, 0, 5) as $pruned) {
            self::assertFileDoesNotExist($this->folder . '/snapshots/' . $pruned);
        }

        // A manual snapshot never prunes, and more manual ones are kept.
        $this->snapshots->take($db, $this->folder, SnapshotReason::Manual);
        self::assertCount(24, $this->snapshots->list($this->folder));
    }

    public function testJustTakenSnapshotIsKeptWhenTimesTie(): void
    {
        $db = $this->project();
        $files = [];
        for ($i = 0; $i < 22; $i++) {
            $files[] = $this->snapshots->take($db, $this->folder, SnapshotReason::EmptyTrash)->file;
            self::assertFileExists($this->folder . '/snapshots/' . end($files));
        }
        self::assertCount(20, $this->snapshots->list($this->folder));
    }

    public function testListIgnoresForeignFiles(): void
    {
        $info = $this->snapshots->take($this->project(), $this->folder, SnapshotReason::FieldOptions);
        $dir = $this->folder . '/snapshots';
        foreach ([
            'notes.txt',
            '20261112T031500Z-field-delete-k3m7.tiffin.bak',
            '20261112T031500Z-unknown-reason-k3m7.tiffin',
            '20261112T031500Z-manual-K3M7.tiffin',
            '20261112T031500Z-manual-k3m.tiffin',
            '20261332T031500Z-manual-k3m7.tiffin',
            '2026-11-12-manual-k3m7.tiffin',
        ] as $foreign) {
            file_put_contents($dir . '/' . $foreign, 'synthetic');
        }
        mkdir($dir . '/20261112T031500Z-manual-abcd.tiffin');

        $listed = $this->snapshots->list($this->folder);
        self::assertCount(1, $listed);
        self::assertSame($info->file, $listed[0]->file);
        self::assertSame(SnapshotReason::FieldOptions, $listed[0]->reason);
        self::assertSame([], $this->snapshots->list($this->temp->path('no-such-project')));
    }
}
