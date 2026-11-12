<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Format;
use TiffinDB\Web\Shell;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\View;
use TiffinDB\Web\ViewScope;

/** T-WEB-4 (view): escaping, helpers, template names, failure handling. */
#[CoversClass(View::class)]
#[CoversClass(ViewScope::class)]
#[CoversClass(Shell::class)]
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

    // M1 additions (M1 spec part 2 section 4.3): csrf(), count(), same-page references, Shell.

    public function testCsrfHelperPrintsTheEscapedToken(): void
    {
        $body = self::view()->withCsrfToken('abc_DEF-123')->page('shell-data', ['title' => null, 'n' => 1])->body;

        self::assertStringContainsString('<input type="hidden" name="_csrf" value="abc_DEF-123" />', $body);
    }

    public function testCsrfHelperWithoutATokenThrows(): void
    {
        $this->expectException(LogicException::class);
        self::view()->page('shell-data', ['title' => null, 'n' => 1]);
    }

    /** @return array<string, array{string}> */
    public static function badTokens(): array
    {
        return [
            'empty' => [''],
            'quote' => ['a"b'],
            'angle' => ['<x>'],
            'space' => ['a b'],
            'too long' => [str_repeat('a', 129)],
            'newline' => ["abc\n"],
        ];
    }

    #[DataProvider('badTokens')]
    public function testUnsafeTokensAreRefused(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::view()->withCsrfToken($token);
    }

    public function testWithersReturnNewViews(): void
    {
        $view = self::view();
        $withToken = $view->withCsrfToken('t1');
        $format = new Format(new Strings(__DIR__ . '/fixtures/strings/en.php'), new DateTimeZone('UTC'), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $withFormat = $withToken->withFormat($format);

        self::assertNotSame($view, $withToken);
        self::assertSame('t1', $withFormat->csrfToken(), 'withFormat() keeps the token.');
        self::assertSame($format, $withFormat->format());
        self::assertNotSame($format, $withToken->format());

        $this->expectException(LogicException::class);
        $view->csrfToken();
    }

    public function testCountHelperEscapesItsText(): void
    {
        $body = self::view()->withCsrfToken('t')->page('shell-data', ['title' => null, 'n' => 1234])->body;
        self::assertStringContainsString('<p id="count">1,234 &lt;things&gt;</p>', $body);

        $body = self::view()->withCsrfToken('t')->page('shell-data', ['title' => null, 'n' => 1])->body;
        self::assertStringContainsString('<p id="count">One &lt;thing&gt;</p>', $body);
    }

    public function testTheDefaultFormatCountsButHasNoClock(): void
    {
        $format = self::view()->format();
        self::assertSame('2 <things>', $format->count('count.test', 2));

        $this->expectException(LogicException::class);
        $format->relativeDate('2026-01-01T00:00:00Z');
    }

    public function testUrlAcceptsASamePageReference(): void
    {
        self::assertSame('#field-name', self::scope()->url('#field-name'));
        self::assertSame('#a_B-1', self::scope()->url('#a_B-1'));
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function badReferences(): array
    {
        return [
            'empty' => ['#', []],
            'quote' => ['#a"b', []],
            'space' => ['#a b', []],
            'leading digit' => ['#1a', []],
            'angle' => ['#<x>', []],
            'nested hash' => ['#a#b', []],
            'with a query' => ['#a', ['q' => '1']],
            'newline' => ["#a\n", []],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('badReferences')]
    public function testUrlRefusesOtherReferences(string $reference, array $query): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::scope()->url($reference, $query);
    }

    public function testShellReachesTemplatesAsAnArray(): void
    {
        $shell = new Shell('<b>Example</b>', 'EX', true, 'people', ['key' => 'notice.test', 'params' => ['n' => 1]]);
        $body = self::view()->withCsrfToken('t')->page('shell-data', ['title' => null, 'n' => 2, 'shell' => $shell])->body;

        self::assertStringContainsString('<p id="shell">displayName,initials,isAdmin,nav,flash|&lt;b&gt;Example&lt;/b&gt;</p>', $body);

        $body = self::view()->withCsrfToken('t')->page('shell-data', ['title' => null, 'n' => 2])->body;
        self::assertStringContainsString('<p id="shell">null</p>', $body);
    }

    public function testShellMustBeAShell(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::view()->withCsrfToken('t')->page('shell-data', ['title' => null, 'n' => 2, 'shell' => ['displayName' => 'x']]);
    }

    public function testTitleMustBeTextOrNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::view()->page('home', ['title' => ['x'], 'preview' => null]);
    }

    /** @return array<string, array{string, ?array<string, mixed>}> */
    public static function badShells(): array
    {
        return [
            'unknown area' => ['admin', null],
            'flash without params' => ['', ['key' => 'notice.x']],
            'flash with another key' => ['', ['key' => 'error.404.title', 'params' => []]],
            'flash with markup in the key' => ['', ['key' => 'notice.<x>', 'params' => []]],
            'flash with an array parameter' => ['', ['key' => 'notice.x', 'params' => ['a' => ['b']]]],
            'flash with a list of parameters' => ['', ['key' => 'notice.x', 'params' => ['b']]],
            'flash with an extra key' => ['', ['key' => 'notice.x', 'params' => [], 'tone' => 'warn']],
        ];
    }

    /** @param array<string, mixed>|null $flash */
    #[DataProvider('badShells')]
    public function testShellRefusesBadValues(string $nav, ?array $flash): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Shell('Example', 'EX', false, $nav, $flash);
    }

    public function testShellToArray(): void
    {
        $flash = ['params' => ['name' => 'x', 'n' => 2], 'key' => 'notice.person_saved'];
        $shell = new Shell('Example Member', 'EM', false, 'account', $flash);

        self::assertSame(
            ['displayName' => 'Example Member', 'initials' => 'EM', 'isAdmin' => false, 'nav' => 'account', 'flash' => $flash],
            $shell->toArray(),
        );
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
