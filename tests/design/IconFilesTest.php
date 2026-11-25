<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Design;

use DOMDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-WEB-81 (part), M7 screens spec section 3 and ADR 0021 decision 8: the
 * favicon and the app icon are byte-identical copies of the brand files,
 * so `docs/brand/` stays the source; the web manifest is JSON with exactly
 * the specified fields, and every icon it names exists.
 */
#[CoversNothing]
final class IconFilesTest extends TestCase
{
    /** Served icon => brand file it copies. */
    private const ICONS = [
        'public/assets/icons/favicon.svg' => 'docs/brand/tiffindb-mark-small.svg',
        'public/assets/icons/app-icon.svg' => 'docs/brand/tiffindb-app-icon.svg',
    ];

    private const MANIFEST = 'public/assets/app.webmanifest';

    /** @return iterable<string, array{string, string}> */
    public static function icons(): iterable
    {
        foreach (self::ICONS as $served => $brand) {
            yield $served => [$served, $brand];
        }
    }

    #[DataProvider('icons')]
    public function testIconIsTheBrandFile(string $served, string $brand): void
    {
        self::assertFileExists(RepoFiles::path($brand));
        self::assertFileExists(RepoFiles::path($served));
        self::assertSame(hash_file('sha256', RepoFiles::path($brand)), hash_file('sha256', RepoFiles::path($served)), $served);
    }

    /** @return iterable<string, array{string}> */
    public static function servedIcons(): iterable
    {
        foreach (array_keys(self::ICONS) as $served) {
            yield $served => [$served];
        }
    }

    #[DataProvider('servedIcons')]
    public function testIconIsAnSvgDocumentWithoutScriptOrExternalReference(string $served): void
    {
        $source = RepoFiles::read($served);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($source, LIBXML_NONET), $served);
        self::assertSame('svg', $document->documentElement?->localName);
        self::assertSame('http://www.w3.org/2000/svg', $document->documentElement?->namespaceURI);
        self::assertDoesNotMatchRegularExpression('/<\s*script\b|\son[a-z]+\s*=|href\s*=|<\s*foreignObject\b|<!ENTITY/i', $source, $served);
    }

    public function testTheIconFolderHoldsOnlyTheTwoIcons(): void
    {
        $files = RepoFiles::all('public/assets/icons');
        sort($files);

        self::assertSame(['public/assets/icons/app-icon.svg', 'public/assets/icons/favicon.svg'], $files);
    }

    public function testManifestHasExactlyTheSpecifiedFields(): void
    {
        $manifest = json_decode(RepoFiles::read(self::MANIFEST), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'name' => 'TiffinDB',
                'short_name' => 'TiffinDB',
                'start_url' => '../',
                'scope' => '../',
                'display' => 'browser',
                'icons' => [['src' => 'icons/app-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml']],
            ],
            $manifest,
        );
    }

    public function testEveryManifestIconExists(): void
    {
        $manifest = json_decode(RepoFiles::read(self::MANIFEST), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            self::assertIsArray($icon);
            self::assertIsString($icon['src']);
            self::assertFileExists(RepoFiles::path('public/assets/' . $icon['src']));
        }
    }
}
