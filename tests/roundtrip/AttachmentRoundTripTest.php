<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Tests\Support\M4Fixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-7 (M4 spec part 1 section 12.8): the M4Fixture project, with real
 * attachment files (the four image types, PDFs, an HTML file, an SVG with
 * a script, two rows sharing one file) and three collections (one with a
 * broken rule), exported as a project archive and imported again under a
 * new name through the core operations. A zero-byte upload is refused
 * first and leaves nothing behind. In the copy: every attachment file is
 * byte-equal (SHA-256 equal to its name and to the original's), the
 * attachment rows and links are equal, the collections are equal (key,
 * name, match, rules, sort, and position order), every served file has
 * the original's bytes, type, and disposition; the JSON exports of the two
 * projects are equal apart from M3's T-RT-5 exceptions (`project.key`,
 * `project.name`, `project.updated_at`, `exported_at`).
 */
#[CoversNothing]
final class AttachmentRoundTripTest extends RoundTripCase
{
    public function testArchiveRoundTripKeepsFilesAndCollections(): void
    {
        $fx = M4Fixture::build($this->core, $this->ops);
        $p = $fx->m2->project;
        $this->assertZeroByteUploadIsRefused($fx);

        $zip = $this->archive($p);
        $this->core->clock->advance(3600);
        $result = $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'home-inventory.tiffindb.zip', 'Home Inventory copy');
        $copy = $result->project->key;
        $r = $result->report;
        self::assertSame(
            [0, 0, 0, 0, 0, 0],
            [$r->filesMissing, $r->filesSkipped, $r->attachmentsDropped, $r->linksDropped, $r->collectionsDropped, $r->collectionRulesDropped],
            'Nothing is missing, skipped, or dropped.',
        );

        $before = self::dump($this->projectFile($p));
        $after = self::dump($this->projectFile($copy));
        foreach (['_tiffin_attachments', '_tiffin_attachment_links', 'sqlite_sequence'] as $table) {
            self::assertSame($before[$table], $after[$table], "{$table} is equal.");
        }
        self::assertCount(51, $before['_tiffin_attachments']);
        self::assertCount(3, $before['_tiffin_collections']);
        $collection = static fn (array $rows): array => array_map(
            static fn (array $row): array => [$row['key'], $row['table_key'], $row['name'], $row['match_mode'], $row['rules'], $row['sort']],
            self::byPosition($rows),
        );
        self::assertSame($collection($before['_tiffin_collections']), $collection($after['_tiffin_collections']), 'Collections: key, table, name, match, rules, sort, order.');

        // Files: the same set, each named by its content's SHA-256.
        $original = self::files($this->core->projectFolder($p));
        $copied = self::files($this->core->projectFolder($copy));
        self::assertSame(array_keys($original), array_keys($copied));
        self::assertCount(50, $copied, '51 rows, 50 files: two rows share one.');
        foreach ($copied as $relative => $sha) {
            self::assertSame(basename($relative), $sha, "{$relative} holds the bytes its name says.");
            self::assertSame($original[$relative], $sha);
        }

        // Each fixture file served from the copy: same bytes, type, and disposition.
        $member = $this->core->actor('member');
        $this->ops->projects()->setSharing($this->admin, $copy, [$this->core->person('member')->key]);
        foreach ($fx->attachmentIds as $name => $id) {
            $a = $this->ops->attachments()->serve($member, $p, (string) $id, false);
            $b = $this->ops->attachments()->serve($member, $copy, (string) $id, false);
            self::assertSame([$a->mediaType, $a->fileName, $a->size, $a->inline], [$b->mediaType, $b->fileName, $b->size, $b->inline], $name);
            self::assertSame($fx->files[$name], (string) file_get_contents($b->path), $name);
        }
        $html = $this->ops->attachments()->serve($member, $copy, (string) $fx->attachmentIds['manual.html'], false);
        self::assertSame(['application/octet-stream', false], [$html->mediaType, $html->inline], 'Active content stays download-only.');

        // The collections still work in the copy, the broken rule still broken.
        $summaries = [];
        foreach ([$p, $copy] as $project) {
            $list = [];
            foreach ($this->ops->collections()->summaries($member, $project) as $summary) {
                $list[] = [$summary->name, $summary->count, $summary->broken];
            }
            $summaries[] = $list;
        }
        self::assertSame($summaries[0], $summaries[1]);
        $broken = array_column($summaries[0], 2, 0);
        self::assertSame(['Under warranty' => 0, 'Kitchen or study' => 0, 'Retired items' => 1], $broken, 'One broken rule, kept.');
    }

    public function testJsonOfTheOriginalAndOfTheCopyAreEqual(): void
    {
        $fx = M4Fixture::build($this->core, $this->ops);
        $p = $fx->m2->project;
        $original = json_decode($this->export($p, 'export/json', null), true, 512, JSON_THROW_ON_ERROR);
        $zip = $this->archive($p);
        $this->core->clock->advance(3600);
        $copy = $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'home.zip', 'Home Inventory copy')->project->key;
        $imported = json_decode($this->export($copy, 'export/json', null), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($original);
        self::assertIsArray($imported);
        self::assertSame([$copy, 'Home Inventory copy'], [$imported['project']['key'], $imported['project']['name']]);
        foreach ([&$original, &$imported] as &$doc) {
            unset($doc['project']['key'], $doc['project']['name'], $doc['project']['updated_at'], $doc['exported_at']);
        }
        unset($doc);
        self::assertSame($original, $imported, 'Equal apart from the project key, name, last change, and export time.');
        self::assertSame(M4Fixture::COLLECTIONS, array_column($original['collections'], 'name'));
    }

    private function assertZeroByteUploadIsRefused(M4Fixture $fx): void
    {
        $p = $fx->m2->project;
        $before = self::dump($this->projectFile($p));
        $files = self::files($this->core->projectFolder($p));
        try {
            $this->ops->attachments()->add($this->core->actor('member'), $p, $fx->m2->items, (string) $fx->m2->itemIds['Toaster'], $fx->m2->itemFields['Receipt'], $this->core->upload(''), 'empty.pdf', null);
            self::fail('A zero-byte file was accepted.');
        } catch (ValidationFailed $refused) {
            self::assertSame(['problem.upload.empty'], array_map(static fn ($problem): string => $problem->key, $refused->problems));
        }
        self::assertSame($before, self::dump($this->projectFile($p)), 'No row.');
        self::assertSame($files, self::files($this->core->projectFolder($p)), 'No file.');
        $staging = $this->core->data->path() . '/staging/uploads';
        self::assertSame([], is_dir($staging) ? array_values(array_diff((array) scandir($staging), ['.', '..'])) : [], 'Nothing left in staging.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function byPosition(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['position'], $a['key']] <=> [$b['position'], $b['key']]);

        return $rows;
    }

    /** @return array<string, string> path under attachments/ => SHA-256 of the file, sorted */
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
            $files['attachments/' . substr($file->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
