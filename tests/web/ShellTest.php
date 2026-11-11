<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\View;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-5, T-WEB-6, T-WEB-8, T-WEB-9, T-WEB-10: the real shell templates,
 * strings, and configuration, driven in process through the kernel.
 */
#[CoversNothing]
final class ShellTest extends TestCase
{
    /** Theme parameter value ('' for none) => expected class on <html> (null: no attribute). */
    private const THEMES = [
        'light' => ['', null],
        'dark' => ['dark', 't-dark'],
        'hc-light' => ['hc-light', 't-hcl'],
        'hc-dark' => ['hc-dark', 't-hcd'],
    ];

    /** Page name => [method, path, status]. Every M0 page and error page. */
    private const PAGES = [
        'home' => ['GET', '/', 200],
        'bad request' => ['GET', '/a%00b', 400],
        'not found' => ['GET', '/nope', 404],
        'method not allowed' => ['POST', '/', 405],
        'controller failure' => ['GET', '/shell-test/throws', 500],
        'template failure' => ['GET', '/shell-test/template-failure', 500],
    ];

    /** @return array<string, mixed> */
    private static function config(bool $preview = true): array
    {
        $config = require RepoFiles::path('config/app.php');
        if (!is_array($config)) {
            throw new RuntimeException('config/app.php must return an array.');
        }
        $config['appearance_preview'] = $preview;

        return $config;
    }

    private static function sourceUrl(): string
    {
        $url = self::config()['source_url'];
        self::assertIsString($url);

        return $url;
    }

    private static function string(string $key): string
    {
        $strings = require RepoFiles::path('strings/en.php');
        self::assertIsArray($strings);
        self::assertArrayHasKey($key, $strings);

        return $strings[$key];
    }

    /** @return list<mixed> The real route table plus this test's failing routes. */
    private static function routes(): array
    {
        $routes = require RepoFiles::path('src/Web/routes.php');
        $routes[] = ['GET', '/shell-test/throws', [ShellTestController::class, 'throws']];
        $routes[] = ['GET', '/shell-test/template-failure', [ShellTestController::class, 'templateFailure']];

        return $routes;
    }

    private static function kernel(bool $preview = true): Kernel
    {
        return new Kernel(self::config($preview), RepoFiles::root(), self::routes());
    }

    /** @param array<string, string> $query */
    private static function request(string $method, string $path, array $query = [], string $scriptName = '/index.php'): Request
    {
        $uri = $query === [] ? $path : $path . '?' . http_build_query($query);

        return Fixtures::request($method, $uri, $query, $scriptName);
    }

    /** @param array<string, string> $query */
    private static function handle(string $method, string $path, array $query = [], bool $preview = true): Response
    {
        [$response] = Fixtures::handle(self::kernel($preview), self::request($method, $path, $query));

        return $response;
    }

    /** @return array<string, string> */
    private static function themeQuery(string $theme): array
    {
        return self::THEMES[$theme][0] === '' ? [] : ['theme' => self::THEMES[$theme][0]];
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $name => [$method, $path, $status]) {
            yield $name => [$method, $path, $status];
        }
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function pagesInEveryTheme(): iterable
    {
        foreach (self::PAGES as $name => [$method, $path, $status]) {
            foreach (array_keys(self::THEMES) as $theme) {
                yield "{$name}, {$theme}" => [$method, $path, $status, $theme];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function themes(): iterable
    {
        foreach (array_keys(self::THEMES) as $theme) {
            yield $theme => [$theme];
        }
    }

    // T-WEB-5: status codes and the shell on every page.

    #[DataProvider('pages')]
    public function testStatusAndContentType(string $method, string $path, int $status): void
    {
        $response = self::handle($method, $path);

        self::assertSame($status, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith('<!DOCTYPE html>', $response->body);
        self::assertStringContainsString('<footer class="site-footer">', $response->body);
    }

    public function testHeadGivesTheSameStatusAndHeadersAsGet(): void
    {
        $get = self::handle('GET', '/');
        $head = self::handle('HEAD', '/');

        self::assertSame(200, $head->status);
        self::assertSame($get->headers, $head->headers);
    }

    public function testPostGivesAllowHeader(): void
    {
        $response = self::handle('POST', '/');

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertSame(self::string('error.405.title'), HtmlAssertions::text($response->body, '//h1'));
    }

    public function testControllerFailureRevealsNothing(): void
    {
        [$response, $log] = Fixtures::handle(self::kernel(), self::request('GET', '/shell-test/throws'));

        self::assertSame(500, $response->status);
        self::assertSame(self::string('error.500.title'), HtmlAssertions::text($response->body, '//h1'));
        foreach ([ShellTestController::SECRET, '/secret/', RepoFiles::root(), '.php', 'RuntimeException', 'Stack trace'] as $needle) {
            self::assertStringNotContainsString($needle, $response->body);
        }
        self::assertStringContainsString(ShellTestController::SECRET, $log, 'The failure is logged, not shown.');
    }

    public function testTemplateFailureDiscardsPartialOutput(): void
    {
        $response = self::handle('GET', '/shell-test/template-failure');

        self::assertSame(500, $response->status);
        self::assertSame(self::string('error.500.title'), HtmlAssertions::text($response->body, '//h1'));
        // The home template had printed its title and lead before failing.
        self::assertStringNotContainsString(self::string('home.lead'), $response->body);
        self::assertStringNotContainsString(self::string('home.preview.label'), $response->body);
        self::assertStringNotContainsString(ShellTestController::MISSING_KEY, $response->body);
        self::assertSame(1, substr_count($response->body, '<!DOCTYPE html>'));
    }

    // T-WEB-6: the root class in each theme.

    #[DataProvider('pagesInEveryTheme')]
    public function testRootClassPerTheme(string $method, string $path, int $status, string $theme): void
    {
        $response = self::handle($method, $path, self::themeQuery($theme));

        self::assertSame($status, $response->status);
        self::assertSame(self::THEMES[$theme][1], HtmlAssertions::rootClass($response->body));
    }

    public function testInjectedAppearanceValueIsNeverWritten(): void
    {
        $response = self::handle('GET', '/', ['theme' => '"><script>x</script>', 'accent' => 'plum" onload="x']);

        self::assertNull(HtmlAssertions::rootClass($response->body));
        self::assertStringNotContainsString('<script', $response->body);
        self::assertStringNotContainsString('onload', $response->body);
    }

    public function testPreviewOffIgnoresParametersAndHidesTheLinks(): void
    {
        $response = self::handle('GET', '/', ['theme' => 'dark'], false);

        self::assertNull(HtmlAssertions::rootClass($response->body));
        self::assertStringNotContainsString('<nav', $response->body);
        HtmlAssertions::assertShell(
            $response->body,
            self::sourceUrl(),
            self::string('shell.footer.licence'),
            self::string('shell.footer.source'),
        );
    }

    // T-WEB-8: structure.

    #[DataProvider('pagesInEveryTheme')]
    public function testShellStructure(string $method, string $path, int $status, string $theme): void
    {
        $response = self::handle($method, $path, self::themeQuery($theme));

        HtmlAssertions::assertShell(
            $response->body,
            self::sourceUrl(),
            self::string('shell.footer.licence'),
            self::string('shell.footer.source'),
        );
    }

    public function testSourceUrlIsTheMockUpFooterLink(): void
    {
        $mockUp = RepoFiles::read('docs/design/model-ui/projects.html');
        self::assertSame(1, preg_match('#<footer\b.*?<a href="([^"]+)">Source code</a>.*?</footer>#s', $mockUp, $m));

        self::assertSame($m[1], self::sourceUrl());
    }

    public function testTitles(): void
    {
        self::assertSame(self::string('app.name'), HtmlAssertions::text(self::handle('GET', '/')->body, '/html/head/title'));
        self::assertSame(
            self::string('error.404.title') . ' - ' . self::string('app.name'),
            HtmlAssertions::text(self::handle('GET', '/nope')->body, '/html/head/title'),
        );
    }

    public function testErrorPageLinksToTheStartPage(): void
    {
        $xpath = new DOMXPath(HtmlAssertions::assertWellFormed(self::handle('GET', '/nope')->body));
        $links = $xpath->query('//main//a');

        self::assertSame(1, $links->length);
        $link = $links->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertSame('/', $link->getAttribute('href'));
        self::assertSame(self::string('error.back'), trim($link->textContent));
    }

    #[DataProvider('themes')]
    public function testPreviewLinks(string $theme): void
    {
        $xpath = new DOMXPath(HtmlAssertions::assertWellFormed(self::handle('GET', '/', self::themeQuery($theme))->body));

        $nav = $xpath->query('//main//nav');
        self::assertSame(1, $nav->length);
        $navElement = $nav->item(0);
        self::assertInstanceOf(DOMElement::class, $navElement);
        $label = $xpath->query('//*[@id="' . $navElement->getAttribute('aria-labelledby') . '"]')->item(0);
        self::assertSame(self::string('home.preview.label'), trim((string) $label?->textContent));

        $expected = [
            '/' => ['home.preview.light', 'light'],
            '/?theme=dark' => ['home.preview.dark', 'dark'],
            '/?theme=hc-light' => ['home.preview.hc-light', 'hc-light'],
            '/?theme=hc-dark' => ['home.preview.hc-dark', 'hc-dark'],
        ];
        $actual = [];
        foreach ($xpath->query('.//a', $navElement) as $a) {
            /** @var DOMElement $a */
            $actual[$a->getAttribute('href')] = [trim($a->textContent), $a->getAttribute('aria-current')];
        }
        $want = [];
        foreach ($expected as $href => [$key, $name]) {
            $want[$href] = [self::string($key), $name === $theme ? 'true' : ''];
        }
        self::assertSame($want, $actual);
    }

    public function testBasePathPrefixesEveryLink(): void
    {
        [$response] = Fixtures::handle(self::kernel(), self::request('GET', '/sub/', [], '/sub/public/index.php'));

        self::assertSame(200, $response->status);
        HtmlAssertions::assertShell(
            $response->body,
            self::sourceUrl(),
            self::string('shell.footer.licence'),
            self::string('shell.footer.source'),
            '/sub',
        );
        self::assertStringContainsString('href="/sub/?theme=dark"', $response->body);
    }

    // T-WEB-9 and T-WEB-10: no inline code; well-formed.

    #[DataProvider('pagesInEveryTheme')]
    public function testNoInlineCode(string $method, string $path, int $status, string $theme): void
    {
        HtmlAssertions::assertNoInlineCode(self::handle($method, $path, self::themeQuery($theme))->body, self::sourceUrl());
    }

    #[DataProvider('pagesInEveryTheme')]
    public function testWellFormed(string $method, string $path, int $status, string $theme): void
    {
        $body = self::handle($method, $path, self::themeQuery($theme))->body;

        self::assertStringStartsWith("<!DOCTYPE html>\n", $body);
        HtmlAssertions::assertWellFormed($body);
    }

    // The mark (M0 spec section 9.2).

    public function testMarkGeometryMatchesTheBrandFile(): void
    {
        $page = new DOMXPath(HtmlAssertions::assertWellFormed(self::handle('GET', '/')->body));
        $svgs = $page->query('//header//*[local-name()="svg"]');
        self::assertSame(1, $svgs->length);
        $svg = $svgs->item(0);
        self::assertInstanceOf(DOMElement::class, $svg);

        self::assertSame('brand-mark', $svg->getAttribute('class'));
        self::assertSame('0 0 64 64', $svg->getAttribute('viewBox'));
        self::assertSame('true', $svg->getAttribute('aria-hidden'));
        self::assertSame('false', $svg->getAttribute('focusable'));
        foreach (['width', 'height', 'fill', 'stroke'] as $attribute) {
            self::assertFalse($svg->hasAttribute($attribute), "The svg must not set {$attribute}.");
        }
        self::assertSame(0, $page->query('//header//*[local-name()="title"]')->length);

        $brand = new DOMDocument();
        self::assertTrue($brand->loadXML(RepoFiles::read('docs/brand/tiffindb-mark.svg'), LIBXML_NONET));
        $brandXpath = new DOMXPath($brand);
        $brandXpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        $geometry = ['d', 'x', 'y', 'width', 'height', 'rx', 'stroke-width', 'stroke-linecap'];
        $expected = [];
        foreach ($brandXpath->query('//s:path | //s:rect') as $shape) {
            /** @var DOMElement $shape */
            $expected[] = self::attributes($shape, $geometry);
        }
        $actual = [];
        $classes = [];
        foreach ($page->query('./*', $svg) as $shape) {
            /** @var DOMElement $shape */
            $actual[] = self::attributes($shape, $geometry);
            $classes[] = $shape->getAttribute('class');
            foreach (['fill', 'stroke', 'style'] as $attribute) {
                self::assertFalse($shape->hasAttribute($attribute), "Shapes must not set {$attribute}.");
            }
        }

        self::assertCount(6, $expected);
        self::assertSame($expected, $actual);
        self::assertSame(['mark-frame', 'mark-part', 'mark-part', 'mark-part', 'mark-tier2', 'mark-part'], $classes);
    }

    public function testWordmarkIsLiveText(): void
    {
        $body = self::handle('GET', '/')->body;

        self::assertStringContainsString(
            '<span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>',
            $body,
        );
        self::assertSame(self::string('shell.brand.label'), (new DOMXPath(HtmlAssertions::assertWellFormed($body)))
            ->query('//a[@class="brand"]/@aria-label')->item(0)?->nodeValue);
    }

    // Self-checks: the shared assertions reject what they must.

    /** @return iterable<string, array{string}> */
    public static function inlineCodeCases(): iterable
    {
        $page = static fn (string $inner): string => "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title></head><body>{$inner}</body></html>";
        yield 'style attribute' => [$page('<p style="color: red">x</p>')];
        yield 'style element' => [$page('<style>p{}</style>')];
        yield 'script element' => [$page('<script src="/a.js"></script>')];
        yield 'event handler' => [$page('<p onclick="x()">x</p>')];
        yield 'upper-case event handler' => [$page('<p ONLOAD="x()">x</p>')];
        yield 'javascript url' => [$page('<a href="javascript:x()">x</a>')];
        yield 'other external url' => [$page('<a href="https://example.org/">x</a>')];
        yield 'not well-formed' => [$page('<p>x')];
        yield 'no doctype' => ['<html lang="en"><head><title>x</title></head><body></body></html>'];
    }

    #[DataProvider('inlineCodeCases')]
    public function testInlineCodeAssertionRejects(string $html): void
    {
        $this->expectException(AssertionFailedError::class);
        HtmlAssertions::assertNoInlineCode($html, 'https://example.com/source');
    }

    /**
     * @param list<string> $names
     * @return array<string, string>
     */
    private static function attributes(DOMElement $element, array $names): array
    {
        $values = ['element' => $element->localName];
        foreach ($names as $name) {
            if ($element->hasAttribute($name)) {
                $values[$name] = $element->getAttribute($name);
            }
        }

        return $values;
    }
}

/**
 * Test controller injected through the kernel's route-table parameter
 * (M0 spec section 6.5), rendering the real templates.
 */
final class ShellTestController
{
    public const SECRET = 'Shell test failure in /secret/place/file.php';
    public const MISSING_KEY = 'shell-test.no-such-key';

    public function __construct(private readonly View $view)
    {
    }

    public function throws(Request $request): Response
    {
        throw new RuntimeException(self::SECRET);
    }

    /** The real home template prints its title and lead, then fails on an unknown string key. */
    public function templateFailure(Request $request): Response
    {
        return $this->view->page('home', [
            'title' => null,
            'preview' => [['path' => '/', 'query' => [], 'label' => self::MISSING_KEY, 'current' => false]],
        ]);
    }
}
