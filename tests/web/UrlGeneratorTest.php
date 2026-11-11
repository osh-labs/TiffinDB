<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Web\UrlGenerator;

/** T-WEB-4 (URLs): base-path prefixing, asset versions, unsafe names. */
#[CoversClass(UrlGenerator::class)]
final class UrlGeneratorTest extends TestCase
{
    public function testPathsWithoutBasePath(): void
    {
        $urls = new UrlGenerator('', '0.1.0-dev');

        self::assertSame('/', $urls->to('/'));
        self::assertSame('/projects', $urls->to('/projects'));
    }

    public function testPathsWithBasePath(): void
    {
        $urls = new UrlGenerator('/tiffindb', '0.1.0-dev');

        self::assertSame('/tiffindb/', $urls->to('/'));
        self::assertSame('/tiffindb/projects', $urls->to('/projects'));
    }

    public function testQueryIsAppendedRfc3986(): void
    {
        $urls = new UrlGenerator('/b', '1');

        self::assertSame('/b/?theme=dark', $urls->to('/', ['theme' => 'dark']));
        self::assertSame('/b/x?a=1&q=two%20words%26%3D', $urls->to('/x', ['a' => '1', 'q' => 'two words&=']));
        self::assertSame('/b/x', $urls->to('/x', []));
    }

    /** @return array<string, array{string}> */
    public static function badPaths(): array
    {
        return [
            'relative' => ['projects'],
            'empty' => [''],
            'protocol-relative' => ['//example.com/'],
            'absolute URL' => ['https://example.com/'],
            'query' => ['/x?y=1'],
            'fragment' => ['/x#y'],
            'back-slash' => ['/\\example.com'],
            'space' => ['/a b'],
            'newline' => ["/a\nb"],
            'NUL' => ["/a\0b"],
        ];
    }

    #[DataProvider('badPaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGenerator('', '1'))->to($path);
    }

    public function testNonStringQueryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGenerator('', '1'))->to('/', ['a' => ['b']]);
    }

    public function testAssetCarriesVersion(): void
    {
        self::assertSame('/assets/css/app.css?v=0.1.0-dev', (new UrlGenerator('', '0.1.0-dev'))->asset('css/app.css'));
        self::assertSame(
            '/tiffindb/assets/fonts/inter/inter-latin-400-normal.woff2?v=1%2B2%20x',
            (new UrlGenerator('/tiffindb', '1+2 x'))->asset('fonts/inter/inter-latin-400-normal.woff2'),
        );
    }

    /** @return array<string, array{string}> */
    public static function badAssets(): array
    {
        return [
            'parent' => ['../config/app.php'],
            'inner parent' => ['css/../../x'],
            'leading slash' => ['/css/app.css'],
            'leading dot' => ['.htaccess'],
            'upper case' => ['css/App.css'],
            'query' => ['css/app.css?x=1'],
            'scheme' => ['https://example.com/x.css'],
            'back-slash' => ['css\\app.css'],
            'empty' => [''],
            'quote' => ['css/a"b.css'],
            'NUL' => ["css/a\0.css"],
            'trailing newline' => ["css/app.css\n"],
        ];
    }

    #[DataProvider('badAssets')]
    public function testUnsafeAssetNamesAreRejected(string $file): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGenerator('', '1'))->asset($file);
    }

    /** @return array<string, array{string}> */
    public static function badBasePaths(): array
    {
        return [
            'trailing slash' => ['/trailing/'],
            'protocol-relative' => ['//example.com'],
            'relative' => ['tiffindb'],
            'root only' => ['/'],
            'back-slash' => ['/\\example.com'],
            'trailing newline' => ["/tiffindb\n"],
        ];
    }

    public function testBasePathSegmentsAreEncoded(): void
    {
        $urls = new UrlGenerator('/my app/100%/a?b#c/café', '1');

        self::assertSame('/my%20app/100%25/a%3Fb%23c/caf%C3%A9/x', $urls->to('/x'));
        self::assertSame('/my%20app/100%25/a%3Fb%23c/caf%C3%A9/', $urls->to('/'));
        self::assertSame(
            '/my%20app/100%25/a%3Fb%23c/caf%C3%A9/assets/css/app.css?v=1',
            $urls->asset('css/app.css'),
        );
        self::assertSame('/plain-dir_1.x/x', (new UrlGenerator('/plain-dir_1.x', '1'))->to('/x'));
    }

    #[DataProvider('badBasePaths')]
    public function testInvalidBasePathIsRejected(string $basePath): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlGenerator($basePath, '1');
    }
}
