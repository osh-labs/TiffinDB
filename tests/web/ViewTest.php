<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\View;
use TiffinDB\Web\ViewScope;

/** T-WEB-4 (view): escaping, helpers, template names, failure handling. */
#[CoversClass(View::class)]
#[CoversClass(ViewScope::class)]
final class ViewTest extends TestCase
{
    private const SOURCE_URL = 'https://example.com/tiffindb-source';

    private static function view(string $basePath = '', ?Appearance $appearance = null): View
    {
        $root = __DIR__ . '/fixtures';

        return new View(
            $root . '/templates',
            new Strings($root . '/strings/en.php'),
            new UrlGenerator($basePath, '1.0'),
            $appearance ?? Appearance::default(),
            ['source_url' => self::SOURCE_URL, 'appearance_preview' => true, 'version' => '1.0'],
        );
    }

    private static function scope(): ViewScope
    {
        $root = __DIR__ . '/fixtures';
        $strings = new Strings($root . '/strings/en.php');
        $urls = new UrlGenerator('/b', '1.0');

        return new ViewScope(self::view(), $strings, $urls, Appearance::fromQuery(['theme' => 'dark']));
    }

    public function testEscapesTheFiveSpecialCharacters(): void
    {
        self::assertSame('&amp;&lt;&gt;&quot;&apos;', self::scope()->e('&<>"\''));
    }

    public function testEscapesScalarsAndNull(): void
    {
        $scope = self::scope();

        self::assertSame('42', $scope->e(42));
        self::assertSame('1.5', $scope->e(1.5));
        self::assertSame('', $scope->e(null));
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        self::assertSame("a\u{FFFD}b", self::scope()->e("a\xFFb"));
    }

    public function testTranslateEscapesTextAndParameters(): void
    {
        self::assertSame(
            'Hello, &lt;img src=x&gt; &amp; welcome',
            self::scope()->t('test.greeting', ['name' => '<img src=x>']),
        );
        self::assertSame('&lt;b&gt;&amp;amp;&lt;/b&gt;', self::scope()->t('test.markup', ['x' => '&amp;']));
    }

    public function testTranslateMissingKeyThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::scope()->t('no.such.key');
    }

    public function testUrlAndAssetAreEscaped(): void
    {
        $scope = self::scope();

        self::assertSame('/b/x?a=1&amp;q=%22', $scope->url('/x', ['a' => '1', 'q' => '"']));
        self::assertSame('/b/assets/css/app.css?v=1.0', $scope->asset('css/app.css'));
    }

    public function testRootClassAttribute(): void
    {
        self::assertSame(' class="t-dark"', self::scope()->rootClassAttribute());

        $page = self::view()->page('home', ['title' => null, 'preview' => null]);
        self::assertStringContainsString('<html lang="en">', $page->body);
    }

    public function testUnknownSlotThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::scope()->slot('sidebar');
    }

    public function testPageRendersPageInsideLayout(): void
    {
        $response = self::view('/base', Appearance::fromQuery(['theme' => 'hc-dark']))
            ->page('helpers', ['title' => 'A & B', 'name' => '<x>', 'value' => '"q"'], 201);

        self::assertSame(201, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));

        $body = $response->body;
        self::assertStringStartsWith('<!DOCTYPE html>', $body);
        self::assertStringContainsString('<html lang="en" class="t-hcd">', $body);
        self::assertStringContainsString('<title>A &amp; B - TiffinDB</title>', $body);
        self::assertStringContainsString('<h1>Hello, &lt;x&gt; &amp; welcome</h1>', $body);
        self::assertStringContainsString('<p id="e">&quot;q&quot;</p>', $body);
        self::assertStringContainsString('<p id="url">/base/projects?q=%3Cx%3E</p>', $body);
        self::assertStringContainsString('<p id="asset">/base/assets/css/app.css?v=1.0</p>', $body);
        self::assertStringContainsString('<span class="item">&lt;x&gt;</span>', $body);
        self::assertStringContainsString('href="' . self::SOURCE_URL . '"', $body);
        self::assertStringContainsString('<main id="main"><h1>', $body);
        self::assertStringNotContainsString('<x>', $body);
    }

    public function testOnlyDataIsInScope(): void
    {
        $body = self::view()->page('helpers', ['title' => null, 'name' => 'n', 'value' => 'v'])->body;

        self::assertStringContainsString('<p id="vars">data</p>', $body);
    }

    public function testHomeTitleIsTheProductNameAlone(): void
    {
        $body = self::view()->page('home', ['title' => null, 'preview' => null])->body;

        self::assertStringContainsString('<title>TiffinDB</title>', $body);
    }

    public function testControllerCannotOverrideTheSourceUrl(): void
    {
        $body = self::view()->page('home', ['title' => null, 'sourceUrl' => 'https://evil.example/'])->body;

        self::assertStringContainsString('href="' . self::SOURCE_URL . '"', $body);
        self::assertStringNotContainsString('evil.example', $body);
    }

    public function testPageIsWellFormedXml(): void
    {
        $body = self::view()->page('helpers', ['title' => 't', 'name' => '<&>', 'value' => "'"])->body;

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($body, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded);
        self::assertSame([], $errors);
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return [
            'parent' => ['../layout'],
            'absolute' => ['/etc/passwd'],
            'extension' => ['home.php'],
            'upper case' => ['Home'],
            'empty' => [''],
            'trailing slash' => ['pages/'],
            'double slash' => ['a//b'],
            'leading digit' => ['1home'],
            'NUL' => ["home\0"],
            'back-slash' => ['a\\b'],
            'dot' => ['.'],
            'trailing newline' => ["home\n"],
            'nested trailing newline' => ["a/b\n"],
        ];
    }

    #[DataProvider('badNames')]
    public function testInvalidTemplateNamesThrow(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::view()->page($name, []);
    }

    #[DataProvider('badNames')]
    public function testInvalidPartialNamesThrow(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::scope()->partial($name);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::view()->page('no-such-page', []);
    }

    public function testFailingTemplateDiscardsPartialOutput(): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            self::view()->page('partial-failure', []);
            self::fail('The template should have thrown.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Template failure', $e->getMessage());
        } finally {
            $leaked = (string) ob_get_clean();
        }

        self::assertSame($level, ob_get_level(), 'Every buffer the view opened is closed.');
        self::assertSame('', $leaked, 'No partial output escapes the view.');
    }
}
