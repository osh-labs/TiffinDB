<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Storage\Database;

require_once __DIR__ . '/ImportFixture.php';

/** T-IO-3: the job file, its meta, state transitions, ownership, and the job lock. */
#[CoversClass(ImportJob::class)]
#[CoversClass(JobState::class)]
final class ImportJobTest extends TestCase
{
    use ImportFixture;

    protected function setUp(): void
    {
        $this->setUpImport('tiffindb-import-job-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownImport();
    }

    public function testSchemaAndMeta(): void
    {
        $job = $this->newJob();
        $master = [];
        foreach ($job->db()->all('SELECT name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name') as $row) {
            $master[$row['name']] = $row['sql'];
        }
        self::assertSame([
            'issues' => 'CREATE TABLE issues (n INTEGER NOT NULL, col INTEGER NOT NULL, kind TEXT NOT NULL, PRIMARY KEY (n, col)) WITHOUT ROWID',
            'meta' => 'CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID',
            'prepared' => 'CREATE TABLE prepared (n INTEGER PRIMARY KEY, record TEXT, skip TEXT)',
            'rows' => 'CREATE TABLE rows (n INTEGER PRIMARY KEY, cells TEXT NOT NULL)',
        ], $master);
        self::assertSame(JobState::Reading, $job->state());
        self::assertSame('inventory.csv', $job->meta('file_name'));
        self::assertSame(0, $job->metaInt('read_rows'));
        $job->setMetaJson('inspection', ['tables' => [['name' => "Sheet \u{00e9}", 'columns' => ['A']]], 'n' => 1.0]);
        self::assertSame(['tables' => [['name' => "Sheet \u{00e9}", 'columns' => ['A']]], 'n' => 1.0], $job->metaJson('inspection'));
        $job->setMeta('file_name', 'other.csv');
        self::assertSame('other.csv', $job->meta('file_name'));
        $job->removeMeta('file_name');
        self::assertNull($job->meta('file_name'));
        self::assertNull($job->metaJson('mapping'));
        foreach ([fn () => $job->meta('unknown'), fn () => $job->setMeta('cells', 'x'), fn () => $job->setMeta('state', 'reading" OR 1')] as $i => $bad) {
            try {
                $bad();
                if ($i < 2) {
                    self::fail('An unknown meta key was accepted.');
                }
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $job->setMeta('state', 'nonsense');
        $this->expectException(\UnexpectedValueException::class);
        $job->state();
    }

    public function testReopenAndKey(): void
    {
        $job = $this->newJob();
        $folder = $job->folder();
        self::assertSame(basename($folder), $job->key());
        $job->close();
        $again = ImportJob::open($folder);
        self::assertSame('inventory.csv', $again->meta('file_name'));
        $again->close();
        unlink($folder . '/' . ImportJob::FILE);
        $this->expectException(NotFound::class);
        ImportJob::open($folder);
    }

    public function testTransitions(): void
    {
        $allowed = [
            'reading' => ['review', 'reading', 'failed'],
            'review' => ['preparing', 'reading', 'review', 'failed'],
            'preparing' => ['ready', 'review', 'reading', 'preparing', 'failed'],
            'ready' => ['done', 'review', 'reading', 'preparing', 'failed'],
            'done' => [],
            'failed' => [],
        ];
        foreach (JobState::cases() as $from) {
            foreach (JobState::cases() as $to) {
                self::assertSame(in_array($to->value, $allowed[$from->value], true), $from->canMoveTo($to), $from->value . ' to ' . $to->value);
            }
        }
        $job = $this->newJob();
        $job->moveTo(JobState::Review);
        try {
            $job->moveTo(JobState::Done);
            self::fail('Review to Done was allowed.');
        } catch (\LogicException) {
            self::assertSame(JobState::Review, $job->state());
        }
        $job->fail('plugin-unavailable');
        self::assertSame(JobState::Failed, $job->state());
        self::assertSame('plugin-unavailable', $job->failure());
        foreach ([JobState::Reading, JobState::Review] as $to) {
            try {
                $job->moveTo($to);
                self::fail('A failed job moved on.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $other = $this->newJob();
        $this->expectException(\InvalidArgumentException::class);
        $other->fail('Not A Code');
    }

    public function testResetAndAcceptMapping(): void
    {
        $job = $this->newJob();
        $job->db()->write(static function (Database $db): void {
            $db->run('INSERT INTO rows (n, cells) VALUES (1, \'["a"]\')');
            $db->run('INSERT INTO prepared (n, record, skip) VALUES (1, \'{}\', NULL)');
            $db->run('INSERT INTO issues (n, col, kind) VALUES (1, 0, \'too-long\'), (1, 1, \'unreadable\')');
        });
        $job->setMeta('read_rows', '9');
        $job->moveTo(JobState::Review);
        $mapping = new Mapping(Mapping::NEW, 'Items', null, [new ColumnMapping(0, true, 'A', 'text', null)]);
        $job->acceptMapping($mapping, str_repeat('ab', 32));
        self::assertSame(JobState::Preparing, $job->state());
        self::assertSame(0, (int) $job->db()->value('SELECT COUNT(*) FROM prepared'));
        self::assertSame([['kind' => 'too-long']], $job->db()->all('SELECT kind FROM issues'), 'Read issues stay; prepare issues go.');
        self::assertSame($mapping->toArray(), $job->mapping()?->toArray());
        self::assertSame(str_repeat('ab', 32), $job->meta('schema_hash'));

        $job->resetReading();
        self::assertSame(JobState::Reading, $job->state());
        foreach (['rows', 'prepared', 'issues'] as $table) {
            self::assertSame(0, (int) $job->db()->value('SELECT COUNT(*) FROM ' . $table));
        }
        self::assertNull($job->mapping());
        self::assertSame(0, $job->metaInt('read_rows'));

        $job->moveTo(JobState::Review);
        $this->expectException(\InvalidArgumentException::class);
        $job->acceptMapping($mapping, 'not a hash');
    }

    public function testOwnership(): void
    {
        $job = $this->newJob();
        $job->checkOwner(self::PROJECT_KEY, self::JOB_USER);
        foreach ([[self::PROJECT_KEY, self::JOB_USER + 1], ['b7xk2mq4nz3p', self::JOB_USER], ['', 0]] as [$project, $user]) {
            try {
                $job->checkOwner($project, $user);
                self::fail('Another owner was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheJobLockIsExclusive(): void
    {
        $job = $this->newJob();
        self::assertTrue($job->isLocked());
        $second = ImportJob::open($job->folder());
        try {
            $second->lock();
            self::fail('Two holders of one job lock.');
        } catch (Busy) {
            self::assertFalse($second->isLocked());
        }
        $job->unlock();
        $second->lock();
        self::assertTrue($second->isLocked());
        $second->close();
        $job->lock();
        $job->lock();
        self::assertTrue($job->isLocked());
        unlink($job->folder() . '/job.lock');
        $job->unlock();
        $this->expectException(NotFound::class);
        $job->lock();
    }
}
