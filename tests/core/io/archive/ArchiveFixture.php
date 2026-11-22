<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Io\Archive\ArchiveManifest;
use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ArchiveWriter;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Io\Archive\ZipCheck;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\RelationDef;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Tests\Core\Schema\ProjectFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/../../schema/ProjectFixture.php';
require_once __DIR__ . '/ZipBytes.php';

/**
 * A synthetic source project and helpers for the archive tests (M3 spec
 * section 15.5): export it, copy its database and change the copy with
 * plain SQL to build a hostile file, wrap any database in a zip with a
 * matching manifest, and run the whole import (reader, check, rebuild)
 * into a new project. Every name and value is made up.
 *
 * The source has two tables. Items: Name (text, title), Code
 * (auto-number), Price (currency), Done (checkbox), Kind (choice: Tool,
 * Toy), Notes (long text), Bought (date), Weight (number, at most 1000),
 * Photo (attachment), Room (relation to Rooms, one room per item); three
 * records, the third in the trash. Rooms: Name, Items (the other side);
 * two records. Two attachment files, each linked to one item.
 */
trait ArchiveFixture
{
    use ProjectFixture;

    /** The key the imported project gets. */
    private const NEW_KEY = 'n3wk2yq7zx4b';
    private const IMPORTER_KEY = 'p5rk3wq2zn7x';
    private const VERSION_TEXT = '0.3.0-dev';
    private const CREATED = '2026-11-19T10:15:00Z';
    private const FILE_ONE = 'synthetic receipt bytes, example one';
    private const FILE_TWO = 'synthetic photo bytes, example two';

    private TableDef $items;
    private TableDef $rooms;
    /** @var array<string, FieldDef> by name, Items fields */
    private array $f = [];
    private FieldDef $roomName;
    private RelationDef $room;
    /** @var list<int> */
    private array $itemIds = [];
    /** @var list<int> */
    private array $roomIds = [];
    private int $jobs = 0;
    /** @var list<Database> */
    private array $opened = [];
    /** @var list<UntrustedProject> */
    private array $checked = [];
    /** @var list<ArchiveReader> */
    private array $readers = [];

    private function setUpArchive(string $seed): void
    {
        $this->setUpProject($seed);
        mkdir($this->folder . '/attachments', 0750);
        mkdir($this->folder . '/snapshots', 0750);
    }

    private function tearDownArchive(): void
    {
        foreach ($this->readers as $reader) {
            $reader->close();
        }
        foreach ($this->checked as $checked) {
            $checked->close();
        }
        foreach ($this->opened as $db) {
            $db->close();
        }
        $this->tearDownProject();
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
            static function (SnapshotInfo $info): void {
            },
            function (Database $db, array $tableKeys): void {
                (new FormStore($db, $this->keys, $this->clock, new FormParser($this->types)))->regenerate(new Schema($db, $this->types), $tableKeys);
            },
        );
    }

    private function buildSource(): void
    {
        $editor = $this->hookedEditor();
        $this->items = $editor->createTable('Items', 'item', true);
        $this->f['Name'] = $this->schema()->fields($this->items->key)[0];
        $this->f['Code'] = $editor->addField($this->items->key, 'Code', 'auto_number', [], false, '');
        $this->f['Price'] = $editor->addField($this->items->key, 'Price', 'currency', ['code' => 'USD', 'minor_digits' => 2], false, '');
        $this->f['Done'] = $editor->addField($this->items->key, 'Done', 'checkbox', [], false, '');
        $this->f['Kind'] = $editor->addField($this->items->key, 'Kind', 'choice', ['choices' => ['Tool', 'Toy']], false, '');
        $this->f['Notes'] = $editor->addField($this->items->key, 'Notes', 'long_text', [], false, '');
        $this->f['Bought'] = $editor->addField($this->items->key, 'Bought', 'date', [], false, '');
        $this->f['Weight'] = $editor->addField($this->items->key, 'Weight', 'number', ['decimals' => 2, 'max' => 1000], false, '');
        $this->f['Photo'] = $editor->addField($this->items->key, 'Photo', 'attachment', [], false, '');
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->roomName = $this->schema()->fields($this->rooms->key)[0];
        $this->f['Room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $relation = $this->schema()->relationOf($this->f['Room']);
        self::assertNotNull($relation);
        $this->room = $relation;
        $this->items = $this->schema()->table($this->items->key);

        $this->roomIds[] = $this->insert($this->rooms->key, [$this->roomName->key => 'Kitchen']);
        $this->roomIds[] = $this->insert($this->rooms->key, [$this->roomName->key => 'Garage']);
        $this->itemIds[] = $this->insert($this->items->key, [
            $this->f['Name']->key => 'Hammer', $this->f['Code']->key => 1, $this->f['Price']->key => 1299, $this->f['Done']->key => 1,
            $this->f['Kind']->key => 'Tool', $this->f['Notes']->key => "First line\nSecond line", $this->f['Bought']->key => '2025-03-14',
            $this->f['Weight']->key => 0.75,
        ]);
        $this->itemIds[] = $this->insert($this->items->key, [
            $this->f['Name']->key => 'Kite', $this->f['Code']->key => 2, $this->f['Price']->key => -450, $this->f['Kind']->key => 'Toy',
        ]);
        $this->itemIds[] = $this->insert($this->items->key, [$this->f['Name']->key => 'Old lamp', $this->f['Code']->key => 3], true);
        $this->db->run('UPDATE ' . Identifier::table($this->items->key) . ' SET "_trashed_by" = ?, "_created_by" = ? WHERE "_trashed_at" IS NOT NULL', [self::USER_KEY, self::USER_KEY]);
        $this->db->run('UPDATE "_tiffin_fields" SET "options" = ? WHERE "key" = ?', ['{"start":1,"next":4}', $this->f['Code']->key]);
        $this->link($this->room->key, $this->itemIds[0], $this->roomIds[0]);
        $this->link($this->room->key, $this->itemIds[1], $this->roomIds[1]);
        $this->link($this->room->key, $this->itemIds[2], $this->roomIds[0]);
        $this->attach(1, self::FILE_ONE, 'receipt.pdf', 'application/pdf', $this->itemIds[0]);
        $this->attach(2, self::FILE_TWO, 'photo.png', 'image/png', $this->itemIds[1]);
    }

    /** Adds an attachment row, its link, and its file in the source project. */
    private function attach(int $id, string $content, string $name, string $mediaType, int $recordId, bool $withFile = true): string
    {
        $sha256 = hash('sha256', $content);
        $this->db->run(
            'INSERT INTO "_tiffin_attachments" ("id", "sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $sha256, strlen($content), $mediaType, $name, self::START, self::USER_KEY],
        );
        $this->db->run(
            'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
            [$this->f['Photo']->key, $recordId, $id, 1],
        );
        if ($withFile) {
            $folder = $this->folder . '/attachments/' . substr($sha256, 0, 2);
            if (!is_dir($folder)) {
                mkdir($folder, 0750);
            }
            file_put_contents($folder . '/' . $sha256, $content);
        }

        return $sha256;
    }

    /** A fresh, empty folder for one job. */
    private function jobFolder(): string
    {
        $this->jobs++;
        $path = $this->temp->path('jobs/job' . $this->jobs);
        mkdir($path, 0750, true);

        return $path;
    }

    /** Writes an archive of the source project with ArchiveWriter; returns the zip path. */
    private function export(): string
    {
        $target = $this->jobFolder() . '/archive.zip';
        (new ArchiveWriter())->write($this->db, $this->folder, $target, ArchiveManifest::facts(self::CREATED, self::VERSION_TEXT, 'Example Project'));

        return $target;
    }

    /** A consistent copy of the source database, for a test to change with plain SQL. */
    private function copyOfSource(): string
    {
        $path = $this->jobFolder() . '/hostile.tiffin';
        $this->db->run('VACUUM INTO ?', [$path]);

        return $path;
    }

    /** Runs statements on a database file with a plain PDO connection (no TiffinDB settings, foreign keys off). */
    private static function rawEdit(string $path, string ...$statements): void
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo = null;
    }

    /**
     * Wraps a database file in a zip with ZipArchive: the manifest first
     * (facts of the file unless $manifest overrides members; a member set
     * to null is left out), the file as `project.tiffin`, then $entries
     * (name => bytes).
     *
     * @param array<string, mixed> $manifest
     * @param array<string, string> $entries
     */
    private function zipFor(string $dbPath, array $manifest = [], array $entries = []): string
    {
        $bytes = (string) file_get_contents($dbPath);
        $version = ProjectFile::VERSION;
        if (str_starts_with($bytes, "SQLite format 3\0") && ord($bytes[18] ?? "\0") === 1) {
            try {
                $pdo = new \PDO('sqlite:' . $dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            } catch (\PDOException) {
                $version = ProjectFile::VERSION;
            }
            $pdo = null;
        }
        $members = array_merge([
            'format' => 'tiffindb-archive',
            'archive_version' => 1,
            'created_at' => self::CREATED,
            'created_with' => self::VERSION_TEXT,
            'project_format_version' => $version,
            'project_name' => 'Example Project',
            'project_size' => strlen($bytes),
            'project_sha256' => hash('sha256', $bytes),
            'attachments' => count($entries),
        ], $manifest);
        $members = array_filter($members, static fn (mixed $value): bool => $value !== null);
        $path = $this->jobFolder() . '/upload.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL));
        $zip->addFromString(ZipCheck::MANIFEST, json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $zip->addFromString(ZipCheck::PROJECT, $bytes);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        self::assertTrue($zip->close());

        return $path;
    }

    /** The source database wrapped as an archive after $statements ran on a copy of it. */
    private function hostileArchive(string ...$statements): string
    {
        $copy = $this->copyOfSource();
        self::rawEdit($copy, ...$statements);

        return $this->zipFor($copy);
    }

    /** Opens and checks an archive; returns the reader and the checked database. */
    private function check(string $zip, ?ZipCheck $zipCheck = null, ?int $freeBytes = 1 << 40): array
    {
        $job = $this->jobFolder();
        $reader = ArchiveReader::open($zip, $job, $freeBytes, $zipCheck);
        $this->readers[] = $reader;
        $checked = UntrustedProject::check($reader->projectPath(), $reader->manifest->projectFormatVersion, $this->types);
        $this->checked[] = $checked;

        return [$reader, $checked, $job];
    }

    /**
     * The whole import into a new project folder: returns the report, the
     * new project's database (opened as TiffinDB opens projects), and its folder.
     *
     * @return array{0: RebuildReport, 1: Database, 2: string}
     */
    private function import(string $zip, string $clientName = 'example.zip'): array
    {
        [$reader, $checked, $job] = $this->check($zip);
        $folder = $job . '/rebuilt';
        $report = (new ProjectRebuilder($this->types, $this->clock, new Keys(new SeededRandom('archive-rebuild')), self::VERSION_TEXT))
            ->rebuild($checked, $reader, $folder, self::NEW_KEY, 'Imported Project', self::IMPORTER_KEY, $clientName);
        $db = ProjectFile::open($folder . '/project.tiffin', self::NEW_KEY, $this->snapshots);
        $this->opened[] = $db;

        return [$report, $db, $folder];
    }

    /** The reason the import of $zip is refused; fails when it is not. */
    private function refusal(string $zip, ?ZipCheck $zipCheck = null, ?int $freeBytes = 1 << 40): string
    {
        try {
            $this->check($zip, $zipCheck, $freeBytes);
        } catch (ArchiveRefused $refused) {
            return $refused->reason;
        }
        self::fail('The archive was accepted.');
    }
}
