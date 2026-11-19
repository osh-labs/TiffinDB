<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\ArchiveOperations;
use TiffinDB\Core\App\ExportOperations;
use TiffinDB\Core\App\ImportOperations;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\PluginOperations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

require_once __DIR__ . '/AppTestCase.php';
require_once __DIR__ . '/ImportOperationsTest.php';

/**
 * T-APP-90: every method of ImportOperations, ExportOperations,
 * ArchiveOperations, and PluginOperations refuses everyone but an active
 * Admin, in the core: a granted Member AccessDenied, an ungranted Member
 * NotFound (project-scoped), a deactivated or demoted Admin and an Actor
 * naming nobody AccessDenied; jobs are bound to the project and the Admin
 * who started them (P134): another Admin, another project, and keys that
 * are not jobs get NotFound. A refused call changes nothing: no audit
 * entry, no staging folder, no plugin state, no project, no job state.
 * T-APP-96: the sidebar flag for Import and Export.
 */
#[CoversClass(ImportOperations::class)]
#[CoversClass(ExportOperations::class)]
#[CoversClass(ArchiveOperations::class)]
#[CoversClass(PluginOperations::class)]
final class IoPermissionsTest extends AppTestCase
{
    /** Methods that take a project key. */
    private const PROJECT_SCOPED = [
        'imports.startScreen', 'imports.start', 'imports.job', 'imports.readChunk', 'imports.readingOptions', 'imports.setReadingOptions',
        'imports.review', 'imports.recheck', 'imports.saveMapping', 'imports.prepareChunk', 'imports.commit', 'imports.save', 'imports.report', 'imports.cancel',
        'exports.screen', 'exports.export', 'exports.archive', 'exports.finish',
    ];
    /** Methods bound to the import job of the Admin who started it. */
    private const IMPORT_JOB = [
        'imports.job', 'imports.readChunk', 'imports.readingOptions', 'imports.setReadingOptions', 'imports.review', 'imports.recheck',
        'imports.saveMapping', 'imports.prepareChunk', 'imports.commit', 'imports.save', 'imports.cancel',
    ];

    private ProjectRecord $project;
    private ProjectRecord $other;
    private TableDef $rooms;
    private Actor $admin2;
    /** @var array<string, string> */
    private array $k = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Home', null);
        $this->other = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $this->rooms = $this->ops->schema()->createTable($this->admin, $this->project->key, 'Rooms', 'room', true);
        $this->ops->projects()->setSharing($this->admin, $this->project->key, [$this->member->userKey]);
        $this->ops->projects()->setSharing($this->admin, $this->other->key, [$this->member->userKey]);
        $this->admin2 = $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
        $imports = $this->ops->imports();
        $p = $this->project->key;

        // A finished import (for report), a job in Review, a Ready job, an export download, and a kept archive.
        $done = $this->newJob('items.csv');
        $imports->saveMapping($this->admin, $p, $done, ImportOperationsTest::input($imports->review($this->admin, $p, $done, null, [])));
        while (!$imports->prepareChunk($this->admin, $p, $done)->finished) {
        }
        $this->k['log'] = $imports->commit($this->admin, $p, $done);
        $this->k['job'] = $this->newJob('rooms.csv');
        $this->k['ready'] = $this->newJob('stock.csv');
        $imports->saveMapping($this->admin, $p, $this->k['ready'], ImportOperationsTest::input($imports->review($this->admin, $p, $this->k['ready'], null, [])));
        while (!$imports->prepareChunk($this->admin, $p, $this->k['ready'])->finished) {
        }
        $this->k['export'] = $this->ops->exports()->export($this->admin, $p, 'export/csv', $this->rooms->key, [])->jobKey;
        $archive = $this->ops->exports()->archive($this->admin, $p);
        $this->k['zip'] = (string) file_get_contents($archive->path);
        $this->ops->exports()->finish($this->admin, $p, $archive->jobKey);
        $problems = self::problems(fn () => $this->ops->archives()->import($this->admin, $this->fx->upload($this->k['zip']), 'home.zip', null));
        $this->k['kept'] = (string) $problems[0][2][ArchiveOperations::JOB_PARAM];
        // The JSON export turned off, so that turning it on is a change.
        $this->ops->plugins()->disable($this->admin, 'export', 'json');
        // A second job of the same Admin in another project.
        $this->k['otherJob'] = $imports->start($this->admin, $this->other->key, $this->fx->upload("Name\nRake\n"), 'garden.csv', null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function methods(): iterable
    {
        foreach ([
            ...self::PROJECT_SCOPED,
            'archives.importScreen', 'archives.import', 'archives.importWithName',
            'plugins.list', 'plugins.get', 'plugins.enable', 'plugins.disable',
        ] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methods')]
    public function testHostileActorsAreRefusedAndChangeNothing(string $method): void
    {
        $scoped = in_array($method, self::PROJECT_SCOPED, true);
        $hostile = [
            'granted member' => [$this->member, AccessDenied::class],
            'ungranted member' => [$this->member2, $scoped ? NotFound::class : AccessDenied::class],
            'deactivated admin' => [$this->deactivated(), AccessDenied::class],
            'demoted admin' => [$this->demoted(), AccessDenied::class],
            'unknown actor' => [new Actor(99999, 'zzzzzzzzzzzz', Role::Admin, 'Nobody'), AccessDenied::class],
            'forged key' => [new Actor($this->admin->userId, 'zzzzzzzzzzzz', Role::Admin, 'Example Admin'), AccessDenied::class],
        ];
        foreach ($hostile as $label => [$actor, $expected]) {
            $before = $this->state();
            try {
                $this->call($method, $actor);
                self::fail($method . ' allowed the ' . $label . '.');
            } catch (\Throwable $error) {
                self::assertInstanceOf($expected, $error, $method . ' for the ' . $label . ': ' . $error::class . ' ' . $error->getMessage());
            }
            self::assertSame($before, $this->state(), $method . ' for the ' . $label . ' changed something.');
        }

        // The Admin who owns the jobs may call it.
        $this->call($method, $this->admin);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('methods')]
    public function testJobsAndKeysAreBoundToTheirOwnerAndProject(string $method): void
    {
        $before = $this->state();
        $importJob = in_array($method, self::IMPORT_JOB, true);
        if ($importJob || $method === 'archives.importWithName') {
            // Another Admin: not found, so they cannot even tell the job exists or is busy.
            $this->assertRefused(NotFound::class, $method, $this->admin2);
        } elseif ($method === 'exports.finish') {
            $this->call($method, $this->admin2);
            self::assertDirectoryExists($this->fx->data->path() . '/staging/exports/' . $this->k['export']);
        }
        if (in_array($method, self::PROJECT_SCOPED, true)) {
            foreach (['', 'nokey0000000', '../' . $this->project->key, $this->project->key . "\0"] as $badProject) {
                $this->assertRefused(NotFound::class, $method, $this->admin, ['project' => $badProject]);
            }
        }
        if ($importJob) {
            foreach (['', 'nokey0000000', '..', '../imports/' . $this->k['job'], $this->k['export'], $this->k['kept']] as $badJob) {
                $this->assertRefused(NotFound::class, $method, $this->admin, ['job' => $badJob, 'ready' => $badJob]);
            }
            // The Admin's own job of another project is not found under this project.
            $this->assertRefused(NotFound::class, $method, $this->admin, ['job' => $this->k['otherJob'], 'ready' => $this->k['otherJob']]);
        }
        if ($method === 'imports.report') {
            foreach (['', 'nokey0000000', $this->k['job']] as $badLog) {
                $this->assertRefused(NotFound::class, $method, $this->admin, ['log' => $badLog]);
            }
            $this->assertRefused(NotFound::class, $method, $this->admin, ['project' => $this->other->key]);
        }
        if ($method === 'archives.importWithName') {
            foreach (['', 'nokey0000000', '../archives', $this->k['job'], $this->k['export']] as $badJob) {
                $this->assertRefused(NotFound::class, $method, $this->admin, ['kept' => $badJob]);
            }
        }
        self::assertSame($before, $this->state());
    }

    /** T-APP-96. */
    public function testTheSidebarOffersImportAndExportToAdminsOnly(): void
    {
        $p = $this->project->key;
        self::assertTrue($this->ops->records()->listing($this->admin, $p, $this->rooms->key, ListRequest::none())->canImportExport);
        self::assertFalse($this->ops->records()->listing($this->member, $p, $this->rooms->key, ListRequest::none())->canImportExport);
        self::assertTrue($this->ops->records()->search($this->admin, $p, 'x')->canImportExport);
        self::assertFalse($this->ops->records()->search($this->member, $p, 'x')->canImportExport);
    }

    /** @param array<string, string> $override */
    private function assertRefused(string $class, string $method, Actor $actor, array $override = []): void
    {
        try {
            $this->call($method, $actor, $override);
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error, $method . ' with ' . json_encode($override) . ': ' . $error::class . ' ' . $error->getMessage());

            return;
        }
        self::fail($method . ' with ' . json_encode($override) . ' was allowed.');
    }

    /** @param array<string, string> $override */
    private function call(string $method, Actor $actor, array $override = []): mixed
    {
        $k = [...$this->k, 'project' => $this->project->key, ...$override];
        $p = $k['project'];
        $imports = $this->ops->imports();
        $exports = $this->ops->exports();
        $archives = $this->ops->archives();
        $plugins = $this->ops->plugins();
        $mapping = ['target' => 'new', 'table_name' => 'Imported', 'include' => ['0' => '1'], 'name' => ['0' => 'Name'], 'type' => ['0' => 'text']];

        return match ($method) {
            'imports.startScreen' => $imports->startScreen($actor, $p),
            'imports.start' => $imports->start($actor, $p, $this->fx->upload("Name\nKettle\n"), 'items.csv', null),
            'imports.job' => $imports->job($actor, $p, $k['job']),
            'imports.readChunk' => $imports->readChunk($actor, $p, $k['job']),
            'imports.readingOptions' => $imports->readingOptions($actor, $p, $k['job']),
            'imports.setReadingOptions' => $imports->setReadingOptions($actor, $p, $k['job'], ['heading' => '1']),
            'imports.review' => $imports->review($actor, $p, $k['job'], null, []),
            'imports.recheck' => $imports->recheck($actor, $p, $k['job'], $mapping),
            'imports.saveMapping' => $imports->saveMapping($actor, $p, $k['job'], $mapping),
            'imports.prepareChunk' => $imports->prepareChunk($actor, $p, $k['job']),
            'imports.commit' => $imports->commit($actor, $p, $k['ready']),
            'imports.save' => $imports->save($actor, $p, $k['ready']),
            'imports.report' => $imports->report($actor, $p, $k['log']),
            'imports.cancel' => $imports->cancel($actor, $p, $k['job']),
            'exports.screen' => $exports->screen($actor, $p),
            'exports.export' => $exports->export($actor, $p, 'export/csv', $this->rooms->key, []),
            'exports.archive' => $exports->archive($actor, $p),
            'exports.finish' => $exports->finish($actor, $p, $k['export']),
            'archives.importScreen' => $archives->importScreen($actor),
            'archives.import' => $archives->import($actor, $this->fx->upload($k['zip']), 'home.zip', 'Example Home 2'),
            'archives.importWithName' => $archives->importWithName($actor, $k['kept'], 'Example Home 2'),
            'plugins.list' => $plugins->list($actor),
            'plugins.get' => $plugins->get($actor, 'import', 'csv'),
            'plugins.enable' => $plugins->enable($actor, 'export', 'json'),
            'plugins.disable' => $plugins->disable($actor, 'import', 'csv'),
        };
    }

    private function newJob(string $fileName): string
    {
        return $this->ops->imports()->start($this->admin, $this->project->key, $this->fx->upload("Name,Floor\nKitchen,0\nAttic,2\n"), $fileName, null);
    }

    private function deactivated(): Actor
    {
        $actor = $this->fx->addPerson('gone', 'Former Admin', Role::Admin);
        $this->fx->setActive('gone', false);

        return $actor;
    }

    private function demoted(): Actor
    {
        $actor = $this->fx->addPerson('demoted', 'Demoted Admin', Role::Admin);
        $id = $actor->userId;
        $this->fx->system->db()->write(function () use ($id): void {
            $this->fx->users->update($id, 'demoted', 'Demoted Admin', Role::Member);
        });
        // A grant, so a Member check alone would let the stale Admin actor through to the project.
        $this->ops->projects()->setSharing($this->admin, $this->project->key, [$this->member->userKey, $actor->userKey]);

        return $actor;
    }

    /**
     * What a refused call must not change: audit entries, the staging
     * folders, plugin states, projects, and the states of the import jobs.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $staging = [];
        $root = $this->fx->data->path() . '/staging';
        if (is_dir($root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                $name = substr($file->getPathname(), strlen($root));
                if (!str_ends_with($name, '-wal') && !str_ends_with($name, '-shm')) {
                    $staging[] = $name;
                }
            }
            sort($staging);
        }
        $db = $this->fx->system->db();
        $jobs = [];
        foreach (['job', 'ready', 'otherJob'] as $name) {
            $jobs[$name] = $this->jobState($this->k[$name]);
        }

        return [
            'audit' => $db->all('SELECT id, action FROM audit_log ORDER BY id'),
            'staging' => $staging,
            'plugins' => $db->all('SELECT * FROM plugins ORDER BY kind, id'),
            'projects' => $db->all('SELECT id, key, name, state FROM projects ORDER BY id'),
            'grants' => $db->all('SELECT * FROM project_grants ORDER BY project_id, user_id'),
            'jobs' => $jobs,
            'records' => $this->fx->projectDb($this->project->key)->all('SELECT name FROM sqlite_master ORDER BY name'),
        ];
    }

    private function jobState(string $key): ?string
    {
        foreach (['imports'] as $kind) {
            $path = $this->fx->data->path() . '/staging/' . $kind . '/' . $key . '/job.sqlite';
            if (is_file($path)) {
                $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
                $value = $pdo->query("SELECT value FROM meta WHERE key IN ('state', 'read_rows', 'prepared_rows', 'mapping', 'settings') ORDER BY key")?->fetchAll(\PDO::FETCH_COLUMN);
                $pdo = null;

                return json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return null;
    }
}
