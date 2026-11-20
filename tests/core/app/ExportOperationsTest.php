<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\ExportFile;
use TiffinDB\Core\App\ExportOperations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-92: export through the operations for each format and scope (M3 spec sections 10, 13.2). */
#[CoversClass(ExportOperations::class)]
final class ExportOperationsTest extends AppTestCase
{
    private const SECRET_VALUE = 'Synthetic kettle 7';

    private ProjectRecord $project;
    private TableDef $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Home', null);
        $this->items = $this->ops->schema()->createTable($this->admin, $this->project->key, 'Kitchen items', 'item', true);
        $name = $this->ops->schema()->design($this->admin, $this->project->key, $this->items->key)->fields[0]->field->key;
        $this->fx->addRecord($this->project->key, $this->items->key, [$name => self::SECRET_VALUE]);
        $this->fx->addRecord($this->project->key, $this->items->key, [$name => '=1+1']);
    }

    /** T-CF-7 (CF-5): the export page's project size is the project file plus the stored attachment files. */
    public function testTheScreenGivesTheProjectSize(): void
    {
        $p = $this->project->key;
        $folder = $this->fx->projectFolder($p);
        $file = $folder . '/project.tiffin';
        clearstatcache();
        self::assertSame(filesize($file), $this->ops->exports()->screen($this->admin, $p)->projectBytes, 'No attachments folder yet.');

        $hash = hash('sha256', 'synthetic attachment one');
        mkdir($folder . '/attachments/' . substr($hash, 0, 2), 0o700, true);
        file_put_contents($folder . '/attachments/' . substr($hash, 0, 2) . '/' . $hash, str_repeat('a', 5000));
        $other = hash('sha256', 'synthetic attachment two');
        mkdir($folder . '/attachments/' . substr($other, 0, 2), 0o700, true);
        file_put_contents($folder . '/attachments/' . substr($other, 0, 2) . '/' . $other, str_repeat('b', 300));
        // Not counted: a link, a folder in a stored folder, a file directly under attachments/, and an odd folder.
        symlink($file, $folder . '/attachments/' . substr($hash, 0, 2) . '/link');
        mkdir($folder . '/attachments/' . substr($hash, 0, 2) . '/inner');
        file_put_contents($folder . '/attachments/' . substr($hash, 0, 2) . '/inner/x', str_repeat('c', 70));
        file_put_contents($folder . '/attachments/loose', str_repeat('d', 90));
        mkdir($folder . '/attachments/zz');
        file_put_contents($folder . '/attachments/zz/x', str_repeat('e', 110));

        clearstatcache();
        self::assertSame(filesize($file) + 5300, $this->ops->exports()->screen($this->admin, $p)->projectBytes);
    }

    public function testEveryEnabledFormatAndScopeExports(): void
    {
        $exports = $this->ops->exports();
        $p = $this->project->key;
        $screen = $exports->screen($this->admin, $p);
        $refs = array_map(static fn ($f) => $f->ref, $screen->formats);
        self::assertContains('export/csv', $refs);
        self::assertContains('export/json', $refs);
        self::assertSame('export/csv', $refs[0]);
        self::assertTrue($screen->archive);
        self::assertSame(['Kitchen items'], array_map(static fn ($t) => $t->table->name, $screen->tables));
        $csv = $screen->formats[0];
        self::assertSame(['table'], $csv->scopes);
        self::assertSame(['record_ids', 'spreadsheet_safe'], array_map(static fn ($s) => $s->name, $csv->tableSettings));

        $exported = 0;
        foreach ($screen->formats as $format) {
            foreach ($format->scopes as $scope) {
                $file = $exports->export($this->admin, $p, $format->ref, $scope === 'table' ? $this->items->key : null, []);
                self::assertFileExists($file->path);
                self::assertSame(filesize($file->path), $file->size);
                $base = $scope === 'table' ? 'kitchen-items' : 'example-home';
                self::assertSame($base . '.' . $format->extension, $file->fileName);
                $this->assertContent($format->ref, $file);
                $exports->finish($this->admin, $p, $file->jobKey);
                self::assertFileDoesNotExist($file->path);
                $exported++;
            }
        }
        self::assertGreaterThanOrEqual(2, $exported);

        $entry = $this->lastAudit(AuditAction::EXPORT_CREATED);
        self::assertSame([$this->admin->userId, $this->project->id], [$entry->actorUserId, $entry->projectId]);
        $first = array_values(array_filter($this->auditEntries(), static fn ($e) => $e->action === AuditAction::EXPORT_CREATED))[0];
        self::assertSame(['table', $this->items->key, ['format' => 'export/csv', 'scope' => 'table', 'records' => 2]], [$first->targetKind, $first->targetKey, $first->detail]);
        $all = json_encode($this->auditEntries(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Synthetic kettle', $all);
        self::assertStringNotContainsString('kitchen-items', $all);
        self::assertStringNotContainsString('.csv', $all);
    }

    public function testSettingsArePassedAndUnknownOnesIgnored(): void
    {
        $file = $this->ops->exports()->export($this->admin, $this->project->key, 'export/csv', $this->items->key, ['record_ids' => '1', 'spreadsheet_safe' => '1', 'other' => 'x', 'record' => ['x']]);
        $text = (string) file_get_contents($file->path);
        self::assertStringStartsWith("\u{FEFF}id,Name\r\n1,", $text);
        self::assertStringContainsString("'=1+1", $text);
    }

    public function testTamperedChoicesAndDisabledFormatsAreRefused(): void
    {
        $exports = $this->ops->exports();
        $p = $this->project->key;
        self::assertSame([['format', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'export/none', null, [])));
        self::assertSame([['format', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'import/csv', $this->items->key, [])));
        self::assertSame([['scope', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'export/csv', null, [])));
        self::assertSame([['scope', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'export/json', $this->items->key, [])));
        self::assertSame([['table', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'export/csv', 'nokey0000000', [])));

        $this->ops->plugins()->disable($this->admin, 'export', 'csv');
        self::assertNotContains('export/csv', array_map(static fn ($f) => $f->ref, $exports->screen($this->admin, $p)->formats));
        self::assertSame([['format', 'problem.options.invalid', []]], self::problems(fn () => $exports->export($this->admin, $p, 'export/csv', $this->items->key, [])));
        self::assertSame([], $this->stagedJobs());
        self::assertNotContains(AuditAction::EXPORT_CREATED, $this->auditActions());
    }

    public function testFinishRemovesOnlyTheActorsOwnJob(): void
    {
        $exports = $this->ops->exports();
        $p = $this->project->key;
        $file = $exports->export($this->admin, $p, 'export/csv', $this->items->key, []);
        $other = $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
        $exports->finish($other, $p, $file->jobKey);
        self::assertFileExists($file->path, 'Another Admin cannot remove the download.');
        $second = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $exports->finish($this->admin, $second->key, $file->jobKey);
        self::assertFileExists($file->path, 'The job of another project is left alone.');
        $exports->finish($this->admin, $p, 'not a key');
        $exports->finish($this->admin, $p, 'nokey0000000');
        $exports->finish($this->admin, $p, $file->jobKey);
        self::assertSame([], $this->stagedJobs());
    }

    public function testTheProjectArchive(): void
    {
        $file = $this->ops->exports()->archive($this->admin, $this->project->key);
        self::assertSame(['example-home.tiffindb.zip', 'application/zip'], [$file->fileName, $file->mediaType]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file->path));
        self::assertSame('tiffindb-archive.json', $zip->getNameIndex(0));
        $zip->close();
        $entry = $this->lastAudit(AuditAction::ARCHIVE_EXPORTED);
        self::assertSame(['project', $this->project->key, ['attachments' => 0]], [$entry->targetKind, $entry->targetKey, $entry->detail]);
        $this->ops->exports()->finish($this->admin, $this->project->key, $file->jobKey);
        self::assertSame([], $this->stagedJobs());
    }

    private function assertContent(string $ref, ExportFile $file): void
    {
        $bytes = (string) file_get_contents($file->path);
        if ($ref === 'export/csv') {
            self::assertSame('text/csv', $file->mediaType);
            self::assertSame("\u{FEFF}Name\r\n" . self::SECRET_VALUE . "\r\n=1+1\r\n", $bytes);
        } elseif ($ref === 'export/json') {
            self::assertSame('application/json', $file->mediaType);
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertStringContainsString(self::SECRET_VALUE, $bytes);
        } else {
            self::assertGreaterThan(0, strlen($bytes));
        }
    }

    /** @return list<string> */
    private function stagedJobs(): array
    {
        $folder = $this->fx->data->path() . '/staging/exports';

        return is_dir($folder) ? array_values(array_diff(scandir($folder) ?: [], ['.', '..'])) : [];
    }
}
