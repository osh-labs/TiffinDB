<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io;

use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Io\Committer;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\ImportReport;
use TiffinDB\Core\Io\Inference;
use TiffinDB\Core\Io\LinkTarget;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\MappingCheck;
use TiffinDB\Core\Io\Preparer;
use TiffinDB\Core\Io\Reader;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Io\TypeGuess;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Tests\Core\Schema\ProjectFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/../schema/ProjectFixture.php';
require_once __DIR__ . '/FakeImporter.php';

/** A project, a staging area, and helpers that run the import pipeline step by step. */
trait ImportFixture
{
    use ProjectFixture;

    private const JOB_USER = 7;

    private StagingArea $staging;
    /** @var list<SnapshotInfo> */
    private array $importSnapshots = [];
    private string|false|null $previousLog = null;

    private function setUpImport(string $seed): void
    {
        $this->setUpProject($seed);
        $this->staging = new StagingArea(new DataDirectory($this->temp->path(), 'test'), $this->clock, new Keys(new SeededRandom($seed . '-staging')));
    }

    private function tearDownImport(): void
    {
        if ($this->previousLog !== null) {
            ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        }
        $this->tearDownProject();
    }

    /** Sends error_log() to a file for the rest of the test (call in the test body); returns its path. */
    private function captureLog(): string
    {
        $path = $this->temp->path('error.log');
        $this->previousLog = ini_set('error_log', $path);

        return $path;
    }

    /** An editor whose after-change hook regenerates forms, as Core\App's does. */
    private function hookedEditor(): SchemaEditor
    {
        return new SchemaEditor(
            $this->db,
            $this->folder,
            $this->types,
            $this->keys,
            $this->clock,
            $this->snapshots,
            self::USER_KEY,
            function (SnapshotInfo $info): void {
                $this->taken[] = $info;
            },
            function (Database $db, array $tableKeys): void {
                (new FormStore($db, $this->keys, $this->clock, new FormParser($this->types)))->regenerate(new Schema($db, $this->types), array_values($tableKeys));
            },
        );
    }

    private function newJob(string $fileName = 'inventory.csv'): ImportJob
    {
        [, $folder] = $this->staging->create('imports');
        $bytes = 'synthetic upload';
        file_put_contents($folder . '/' . ImportJob::UPLOAD, $bytes);
        $job = ImportJob::create($folder, [
            'project' => self::PROJECT_KEY,
            'user' => (string) self::JOB_USER,
            'plugin' => 'import/csv',
            'file_name' => $fileName,
            'file_size' => (string) strlen($bytes),
            'file_sha256' => hash('sha256', $bytes),
            'extension' => 'csv',
            'settings' => '{}',
            'inspection' => '{}',
            'table_index' => '0',
            'created_at' => self::START,
        ]);
        $job->lock();

        return $job;
    }

    private function readAll(ImportJob $job, FakeImporter $importer, ?Reader $reader = null): int
    {
        $reader ??= new Reader($this->types);
        $chunks = 0;
        do {
            $chunks++;
            $done = $reader->chunk($job, $importer, $importer->detect($job->sourceFile()), $importer->table());
        } while (!$done);

        return $chunks;
    }

    /** @return list<TypeGuess> */
    private function guesses(ImportJob $job, FakeImporter $importer): array
    {
        return Inference::guessAll($importer->columns, $job->stats(), LinkTarget::all($this->schema()));
    }

    private function defaultMapping(ImportJob $job, FakeImporter $importer, string $tableName = 'Imported items'): Mapping
    {
        return MappingCheck::defaults(Mapping::NEW, $this->schema(), $this->guesses($job, $importer), $tableName);
    }

    private function accept(ImportJob $job, Mapping $mapping): void
    {
        self::assertSame([], MappingCheck::check($mapping, $this->schema(), $this->types, $job->stats()));
        $job->acceptMapping($mapping, Committer::schemaHash($this->db, $mapping));
    }

    private function prepareAll(ImportJob $job, ?Preparer $preparer = null): int
    {
        $preparer ??= new Preparer($this->types);
        $chunks = 0;
        do {
            $chunks++;
        } while (!$preparer->chunk($job, $this->schema()));

        return $chunks;
    }

    private function commit(ImportJob $job, ?\Closure $fault = null): ImportReport
    {
        $mapping = $job->mapping();
        self::assertNotNull($mapping);

        return (new Committer($fault))->commit(
            $job,
            $mapping,
            $this->db,
            $this->types,
            $this->clock,
            $this->hookedEditor(),
            $this->snapshots,
            $this->folder,
            self::USER_KEY,
            function (SnapshotInfo $info): void {
                $this->importSnapshots[] = $info;
            },
        );
    }

    /** Reads, accepts $mapping (or the default), and prepares, leaving the job Ready. */
    private function readyJob(FakeImporter $importer, ?\Closure $mapping = null): ImportJob
    {
        $job = $this->newJob();
        $this->readAll($job, $importer);
        $chosen = $mapping === null ? $this->defaultMapping($job, $importer) : $mapping($job);
        $this->accept($job, $chosen);
        $this->prepareAll($job);

        return $job;
    }
}
