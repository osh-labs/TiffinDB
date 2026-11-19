<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Io\ImportLog;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-2 and T-ARC-8: a written archive imports into a new project with
 * a new key, keys and record ids kept, links, forms, attachments, and
 * files intact, nothing emptied or dropped, one `archive` row in the
 * import log, ids that continue after the source's, and no grants; the
 * project opens through the core. Attachment files whose hash or size do
 * not match are not kept, unlisted entries are skipped, and listed but
 * absent files are counted.
 */
#[CoversClass(ProjectRebuilder::class)]
#[CoversClass(ArchiveReader::class)]
#[CoversClass(UntrustedProject::class)]
#[CoversClass(RebuildReport::class)]
final class ArchiveImportTest extends TestCase
{
    use ArchiveFixture;

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-import-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testRoundTripKeepsKeysIdsLinksFormsAndFiles(): void
    {
        $zip = $this->export();
        $this->clock->set('2026-11-20T08:00:00Z');
        [$report, $db, $folder] = $this->import($zip);

        self::assertTrue($report->isClean());
        self::assertSame(2, $report->tables);
        self::assertSame(5, $report->records);
        self::assertSame(0, $report->valuesEmptiedTotal());

        $meta = array_column($db->all('SELECT "key", "value" FROM "_tiffin_meta"'), 'value', 'key');
        self::assertSame(self::NEW_KEY, $meta['project_key']);
        self::assertSame('Imported Project', $meta['project_name']);
        self::assertSame(self::START, $meta['created_at']);
        self::assertSame(self::USER_KEY, $meta['created_by']);
        self::assertSame('0.2.0-dev', $meta['created_with']);
        self::assertSame('2026-11-20T08:00:00Z', $meta['updated_at']);
        self::assertSame(self::IMPORTER_KEY, $meta['updated_by']);
        self::assertSame('3', $meta['format_version']);

        foreach (['_tiffin_tables', '_tiffin_fields', '_tiffin_relations', '_tiffin_attachments', '_tiffin_attachment_links'] as $table) {
            self::assertSame($this->rows($this->db, $table), $this->rows($db, $table), $table);
        }
        foreach ([Identifier::tableName($this->items->key), Identifier::tableName($this->rooms->key), Identifier::joinName($this->room->key)] as $table) {
            self::assertSame($this->rows($this->db, $table), $this->rows($db, $table), $table);
        }
        $forms = 'SELECT "key", "table_key", "name", "format", "html", "css", "is_default", "generated" FROM "_tiffin_forms" ORDER BY "key"';
        self::assertSame($this->db->all($forms), $db->all($forms));

        foreach ([self::FILE_ONE, self::FILE_TWO] as $content) {
            $sha256 = hash('sha256', $content);
            $path = $folder . '/attachments/' . substr($sha256, 0, 2) . '/' . $sha256;
            self::assertSame($content, file_get_contents($path));
            self::assertSame(0640, fileperms($path) & 0777);
        }
        self::assertDirectoryExists($folder . '/snapshots');

        $log = $db->all('SELECT "key" FROM "_tiffin_import_log"');
        self::assertCount(1, $log);
        $entry = ImportLog::get($db, (string) $log[0]['key']);
        self::assertNotNull($entry);
        self::assertSame($report->importLogKey, $entry->key);
        self::assertSame('archive', $entry->source);
        self::assertNull($entry->plugin);
        self::assertNull($entry->tableKey);
        self::assertSame('example.zip', $entry->fileName);
        self::assertSame(filesize($zip), $entry->fileSize);
        self::assertSame(hash_file('sha256', $zip), $entry->fileSha256);
        self::assertSame(5, $entry->rowsRead);
        self::assertSame(5, $entry->rowsImported);
        self::assertSame(0, $entry->valuesEmptied);
        self::assertSame(self::IMPORTER_KEY, $entry->importedBy);
        self::assertSame(['archive' => ['values_emptied' => 0, 'links_dropped' => 0, 'forms_dropped' => 0, 'forms_extended' => 0, 'files_missing' => 0]], $entry->report);

        self::assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'));
        self::assertSame([], $db->all('PRAGMA foreign_key_check'));
    }

    public function testNewIdsContinueAfterTheSource(): void
    {
        $gone = $this->insert($this->items->key, [$this->f['Name']->key => 'Deleted later']);
        $this->db->run('DELETE FROM ' . Identifier::table($this->items->key) . ' WHERE "id" = ?', [$gone]);
        [, $db] = $this->import($this->export());

        self::assertSame($gone, $db->value('SELECT "seq" FROM "sqlite_sequence" WHERE "name" = ?', [Identifier::tableName($this->items->key)]));
        $db->run('INSERT INTO ' . Identifier::table($this->items->key) . ' ("_created_at", "_updated_at") VALUES (?, ?)', [self::START, self::START]);
        self::assertSame($gone + 1, $db->lastInsertId());
        $options = json_decode((string) $db->value('SELECT "options" FROM "_tiffin_fields" WHERE "key" = ?', [$this->f['Code']->key]), true);
        self::assertSame(['start' => 1, 'next' => 4], $options);
    }

    public function testImportedProjectOpensThroughTheCoreWithoutGrants(): void
    {
        [, $db, $folder] = $this->import($this->export());
        $db->close();
        $core = new CoreFixture();
        try {
            $this->register($core, $folder);
            $ops = $core->operations();
            $screen = $ops->records()->blank($core->actor('admin'), self::NEW_KEY, $this->items->key, ListRequest::none());
            self::assertNotNull($screen->form);
            self::assertSame('Imported Project', $screen->project->name);
            $listing = $ops->records()->listing($core->actor('admin'), self::NEW_KEY, $this->items->key, ListRequest::none());
            self::assertSame(2, $listing->total ?? count($listing->rows));
            self::assertSame([], $core->system->db()->all('SELECT * FROM project_grants'));
            $this->expectException(NotFound::class);
            $ops->records()->listing($core->actor('member'), self::NEW_KEY, $this->items->key, ListRequest::none());
        } finally {
            $core->remove();
        }
    }

    public function testAttachmentFilesMustMatchTheirRows(): void
    {
        $copy = $this->copyOfSource();
        $three = 'synthetic bytes whose row gives the wrong size';
        $four = 'synthetic bytes listed but never packed';
        $unlisted = 'synthetic bytes nobody lists';
        $sha3 = hash('sha256', $three);
        $sha4 = hash('sha256', $four);
        $sha5 = hash('sha256', $unlisted);
        $sha1 = hash('sha256', self::FILE_ONE);
        $sha2 = hash('sha256', self::FILE_TWO);
        $insert = 'INSERT INTO "_tiffin_attachments" ("id", "sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES ';
        self::rawEdit(
            $copy,
            $insert . "(3, '" . $sha3 . "', 5, 'text/plain', 'three.txt', '" . self::START . "', NULL)",
            $insert . "(4, '" . $sha4 . "', " . strlen($four) . ", 'text/plain', 'four.txt', '" . self::START . "', NULL)",
        );
        $entries = [
            'attachments/' . substr($sha1, 0, 2) . '/' . $sha1 => self::FILE_ONE,
            'attachments/' . substr($sha2, 0, 2) . '/' . $sha2 => 'synthetic bytes that are not file two',
            'attachments/' . substr($sha3, 0, 2) . '/' . $sha3 => $three,
            'attachments/' . substr($sha5, 0, 2) . '/' . $sha5 => $unlisted,
        ];
        [$report, $db, $folder] = $this->import($this->zipFor($copy, [], $entries));

        self::assertSame(3, $report->filesMissing);
        self::assertSame(1, $report->filesSkipped);
        self::assertSame(self::FILE_ONE, file_get_contents($folder . '/attachments/' . substr($sha1, 0, 2) . '/' . $sha1));
        foreach ([$sha2, $sha3, $sha4, $sha5] as $sha256) {
            self::assertFileDoesNotExist($folder . '/attachments/' . substr($sha256, 0, 2) . '/' . $sha256);
        }
        self::assertSame(4, $db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'));
        self::assertSame(3, json_decode((string) $db->value('SELECT "report" FROM "_tiffin_import_log"'), true)['archive']['files_missing']);
    }

    public function testBadAttachmentRowsAreDroppedOrRepaired(): void
    {
        $copy = $this->copyOfSource();
        $insert = 'INSERT INTO "_tiffin_attachments" ("id", "sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES ';
        $sha = hash('sha256', 'synthetic');
        self::rawEdit(
            $copy,
            $insert . "(3, 'not a hash', 1, 'text/plain', 'a.txt', '" . self::START . "', NULL)",
            $insert . "(4, '" . $sha . "', -1, 'text/plain', 'b.txt', '" . self::START . "', NULL)",
            $insert . "(5, '" . $sha . "', 9, 'Text/<b>', '../../etc/passwd', 'yesterday', 'not a key')",
            'INSERT INTO "_tiffin_attachment_links" VALUES (\'' . $this->f['Photo']->key . "', 999, 5, 1)",
            'INSERT INTO "_tiffin_attachment_links" VALUES (\'' . $this->f['Name']->key . "', 1, 5, 1)",
        );
        [$report, $db] = $this->import($this->zipFor($copy));

        self::assertSame(2, $report->attachmentsDropped);
        $row = $db->one('SELECT * FROM "_tiffin_attachments" WHERE "id" = 5');
        self::assertNotNull($row);
        self::assertSame('application/octet-stream', $row['media_type']);
        self::assertSame('file', $row['original_name']);
        self::assertSame(Timestamp::format($this->clock->now()), $row['created_at']);
        self::assertNull($row['created_by']);
        self::assertSame(1, $report->personKeysCleared);
        self::assertSame(0, $db->value('SELECT COUNT(*) FROM "_tiffin_attachment_links" WHERE "attachment_id" = 5'));
    }

    /** Registers a rebuilt folder as the fixture instance's project NEW_KEY (what M3-WP8 does). */
    private function register(CoreFixture $core, string $folder): void
    {
        $core->system->db()->write(function () use ($core): void {
            $registry = new ProjectRegistry($core->system);
            $record = $registry->add(self::NEW_KEY, 'Imported Project', $core->person('admin')->id, CoreFixture::START);
            $registry->activate($record->id);
        });
        $target = $core->projectFolder(self::NEW_KEY);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0750, true);
        }
        self::assertTrue(rename($folder, $target));
    }

    /** @return list<array<string, mixed>> */
    private function rows(Database $db, string $table): array
    {
        $columns = (int) $db->value('SELECT COUNT(*) FROM pragma_table_info(?)', [$table]);

        return $db->all('SELECT * FROM "' . $table . '" ORDER BY ' . implode(', ', range(1, $columns)));
    }
}
