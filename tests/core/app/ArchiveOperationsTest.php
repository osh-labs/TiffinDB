<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\ArchiveOperations;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\RecordOperations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-APP-93 (archive export then import through the operations, with
 * audit entries), registration (M3 spec section 11.6), and T-APP-95
 * (RecordOperations::create with a stored edited form, ADR 0017 decision 4).
 */
#[CoversClass(ArchiveOperations::class)]
#[CoversClass(RecordOperations::class)]
final class ArchiveOperationsTest extends AppTestCase
{
    private ProjectRecord $project;
    private TableDef $rooms;
    private string $nameKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Home', null);
        $this->rooms = $this->ops->schema()->createTable($this->admin, $this->project->key, 'Rooms', 'room', true);
        $this->nameKey = $this->ops->schema()->design($this->admin, $this->project->key, $this->rooms->key)->fields[0]->field->key;
        $this->fx->addRecord($this->project->key, $this->rooms->key, [$this->nameKey => 'Kitchen']);
        $this->fx->addRecord($this->project->key, $this->rooms->key, [$this->nameKey => 'Garage']);
    }

    public function testExportThenImportThroughTheOperations(): void
    {
        self::assertSame(IoLimits::MAX_UPLOAD_BYTES, $this->ops->archives()->importScreen($this->admin)->maxBytes);
        $zip = $this->archiveUpload();
        $result = $this->ops->archives()->import($this->admin, $zip, 'home.tiffindb.zip', 'Example Home copy');
        $copy = $result->project;
        self::assertTrue($copy->isActive());
        self::assertNotSame($this->project->key, $copy->key);
        self::assertSame(['Example Home copy', 1, 2], [$copy->name, $result->report->tables, $result->report->records]);
        self::assertTrue($result->report->isClean());
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM project_grants WHERE project_id = ?', [$copy->id]), 'No grants are created.');

        $listing = $this->ops->records()->listing($this->admin, $copy->key, $this->rooms->key, ListRequest::none());
        self::assertSame(2, $listing->tableTotal);
        $this->assertThrows(NotFound::class, fn () => $this->ops->records()->listing($this->member, $copy->key, $this->rooms->key, ListRequest::none()));

        $actions = $this->auditActions();
        self::assertSame([AuditAction::PROJECT_CREATED, AuditAction::ARCHIVE_IMPORTED], array_slice($actions, -2));
        $entry = $this->lastAudit(AuditAction::ARCHIVE_IMPORTED);
        self::assertSame([$this->admin->userId, $copy->id, 'project', $copy->key], [$entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey]);
        self::assertSame(['tables' => 1, 'records' => 2, 'values_emptied' => 0, 'links_dropped' => 0, 'forms_dropped' => 0, 'files_missing' => 0], $entry->detail);
        $all = json_encode($this->auditEntries(), JSON_THROW_ON_ERROR);
        foreach (['Kitchen', 'home.tiffindb.zip', 'Example Home'] as $text) {
            self::assertStringNotContainsString($text, $all);
        }
        self::assertSame([], $this->stagedJobs('archives'));
        self::assertSame([], $this->stagedJobs('exports'));
    }

    public function testATakenNameKeepsTheCheckedArchiveForItsOwner(): void
    {
        $archives = $this->ops->archives();
        $problems = self::problems(fn () => $archives->import($this->admin, $this->archiveUpload(), 'home.zip', null));
        self::assertCount(1, $problems);
        [$field, $key, $params] = $problems[0];
        self::assertSame(['name', 'problem.project_name.taken'], [$field, $key]);
        $job = $params[ArchiveOperations::JOB_PARAM];
        self::assertIsString($job);
        self::assertSame([$job], $this->stagedJobs('archives'));

        $tooLong = self::problems(fn () => $archives->importWithName($this->admin, $job, str_repeat('x', 81)));
        self::assertSame([['name', 'problem.name.too_long', ['max' => 80, ArchiveOperations::JOB_PARAM => $job]]], $tooLong);

        $other = $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
        $this->assertThrows(NotFound::class, fn () => $archives->importWithName($other, $job, 'Example Home copy'));
        $this->assertThrows(NotFound::class, fn () => $archives->importWithName($this->admin, 'nokey0000000', 'Example Home copy'));
        $this->assertThrows(NotFound::class, fn () => $archives->importWithName($this->admin, '../imports', 'Example Home copy'));

        $result = $archives->importWithName($this->admin, $job, 'Example Home copy');
        self::assertSame('Example Home copy', $result->project->name);
        self::assertSame([], $this->stagedJobs('archives'));
        $this->assertThrows(NotFound::class, fn () => $archives->importWithName($this->admin, $job, 'Example Home 3'));
    }

    public function testAnArchiveTheCheckRefusesLeavesNothing(): void
    {
        $this->captureLog();
        $before = $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key');
        $problems = self::problems(fn () => $this->ops->archives()->import($this->admin, $this->fx->upload('not a zip file'), 'x.zip', null));
        self::assertSame([['file', 'problem.archive.missing_entry', []]], $problems);
        $zip = new \ZipArchive();
        $path = $this->fx->temp->path('evil.zip');
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('../escape.txt', 'synthetic');
        $zip->close();
        $problems = self::problems(fn () => $this->ops->archives()->import($this->admin, $this->fx->upload((string) file_get_contents($path)), 'evil.zip', null));
        self::assertSame([['file', 'problem.archive.entry_name', []]], $problems);
        self::assertSame([], $this->stagedJobs('archives'));
        self::assertSame($before, $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key'));
        self::assertNotContains(AuditAction::ARCHIVE_IMPORTED, $this->auditActions());
        self::assertStringContainsString('an archive was refused (entry-name', $this->errorLog());
    }

    public function testAFailureAfterTheRegistryRowLeavesNoProject(): void
    {
        $upload = $this->archiveUpload();
        $before = $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key');
        $folders = scandir($this->fx->data->path() . '/projects');
        $ops = $this->fx->operations(null, null, static function (string $point): void {
            if ($point === 'archive.activate') {
                throw new \RuntimeException('Injected failure.');
            }
        });
        $this->assertThrows(\RuntimeException::class, fn () => $ops->archives()->import($this->admin, $upload, 'home.zip', 'Example Home copy'));
        self::assertSame($before, $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key'));
        self::assertSame($folders, scandir($this->fx->data->path() . '/projects'));
        self::assertSame([], $this->stagedJobs('archives'));
        self::assertNotContains(AuditAction::ARCHIVE_IMPORTED, $this->auditActions());
    }

    public function testAnAdminDeactivatedDuringRegistrationIsRefused(): void
    {
        $upload = $this->archiveUpload();
        $other = $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
        $before = $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key');
        $ops = $this->fx->operations(null, null, function (string $point): void {
            if ($point === 'archive.register') {
                $this->fx->system->db()->run('UPDATE users SET active = 0 WHERE username = ?', ['admin2']);
            }
        });
        $this->assertThrows(\TiffinDB\Core\Error\AccessDenied::class, fn () => $ops->archives()->import($other, $upload, 'home.zip', 'Example Home copy'));
        self::assertSame($before, $this->fx->system->db()->all('SELECT key FROM projects ORDER BY key'));
        self::assertSame([], $this->stagedJobs('archives'));
    }

    /** T-APP-95: required fields a stored edited form does not show are not enforced (ADR 0017 decision 4). */
    public function testRecordCreationRequiresOnlyTheRequiredFieldsTheFormShows(): void
    {
        $p = $this->project->key;
        $notes = $this->ops->schema()->addField($this->admin, $p, $this->rooms->key, 'Notes', 'text', [], true, '');
        $db = $this->fx->projectDb($p);
        $tableKey = $this->rooms->key;
        $setForm = static function (string $inner) use ($db, $tableKey): void {
            $db->run(
                'UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "is_default" = 1 AND "table_key" = ?',
                ['<div data-tiffin-form="1"><fieldset data-tiffin-section="details"><legend>Room card</legend>' . $inner . '</fieldset></div>', $tableKey],
            );
        };
        $setForm('<div data-tiffin-field="' . $this->nameKey . '"></div>');
        $records = $this->ops->records();
        $id = $records->create($this->admin, $p, $this->rooms->key, [$this->nameKey => 'Pantry']);
        self::assertGreaterThan(0, $id);

        $setForm('<div data-tiffin-field="' . $this->nameKey . '"></div><div data-tiffin-field="' . $notes->key . '"></div>');
        $problems = self::problems(fn () => $records->create($this->admin, $p, $this->rooms->key, [$this->nameKey => 'Hall', $notes->key => '']));
        self::assertSame([[$notes->key, 'problem.value.required', []]], $problems);
    }

    /** An archive of the fixture project, copied where PHP leaves an upload. */
    private function archiveUpload(): string
    {
        $file = $this->ops->exports()->archive($this->admin, $this->project->key);
        $upload = $this->fx->upload((string) file_get_contents($file->path));
        $this->ops->exports()->finish($this->admin, $this->project->key, $file->jobKey);

        return $upload;
    }

    /** @return list<string> */
    private function stagedJobs(string $kind): array
    {
        $folder = $this->fx->data->path() . '/staging/' . $kind;

        return is_dir($folder) ? array_values(array_diff(scandir($folder) ?: [], ['.', '..'])) : [];
    }
}
