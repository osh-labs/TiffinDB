<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-4 and T-RT-5 (M3 spec section 15.7): the fixture project
 * (IoFixture::project, with records and a file in the trash, a
 * self-relation, forms, and attachment files on disk) exported as a
 * project archive and imported again under a new name through the core
 * operations.
 *
 * T-RT-4 compares the two project files table by table with plain SQL:
 * every `_tiffin_*` row except the meta values `project_key`,
 * `project_name`, `updated_at`, `updated_by` and the import log (the copy
 * has its own `archive` row and the original none), every user table row
 * (records in the trash included, ids kept), every join row, the
 * sequences, and the schema of every object; then every attachment file
 * byte for byte. T-RT-5 compares the JSON exports of the two projects,
 * which are equal apart from `project.key`, `project.name`,
 * `project.updated_at`, and `exported_at`.
 */
#[CoversNothing]
final class ArchiveRoundTripTest extends RoundTripCase
{
    public function testArchiveExportThenImportGivesTheSameProject(): void
    {
        $fx = IoFixture::project($this->core, $this->ops);
        $p = $fx['m2']->project;
        $zip = $this->archive($p);
        $this->core->clock->advance(3600);
        $result = $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'home-inventory.tiffindb.zip', 'Home Inventory copy');
        $copy = $result->project->key;
        self::assertNotSame($p, $copy);
        $r = $result->report;
        // The M2 data set's attachment rows have no files (M2Fixture); every other count is zero.
        $withoutFiles = (int) $this->core->projectDb($p)->value('SELECT COUNT(*) FROM _tiffin_attachments') - count($fx['files']);
        self::assertSame(
            [4, 92, [], 0, 0, 0, $withoutFiles, 0, 0, 0, 0, 0],
            [$r->tables, $r->records, $r->valuesEmptied, $r->linksDropped, $r->formsDropped, $r->formsExtended, $r->filesMissing, $r->filesSkipped, $r->timesReplaced, $r->personKeysCleared, $r->recordsSkipped, $r->attachmentsDropped],
        );

        $before = self::dump($this->projectFile($p));
        $after = self::dump($this->projectFile($copy));
        $log = $after['_tiffin_import_log'];
        self::assertSame([], $before['_tiffin_import_log']);
        self::assertCount(1, $log);
        self::assertSame(['archive', 'home-inventory.tiffindb.zip', strlen($zip), hash('sha256', $zip)], [$log[0]['source'], $log[0]['file_name'], $log[0]['file_size'], $log[0]['file_sha256']]);

        $meta = static fn (array $rows): array => array_column($rows, 'value', 'key');
        $metaBefore = $meta($before['_tiffin_meta']);
        $metaAfter = $meta($after['_tiffin_meta']);
        self::assertSame([$copy, 'Home Inventory copy', $this->admin->userKey], [$metaAfter['project_key'], $metaAfter['project_name'], $metaAfter['updated_by']]);
        foreach (['project_key', 'project_name', 'updated_at', 'updated_by'] as $key) {
            unset($metaBefore[$key], $metaAfter[$key]);
        }
        self::assertSame($metaBefore, $metaAfter, '_tiffin_meta apart from the key, name, and last change.');
        unset($before['_tiffin_meta'], $after['_tiffin_meta'], $before['_tiffin_import_log'], $after['_tiffin_import_log']);

        self::assertSame(array_keys($before), array_keys($after), 'The same tables.');
        $userTables = 0;
        foreach ($before as $name => $rows) {
            $userTables += preg_match('/^[tj]_/', $name) === 1 ? 1 : 0;
            self::assertSame($rows, $after[$name], "{$name} is equal.");
        }
        self::assertSame(8, $userTables, 'Four user tables and four join tables were compared.');
        self::assertGreaterThan(0, count(array_filter($before['t_' . $fx['m2']->items], static fn (array $r): bool => $r['_trashed_at'] !== null)), 'Records in the trash are carried.');

        // Attachment files.
        self::assertSame(self::files($this->core->projectFolder($p)), self::files($this->core->projectFolder($copy)));
        $written = $fx['files'];
        ksort($written);
        self::assertSame($written, self::files($this->core->projectFolder($copy)), 'Every file that existed is in the copy.');
    }

    public function testJsonOfTheOriginalAndOfTheCopyAreEqual(): void
    {
        $fx = IoFixture::project($this->core, $this->ops);
        $p = $fx['m2']->project;
        $original = json_decode($this->export($p, 'export/json', null), true, 512, JSON_THROW_ON_ERROR);
        $zip = $this->archive($p);
        $this->core->clock->advance(3600);
        $copy = $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'home.zip', 'Home Inventory copy')->project->key;
        $imported = json_decode($this->export($copy, 'export/json', null), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($original);
        self::assertIsArray($imported);
        self::assertSame([$copy, 'Home Inventory copy'], [$imported['project']['key'], $imported['project']['name']]);
        self::assertNotSame($original['exported_at'], $imported['exported_at']);
        foreach ([&$original, &$imported] as &$doc) {
            unset($doc['project']['key'], $doc['project']['name'], $doc['project']['updated_at'], $doc['exported_at']);
        }
        unset($doc);
        self::assertSame($original, $imported, 'Equal apart from the project key, name, last change, and export time.');
        self::assertGreaterThan(80, array_sum(array_map(static fn (array $t): int => count($t['records']), $original['tables'])));
    }

    /** @return array<string, string> path under attachments/ => bytes, sorted */
    private static function files(string $folder): array
    {
        $files = [];
        $root = $folder . '/attachments';
        if (!is_dir($root)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $files['attachments/' . substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
