<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\AttachmentAdded;
use TiffinDB\Core\Query\AttachmentRemoved;
use TiffinDB\Core\Query\AttachmentRow;
use TiffinDB\Core\Query\Attachments;
use TiffinDB\Core\Query\AttachmentSummary;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-ATT-1 to T-ATT-11, T-ATT-13, T-ATT-14: attachment rows, links, and
 * the order of file placement and removal, with injected failures and a
 * second process holding the write. Files are built by FileFixture; all
 * data is synthetic.
 */
#[CoversClass(Attachments::class)]
#[CoversClass(AttachmentAdded::class)]
#[CoversClass(AttachmentRemoved::class)]
#[CoversClass(AttachmentRow::class)]
#[CoversClass(AttachmentSummary::class)]
final class AttachmentsTest extends TestCase
{
    use RecordsFixture;

    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-attachments-test-1');
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        $this->tearDownProject();
    }

    /** Sends error_log() to a file for the rest of the test; returns its path. */
    private function captureLog(): string
    {
        $path = $this->temp->path('error.log');
        $this->previousLog = ini_set('error_log', $path);

        return $path;
    }

    private function log(): string
    {
        $path = $this->temp->path('error.log');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return list<array{record_id: int, attachment_id: int, position: int}> links of the field in order */
    private function links(?FieldDef $field = null): array
    {
        return $this->db->all(
            'SELECT "record_id", "attachment_id", "position" FROM "_tiffin_attachment_links" WHERE "field_key" = ? ORDER BY "record_id", "position", "attachment_id"',
            [($field ?? $this->f['files'])->key],
        );
    }

    private function rowCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"');
    }

    private function hashOf(string $content): string
    {
        return hash('sha256', $content);
    }

    /** The path a hash's file has in the project folder, built here independently of the store. */
    private function targetOf(string $content): string
    {
        $hash = $this->hashOf($content);

        return $this->folder . '/attachments/' . substr($hash, 0, 2) . '/' . $hash;
    }

    private function imagesOnly(): FieldDef
    {
        return $this->editor()->addField($this->items->key, 'Photos', 'attachment', ['images_only' => true], false, '');
    }

    // ------------------------------------------------------------ T-ATT-1

    public function testAddStoresTheFileThenTheRowAndTheLink(): void
    {
        $item = $this->item(['name' => 'Toaster']);
        $png = FileFixture::png(2, 2);
        $this->clock->advance(60);

        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage($png), 'toaster.png', self::OTHER_USER_KEY, null);

        self::assertNull($added->replaced);
        self::assertSame([], $added->freedHashes);
        self::assertEquals(
            new AttachmentSummary($added->attachment->id, 'toaster.png', MediaType::PNG, strlen($png), '2026-11-12T03:16:00Z', self::OTHER_USER_KEY, $this->hashOf($png)),
            $added->attachment,
        );
        self::assertSame([
            'id' => $added->attachment->id,
            'sha256' => $this->hashOf($png),
            'size' => strlen($png),
            'media_type' => 'image/png',
            'original_name' => 'toaster.png',
            'created_at' => '2026-11-12T03:16:00Z',
            'created_by' => self::OTHER_USER_KEY,
        ], $this->db->one('SELECT * FROM "_tiffin_attachments"'));
        self::assertSame([['record_id' => $item, 'attachment_id' => $added->attachment->id, 'position' => 1]], $this->links());
        self::assertSame($png, file_get_contents($this->targetOf($png)));
        self::assertSame(0640, fileperms($this->targetOf($png)) & 0777);
        $row = $this->rowOf($this->items, $item);
        self::assertSame('2026-11-12T03:16:00Z', $row['_updated_at']);
        self::assertSame(self::OTHER_USER_KEY, $row['_updated_by']);
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));
        self::assertSame(self::OTHER_USER_KEY, $this->meta('updated_by'));
        self::assertEquals([$added->attachment], $this->records()->get($this->items->key, $item)?->attachments[$this->f['files']->key]);

        // The same content again: a second row and link at position 2, one stored file.
        $second = $this->attachments()->add($this->f['files']->key, $item, $this->stage($png), 'copy.png', self::USER_KEY, null);
        self::assertSame(2, $this->rowCount());
        self::assertSame([1, 2], array_column($this->links(), 'position'));
        self::assertSame([$added->attachment->id, $second->attachment->id], array_column($this->links(), 'attachment_id'));
        self::assertSame([$this->hashOf($png)], $this->store()->storedHashes());
        self::assertSame(['files' => 1, 'bytes' => strlen($png)], $this->attachments()->totals());
    }

    public function testTheStagedFileIsMovedOnlyWhenTheContentIsNew(): void
    {
        $item = $this->item();
        $first = $this->stage('first content');
        $this->attachments()->add($this->f['files']->key, $item, $first, 'a.txt', self::USER_KEY, null);
        self::assertFileDoesNotExist($first->path);
        $again = $this->stage('first content');
        $this->attachments()->add($this->f['files']->key, $item, $again, 'b.txt', self::USER_KEY, null);
        self::assertFileExists($again->path, 'Identical content is left for the caller to remove.');
    }

    public function testAddChecksItsKeysAndTheRecord(): void
    {
        $item = $this->item();
        $trashed = $this->item();
        $this->trashRecord($this->items, $trashed);
        $upload = $this->stage('some bytes');
        $before = $this->dump();
        $cases = [
            'unknown field' => fn () => $this->attachments()->add('zzzzzzzzzzzz', $item, $upload, 'a', self::USER_KEY, null),
            'malformed field key' => fn () => $this->attachments()->add('../x', $item, $upload, 'a', self::USER_KEY, null),
            'text field' => fn () => $this->attachments()->add($this->f['text']->key, $item, $upload, 'a', self::USER_KEY, null),
            'relation field' => fn () => $this->attachments()->add($this->f['room']->key, $item, $upload, 'a', self::USER_KEY, null),
            'absent record' => fn () => $this->attachments()->add($this->f['files']->key, 999, $upload, 'a', self::USER_KEY, null),
            'trashed record' => fn () => $this->attachments()->add($this->f['files']->key, $trashed, $upload, 'a', self::USER_KEY, null),
            'replace id never linked' => fn () => $this->attachments()->add($this->f['files']->key, $item, $upload, 'a', self::USER_KEY, 77),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ' was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([fn () => $this->attachments()->add($this->f['files']->key, 0, $upload, 'a', self::USER_KEY, null),
            fn () => $this->attachments()->add($this->f['files']->key, $item, $upload, 'a', 'Example Person', null),
            fn () => $this->attachments()->add($this->f['files']->key, $item, $upload, 'a', self::USER_KEY, 0)] as $case) {
            try {
                $case();
                self::fail('A bad argument was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->dump());
        self::assertSame([], $this->store()->storedHashes(), 'Nothing is placed before the checks pass.');
        self::assertFileExists($upload->path);
    }

    // ------------------------------------------------------------ T-ATT-2

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'plain' => ['receipt.pdf', 'receipt.pdf'];
        yield 'control characters' => ["re\x00ce\x07ipt\r\n.pdf", 'receipt.pdf'];
        yield 'right-to-left override' => ["invoice\u{202E}fdp.exe", 'invoicefdp.exe'];
        yield 'zero-width space' => ["a\u{200B}b.txt", 'ab.txt'];
        yield 'byte order mark' => ["\u{FEFF}notes.txt", 'notes.txt'];
        yield 'zero-width joiner' => ["a\u{200D}b.txt", 'ab.txt'];
        yield 'line and paragraph separators' => ["a\u{2028}b\u{2029}c", 'abc'];
        yield 'slashes' => ['../../etc/passwd', '.._.._etc_passwd'];
        yield 'backslashes' => ['..\\windows\\file.txt', '.._windows_file.txt'];
        yield 'trimmed' => ["  \u{00A0}photo.jpg\t ", 'photo.jpg'];
        yield '300 characters' => [str_repeat('é', 300), str_repeat('é', 255)];
        yield 'cut then trimmed' => [str_repeat('a', 254) . ' b', str_repeat('a', 254)];
        yield 'empty' => ['', 'file'];
        yield 'only controls and spaces' => ["\u{200B} \x01 ", 'file'];
        yield 'invalid UTF-8' => ["bad\xC3\x28.txt", 'file'];
        yield 'letters kept' => ['Küche 台所 مطبخ.png', 'Küche 台所 مطبخ.png'];
    }

    #[DataProvider('names')]
    public function testNamesAreCleaned(string $given, string $expected): void
    {
        self::assertSame($expected, Attachments::cleanName($given));
    }

    public function testAddStoresTheCleanedName(): void
    {
        $item = $this->item();
        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage('x'), "a/b\u{202E}.txt", self::USER_KEY, null);
        self::assertSame('a_b.txt', $added->attachment->originalName);
        self::assertSame('a_b.txt', $this->db->value('SELECT "original_name" FROM "_tiffin_attachments"'));
    }

    // ------------------------------------------------------------ T-ATT-3

    public function testImagesOnlyRefusesOtherTypes(): void
    {
        $photos = $this->imagesOnly();
        $item = $this->item();
        foreach (['pdf' => FileFixture::pdf(), 'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'html' => '<!doctype html><p>x'] as $name => $content) {
            $upload = $this->stage($content);
            self::assertSame(
                [[$photos->key, 'problem.attachment.not_image', []]],
                self::problems(fn () => $this->attachments()->add($photos->key, $item, $upload, $name, self::USER_KEY, null)),
                $name,
            );
            self::assertFileExists($upload->path, $name);
        }
        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->store()->storedHashes());

        foreach ([MediaType::PNG => FileFixture::png(), MediaType::JPEG => FileFixture::jpeg(), MediaType::GIF => FileFixture::gif(), MediaType::WEBP => FileFixture::webp()] as $type => $content) {
            $added = $this->attachments()->add($photos->key, $item, $this->stage($content), 'image', self::USER_KEY, null);
            self::assertSame($type, $added->attachment->mediaType);
        }
        self::assertCount(4, $this->links($photos));

        // A field without the option takes anything.
        self::assertSame(MediaType::PDF, $this->attachments()->add($this->f['files']->key, $item, $this->stage(FileFixture::pdf()), 'a.pdf', self::USER_KEY, null)->attachment->mediaType);
    }

    // ------------------------------------------------------------ T-ATT-4

    public function testAtMostOneHundredFilesPerFieldAndRecord(): void
    {
        $item = $this->item();
        $other = $this->item();
        $first = 0;
        for ($n = 1; $n <= Attachments::MAX_PER_FIELD; $n++) {
            $id = $this->attach($item, 'same content', 'f' . (string) $n);
            $first = $first === 0 ? $id : $first;
        }
        self::assertSame(100, Attachments::MAX_PER_FIELD);
        $upload = $this->stage('one too many');
        self::assertSame(
            [[$this->f['files']->key, 'problem.attachment.too_many', ['item' => 'item', 'max' => 100]]],
            self::problems(fn () => $this->attachments()->add($this->f['files']->key, $item, $upload, 'x', self::USER_KEY, null)),
        );
        self::assertCount(100, $this->links());
        self::assertSame([$this->hashOf('same content')], $this->store()->storedHashes());

        // Replacing at the limit is accepted; other records and fields have their own limit.
        $replaced = $this->attachments()->add($this->f['files']->key, $item, $upload, 'x', self::USER_KEY, $first);
        self::assertSame($first, $replaced->replaced?->id);
        self::assertCount(100, $this->links());
        $this->attach($other, 'same content');
        self::assertCount(101, $this->links());
        $more = $this->editor()->addField($this->items->key, 'More', 'attachment', [], false, '');
        $this->attach($item, 'same content', 'p', $more);
        self::assertCount(1, $this->links($more));
    }

    public function testProblemsAreCollected(): void
    {
        $photos = $this->imagesOnly();
        $item = $this->item();
        for ($n = 1; $n <= Attachments::MAX_PER_FIELD; $n++) {
            $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [str_repeat('a', 64), 'image/png', 'a', self::START]);
            $this->db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, ?)', [$photos->key, $item, $this->db->lastInsertId(), $n]);
        }
        $upload = $this->stage('not an image');
        self::assertSame([
            [$photos->key, 'problem.attachment.not_image', []],
            [$photos->key, 'problem.attachment.too_many', ['item' => 'item', 'max' => 100]],
        ], self::problems(fn () => $this->attachments()->add($photos->key, $item, $upload, 'x', self::USER_KEY, null)));
    }

    // ------------------------------------------------------------ T-ATT-5

    public function testReplaceSwapsTheLinkAndFreesTheOldRow(): void
    {
        $item = $this->item();
        $old = $this->attach($item, 'old content', 'old.txt');
        $keep = $this->attach($item, 'kept content', 'kept.txt');
        $this->clock->advance(60);

        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage('new content'), 'new.txt', self::OTHER_USER_KEY, $old);

        self::assertSame($old, $added->replaced?->id);
        self::assertSame('old.txt', $added->replaced->originalName);
        self::assertSame($this->hashOf('old content'), $added->replaced->sha256);
        self::assertSame([$this->hashOf('old content')], $added->freedHashes);
        self::assertSame([$keep, $added->attachment->id], array_column($this->links(), 'attachment_id'), 'The new link is at the end.');
        self::assertSame([2, 3], array_column($this->links(), 'position'));
        self::assertNull($this->attachments()->get($old));
        self::assertNotNull($this->storedFile($this->hashOf('old content')), 'The file goes only in deleteFiles, after the write.');
        self::assertSame('2026-11-12T03:16:00Z', $this->rowOf($this->items, $item)['_updated_at']);

        self::assertSame(1, $this->attachments()->deleteFiles($added->freedHashes));
        self::assertNull($this->storedFile($this->hashOf('old content')));
    }

    public function testReplacingARowStillLinkedElsewhereKeepsIt(): void
    {
        $item = $this->item();
        $other = $this->item();
        $shared = $this->attach($item, 'shared content');
        $this->db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, 1)', [$this->f['files']->key, $other, $shared]);

        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage('fresh'), 'f', self::USER_KEY, $shared);

        self::assertSame([], $added->freedHashes);
        self::assertNotNull($this->attachments()->get($shared));
        self::assertSame([[$item, $added->attachment->id], [$other, $shared]], array_map(static fn (array $l): array => [$l['record_id'], $l['attachment_id']], $this->links()));
    }

    public function testAFreedRowWhoseHashAnotherRowHasIsNotReported(): void
    {
        $item = $this->item();
        $old = $this->attach($item, 'twin');
        $this->attach($item, 'twin');
        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage('other'), 'o', self::USER_KEY, $old);
        self::assertSame([], $added->freedHashes);
        self::assertNull($this->attachments()->get($old));
    }

    public function testReplaceIdOfAnotherRecordOrFieldIsNotFound(): void
    {
        $item = $this->item();
        $other = $this->item();
        $more = $this->editor()->addField($this->items->key, 'More', 'attachment', [], false, '');
        $ofOther = $this->attach($other, 'other record');
        $ofMore = $this->attach($item, 'other field', 'x', $more);
        $trashedHolder = $this->item();
        $ofTrashed = $this->attach($trashedHolder, 'trashed');
        $this->trashRecord($this->items, $trashedHolder);
        $before = $this->dump();
        foreach ([$ofOther, $ofMore, $ofTrashed] as $replaceId) {
            $upload = $this->stage('replacement');
            try {
                $this->attachments()->add($this->f['files']->key, $item, $upload, 'r', self::USER_KEY, $replaceId);
                self::fail('A replace id the record and field do not link was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->dump());
    }

    // ------------------------------------------------------------ T-ATT-6

    public function testAFailureAfterPlacementLeavesOnlyAnOrphanFile(): void
    {
        $item = $this->item();
        $before = $this->dump();
        $this->db->run('CREATE TEMP TRIGGER "fail_attach" BEFORE INSERT ON "_tiffin_attachments" BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END');
        $this->clock->advance(60);
        try {
            $this->attachments()->add($this->f['files']->key, $item, $this->stage('lost content'), 'lost.txt', self::OTHER_USER_KEY, null);
            self::fail('The injected failure did not happen.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('synthetic failure', $e->getMessage());
        }
        $this->db->run('DROP TRIGGER "temp"."fail_attach"');
        self::assertFalse($this->db->inTransaction());
        self::assertSame($before, $this->dump(), 'No row, no link, record and meta unchanged.');
        self::assertSame('lost content', file_get_contents($this->targetOf('lost content')), 'The placed file stays as an orphan.');

        self::assertSame(1, $this->attachments()->sweep());
        self::assertFileDoesNotExist($this->targetOf('lost content'));
        self::assertSame($before, $this->dump());
    }

    public function testAFailureInTheLinkInsertOrTheTouchRollsBackTheRowToo(): void
    {
        $item = $this->item();
        $old = $this->attach($item, 'old');
        $before = $this->dump();
        $triggers = [
            'link' => 'CREATE TEMP TRIGGER "fail_step" BEFORE INSERT ON "_tiffin_attachment_links" BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END',
            'replace' => 'CREATE TEMP TRIGGER "fail_step" BEFORE DELETE ON "_tiffin_attachments" BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END',
            'touch' => 'CREATE TEMP TRIGGER "fail_step" AFTER UPDATE ON "_tiffin_meta" WHEN NEW."key" = \'updated_by\' BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END',
        ];
        foreach ($triggers as $name => $trigger) {
            $this->db->run($trigger);
            try {
                $this->attachments()->add($this->f['files']->key, $item, $this->stage('new ' . $name), 'n', self::OTHER_USER_KEY, $old);
                self::fail($name . ': the injected failure did not happen.');
            } catch (\PDOException $e) {
                self::assertStringContainsString('synthetic failure', $e->getMessage(), $name);
            }
            $this->db->run('DROP TRIGGER "temp"."fail_step"');
            self::assertSame($before, $this->dump(), $name);
            self::assertNotNull($this->storedFile($this->hashOf('old')), $name);
        }
        self::assertSame(3, $this->attachments()->sweep(), 'Only the three orphans go.');
        self::assertSame([$this->hashOf('old')], $this->store()->storedHashes());
    }

    public function testAShorterOrphanAtTheTargetIsReplacedByTheNextUpload(): void
    {
        $item = $this->item();
        $content = FileFixture::bytes(4096, 'orphan');
        mkdir(dirname($this->targetOf($content)), 0750, true);
        file_put_contents($this->targetOf($content), substr($content, 0, 1000));

        $added = $this->attachments()->add($this->f['files']->key, $item, $this->stage($content), 'whole.bin', self::USER_KEY, null);

        self::assertSame(4096, $added->attachment->size);
        self::assertSame($content, file_get_contents($this->targetOf($content)));
    }

    public function testADifferentSizeFileThatARowNamesIsRefused(): void
    {
        $item = $this->item();
        $content = FileFixture::bytes(2048, 'named');
        $this->attach($item, $content);
        file_put_contents($this->targetOf($content), 'damaged');
        $before = $this->dump();
        $upload = $this->stage($content);
        try {
            $this->attachments()->add($this->f['files']->key, $item, $upload, 'again.bin', self::USER_KEY, null);
            self::fail('A file of another size that a row names was replaced.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(ValidationFailed::class, $e);
        }
        self::assertSame($before, $this->dump());
        self::assertSame('damaged', file_get_contents($this->targetOf($content)));
        self::assertFileExists($upload->path);
    }

    public function testALinkOrFolderAtTheTargetIsRefused(): void
    {
        $item = $this->item();
        $folderContent = 'folder at target';
        mkdir($this->targetOf($folderContent), 0750, true);
        $linkContent = 'link at target';
        mkdir(dirname($this->targetOf($linkContent)), 0750, true);
        $outside = $this->temp->path('outside.txt');
        file_put_contents($outside, 'outside');
        symlink($outside, $this->targetOf($linkContent));
        $before = $this->dump();
        foreach ([$folderContent, $linkContent] as $content) {
            try {
                $this->attachments()->add($this->f['files']->key, $item, $this->stage($content), 'x', self::USER_KEY, null);
                self::fail('Something other than a file at the target was accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->dump());
        self::assertDirectoryExists($this->targetOf($folderContent));
        self::assertTrue(is_link($this->targetOf($linkContent)));
        self::assertSame('outside', file_get_contents($outside));
    }

    // ------------------------------------------------------------ T-ATT-7

    public function testRemoveDeletesTheLinkThenTheRowThenLaterTheFile(): void
    {
        $item = $this->item();
        $a = $this->attach($item, 'alpha', 'a.txt');
        $b = $this->attach($item, 'beta', 'b.txt');
        $this->clock->advance(60);

        $removed = $this->attachments()->remove($this->f['files']->key, $item, $a, self::OTHER_USER_KEY);

        self::assertSame($a, $removed->attachment->id);
        self::assertSame('a.txt', $removed->attachment->originalName);
        self::assertSame([$this->hashOf('alpha')], $removed->freedHashes);
        self::assertSame([$b], array_column($this->links(), 'attachment_id'));
        self::assertNull($this->attachments()->get($a));
        self::assertNotNull($this->storedFile($this->hashOf('alpha')), 'The file is deleted after the write, not in it.');
        self::assertSame('2026-11-12T03:16:00Z', $this->rowOf($this->items, $item)['_updated_at']);
        self::assertSame(self::OTHER_USER_KEY, $this->rowOf($this->items, $item)['_updated_by']);
        self::assertSame('2026-11-12T03:16:00Z', $this->meta('updated_at'));

        self::assertSame(1, $this->attachments()->deleteFiles($removed->freedHashes));
        self::assertNull($this->storedFile($this->hashOf('alpha')));
        self::assertSame(0, $this->attachments()->deleteFiles($removed->freedHashes), 'Idempotent.');
        self::assertNotNull($this->storedFile($this->hashOf('beta')));
    }

    public function testTwoRowsSharingAFileKeepItUntilBothAreGone(): void
    {
        $item = $this->item();
        $other = $this->item();
        $first = $this->attach($item, 'shared');
        $second = $this->attach($other, 'shared');

        $removed = $this->attachments()->remove($this->f['files']->key, $item, $first, self::USER_KEY);
        self::assertSame([$this->hashOf('shared')], $removed->freedHashes);
        self::assertSame(0, $this->attachments()->deleteFiles($removed->freedHashes));
        self::assertNotNull($this->storedFile($this->hashOf('shared')));

        $removed = $this->attachments()->remove($this->f['files']->key, $other, $second, self::USER_KEY);
        self::assertSame(1, $this->attachments()->deleteFiles($removed->freedHashes));
        self::assertNull($this->storedFile($this->hashOf('shared')));
    }

    public function testARowLinkedTwiceStaysUntilItsLastLinkGoes(): void
    {
        $item = $this->item();
        $other = $this->item();
        $id = $this->attach($item, 'twice');
        $this->db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, 1)', [$this->f['files']->key, $other, $id]);
        self::assertSame([], $this->attachments()->remove($this->f['files']->key, $item, $id, self::USER_KEY)->freedHashes);
        self::assertNotNull($this->attachments()->get($id));
        self::assertSame([$this->hashOf('twice')], $this->attachments()->remove($this->f['files']->key, $other, $id, self::USER_KEY)->freedHashes);
    }

    public function testRemoveChecksTheLinkAndTheRecord(): void
    {
        $item = $this->item();
        $other = $this->item();
        $more = $this->editor()->addField($this->items->key, 'More', 'attachment', [], false, '');
        $id = $this->attach($item, 'content');
        $trashed = $this->item();
        $ofTrashed = $this->attach($trashed, 'trashed content');
        $this->trashRecord($this->items, $trashed);
        $before = $this->dump();
        $cases = [
            'other record' => fn () => $this->attachments()->remove($this->f['files']->key, $other, $id, self::USER_KEY),
            'other field' => fn () => $this->attachments()->remove($more->key, $item, $id, self::USER_KEY),
            'absent attachment' => fn () => $this->attachments()->remove($this->f['files']->key, $item, 999, self::USER_KEY),
            'trashed record' => fn () => $this->attachments()->remove($this->f['files']->key, $trashed, $ofTrashed, self::USER_KEY),
            'not an attachment field' => fn () => $this->attachments()->remove($this->f['text']->key, $item, $id, self::USER_KEY),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail($name . ' was accepted.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->dump());
    }

    public function testAFailedRemoveChangesNothing(): void
    {
        $item = $this->item();
        $id = $this->attach($item, 'content');
        $before = $this->dump();
        $this->db->run('CREATE TEMP TRIGGER "fail_touch" AFTER UPDATE ON "_tiffin_meta" WHEN NEW."key" = \'updated_by\' BEGIN SELECT RAISE(ABORT, \'synthetic failure\'); END');
        try {
            $this->attachments()->remove($this->f['files']->key, $item, $id, self::OTHER_USER_KEY);
            self::fail('The injected failure did not happen.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->db->run('DROP TRIGGER "temp"."fail_touch"');
        self::assertSame($before, $this->dump());
        self::assertNotNull($this->storedFile($this->hashOf('content')));
    }

    public function testDeleteFilesSkipsValuesThatAreNotHashesAndLogsFailures(): void
    {
        $log = $this->captureLog();
        $item = $this->item();
        $id = $this->attach($item, 'kept');
        self::assertSame(0, $this->attachments()->deleteFiles(['../project.tiffin', strtoupper($this->hashOf('kept')), 7, null, str_repeat('0', 63)]));
        self::assertSame(0, $this->attachments()->deleteFiles([]));
        self::assertSame(0, $this->attachments()->deleteFiles([$this->hashOf('kept')]), 'A row still names it.');
        self::assertNotNull($this->storedFile($this->hashOf('kept')));
        self::assertFileExists($this->path());

        // Something other than a regular file at an unnamed hash is not TiffinDB's: logged, kept.
        $this->attachments()->remove($this->f['files']->key, $item, $id, self::USER_KEY);
        unlink($this->targetOf('kept'));
        mkdir($this->targetOf('kept'));
        self::assertSame(0, $this->attachments()->deleteFiles([$this->hashOf('kept')]));
        self::assertDirectoryExists($this->targetOf('kept'));
        self::assertStringContainsString('TiffinDB: an attachment file could not be deleted.', (string) file_get_contents($log));
        self::assertStringNotContainsString($this->hashOf('kept'), (string) file_get_contents($log));
    }

    // ------------------------------------------------------------ T-ATT-8

    /**
     * Another process adds a row with the same content and holds the
     * write; deleteFiles waits for it (busy_timeout), then sees the row
     * and keeps the file.
     */
    public function testDeleteFilesWaitsForAnUploadOfTheSameContent(): void
    {
        $item = $this->item();
        $id = $this->attach($item, 'contended');
        $freed = $this->attachments()->remove($this->f['files']->key, $item, $id, self::USER_KEY)->freedHashes;
        self::assertSame([$this->hashOf('contended')], $freed);

        $code = '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            . ' $pdo->exec("PRAGMA busy_timeout = 5000"); $pdo->exec("BEGIN IMMEDIATE");'
            . ' $s = $pdo->prepare("INSERT INTO \"_tiffin_attachments\" (\"sha256\", \"size\", \"media_type\", \"original_name\", \"created_at\") VALUES (?, 9, \'application/octet-stream\', \'again\', \'2026-11-12T03:15:00Z\')");'
            . ' $s->execute([$argv[2]]); $a = (int) $pdo->lastInsertId();'
            . ' $pdo->prepare("INSERT INTO \"_tiffin_attachment_links\" VALUES (?, ?, ?, 1)")->execute([$argv[3], (int) $argv[4], $a]);'
            . ' fwrite(STDOUT, "ready\n"); fflush(STDOUT); usleep(400000); $pdo->exec("COMMIT"); fwrite(STDOUT, "done\n");';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code, '--', $this->path(), $this->hashOf('contended'), $this->f['files']->key, (string) $item],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $ready = fgets($pipes[1]);
        if ($ready !== "ready\n") {
            self::fail('The other process did not start its write: ' . (string) stream_get_contents($pipes[2]));
        }
        self::assertSame(0, $this->rowCount(), 'Its row is not visible before it commits.');
        $started = microtime(true);
        $deleted = $this->attachments()->deleteFiles($freed);
        $waited = microtime(true) - $started;
        self::assertSame("done\n", fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));

        self::assertSame(0, $deleted);
        self::assertGreaterThan(0.2, $waited, 'deleteFiles waited for the other write.');
        self::assertSame('contended', file_get_contents($this->targetOf('contended')));
        self::assertSame(1, $this->rowCount());
    }

    public function testDeleteFilesCannotRunWhileAnotherConnectionWrites(): void
    {
        $item = $this->item();
        $id = $this->attach($item, 'held');
        $freed = $this->attachments()->remove($this->f['files']->key, $item, $id, self::USER_KEY)->freedHashes;
        $short = Sqlite::open($this->path(), 50);
        $this->extraConnections[] = $short;
        $waiting = new Attachments($short, new Schema($short, $this->types), $this->store(), $this->clock);
        $this->db->write(function () use ($waiting, $freed): void {
            try {
                $waiting->deleteFiles($freed);
                self::fail('deleteFiles ran inside another connection\'s write.');
            } catch (Busy) {
                $this->addToAssertionCount(1);
            }
        });
        self::assertNotNull($this->storedFile($this->hashOf('held')));
        self::assertSame(1, $waiting->deleteFiles($freed));
    }

    // ------------------------------------------------------------ T-ATT-9

    public function testSweepDeletesUnlinkedRowsAndOrphanFilesAndKeepsStrays(): void
    {
        $log = $this->captureLog();
        $item = $this->item();
        $kept = $this->attach($item, 'linked');
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 3, ?, ?, ?)', [$this->hashOf('row only'), 'text/plain', 'r', self::START]);
        $unlinkedWithFile = $this->attach($item, 'was linked');
        $this->db->run('DELETE FROM "_tiffin_attachment_links" WHERE "attachment_id" = ?', [$unlinkedWithFile]);
        mkdir(dirname($this->targetOf('orphan')), 0750, true);
        file_put_contents($this->targetOf('orphan'), 'orphan');
        $strays = [$this->folder . '/attachments/notes.txt', $this->folder . '/attachments/' . substr($this->hashOf('linked'), 0, 2) . '/notes.txt'];
        foreach ($strays as $stray) {
            file_put_contents($stray, 'not ours');
        }
        $wrongFolder = $this->folder . '/attachments/zz';
        mkdir($wrongFolder);
        file_put_contents($wrongFolder . '/' . $this->hashOf('orphan'), 'misplaced');

        self::assertSame(2, $this->attachments()->sweep());

        self::assertSame([$kept], array_map('intval', array_column($this->db->all('SELECT "id" FROM "_tiffin_attachments"'), 'id')));
        self::assertSame([$this->hashOf('linked')], $this->store()->storedHashes());
        self::assertFileDoesNotExist($this->targetOf('orphan'));
        self::assertFileDoesNotExist($this->targetOf('was linked'));
        foreach ([...$strays, $wrongFolder . '/' . $this->hashOf('orphan')] as $stray) {
            self::assertFileExists($stray);
        }
        self::assertSame(0, $this->attachments()->sweep(), 'Idempotent.');
        self::assertSame(0, $this->attachments()->sweepFiles());
        self::assertStringNotContainsString('could not be deleted', (string) file_get_contents($log));
    }

    public function testSweepFilesLeavesRowsAlone(): void
    {
        $item = $this->item();
        $this->attach($item, 'linked');
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 6, ?, ?, ?)', [$this->hashOf('unlinked'), 'text/plain', 'u', self::START]);
        mkdir(dirname($this->targetOf('unlinked')), 0750, true);
        file_put_contents($this->targetOf('unlinked'), 'unlinked');
        self::assertSame(0, $this->attachments()->sweepFiles(), 'A row still names it.');
        self::assertSame(2, $this->rowCount());
        self::assertSame(1, $this->attachments()->sweep());
        self::assertSame(1, $this->rowCount());
    }

    public function testSweepWithNoAttachmentsFolder(): void
    {
        self::assertSame(0, $this->attachments()->sweep());
        self::assertDirectoryDoesNotExist($this->folder . '/attachments');
    }

    // ------------------------------------------------------------ T-ATT-10

    public function testDeletingAFieldOrATableThenSweepingRemovesTheirFiles(): void
    {
        $more = $this->editor()->addField($this->items->key, 'More', 'attachment', [], false, '');
        $photos = $this->editor()->addField($this->rooms->key, 'Photos', 'attachment', [], false, '');
        $item = $this->item();
        $room = $this->room('Kitchen');
        $this->attach($item, 'field only', 'a', $more);
        $this->attach($item, 'shared by fields', 'b', $more);
        $this->attach($item, 'shared by fields', 'c');
        $this->attach($room, 'table only', 'd', $photos);
        $this->attach($room, 'shared with table', 'e', $photos);
        $this->attach($item, 'shared with table', 'f');

        $this->editor()->deleteField($more->key);
        self::assertSame(1, $this->attachments()->sweep());
        self::assertNull($this->storedFile($this->hashOf('field only')));
        self::assertNotNull($this->storedFile($this->hashOf('shared by fields')));

        $this->editor()->deleteTable($this->rooms->key);
        $sweeper = new Attachments($this->db, $this->schema(), $this->store(), $this->clock);
        self::assertSame(1, $sweeper->sweep());
        self::assertNull($this->storedFile($this->hashOf('table only')));
        self::assertNotNull($this->storedFile($this->hashOf('shared with table')));
        self::assertSame(2, $this->rowCount());
    }

    // ------------------------------------------------------------ T-ATT-11

    public function testIsServable(): void
    {
        $item = $this->item();
        $trashed = $this->item();
        $live = $this->attach($item, 'live');
        $onlyTrashed = $this->attach($trashed, 'trashed');
        $both = $this->attach($trashed, 'both');
        $this->db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, 9)', [$this->f['files']->key, $item, $both]);
        $this->trashRecord($this->items, $trashed);
        $more = $this->editor()->addField($this->items->key, 'More', 'attachment', [], false, '');
        $ofDeletedField = $this->attach($item, 'deleted field', 'x', $more);
        $this->db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [$this->hashOf('no link'), 'text/plain', 'n', self::START]);
        $noLink = $this->db->lastInsertId();

        $attachments = $this->attachments();
        self::assertTrue($attachments->isServable($live));
        self::assertTrue($attachments->isServable($both));
        self::assertTrue($attachments->isServable($ofDeletedField));
        self::assertFalse($attachments->isServable($onlyTrashed));
        self::assertFalse($attachments->isServable($noLink));
        self::assertFalse($attachments->isServable(999));

        $this->editor()->deleteField($more->key);
        self::assertFalse($this->attachments()->isServable($ofDeletedField));

        self::assertTrue($attachments->isLinked($this->f['files']->key, $item, $live));
        self::assertFalse($attachments->isLinked($this->f['files']->key, $trashed, $onlyTrashed));
        self::assertFalse($attachments->isLinked($this->f['files']->key, $trashed, $live));
    }

    public function testAnotherProjectsAttachmentIsNotServable(): void
    {
        $item = $this->item();
        $this->attach($item, 'here');
        $otherFolder = $this->temp->path('projects/b3kq7vn3xbpa');
        mkdir($otherFolder, 0750, true);
        $otherDb = ProjectFile::create($otherFolder . '/project.tiffin', 'b3kq7vn3xbpa', 'Other Example', self::USER_KEY, '0.2.0-dev', $this->clock);
        $this->extraConnections[] = $otherDb;
        $editor = new SchemaEditor($otherDb, $otherFolder, $this->types, new Keys(new SeededRandom('tiffindb-attachments-other')), $this->clock, $this->snapshots, self::USER_KEY, static function (SnapshotInfo $info): void {
        });
        $table = $editor->createTable('Things', 'thing', true);
        $files = $editor->addField($table->key, 'Files', 'attachment', [], false, '');
        $otherDb->run('INSERT INTO ' . Identifier::table($table->key) . ' ("_created_at", "_updated_at") VALUES (?, ?)', [self::START, self::START]);
        $otherSchema = new Schema($otherDb, $this->types);
        $there = new Attachments($otherDb, $otherSchema, new AttachmentStore($otherFolder), $this->clock);
        $ids = [];
        foreach (['one', 'two', 'three'] as $content) {
            $ids[] = $there->add($files->key, 1, $this->stage($content), $content, self::USER_KEY, null)->attachment->id;
        }
        self::assertTrue($there->isServable($ids[2]));
        self::assertFalse($this->attachments()->isServable($ids[2]));
        self::assertNull($this->attachments()->get($ids[2]));
    }

    public function testReadsCheckTheirArguments(): void
    {
        foreach ([fn () => $this->attachments()->get(0), fn () => $this->attachments()->isServable(-1), fn () => $this->attachments()->isLinked($this->f['files']->key, 0, 1)] as $case) {
            try {
                $case();
                self::fail('A bad id was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(NotFound::class);
        $this->attachments()->isLinked($this->f['name']->key, 1, 1);
    }

    // ------------------------------------------------------------ T-ATT-13

    public function testTotalsCountDistinctContents(): void
    {
        self::assertSame(['files' => 0, 'bytes' => 0], $this->attachments()->totals());
        $item = $this->item();
        $this->attach($item, 'abc');
        $this->attach($item, 'abc');
        $this->attach($item, 'defgh');
        $other = $this->item();
        $id = $this->attach($other, str_repeat('z', 1000));
        self::assertSame(['files' => 3, 'bytes' => 1008], $this->attachments()->totals());
        $this->attachments()->remove($this->f['files']->key, $other, $id, self::USER_KEY);
        self::assertSame(['files' => 2, 'bytes' => 8], $this->attachments()->totals());
    }

    // ------------------------------------------------------------ T-ATT-14

    public function testAttachmentQueriesSearchTheLinkTableByKeyFirst(): void
    {
        $this->db->run('ANALYZE');
        $this->db->run('DELETE FROM "sqlite_stat1"');
        $name = Identifier::tableName($this->items->key);
        $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', [$name, trim(Identifier::index($name, 'trash'), '"'), '20000 1']);
        $this->db->run('INSERT INTO "sqlite_stat1" VALUES (?, ?, ?)', ['_tiffin_attachment_links', 'sqlite_autoindex__tiffin_attachment_links_1', '40000 20000 20000 1']);
        $this->db->run('ANALYZE "sqlite_schema"');
        $table = $this->items->sql();
        $queries = [
            'linked' => ['SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN ' . $table . ' AS "r" ON "r"."id" = "l"."record_id"'
                . ' WHERE "l"."field_key" = ? AND "l"."record_id" = ? AND "l"."attachment_id" = ? AND "r"."_trashed_at" IS NULL LIMIT 1', 3],
            'servable' => ['SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN ' . $table . ' AS "r" ON "r"."id" = "l"."record_id"'
                . ' WHERE "l"."field_key" = ? AND "l"."attachment_id" = ? AND "r"."_trashed_at" IS NULL LIMIT 1', 2],
        ];
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Core/Query/Attachments.php');
        self::assertSame(2, substr_count($source, '\'SELECT 1 FROM "_tiffin_attachment_links" AS "l" CROSS JOIN \' . $table->sql() . \' AS "r" ON "r"."id" = "l"."record_id"\''), 'The queries below are copies of the class\'s.');
        foreach ($queries as $label => [$sql, $params]) {
            self::assertStringContainsString(substr($sql, (int) strpos($sql, ' WHERE ')), str_replace("'\n            . '", '', $source), $label);
            $plan = array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql, array_fill(0, $params, 1)), 'detail');
            self::assertCount(2, $plan, $label);
            // The link row by its primary key (a skip-scan over its leading columns included), then the record by id.
            self::assertMatchesRegularExpression('/^SEARCH l USING PRIMARY KEY \((field_key=\?|ANY\(field_key\))/', $plan[0], $label);
            self::assertSame('SEARCH r USING INTEGER PRIMARY KEY (rowid=?)', $plan[1], $label);
        }
        // Every join of the link table in the class is a CROSS JOIN.
        self::assertSame(preg_match_all('/\bJOIN\b/', $source), preg_match_all('/\bCROSS JOIN\b/', $source));
        self::assertSame(2, preg_match_all('/\bCROSS JOIN\b/', $source));
    }
}
