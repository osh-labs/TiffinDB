<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\AttachmentOperations;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/M4AppTestCase.php';

/**
 * T-APP-120 to T-APP-129: the access-control matrix of file serving at
 * core level (M4 spec section 12.9, ADR 0018 decision 5). T-APP-128 has
 * no core case: no core method names a path under the data folder.
 */
#[CoversClass(AttachmentOperations::class)]
final class AttachmentAccessTest extends M4AppTestCase
{
    public function testUngrantedMemberGetsNotFound(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $outcome = null;
        try {
            $this->ops->attachments()->serve($this->member2, $this->p, (string) $photo->id, false);
        } catch (NotFound $outcome) {
        }
        self::assertInstanceOf(NotFound::class, $outcome, 'T-APP-120');
        $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member2, 'k2xq7vn4mz3t', (string) $photo->id, false));
    }

    public function testGrantedMemberGetsTheFileLinkedFromALiveRecord(): void
    {
        $png = self::png();
        $photo = $this->attach($this->f['photos'], 'Example lamp', $png, 'lamp.png');
        $served = $this->ops->attachments()->serve($this->member, $this->p, (string) $photo->id, false);
        self::assertSame([MediaType::PNG, 'lamp.png', strlen($png), true], [$served->mediaType, $served->fileName, $served->size, $served->inline], 'T-APP-121');
        self::assertSame($png, file_get_contents($served->path));
    }

    public function testAnIdFromAnotherProjectIsNotFound(): void
    {
        $other = $this->ops->projects()->create($this->admin, 'Example Shed', 'Tools');
        $this->ops->projects()->setSharing($this->admin, $other->key, [$this->member->userKey]);
        $tools = $this->ops->schema()->tables($this->admin, $other->key)[0]->table->key;
        $name = $this->ops->schema()->design($this->admin, $other->key, $tools)->fields[0]->field->key;
        $files = $this->ops->schema()->addField($this->admin, $other->key, $tools, 'Files', 'attachment', [], false, '')->key;
        $tool = $this->fx->addRecord($other->key, $tools, [$name => 'Example saw']);
        $add = fn (string $label): mixed => $this->ops->attachments()->add($this->admin, $other->key, $tools, (string) $tool, $files, $this->fx->upload(self::png($label)), 'saw.png', null);
        $add('saw-1');
        $second = $add('saw-2');
        $this->attach($this->f['photos'], 'Example lamp', self::png());
        // Id 2 exists only in the other project (ids are numbered per project): T-APP-122.
        self::assertSame(2, $second->id);
        $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member, $this->p, '2', false));
        self::assertTrue($this->ops->attachments()->serve($this->member, $other->key, '2', false)->inline);
    }

    public function testAFileLinkedOnlyFromATrashedRecordIsNotFound(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $this->ops->trash()->moveToTrash($this->admin, $this->p, $this->items, [$this->ids['Example lamp']]);
        $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member, $this->p, (string) $photo->id, false));
        $this->ops->trash()->restore($this->admin, $this->p, $this->items, [$this->ids['Example lamp']]);
        self::assertTrue($this->ops->attachments()->serve($this->member, $this->p, (string) $photo->id, false)->inline, 'T-APP-123');
    }

    public function testAMissingFileIsNotFoundAndLoggedWithoutItsName(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png(), 'private-name.png');
        unlink($this->storedPath($photo->sha256));
        $this->captureLog();
        $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member, $this->p, (string) $photo->id, false));
        $log = $this->errorLog();
        self::assertSame(1, substr_count($log, 'TiffinDB: an attachment file is missing; attachment=' . $photo->id), 'T-APP-124');
        self::assertStringNotContainsString('private-name', $log);
        self::assertStringNotContainsString($photo->sha256, $log);
    }

    public function testAnUploadOverTheCapIsRefusedAndNothingIsStored(): void
    {
        $large = $this->fx->upload('');
        $handle = fopen($large, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, AttachmentOperations::MAX_BYTES + 1);
        fclose($handle);
        self::assertSame('problem.upload.too_large', self::problems(fn () => $this->ops->attachments()->add($this->member, $this->p, $this->items, (string) $this->ids['Example lamp'], $this->f['files'], $large, 'big.bin', null))[0][1]);
        self::assertSame([[], []], [$this->storedHashes(), $this->uploadJobs()], 'T-APP-125');
    }

    public function testHtmlSvgAndScriptAreStoredAndServedAsOctetStream(): void
    {
        foreach ([
            'page.html' => "<!DOCTYPE html><html><script>alert(1)</script></html>",
            'drawing.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'run.js' => 'alert(1);',
        ] as $name => $contents) {
            $file = $this->attach($this->f['files'], 'Example lamp', $contents, $name);
            self::assertSame(MediaType::OTHER, $file->mediaType, $name);
            foreach ([false, true] as $download) {
                $served = $this->ops->attachments()->serve($this->member, $this->p, (string) $file->id, $download);
                self::assertSame([MediaType::OTHER, false], [$served->mediaType, $served->inline], 'T-APP-126 ' . $name);
            }
        }
        // Not an image: refused in an images-only field.
        self::assertSame('problem.attachment.not_image', self::problems(fn () => $this->attach($this->f['photos'], 'Example lamp', '<svg/>', 'x.svg'))[0][1]);
    }

    public function testADeactivatedPersonIsRefused(): void
    {
        $photo = $this->attach($this->f['photos'], 'Example lamp', self::png());
        $this->fx->setActive('member', false);
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->attachments()->serve($this->member, $this->p, (string) $photo->id, false));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->attachments()->add($this->member, $this->p, $this->items, (string) $this->ids['Example lamp'], $this->f['files'], $this->fx->upload('x'), 'x', null));
        $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member2, $this->p, (string) $photo->id, false), 'T-APP-127: ungranted first');
    }

    public function testOnlyTheFourImageTypesAreInlineAndDownloadIsNever(): void
    {
        foreach ([
            MediaType::PNG => self::png(),
            MediaType::GIF => FileFixture::gif(),
            MediaType::JPEG => FileFixture::jpeg(),
            MediaType::WEBP => FileFixture::webp(),
            MediaType::PDF => FileFixture::pdf(),
        ] as $type => $contents) {
            $file = $this->attach($this->f['files'], 'Example lamp', $contents, 'file');
            self::assertSame($type, $file->mediaType);
            $inline = $this->ops->attachments()->serve($this->member, $this->p, (string) $file->id, false);
            $download = $this->ops->attachments()->serve($this->member, $this->p, (string) $file->id, true);
            $isImage = $type !== MediaType::PDF;
            self::assertSame([$isImage ? $type : MediaType::OTHER, $isImage], [$inline->mediaType, $inline->inline], 'T-APP-129 ' . $type);
            self::assertSame([$isImage ? $type : MediaType::OTHER, false], [$download->mediaType, $download->inline], $type);
        }
        foreach (['0', '01', 'x', '', '-1', '99999999999999999999'] as $bad) {
            $this->assertThrows(NotFound::class, fn () => $this->ops->attachments()->serve($this->member, $this->p, $bad, false));
        }
    }
}
