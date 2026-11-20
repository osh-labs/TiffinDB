<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\MediaType;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\TempDir;

/** T-STO-9: type detection from leading bytes only (ADR 0018 decision 2). */
#[CoversClass(MediaType::class)]
final class MediaTypeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function heads(): iterable
    {
        yield 'png' => ["\x89PNG\r\n\x1A\n", MediaType::PNG];
        yield 'jpeg' => ["\xFF\xD8\xFF", MediaType::JPEG];
        yield 'gif87a' => ['GIF87a', MediaType::GIF];
        yield 'gif89a' => ['GIF89a', MediaType::GIF];
        yield 'webp' => ['RIFF' . "\x00\x00\x00\x00" . 'WEBP', MediaType::WEBP];
        yield 'pdf' => ['%PDF-', MediaType::PDF];

        // One byte short.
        yield 'png short' => ["\x89PNG\r\n\x1A", MediaType::OTHER];
        yield 'jpeg short' => ["\xFF\xD8", MediaType::OTHER];
        yield 'gif short' => ['GIF89', MediaType::OTHER];
        yield 'webp short' => ['RIFF' . "\x00\x00\x00\x00" . 'WEB', MediaType::OTHER];
        yield 'pdf short' => ['%PDF', MediaType::OTHER];

        // One byte altered.
        yield 'png altered' => ["\x89PNG\r\n\x1A\x0B", MediaType::OTHER];
        yield 'png lower case' => ["\x89png\r\n\x1A\n", MediaType::OTHER];
        yield 'jpeg altered' => ["\xFF\xD8\xFE", MediaType::OTHER];
        yield 'gif version' => ['GIF88a', MediaType::OTHER];
        yield 'gif lower case' => ['gif89a', MediaType::OTHER];
        yield 'riff altered' => ['RIFX' . "\x00\x00\x00\x00" . 'WEBP', MediaType::OTHER];
        yield 'pdf altered' => ['%PDF_', MediaType::OTHER];
        yield 'pdf not at the start' => [' %PDF-1.4', MediaType::OTHER];
        yield 'png not at the start' => ["\x00\x89PNG\r\n\x1A\n", MediaType::OTHER];

        // A RIFF of another form type.
        yield 'riff wave' => ['RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ', MediaType::OTHER];
        yield 'riff avi' => ['RIFF' . "\x24\x00\x00\x00" . 'AVI LIST', MediaType::OTHER];
        yield 'webp at the wrong offset' => ['RIFFWEBP' . "\x00\x00\x00\x00", MediaType::OTHER];

        yield 'empty' => ['', MediaType::OTHER];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', MediaType::OTHER];
        yield 'svg with xml declaration' => ['<?xml version="1.0"?><svg/>', MediaType::OTHER];
        yield 'html' => ['<!DOCTYPE html><html><script>alert(1)</script></html>', MediaType::OTHER];
        yield 'text' => ['synthetic notes', MediaType::OTHER];
        // Served under nosniff and a sandbox policy (ADR 0018 decision 5).
        yield 'png signature then html' => ["\x89PNG\r\n\x1A\n<html><script>alert(1)</script></html>", MediaType::PNG];
        yield 'pdf then html' => ['%PDF-<html><script>alert(1)</script>', MediaType::PDF];
    }

    #[DataProvider('heads')]
    public function testSniff(string $head, string $expected): void
    {
        self::assertSame($expected, MediaType::sniff($head));
    }

    public function testEveryFixtureIsRecognised(): void
    {
        self::assertSame(MediaType::PNG, MediaType::sniff(FileFixture::png(2, 2)));
        self::assertSame(MediaType::JPEG, MediaType::sniff(FileFixture::jpeg()));
        self::assertSame(MediaType::GIF, MediaType::sniff(FileFixture::gif()));
        self::assertSame(MediaType::WEBP, MediaType::sniff(FileFixture::webp()));
        self::assertSame(MediaType::PDF, MediaType::sniff(FileFixture::pdf()));
        self::assertSame(FileFixture::png(3, 2), FileFixture::png(3, 2), 'Fixtures are deterministic.');
        self::assertNotSame(FileFixture::png(1, 1, 'a'), FileFixture::png(1, 1, 'b'));
    }

    public function testIsInlineComparesExactly(): void
    {
        foreach (MediaType::INLINE as $type) {
            self::assertTrue(MediaType::isInline($type), $type);
        }
        self::assertSame([MediaType::PNG, MediaType::JPEG, MediaType::GIF, MediaType::WEBP], MediaType::INLINE);
        foreach ([MediaType::PDF, MediaType::OTHER, 'image/svg+xml', 'IMAGE/PNG', 'image/png ', ' image/png', 'image/png; charset=utf-8', 'image/jpg', 'text/html', ''] as $type) {
            self::assertFalse(MediaType::isInline($type), $type);
        }
    }

    public function testOfFileReadsSixteenBytesAtMost(): void
    {
        $temp = TempDir::create();
        try {
            $cases = [
                'a.png' => [FileFixture::png(), MediaType::PNG],
                'a.webp' => [FileFixture::webp(), MediaType::WEBP],
                'short' => ["\xFF\xD8\xFF", MediaType::JPEG],
                'empty' => ['', MediaType::OTHER],
                // The extension is never consulted.
                'b.png' => ['<svg/>', MediaType::OTHER],
                'c.txt' => [FileFixture::gif(), MediaType::GIF],
            ];
            foreach ($cases as $name => [$bytes, $type]) {
                file_put_contents($temp->path($name), $bytes);
                self::assertSame($type, MediaType::ofFile($temp->path($name)), $name);
            }
            self::assertSame(16, MediaType::HEAD_BYTES);

            symlink($temp->path('a.png'), $temp->path('link.png'));
            mkdir($temp->path('folder'));
            foreach (['link.png', 'folder', 'missing'] as $name) {
                try {
                    MediaType::ofFile($temp->path($name));
                    self::fail($name . ' was read.');
                } catch (\RuntimeException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            $temp->remove();
        }
    }
}
