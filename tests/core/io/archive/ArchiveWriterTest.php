<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveManifest;
use TiffinDB\Core\Io\Archive\ArchiveWriter;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Sqlite;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-1: ArchiveWriter writes exactly the entries of
 * `docs/formats/tiffin.md` section 13.1, a manifest whose values match the
 * copy, a consistent copy while another connection writes to the WAL, no
 * `-wal` or `-shm` entry, and skips attachment files that are missing or
 * are not regular files.
 */
#[CoversClass(ArchiveWriter::class)]
#[CoversClass(ArchiveManifest::class)]
final class ArchiveWriterTest extends TestCase
{
    use ArchiveFixture;

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-writer-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testEntriesAreExactlyTheFormatInOrder(): void
    {
        $zipPath = $this->export();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS));
        $names = [];
        $methods = [];
        for ($i = 0; $i < $zip->count(); $i++) {
            $stat = $zip->statIndex($i);
            self::assertIsArray($stat);
            $names[] = $stat['name'];
            $methods[$stat['name']] = $stat['comp_method'];
            self::assertSame(0, $stat['encryption_method']);
        }
        $one = hash('sha256', self::FILE_ONE);
        $two = hash('sha256', self::FILE_TWO);
        $attachments = ['attachments/' . substr($one, 0, 2) . '/' . $one, 'attachments/' . substr($two, 0, 2) . '/' . $two];
        sort($attachments);
        $entryAttachments = array_slice($names, 2);
        sort($entryAttachments);
        self::assertSame(['tiffindb-archive.json', 'project.tiffin'], array_slice($names, 0, 2));
        self::assertSame($attachments, $entryAttachments);
        self::assertSame(\ZipArchive::CM_DEFLATE, $methods['tiffindb-archive.json']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $methods['project.tiffin']);
        foreach ($attachments as $name) {
            self::assertSame(\ZipArchive::CM_STORE, $methods[$name]);
        }
        self::assertSame(self::FILE_ONE, $zip->getFromName($attachments[array_search('attachments/' . substr($one, 0, 2) . '/' . $one, $attachments, true)]));
        $zip->close();
        self::assertFileDoesNotExist(dirname($zipPath) . '/' . ArchiveWriter::COPY_FILE);
        self::assertSame(0640, fileperms($zipPath) & 0777);
    }

    public function testManifestMatchesTheCopy(): void
    {
        $zipPath = $this->export();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        $json = (string) $zip->getFromName('tiffindb-archive.json');
        $copy = (string) $zip->getFromName('project.tiffin');
        $zip->close();

        $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(ArchiveManifest::MEMBERS, array_keys($decoded));
        self::assertSame([
            'format' => 'tiffindb-archive',
            'archive_version' => 1,
            'created_at' => self::CREATED,
            'created_with' => self::VERSION_TEXT,
            'project_format_version' => 4,
            'project_name' => 'Example Project',
            'project_size' => strlen($copy),
            'project_sha256' => hash('sha256', $copy),
            'attachments' => 2,
        ], $decoded);
        $manifest = ArchiveManifest::fromJson($json);
        self::assertSame($json, $manifest->toJson());
        self::assertSame("SQLite format 3\0", substr($copy, 0, 16));
        self::assertSame("\x01\x01", substr($copy, 18, 2), 'The copy is a rollback-journal file that opens on its own.');
    }

    public function testCopyIsConsistentWhileAnotherConnectionWrites(): void
    {
        self::assertSame('wal', $this->db->journalMode());
        // A committed change that is still only in the WAL, and an uncommitted one on another connection.
        $this->insert($this->rooms->key, [$this->roomName->key => 'Hallway']);
        $other = Sqlite::open($this->path());
        $this->extraConnections[] = $other;
        $zipPath = null;
        $other->write(function () use ($other, &$zipPath): void {
            $other->run('INSERT INTO ' . Identifier::table($this->rooms->key) . ' ("_created_at", "_updated_at", ' . Identifier::column($this->roomName->key) . ') VALUES (?, ?, ?)', [self::START, self::START, 'Attic']);
            $zipPath = $this->export();
        });
        self::assertIsString($zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        $copyPath = $this->jobFolder() . '/copy.tiffin';
        file_put_contents($copyPath, (string) $zip->getFromName('project.tiffin'));
        for ($i = 0; $i < $zip->count(); $i++) {
            self::assertStringNotContainsString('-wal', (string) $zip->getNameIndex($i));
            self::assertStringNotContainsString('-shm', (string) $zip->getNameIndex($i));
        }
        $zip->close();
        $copy = Sqlite::openUntrusted($copyPath);
        $this->extraConnections[] = $copy;
        $names = array_column($copy->all('SELECT ' . Identifier::column($this->roomName->key) . ' AS "n" FROM ' . Identifier::table($this->rooms->key) . ' ORDER BY "id"'), 'n');
        self::assertSame(['Kitchen', 'Garage', 'Hallway'], $names);
        self::assertSame(['ok'], array_column($copy->all('PRAGMA integrity_check'), 'integrity_check'));
    }

    public function testMissingAndLinkedAttachmentFilesAreSkipped(): void
    {
        $three = $this->attach(3, 'synthetic bytes, never stored', 'gone.txt', 'text/plain', $this->itemIds[0], false);
        $four = $this->attach(4, 'synthetic bytes behind a link', 'linked.txt', 'text/plain', $this->itemIds[1], false);
        $outside = $this->temp->path('outside.txt');
        file_put_contents($outside, 'synthetic bytes behind a link');
        mkdir($this->folder . '/attachments/' . substr($four, 0, 2), 0750, true);
        symlink($outside, $this->folder . '/attachments/' . substr($four, 0, 2) . '/' . $four);

        $zipPath = $this->export();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        self::assertFalse($zip->locateName('attachments/' . substr($three, 0, 2) . '/' . $three));
        self::assertFalse($zip->locateName('attachments/' . substr($four, 0, 2) . '/' . $four));
        self::assertSame(4, $zip->count());
        $manifest = ArchiveManifest::fromJson((string) $zip->getFromName('tiffindb-archive.json'));
        $zip->close();
        self::assertSame(2, $manifest->attachments);
    }

    public function testRefusesInsideATransactionAndAnExistingTarget(): void
    {
        $target = $this->jobFolder() . '/archive.zip';
        file_put_contents($target, 'already here');
        try {
            (new ArchiveWriter())->write($this->db, $this->folder, $target, ArchiveManifest::facts(self::CREATED, self::VERSION_TEXT, 'Example Project'));
            self::fail('An existing target was overwritten.');
        } catch (\RuntimeException) {
            self::assertSame('already here', file_get_contents($target));
        }
        $this->expectException(\LogicException::class);
        $this->db->write(function (): void {
            (new ArchiveWriter())->write($this->db, $this->folder, $this->jobFolder() . '/archive.zip', ArchiveManifest::facts(self::CREATED, self::VERSION_TEXT, 'Example Project'));
        });
    }
}
