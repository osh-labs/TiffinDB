<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveManifest;
use TiffinDB\Core\Io\Archive\ArchiveReader;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ZipCheck;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-4: the manifest corpus. Exactly the nine members of
 * `docs/formats/tiffin.md` section 13.2 with their documented types and
 * forms; size and hash equal to the project file; versions above what
 * this TiffinDB reads refused as `newer`.
 */
#[CoversClass(ArchiveManifest::class)]
#[CoversClass(ArchiveReader::class)]
final class ManifestCorpusTest extends TestCase
{
    use ArchiveFixture;

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-manifest-corpus-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function members(): iterable
    {
        yield 'extra member' => [['comment' => 'synthetic'], ArchiveRefused::MANIFEST];
        yield 'missing member' => [['attachments' => null], ArchiveRefused::MANIFEST];
        yield 'wrong format' => [['format' => 'tiffindb-project'], ArchiveRefused::MANIFEST];
        yield 'archive version 0' => [['archive_version' => 0], ArchiveRefused::MANIFEST];
        yield 'archive version as text' => [['archive_version' => '1'], ArchiveRefused::MANIFEST];
        yield 'archive version 2' => [['archive_version' => 2], ArchiveRefused::NEWER];
        yield 'project format version 4' => [['project_format_version' => 4], ArchiveRefused::NEWER];
        yield 'project format version 0' => [['project_format_version' => 0], ArchiveRefused::MANIFEST];
        yield 'project format version real' => [['project_format_version' => 2.5], ArchiveRefused::MANIFEST];
        yield 'size mismatch' => [['project_size' => 1], ArchiveRefused::MANIFEST];
        yield 'negative size' => [['project_size' => -1], ArchiveRefused::MANIFEST];
        yield 'hash mismatch' => [['project_sha256' => str_repeat('0', 64)], ArchiveRefused::MANIFEST];
        yield 'upper-case hash' => [['project_sha256' => str_repeat('A', 64)], ArchiveRefused::MANIFEST];
        yield 'bad time' => [['created_at' => '2026-02-30T10:00:00Z'], ArchiveRefused::MANIFEST];
        yield 'bad version text' => [['created_with' => '<script>'], ArchiveRefused::MANIFEST];
        yield 'empty name' => [['project_name' => '   '], ArchiveRefused::MANIFEST];
        yield 'name with control character' => [['project_name' => "Example\u{202E}Project"], ArchiveRefused::MANIFEST];
        yield 'name as number' => [['project_name' => 7], ArchiveRefused::MANIFEST];
        yield 'negative attachments' => [['attachments' => -1], ArchiveRefused::MANIFEST];
        yield 'nested object' => [['attachments' => ['count' => 0]], ArchiveRefused::MANIFEST];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('members')]
    public function testManifestIsChecked(array $override, string $reason): void
    {
        self::assertSame($reason, $this->refusal($this->zipFor($this->copyOfSource(), $override)));
    }

    public function testNotJsonOrNotAnObjectIsRefused(): void
    {
        foreach (['not json', '[1, 2]', '"text"', '{"format": "tiffindb-archive",}', "\xff\xfe{}"] as $text) {
            $copy = (string) file_get_contents($this->copyOfSource());
            $path = (new ZipBytes())->add(ZipCheck::MANIFEST, $text)->add(ZipCheck::PROJECT, $copy)->write($this->jobFolder() . '/upload.zip');
            self::assertSame(ArchiveRefused::MANIFEST, $this->refusal($path), $text);
        }
    }

    public function testDuplicateMembersAreRefused(): void
    {
        $copy = $this->copyOfSource();
        $bytes = (string) file_get_contents($copy);
        $members = '"format":"tiffindb-archive","archive_version":1,"created_at":"' . self::CREATED . '","created_with":"0.3.0-dev",'
            . '"project_format_version":3,"project_name":"Example Project","project_size":' . strlen($bytes)
            . ',"project_sha256":"' . hash('sha256', $bytes) . '","attachments":0';
        $cases = [
            'repeated' => '{' . $members . ',"project_name":"Other Project"}',
            'escaped repeat' => '{' . $members . ',"project\\u005fname":"Other Project"}',
            'repeat after spaces' => '{' . $members . ' , "attachments" : 0}',
        ];
        foreach ($cases as $label => $json) {
            $json = str_replace('\\\\u', '\\u', $json);
            $path = (new ZipBytes())->add(ZipCheck::MANIFEST, $json)->add(ZipCheck::PROJECT, $bytes)->write($this->jobFolder() . '/upload.zip');
            self::assertSame(ArchiveRefused::MANIFEST, $this->refusal($path), $label);
        }
        $quoted = '{' . str_replace('"Example Project"', '"Example \\": \\"project_name\\": x"', $members) . '}';
        $quoted = str_replace('\\\\', '\\', $quoted);
        $path = (new ZipBytes())->add(ZipCheck::MANIFEST, $quoted)->add(ZipCheck::PROJECT, $bytes)->write($this->jobFolder() . '/upload.zip');
        [$reader] = $this->check($path);
        self::assertSame('Example ": "project_name": x', $reader->manifest->projectName, 'A quote inside a value is not a member name.');
    }

    public function testValidManifestReadsBack(): void
    {
        [$reader] = $this->check($this->zipFor($this->copyOfSource(), ['project_name' => '  Example Project  ']));
        self::assertSame('Example Project', $reader->manifest->projectName);
        self::assertSame(3, $reader->manifest->projectFormatVersion);
        self::assertSame(self::CREATED, $reader->manifest->createdAt);
    }
}
