<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\AttachmentOperations;
use TiffinDB\Core\App\AttachmentTotals;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-110 to T-APP-116 (M4 spec sections 10.2 and 10.3): the upload
 * page, adding with every refusal (nothing stored, staging removed),
 * replacing, the file page, removing, the project totals, and audit
 * details without names or values.
 */
#[CoversClass(AttachmentOperations::class)]
#[CoversClass(ProjectOperations::class)]
final class AttachmentOperationsTest extends M4AppTestCase
{
    // ------------------------------------------------------------ T-APP-110

    public function testTheUploadScreenAndItsRefusals(): void
    {
        $ops = $this->ops->attachments();
        $lamp = (string) $this->ids['Example lamp'];
        $first = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $other = $this->attach($this->f['photos'], 'Example kettle', self::png('kettle'));

        $screen = $ops->uploadScreen($this->member, $this->p, $this->items, $lamp, $this->f['photos'], null);
        self::assertSame([AttachmentOperations::MAX_BYTES, true, 1, null], [$screen->maxBytes, $screen->imagesOnly, $screen->count, $screen->replacing]);
        self::assertSame(67108864, $screen->maxBytes);
        self::assertSame([$this->ids['Example lamp'], 'Example lamp', $this->f['photos']], [$screen->record->id, $screen->record->title, $screen->field->key]);
        self::assertFalse($ops->uploadScreen($this->member, $this->p, $this->items, $lamp, $this->f['files'], null)->imagesOnly);
        self::assertSame($first->id, $ops->uploadScreen($this->member, $this->p, $this->items, $lamp, $this->f['photos'], (string) $first->id)->replacing?->id);

        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example chair']]);
        foreach ([
            [$lamp, $this->f['photos'], (string) $other->id],
            [$lamp, $this->f['files'], (string) $first->id],
            [$lamp, $this->f['photos'], '0' . $first->id],
            [$lamp, $this->f['photos'], '999'],
            [$lamp, $this->f['count'], null],
            [$lamp, $this->f['room'], null],
            [$lamp, 'k2xq7vn4mz3t', null],
            ['0' . $lamp, $this->f['photos'], null],
            [(string) $this->ids['Example chair'], $this->f['photos'], null],
        ] as [$record, $field, $replace]) {
            $this->assertThrows(NotFound::class, fn () => $ops->uploadScreen($this->admin, $this->p, $this->items, $record, $field, $replace));
        }
        $this->assertThrows(NotFound::class, fn () => $ops->uploadScreen($this->admin, $this->p, $this->rooms, (string) $this->ids['Example hall'], $this->f['photos'], null));
    }

    // ------------------------------------------------------------ T-APP-111

    public function testAddStagesThenPlacesTheFile(): void
    {
        $png = self::png();
        $file = $this->attach($this->f['photos'], 'Example lamp', $png, "  Kitchen\u{202E}lamp/photo.png ");
        self::assertSame(['Kitchenlamp_photo.png', 'image/png', strlen($png), hash('sha256', $png)], [$file->originalName, $file->mediaType, $file->size, $file->sha256]);
        self::assertSame($png, file_get_contents($this->storedPath($file->sha256)));
        self::assertSame([], $this->uploadJobs(), 'The staging folder is removed on success.');
    }

    public function testEveryRefusedUploadLeavesNothingStoredAndNoStagingFolder(): void
    {
        $ops = $this->ops->attachments();
        $lamp = (string) $this->ids['Example lamp'];
        $add = fn (string $field, string $path): mixed => $ops->add($this->admin, $this->p, $this->items, $lamp, $field, $path, 'example.bin', null);
        $before = $this->rows();

        $cases = [
            'empty' => [$this->f['files'], $this->fx->upload(''), 'problem.upload.empty', []],
            'not an image' => [$this->f['photos'], $this->fx->upload(FileFixture::pdf()), 'problem.attachment.not_image', []],
            'missing' => [$this->f['files'], $this->fx->temp->path('no-such-upload'), 'problem.upload.missing', []],
        ];
        $large = $this->fx->upload('');
        $handle = fopen($large, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, AttachmentOperations::MAX_BYTES + 1);
        fclose($handle);
        $cases['too large'] = [$this->f['files'], $large, 'problem.upload.too_large', ['max' => AttachmentOperations::MAX_BYTES]];
        foreach ($cases as $case => [$field, $path, $key, $params]) {
            self::assertSame([['file', $key, $params]], self::problems(fn () => $add($field, $path)), $case);
            self::assertSame([], $this->uploadJobs(), $case);
            self::assertSame($before, $this->rows(), $case);
            self::assertSame([], $this->storedHashes(), $case);
        }

        // Too many: 100 files in the field already.
        $db = $this->fx->projectDb($this->p);
        for ($i = 1; $i <= 100; $i++) {
            $db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [hash('sha256', 'n' . $i), 'application/octet-stream', 'f', '2026-03-02T10:00:00Z']);
            $db->run('INSERT INTO "_tiffin_attachment_links" VALUES (?, ?, ?, ?)', [$this->f['files'], $this->ids['Example lamp'], $db->lastInsertId(), $i]);
        }
        $before = $this->rows();
        self::assertSame([['file', 'problem.attachment.too_many', ['item' => 'item', 'max' => 100]]], self::problems(fn () => $add($this->f['files'], $this->fx->upload('more'))));
        self::assertSame([[$before, []], []], [[$this->rows(), $this->storedHashes()], $this->uploadJobs()]);

        // An injected store failure: the hash's folder is a regular file.
        $png = self::png('blocked');
        $hash = hash('sha256', $png);
        if (!is_dir($this->fx->projectFolder($this->p) . '/attachments')) {
            mkdir($this->fx->projectFolder($this->p) . '/attachments', 0750, true);
        }
        file_put_contents($this->fx->projectFolder($this->p) . '/attachments/' . substr($hash, 0, 2), 'not a folder');
        $this->captureLog();
        self::assertSame([['file', 'problem.attachment.store_failed', []]], self::problems(fn () => $add($this->f['photos'], $this->fx->upload($png))));
        self::assertSame([[$before, []], []], [[$this->rows(), $this->uploadJobs()], []]);
        self::assertStringContainsString('TiffinDB: an attachment could not be stored', $this->errorLog());
        self::assertStringNotContainsString('example.bin', $this->errorLog());
    }

    // ------------------------------------------------------------ T-APP-112

    public function testReplaceSwapsTheFileInOneTransactionAndDeletesTheOldFile(): void
    {
        $old = $this->attach($this->f['photos'], 'Example lamp', self::png('old'), 'old.png');
        $new = $this->attach($this->f['photos'], 'Example lamp', self::png('new'), 'new.png', $old->id);
        self::assertSame('new.png', $new->originalName);
        self::assertFileDoesNotExist($this->storedPath($old->sha256), 'Unshared: deleted after the commit.');
        self::assertFileExists($this->storedPath($new->sha256));
        $screen = $this->ops->attachments()->uploadScreen($this->admin, $this->p, $this->items, (string) $this->ids['Example lamp'], $this->f['photos'], null);
        self::assertSame(1, $screen->count);
        $added = $this->lastAudit(AuditAction::ATTACHMENT_ADDED);
        $removed = $this->lastAudit(AuditAction::ATTACHMENT_REMOVED);
        self::assertSame($new->id, $added->detail['attachment']);
        self::assertSame(['field' => $this->f['photos'], 'record' => $this->ids['Example lamp'], 'attachment' => $old->id, 'size' => $old->size], $removed->detail);
        self::assertSame([AuditAction::ATTACHMENT_ADDED, AuditAction::ATTACHMENT_REMOVED], array_slice($this->auditActions(), -2));

        // Shared content stays while another row has it.
        $shared = $this->attach($this->f['files'], 'Example kettle', self::png('new'), 'copy.png');
        self::assertSame($new->sha256, $shared->sha256);
        $this->attach($this->f['photos'], 'Example lamp', self::png('third'), 'third.png', $new->id);
        self::assertFileExists($this->storedPath($new->sha256));
        // A replaced id this record and field do not link is refused before anything is staged.
        $this->assertThrows(NotFound::class, fn () => $this->attach($this->f['photos'], 'Example lamp', self::png('x'), 'x.png', $shared->id));
        self::assertSame([], $this->uploadJobs());
    }

    // ------------------------------------------------------------ T-APP-113

    public function testTheFilePageAndItsRefusals(): void
    {
        $ops = $this->ops->attachments();
        $lamp = (string) $this->ids['Example lamp'];
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $pdf = $this->attach($this->f['files'], 'Example lamp', FileFixture::pdf(), 'manual.pdf');
        $kettle = $this->attach($this->f['files'], 'Example kettle', FileFixture::pdf(), 'kettle.pdf');

        $screen = $ops->file($this->member, $this->p, $this->items, $lamp, $this->f['photos'], (string) $photo->id);
        self::assertSame([$photo->id, 'Example Admin', true, true, 'Example lamp'], [$screen->file->id, $screen->addedBy, $screen->inline, $screen->canEdit, $screen->record->title]);
        self::assertFalse($ops->file($this->member, $this->p, $this->items, $lamp, $this->f['files'], (string) $pdf->id)->inline);
        foreach ([[$this->f['files'], (string) $kettle->id], [$this->f['files'], (string) $photo->id], [$this->f['photos'], '0' . $photo->id], [$this->f['room'], (string) $photo->id]] as [$field, $id]) {
            $this->assertThrows(NotFound::class, fn () => $ops->file($this->admin, $this->p, $this->items, $lamp, $field, $id));
        }
    }

    // ------------------------------------------------------------ T-APP-114

    public function testRemoveDeletesTheLinkTheRowAndTheFile(): void
    {
        $ops = $this->ops->attachments();
        $lamp = (string) $this->ids['Example lamp'];
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $this->assertThrows(NotFound::class, fn () => $ops->remove($this->admin, $this->p, $this->items, (string) $this->ids['Example kettle'], $this->f['photos'], (string) $photo->id));
        $removed = $ops->remove($this->member, $this->p, $this->items, $lamp, $this->f['photos'], (string) $photo->id);
        self::assertSame($photo->id, $removed->id);
        self::assertSame([], $this->rows());
        self::assertFileDoesNotExist($this->storedPath($photo->sha256));
        self::assertSame(['field' => $this->f['photos'], 'record' => $this->ids['Example lamp'], 'attachment' => $photo->id, 'size' => $photo->size], $this->lastAudit(AuditAction::ATTACHMENT_REMOVED)->detail);
        self::assertSame($this->member->userId, $this->lastAudit(AuditAction::ATTACHMENT_REMOVED)->actorUserId);
        $this->assertThrows(NotFound::class, fn () => $ops->remove($this->admin, $this->p, $this->items, $lamp, $this->f['photos'], (string) $photo->id));
    }

    // ------------------------------------------------------------ T-APP-115

    public function testAttachmentTotalsCountDistinctFiles(): void
    {
        $a = self::png('a');
        $b = FileFixture::pdf();
        $this->attach($this->f['photos'], 'Example lamp', $a);
        $this->attach($this->f['files'], 'Example kettle', $a, 'copy.png');
        $this->attach($this->f['files'], 'Example lamp', $b, 'b.pdf');
        self::assertEquals(new AttachmentTotals(2, strlen($a) + strlen($b)), $this->ops->projects()->attachmentTotals($this->admin, $this->p));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->projects()->attachmentTotals($this->member, $this->p));
        $this->assertThrows(NotFound::class, fn () => $this->ops->projects()->attachmentTotals($this->member2, $this->p));
    }

    // ------------------------------------------------------------ T-APP-116

    public function testAuditDetailsHoldNoFileNameOrValue(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png(), 'secret-holiday-snap.png');
        $this->ops->attachments()->remove($this->admin, $this->p, $this->items, (string) $this->ids['Example lamp'], $this->f['photos'], (string) $photo->id);
        $key = $this->ops->collections()->save($this->admin, $this->p, $this->items, null, [
            'name' => 'Example hidden name', 'rules' => [['field' => $this->f['name'], 'test' => 'contains', 'value' => 'needle-text']],
        ]);
        $this->ops->collections()->delete($this->admin, $this->p, $key);
        $stored = json_encode($this->fx->systemRows(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['secret-holiday-snap', 'Example hidden name', 'needle-text', $photo->sha256] as $text) {
            self::assertStringNotContainsString($text, $stored);
        }
        $actions = $this->auditActions();
        foreach ([AuditAction::ATTACHMENT_ADDED, AuditAction::ATTACHMENT_REMOVED, AuditAction::COLLECTION_SAVED, AuditAction::COLLECTION_DELETED] as $action) {
            self::assertContains($action, $actions);
        }
    }

    /** @return list<array<string, mixed>> attachment rows and links, for before/after comparisons */
    private function rows(): array
    {
        $db = $this->fx->projectDb($this->p);

        return [
            ...$db->all('SELECT * FROM "_tiffin_attachments" ORDER BY "id"'),
            ...$db->all('SELECT * FROM "_tiffin_attachment_links" ORDER BY "field_key", "record_id", "attachment_id"'),
        ];
    }
}
