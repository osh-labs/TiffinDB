<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use TiffinDB\Core\App\TableSummary;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Format;
use TiffinDB\Web\Shell;
use TiffinDB\Web\Sidebar;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\View;

/**
 * T-WEB-21 (and T-WEB-22's self-checks), T-WEB-28 (the M2 shell: Display
 * link, project search, layouts, sidebar, undo notice, scripts): both
 * shells rendered through View with the real layout, partials, strings,
 * stylesheet, and configuration.
 *
 * Pages: the real error page, a fixture page that uses every shared
 * partial and form convention (tests/web/fixtures/templates/pages/
 * components.php), and the notices.php and form-actions.php fixtures,
 * copied next to the
 * real templates in a temporary
 * directory. Nothing here goes through the kernel, so these tests do not
 * depend on the request pipeline.
 */
#[CoversClass(View::class)]
#[CoversClass(Shell::class)]
#[CoversClass(Sidebar::class)]
final class ShellTest extends TestCase
{
    /** Theme setting => expected class on <html> (null: no attribute). */
    private const THEMES = [
        'light' => null,
        'dark' => 't-dark',
        'hc-light' => 't-hcl',
        'hc-dark' => 't-hcd',
    ];

    private const TOKEN = 'csrf-token_0123456789abcdef';

    /** Synthetic data only (CLAUDE.md). */
    private const NAME = 'Example Admin';
    private const HOSTILE = '<script>alert("x")</script> & \'q\'';

    private const VERSION = '9.9.9-test';

    private static string $templates = '';

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/tiffindb-shell-test-' . bin2hex(random_bytes(6));
        self::copyTree(RepoFiles::path('templates'), $dir);
        foreach (['components', 'notices', 'form-actions'] as $fixture) {
            if (!copy(__DIR__ . "/fixtures/templates/pages/{$fixture}.php", $dir . "/pages/{$fixture}.php")) {
                throw new RuntimeException("Cannot copy the {$fixture} fixture.");
            }
        }
        self::$templates = $dir;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$templates !== '' && is_dir(self::$templates)) {
            self::removeTree(self::$templates);
        }
        self::$templates = '';
    }

    // Helpers.

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $config = require RepoFiles::path('config/app.php');
        if (!is_array($config)) {
            throw new RuntimeException('config/app.php must return an array.');
        }

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

    /** @param array<string, string|int> $params */
    private static function formatted(string $key, array $params): string
    {
        return (new Strings(RepoFiles::path('strings/en.php')))->get($key, $params);
    }

    private static function view(string $theme = 'light', string $basePath = ''): View
    {
        $strings = new Strings(RepoFiles::path('strings/en.php'));
        $format = new Format(
            $strings,
            new DateTimeZone('UTC'),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T10:00:00Z'),
        );

        return (new View(
            self::$templates,
            $strings,
            new UrlGenerator($basePath, self::VERSION),
            Appearance::fromSettings(['theme' => $theme]),
            self::config(),
        ))->withCsrfToken(self::TOKEN)->withFormat($format);
    }

    /** @param array{key: string, params: array<string, string|int>}|null $flash */
    private static function shell(bool $admin = true, string $nav = 'projects', ?array $flash = null, string $name = self::NAME): Shell
    {
        return new Shell($name, 'EA', $admin, $nav, $flash);
    }

    /** @param array<string, mixed> $data */
    private static function render(string $page, array $data, string $theme = 'light', string $basePath = ''): string
    {
        return self::view($theme, $basePath)->page($page, $data)->body;
    }

    /** @return array<string, mixed> */
    private static function errorData(?Shell $shell): array
    {
        return [
            'status' => 404,
            'title' => self::string('error.404.title'),
            'titleKey' => 'error.404.title',
            'bodyKey' => 'error.404.body',
            'shell' => $shell,
        ];
    }

    /** @return array<string, mixed> */
    private static function componentData(?Shell $shell, array $extra = []): array
    {
        return [
            'title' => 'Components',
            'shell' => $shell,
            'name' => self::NAME,
            'nameError' => null,
            'count' => 2,
            'plural' => false,
            ...$extra,
        ];
    }

    private static function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private static function element(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertSame(1, $nodes->length, "Exactly one match for {$query}.");
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private static function text(DOMElement $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0700, true) && !is_dir($to)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                if (!mkdir($target, 0700) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create a temporary directory.');
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Cannot copy a template.');
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // Data providers.

    /** @return iterable<string, array{string, bool, string}> */
    public static function pagesInEveryTheme(): iterable
    {
        foreach (array_keys(self::THEMES) as $theme) {
            foreach (['error', 'components'] as $page) {
                foreach ([true, false] as $signedIn) {
                    $shell = $signedIn ? 'signed in' : 'plain';
                    yield "{$page}, {$shell}, {$theme}" => [$page, $signedIn, $theme];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function pageData(string $page, bool $signedIn): array
    {
        $shell = $signedIn ? self::shell(flash: ['key' => 'notice.password_changed', 'params' => []]) : null;

        return $page === 'error' ? self::errorData($shell) : self::componentData($shell, ['script' => 'show-password']);
    }

    // Structure, themes, inline code, well-formedness (M0 assertions kept).

    #[DataProvider('pagesInEveryTheme')]
    public function testShellStructure(string $page, bool $signedIn, string $theme): void
    {
        $body = self::render($page, self::pageData($page, $signedIn), $theme);

        self::assertStringStartsWith("<!DOCTYPE html>\n", $body);
        HtmlAssertions::assertShell($body, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'));
        HtmlAssertions::assertNoInlineCode($body, self::sourceUrl());
        HtmlAssertions::assertForm($body);
        self::assertSame(self::THEMES[$theme], HtmlAssertions::rootClass($body));
    }

    public function testAccentIsNotWrittenWithAHighContrastTheme(): void
    {
        $view = new View(
            self::$templates,
            new Strings(RepoFiles::path('strings/en.php')),
            new UrlGenerator('', self::VERSION),
            Appearance::fromSettings(['theme' => 'hc-dark', 'accent' => 'plum', 'text_size' => 'largest']),
            self::config(),
        );

        self::assertSame('t-hcd s-largest', HtmlAssertions::rootClass($view->page('error', self::errorData(null))->body));
    }

    public function testSignedInShellParts(): void
    {
        $xpath = self::xpath(self::render('error', self::errorData(self::shell())));

        $header = self::element($xpath, '/html/body/div[@class="app"]/header');
        self::assertSame('top-bar', $header->getAttribute('class'));
        $children = [];
        foreach ($xpath->query('./*', $header) as $child) {
            /** @var DOMElement $child */
            $children[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['a.brand', 'nav.top-nav', 'nav.account-nav'], $children, 'Top bar order: brand, main navigation, account.');

        $brand = self::element($xpath, '//header/a');
        self::assertSame('/', $brand->getAttribute('href'));
        self::assertSame(self::string('shell.brand.label'), $brand->getAttribute('aria-label'));

        self::assertSame(self::string('shell.nav.main'), self::element($xpath, '//nav[@class="top-nav"]')->getAttribute('aria-label'));
        self::assertSame(self::string('shell.nav.account'), self::element($xpath, '//nav[@class="account-nav"]')->getAttribute('aria-label'));

        $main = self::element($xpath, '//main');
        self::assertSame('page', $main->getAttribute('class'));
        self::assertSame('main', $main->getAttribute('id'));
        self::assertSame('site-footer', self::element($xpath, '//footer')->getAttribute('class'));
        self::assertSame(0, $xpath->query('//*[contains(@class, "lockup")]')->length);
    }

    public function testAdminAndMemberNavigation(): void
    {
        $links = static function (string $html): array {
            $result = [];
            foreach (self::xpath($html)->query('//nav[@class="top-nav"]/a') as $a) {
                /** @var DOMElement $a */
                $result[$a->getAttribute('href')] = [self::text($a), $a->getAttribute('class')];
            }

            return $result;
        };

        self::assertSame(
            [
                '/projects' => [self::string('shell.nav.projects'), 'top-pill'],
                '/admin/people' => [self::string('shell.nav.people'), 'top-pill'],
                '/admin/plugins' => [self::string('shell.nav.plugins'), 'top-pill'],
            ],
            $links(self::render('error', self::errorData(self::shell(true)))),
        );
        self::assertSame(
            ['/projects' => [self::string('shell.nav.projects'), 'top-pill']],
            $links(self::render('error', self::errorData(self::shell(false)))),
        );
    }

    /** @return iterable<string, array{string, bool, list<string>}> */
    public static function currentAreas(): iterable
    {
        yield 'projects' => ['projects', true, ['/projects']];
        yield 'people' => ['people', true, ['/admin/people']];
        yield 'account' => ['account', true, ['/account/password']];
        yield 'none' => ['', true, []];
        yield 'people, as a Member' => ['people', false, []];
        yield 'plugins' => ['plugins', true, ['/admin/plugins']];
        yield 'plugins, as a Member' => ['plugins', false, []];
        yield 'account, as a Member' => ['account', false, ['/account/password']];
    }

    /** @param list<string> $expected */
    #[DataProvider('currentAreas')]
    public function testAriaCurrent(string $nav, bool $admin, array $expected): void
    {
        $xpath = self::xpath(self::render('error', self::errorData(self::shell($admin, $nav))));

        $current = [];
        foreach ($xpath->query('//header//*[@aria-current]') as $element) {
            /** @var DOMElement $element */
            self::assertSame('page', $element->getAttribute('aria-current'));
            $current[] = $element->getAttribute('href');
        }
        self::assertSame($expected, $current);
    }

    public function testUserChipAndSignOut(): void
    {
        $html = self::render('error', self::errorData(self::shell(true, 'projects', null, self::HOSTILE)));
        $xpath = self::xpath($html);

        $account = self::element($xpath, '//nav[@class="account-nav"]');
        $parts = [];
        foreach ($xpath->query('./*', $account) as $child) {
            /** @var DOMElement $child */
            $parts[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['a.top-pill top-pill-display', 'a.user-chip', 'form.sign-out'], $parts);

        $chip = self::element($xpath, '//a[@class="user-chip"]');
        self::assertSame('/account/password', $chip->getAttribute('href'));
        self::assertSame(self::HOSTILE . ', change your password', $chip->getAttribute('aria-label'));
        $initials = self::element($xpath, '//a[@class="user-chip"]/span[@class="user-initials"]');
        self::assertSame('true', $initials->getAttribute('aria-hidden'));
        self::assertSame('EA', self::text($initials));
        self::assertSame(self::HOSTILE, self::text(self::element($xpath, '//a[@class="user-chip"]/span[@class="user-name"]')));

        $form = self::element($xpath, '//form[@class="sign-out"]');
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame('/sign-out', $form->getAttribute('action'));
        $first = self::element($xpath, '//form[@class="sign-out"]/*[1]');
        self::assertSame('_csrf', $first->getAttribute('name'));
        self::assertSame(self::TOKEN, $first->getAttribute('value'));
        $button = self::element($xpath, '//form[@class="sign-out"]/button');
        self::assertSame('top-pill', $button->getAttribute('class'));
        self::assertSame('submit', $button->getAttribute('type'));
        self::assertSame(self::string('shell.sign_out'), self::text($button));

        self::assertStringNotContainsString('<script>alert', $html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
        HtmlAssertions::assertForm($html);
    }

    public function testFlashNoticeComesFirstInMainAndIsEscaped(): void
    {
        $flash = ['key' => 'notice.signed_out', 'params' => []];
        $xpath = self::xpath(self::render('error', self::errorData(self::shell(flash: $flash))));

        $first = self::element($xpath, '//main/*[1]');
        self::assertSame('notice', $first->getAttribute('class'));
        self::assertSame('status', $first->getAttribute('role'));
        self::assertSame(self::string('notice.signed_out'), self::text($first));

        self::assertSame(0, self::xpath(self::render('error', self::errorData(self::shell())))->query('//*[@role="status"]')->length);
    }

    public function testSharedPartsComeInOrderBeforeThePage(): void
    {
        $data = self::componentData(self::shell(flash: ['key' => 'notice.password_changed', 'params' => []]), [
            'breadcrumb' => [['label' => 'Projects', 'path' => '/projects'], ['label' => self::HOSTILE, 'path' => null]],
            'problems' => [['field' => 'component-name', 'text' => self::HOSTILE], ['field' => null, 'text' => 'Second']],
            'nameError' => self::HOSTILE,
        ]);
        $html = self::render('components', $data);
        $xpath = self::xpath($html);

        $order = [];
        foreach ($xpath->query('//main/*[position() <= 4]') as $child) {
            /** @var DOMElement $child */
            $order[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['nav.breadcrumb', 'div.notice', 'div.error-summary', 'div.page-head'], $order);
        self::assertSame(1, $xpath->query('//h1')->length);
        HtmlAssertions::assertForm($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testBreadcrumb(): void
    {
        $data = self::errorData(self::shell());
        $data['breadcrumb'] = [
            ['label' => 'Projects', 'path' => '/projects'],
            ['label' => 'Home Inventory', 'path' => null],
            ['label' => 'Items', 'path' => '/projects/abcdefghjkmn/tables'],
            ['label' => self::HOSTILE, 'path' => '/ignored-for-the-last-item'],
        ];
        $xpath = self::xpath(self::render('error', $data));

        $nav = self::element($xpath, '//nav[@class="breadcrumb"]');
        self::assertSame(self::string('shell.breadcrumb'), $nav->getAttribute('aria-label'));
        $items = [];
        foreach ($xpath->query('./*', $nav) as $child) {
            /** @var DOMElement $child */
            $items[] = [$child->nodeName, self::text($child), $child->getAttribute('href'), $child->getAttribute('aria-current'), $child->getAttribute('aria-hidden')];
        }
        self::assertSame([
            ['a', 'Projects', '/projects', '', ''],
            ['span', '/', '', '', 'true'],
            ['span', 'Home Inventory', '', '', ''],
            ['span', '/', '', '', 'true'],
            ['a', 'Items', '/projects/abcdefghjkmn/tables', '', ''],
            ['span', '/', '', '', 'true'],
            ['span', self::HOSTILE, '', 'page', ''],
        ], $items);
    }

    /** @return iterable<string, array{int, string}> */
    public static function problemCounts(): iterable
    {
        yield 'one' => [1, 'There is a problem'];
        yield 'two' => [2, 'There are 2 problems'];
    }

    #[DataProvider('problemCounts')]
    public function testErrorSummary(int $n, string $heading): void
    {
        $problems = [['field' => 'component-name', 'text' => self::HOSTILE], ['field' => null, 'text' => 'Without a field']];
        $data = self::componentData(self::shell(), ['problems' => array_slice($problems, 0, $n), 'nameError' => self::HOSTILE]);
        $xpath = self::xpath(self::render('components', $data));

        $summary = self::element($xpath, '//div[@class="error-summary"]');
        self::assertSame('alert', $summary->getAttribute('role'));
        self::assertSame('-1', $summary->getAttribute('tabindex'));
        self::assertSame($heading, self::text(self::element($xpath, '//div[@class="error-summary"]/h2[@class="error-summary-title"]')));
        $link = self::element($xpath, '//div[@class="error-summary"]//li[1]/a');
        self::assertSame('#component-name', $link->getAttribute('href'));
        self::assertSame(self::HOSTILE, self::text($link));
        if ($n === 2) {
            $plain = self::element($xpath, '//div[@class="error-summary"]//li[2]');
            self::assertSame(0, $xpath->query('./a', $plain)->length);
            self::assertSame('Without a field', self::text($plain));
        }
        self::assertSame('Error: Components - TiffinDB', self::text(self::element($xpath, '/html/head/title')));
    }

    public function testErrorSummaryRefusesAnUnsafeFieldId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::render('components', self::componentData(self::shell(), ['problems' => [['field' => '" onclick="x', 'text' => 'x']]]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function titles(): iterable
    {
        yield 'start page' => [['title' => null], 'TiffinDB'];
        yield 'page' => [['title' => 'Sign in'], 'Sign in - TiffinDB'];
        yield 'error title flag' => [['title' => 'Sign in', 'errorTitle' => true], 'Error: Sign in - TiffinDB'];
        yield 'problems' => [['title' => 'Sign in', 'problems' => [['field' => null, 'text' => 'x']]], 'Error: Sign in - TiffinDB'];
        yield 'empty problems' => [['title' => 'Sign in', 'problems' => []], 'Sign in - TiffinDB'];
        yield 'escaped' => [['title' => '<b>&'], '<b>& - TiffinDB'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('titles')]
    public function testTitles(array $data, string $expected): void
    {
        $data += ['status' => 404, 'titleKey' => 'error.404.title', 'bodyKey' => 'error.404.body'];
        $html = self::render('error', $data);

        self::assertSame($expected, self::text(self::element(self::xpath($html), '/html/head/title')));
    }

    public function testPlainShell(): void
    {
        $data = self::errorData(null);
        $data['breadcrumb'] = null;
        $xpath = self::xpath(self::render('error', $data));

        $header = self::element($xpath, '/html/body/div[@class="app"]/header');
        self::assertSame('lockup-bar', $header->getAttribute('class'));
        $brand = self::element($xpath, '//header/a');
        self::assertSame('brand brand-lockup', $brand->getAttribute('class'));
        self::assertSame('/', $brand->getAttribute('href'));
        self::assertSame(self::string('shell.brand.label'), $brand->getAttribute('aria-label'));
        self::assertSame('page page-plain', self::element($xpath, '//main')->getAttribute('class'));
        self::assertSame('site-footer site-footer-centered', self::element($xpath, '//footer')->getAttribute('class'));
        self::assertSame(0, $xpath->query('//nav')->length, 'No navigation before sign-in.');
        self::assertSame(0, $xpath->query('//form')->length, 'No sign-out form before sign-in.');

        $wide = self::errorData(null);
        $wide['wide'] = true;
        self::assertSame('page', self::element(self::xpath(self::render('error', $wide)), '//main')->getAttribute('class'));
    }

    public function testThePlainShellNeverPrintsTheCsrfTokenUnasked(): void
    {
        self::assertStringNotContainsString(self::TOKEN, self::render('error', self::errorData(null)));
    }

    public function testScriptOnlyWhenRequested(): void
    {
        foreach ([true, false] as $signedIn) {
            $shell = $signedIn ? self::shell() : null;
            $without = self::render('components', self::componentData($shell));
            self::assertStringNotContainsString('<script', $without);

            $with = self::render('components', self::componentData($shell, ['script' => 'show-password']), 'light', '/sub dir');
            $script = self::element(self::xpath($with), '/html/head/script');
            self::assertSame('/sub%20dir/assets/js/show-password.js?v=' . self::VERSION, $script->getAttribute('src'));
            self::assertSame('defer', $script->getAttribute('defer'));
            HtmlAssertions::assertNoInlineCode($with, self::sourceUrl());

            $other = self::render('components', self::componentData($shell, ['script' => 'other']));
            self::assertStringNotContainsString('<script', $other);

            $preview = self::render('components', self::componentData($shell, ['script' => 'settings-preview']));
            $xpath = self::xpath($preview);
            self::assertSame(1, $xpath->query('//script')->length, 'Exactly one script element.');
            self::assertSame('/assets/js/settings-preview.js?v=' . self::VERSION, self::element($xpath, '/html/head/script')->getAttribute('src'));
            self::assertSame('defer', self::element($xpath, '/html/head/script')->getAttribute('defer'));
            HtmlAssertions::assertNoInlineCode($preview, self::sourceUrl());

            // M3 spec part 2 section 7: the third script.
            $continue = self::render('components', self::componentData($shell, ['script' => 'import-continue']));
            $xpath = self::xpath($continue);
            self::assertSame(1, $xpath->query('//script')->length, 'Exactly one script element.');
            self::assertSame('/assets/js/import-continue.js?v=' . self::VERSION, self::element($xpath, '/html/head/script')->getAttribute('src'));
            self::assertSame('defer', self::element($xpath, '/html/head/script')->getAttribute('defer'));
            HtmlAssertions::assertNoInlineCode($continue, self::sourceUrl());
        }
    }

    /** The notice title's markup for a heading value, from the notices fixture. */
    private static function noticeMain(string $tone, mixed $heading): string
    {
        $html = self::render('notices', ['title' => 'Notices', 'shell' => self::shell(), 'tone' => $tone, 'heading' => $heading]);
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function testNoticeTitleIsAParagraphByDefault(): void
    {
        foreach (['info' => '<div class="notice" role="status">', 'warn' => '<div class="notice notice-warn" role="alert">'] as $tone => $open) {
            $main = self::noticeMain($tone, null);
            $expected = $open . "\n" . '<p class="notice-title">' . self::string('error.busy.title') . "</p>\n"
                . '<p>' . self::string('notice.password_changed') . "</p>\n</div>";
            self::assertStringContainsString($expected, $main, $tone);
            self::assertStringNotContainsString('<h2', $main);
            self::assertStringNotContainsString('<h3', $main);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function noticeHeadings(): array
    {
        return ['h2' => [2, 'h2'], 'h3' => [3, 'h3']];
    }

    #[DataProvider('noticeHeadings')]
    public function testNoticeTitleCanBeAHeading(int $heading, string $element): void
    {
        foreach (['info', 'warn'] as $tone) {
            $default = self::noticeMain($tone, null);
            $main = self::noticeMain($tone, $heading);
            // Only the title element changes.
            $title = self::string('error.busy.title');
            self::assertSame(
                str_replace("<p class=\"notice-title\">{$title}</p>", "<{$element} class=\"notice-title\">{$title}</{$element}>", $default),
                $main,
                $tone,
            );
            $xpath = self::xpath(self::render('notices', ['title' => 'Notices', 'shell' => self::shell(), 'tone' => $tone, 'heading' => $heading]));
            self::assertSame($title, self::text(self::element($xpath, "//main/div/{$element}[@class=\"notice-title\"]")));
            self::assertSame(0, $xpath->query('//main/div/p[@class="notice-title"]')->length);
        }
    }

    /** @return array<string, array{mixed}> */
    public static function otherNoticeHeadings(): array
    {
        return ['1' => [1], '4' => [4], 'string 2' => ['2'], 'true' => [true]];
    }

    #[DataProvider('otherNoticeHeadings')]
    public function testOtherNoticeHeadingValuesKeepTheParagraph(mixed $heading): void
    {
        self::assertSame(self::noticeMain('info', null), self::noticeMain('info', $heading));
    }

    /**
     * The form-actions row from the form-actions fixture, as markup.
     *
     * @param array<string, mixed> $actions
     */
    private static function formActions(array $actions): string
    {
        $html = self::render('form-actions', ['title' => 'Actions', 'shell' => self::shell(), 'actions' => $actions]);
        HtmlAssertions::assertForm($html);
        self::assertSame(1, preg_match('#<div class="form-actions">.*?</div>#s', $html, $m));

        return $m[0];
    }

    public function testFormActionsDefaultOutputIsUnchanged(): void
    {
        $submit = self::string('shell.sign_out');
        self::assertSame(
            "<div class=\"form-actions\">\n<button class=\"button button-large button-primary\" type=\"submit\">{$submit}</button>\n"
            . '<a class="form-cancel" href="/projects">' . self::string('form.cancel') . "</a>\n</div>",
            self::formActions(['submit' => 'shell.sign_out', 'danger' => false, 'cancel' => '/projects']),
        );
        self::assertSame(
            "<div class=\"form-actions\">\n<button class=\"button button-large button-danger\" type=\"submit\">{$submit}</button>\n</div>",
            self::formActions(['submit' => 'shell.sign_out', 'danger' => true, 'cancel' => null]),
        );
        // Leaving out the optional data, or giving the defaults, changes nothing.
        $plain = self::formActions(['submit' => 'shell.sign_out', 'danger' => false, 'cancel' => '/projects']);
        self::assertSame($plain, self::formActions(['submit' => 'shell.sign_out', 'cancel' => '/projects']));
        self::assertSame($plain, self::formActions([
            'submit' => 'shell.sign_out', 'submitParams' => [], 'danger' => false, 'secondary' => false,
            'size' => 'large', 'block' => false, 'cancel' => '/projects', 'cancelLabel' => null,
        ]));
    }

    public function testFormActionsSubmitParams(): void
    {
        $html = self::formActions(['submit' => 'shell.account.label', 'submitParams' => ['name' => self::HOSTILE], 'danger' => true, 'cancel' => null]);
        $button = self::element(self::xpath("<!DOCTYPE html>\n" . $html), '//button');
        self::assertSame('button button-large button-danger', $button->getAttribute('class'));
        self::assertSame(self::formatted('shell.account.label', ['name' => self::HOSTILE]), self::text($button));
        self::assertStringNotContainsString('<script>', $html);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function formActionClasses(): array
    {
        return [
            'primary normal' => [['size' => 'normal'], 'button button-primary'],
            'primary block' => [['block' => true], 'button button-large button-primary button-block'],
            'primary normal block' => [['size' => 'normal', 'block' => true], 'button button-primary button-block'],
            'danger normal' => [['danger' => true, 'size' => 'normal'], 'button button-danger'],
            'danger block' => [['danger' => true, 'block' => true], 'button button-large button-danger button-block'],
            'danger normal block' => [['danger' => true, 'size' => 'normal', 'block' => true], 'button button-danger button-block'],
            'danger wins over secondary' => [['danger' => true, 'secondary' => true], 'button button-large button-danger'],
            'secondary' => [['secondary' => true], 'button button-large'],
            'secondary normal' => [['secondary' => true, 'size' => 'normal'], 'button'],
            'secondary block' => [['secondary' => true, 'block' => true], 'button button-large button-block'],
            'secondary normal block' => [['secondary' => true, 'size' => 'normal', 'block' => true], 'button button-block'],
            'unknown size is large' => [['size' => 'small'], 'button button-large button-primary'],
            'block must be true' => [['block' => 'yes'], 'button button-large button-primary'],
            'secondary must be true' => [['secondary' => 1], 'button button-large button-primary'],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('formActionClasses')]
    public function testFormActionsButtonOptions(array $options, string $class): void
    {
        $html = self::formActions(['submit' => 'shell.sign_out', 'cancel' => null, ...$options]);
        $button = self::element(self::xpath("<!DOCTYPE html>\n" . $html), '//button');
        self::assertSame($class, $button->getAttribute('class'));
        self::assertSame('submit', $button->getAttribute('type'));
        self::assertSame(self::string('shell.sign_out'), self::text($button));
        $css = RepoFiles::read('public/assets/css/app.css');
        foreach (explode(' ', $class) as $name) {
            self::assertMatchesRegularExpression('/^\\.' . preg_quote($name, '/') . '[ ,]/m', $css, "{$name} is styled in app.css.");
        }
    }

    /** A plain fieldset with legend.legend-plain (people-and-sharing.md, schema-editor.md) shows no default border. */
    public function testPlainFieldsetHasNoDefaultBorder(): void
    {
        $css = RepoFiles::read('public/assets/css/app.css');
        self::assertSame(1, preg_match('/^fieldset\.field \{([^}]*)\}/m', $css, $m), 'app.css styles fieldset.field.');
        foreach (['border: 0;', 'margin: 0;', 'padding: 0;', 'min-width: 0;'] as $declaration) {
            self::assertStringContainsString($declaration, $m[1]);
        }
    }

    public function testFormActionsCancelLabel(): void
    {
        $html = self::formActions(['submit' => 'shell.sign_out', 'cancel' => '/projects', 'cancelLabel' => 'shell.account.label', 'cancelParams' => ['name' => self::NAME]]);
        $link = self::element(self::xpath("<!DOCTYPE html>\n" . $html), '//a');
        self::assertSame('/projects', $link->getAttribute('href'));
        self::assertSame('form-cancel', $link->getAttribute('class'));
        self::assertSame(self::formatted('shell.account.label', ['name' => self::NAME]), self::text($link));
        self::assertStringNotContainsString(self::string('form.cancel'), $html);
        // A label without a path shows no link.
        self::assertStringNotContainsString('<a', self::formActions(['submit' => 'shell.sign_out', 'cancel' => null, 'cancelLabel' => 'shell.account.label']));
    }

    public function testComponentPartials(): void
    {
        $xpath = self::xpath(self::render('components', self::componentData(self::shell(), ['name' => self::HOSTILE, 'nameError' => 'Enter a name.'])));

        // page-head
        self::assertSame(self::HOSTILE, self::text(self::element($xpath, '//div[@class="page-head"]/div/h1')));
        self::assertSame(self::HOSTILE, self::text(self::element($xpath, '//div[@class="page-head"]/div/p[@class="lead"]')));
        $primary = self::element($xpath, '//div[@class="page-actions"]/a[1]');
        self::assertSame('button button-primary', $primary->getAttribute('class'));
        self::assertSame('/admin/people/new', $primary->getAttribute('href'));
        self::assertSame('true', self::element($xpath, '//div[@class="page-actions"]/a[1]/*[local-name()="svg"]')->getAttribute('aria-hidden'));
        self::assertSame('button', self::element($xpath, '//div[@class="page-actions"]/a[2]')->getAttribute('class'));

        // notice, info and warn with a title
        $warn = self::element($xpath, '//div[@class="notice notice-warn"]');
        self::assertSame('alert', $warn->getAttribute('role'));
        self::assertSame(self::string('error.busy.title'), self::text(self::element($xpath, '//div[@class="notice notice-warn"]/p[@class="notice-title"]')));
        self::assertSame('Too many tries. Wait ' . self::HOSTILE . ', then try again.', self::text(self::element($xpath, '//div[@class="notice notice-warn"]/p[2]')));
        self::assertSame('status', self::element($xpath, '//main/div[@class="notice"]')->getAttribute('role'));

        // count()
        self::assertSame('There are 2 problems', self::text(self::element($xpath, '//main/p[@class="lead"]')));

        // field-label, field-error, and the input wiring of rule 4.5 item 2
        self::assertSame(self::string('shell.nav.projects'), self::text(self::element($xpath, '//label[@for="component-name"]')));
        $optional = self::element($xpath, '//label[@for="component-password"]/span[@class="label-optional"]');
        self::assertSame(self::string('form.optional'), self::text($optional));
        self::assertSame(self::string('shell.nav.account') . ' ' . self::string('form.optional'), self::text(self::element($xpath, '//label[@for="component-password"]')));
        self::assertSame('Enter a name.', self::text(self::element($xpath, '//p[@id="component-name-error"]')));
        self::assertSame(self::HOSTILE, self::element($xpath, '//input[@id="component-name"]')->getAttribute('value'));

        // password-toggle: hidden until the script runs
        $toggle = self::element($xpath, '//div[@class="check show-password"]');
        self::assertSame('hidden', $toggle->getAttribute('hidden'));
        $box = self::element($xpath, '//div[@class="check show-password"]/label/input');
        self::assertSame('checkbox', $box->getAttribute('type'));
        self::assertSame('data-show-password', $box->getAttribute('data-show-password'));
        self::assertFalse($box->hasAttribute('name'), 'The toggle is not submitted.');
        self::assertSame(self::string('form.show_password'), self::text(self::element($xpath, '//div[@class="check show-password"]/label')));

        // form-actions
        $submit = self::element($xpath, '//form[1]/div[@class="form-actions"]/button');
        self::assertSame('button button-large button-primary', $submit->getAttribute('class'));
        self::assertSame('submit', $submit->getAttribute('type'));
        $cancel = self::element($xpath, '//form[1]/div[@class="form-actions"]/a');
        self::assertSame('/projects', $cancel->getAttribute('href'));
        self::assertSame(self::string('form.cancel'), self::text($cancel));
        $danger = self::element($xpath, '//form[2]/div[@class="form-actions"]/button');
        self::assertSame('button button-large button-danger', $danger->getAttribute('class'));
        self::assertSame(0, $xpath->query('//form[2]/div[@class="form-actions"]/a')->length, 'No Cancel without a path.');

        // csrf partial
        foreach ($xpath->query('//form[@method="post"]/*[1]') as $first) {
            /** @var DOMElement $first */
            self::assertSame(self::TOKEN, $first->getAttribute('value'));
        }
    }

    public function testPluralPasswordToggle(): void
    {
        $xpath = self::xpath(self::render('components', self::componentData(self::shell(), ['plural' => true])));

        self::assertSame(self::string('form.show_passwords'), self::text(self::element($xpath, '//div[@class="check show-password"]/label')));
    }

    public function testBasePathPrefixesEveryLink(): void
    {
        $html = self::render('components', self::componentData(self::shell(), ['script' => 'show-password']), 'light', '/sub');

        HtmlAssertions::assertShell($html, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'), '/sub');
        $xpath = self::xpath($html);
        foreach ($xpath->query('//@href | //@src | //@action') as $attribute) {
            $value = (string) $attribute->nodeValue;
            if ($value === self::sourceUrl() || str_starts_with($value, '#')) {
                continue;
            }
            self::assertStringStartsWith('/sub/', $value, "{$attribute->nodeName}={$value}");
        }
    }

    public function testErrorPageLinksToTheStartPage(): void
    {
        foreach ([null, self::shell()] as $shell) {
            $xpath = self::xpath(self::render('error', self::errorData($shell)));
            $links = $xpath->query('//main//a');

            self::assertSame(1, $links->length);
            $link = $links->item(0);
            self::assertInstanceOf(DOMElement::class, $link);
            self::assertSame('/', $link->getAttribute('href'));
            self::assertSame(self::string('error.back'), self::text($link));
            self::assertSame(self::string('error.404.title'), self::text(self::element($xpath, '//h1')));
        }
    }

    public function testCsrfHelperNeedsAToken(): void
    {
        $view = new View(
            self::$templates,
            new Strings(RepoFiles::path('strings/en.php')),
            new UrlGenerator('', self::VERSION),
            Appearance::default(),
            self::config(),
        );

        $this->expectException(\LogicException::class);
        $view->page('error', self::errorData(self::shell()));
    }

    // The mark and wordmark (M0 spec section 9.2), in both shells.

    /** @return iterable<string, array{bool}> */
    public static function shells(): iterable
    {
        yield 'signed in' => [true];
        yield 'plain' => [false];
    }

    #[DataProvider('shells')]
    public function testMarkGeometryMatchesTheBrandFile(bool $signedIn): void
    {
        $page = self::xpath(self::render('error', self::errorData($signedIn ? self::shell() : null)));
        $svg = self::element($page, '//header//a[contains(@class, "brand")]//*[local-name()="svg"]');

        self::assertSame('brand-mark', $svg->getAttribute('class'));
        self::assertSame('0 0 64 64', $svg->getAttribute('viewBox'));
        self::assertSame('true', $svg->getAttribute('aria-hidden'));
        self::assertSame('false', $svg->getAttribute('focusable'));
        foreach (['width', 'height', 'fill', 'stroke'] as $attribute) {
            self::assertFalse($svg->hasAttribute($attribute), "The svg must not set {$attribute}.");
        }
        self::assertSame(0, $page->query('//header//*[local-name()="title"]')->length);
        foreach ($page->query('//header//*[local-name()="svg"]') as $icon) {
            /** @var DOMElement $icon */
            self::assertSame('true', $icon->getAttribute('aria-hidden'), 'Every top-bar icon is hidden from assistive technology.');
        }

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

    #[DataProvider('shells')]
    public function testWordmarkIsLiveText(bool $signedIn): void
    {
        $body = self::render('error', self::errorData($signedIn ? self::shell() : null));

        self::assertSame(1, substr_count($body, '<span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>'));
    }

    // M2 shell (T-WEB-28; M2 spec part 2 sections 3 and 4.1).

    /** A synthetic restore path, as RecordController builds it. */
    private const UNDO = '/projects/abcdefghijkl/tables/mnopqrstuvwx/records/12/restore';

    /** @return iterable<string, array{string, bool}> */
    public static function displayLinkAreas(): iterable
    {
        foreach (['projects', 'people', 'plugins', 'account', 'settings', ''] as $nav) {
            foreach ([true, false] as $admin) {
                yield ($nav === '' ? 'none' : $nav) . ($admin ? ', Admin' : ', Member') => [$nav, $admin];
            }
        }
    }

    #[DataProvider('displayLinkAreas')]
    public function testDisplayLinkOnEverySignedInShell(string $nav, bool $admin): void
    {
        $xpath = self::xpath(self::render('error', self::errorData(self::shell($admin, $nav))));

        $link = self::element($xpath, '//nav[@class="account-nav"]/*[1]');
        self::assertSame('a', $link->nodeName);
        self::assertSame('top-pill top-pill-display', $link->getAttribute('class'));
        self::assertSame('/settings', $link->getAttribute('href'));
        self::assertSame(self::string('shell.nav.display'), self::text($link));
        $icon = self::element($xpath, '//nav[@class="account-nav"]/a[1]/*[local-name()="svg"]');
        self::assertSame('top-pill-icon', $icon->getAttribute('class'));
        self::assertSame('true', $icon->getAttribute('aria-hidden'));
        self::assertSame('false', $icon->getAttribute('focusable'));
        if ($nav === 'settings') {
            self::assertSame('page', $link->getAttribute('aria-current'));
        } else {
            self::assertFalse($link->hasAttribute('aria-current'));
        }
        self::assertSame(0, self::xpath(self::render('error', self::errorData(null)))->query('//a[@href="/settings"]')->length, 'No Display link before sign-in.');
    }

    public function testSettingsIsCurrentOnlyOnTheDisplayLink(): void
    {
        $xpath = self::xpath(self::render('error', self::errorData(self::shell(true, 'settings'))));
        $current = [];
        foreach ($xpath->query('//header//*[@aria-current]') as $element) {
            /** @var DOMElement $element */
            $current[] = $element->getAttribute('href');
        }
        self::assertSame(['/settings'], $current);
    }

    /** @return array{project: string, projectName: string, q: string|null} */
    private static function search(?string $q = null): array
    {
        return ['project' => 'abcdefghijkl', 'projectName' => self::HOSTILE, 'q' => $q];
    }

    public function testProjectSearchOnlyWithSearchData(): void
    {
        $without = self::xpath(self::render('error', self::errorData(self::shell())));
        self::assertSame(0, $without->query('//form[contains(@class, "top-search")] | //*[@role="search"]')->length);

        $html = self::render('error', [...self::errorData(self::shell()), 'search' => self::search()]);
        $xpath = self::xpath($html);
        $children = [];
        foreach ($xpath->query('//header/*') as $child) {
            /** @var DOMElement $child */
            $children[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['a.brand', 'nav.top-nav', 'form.top-search', 'nav.account-nav'], $children, 'Brand, main navigation, search, account (D-1).');

        $form = self::element($xpath, '//header/form[@class="top-search"]');
        self::assertSame('search', $form->getAttribute('role'));
        self::assertSame('get', $form->getAttribute('method'));
        self::assertSame('/projects/abcdefghijkl/search', $form->getAttribute('action'));
        self::assertSame(0, $xpath->query('.//button', $form)->length, 'Enter submits; no button.');
        $icon = self::element($xpath, '//header/form/*[local-name()="svg"]');
        self::assertSame('top-search-icon', $icon->getAttribute('class'));
        self::assertSame('true', $icon->getAttribute('aria-hidden'));

        $input = self::element($xpath, '//header/form/input');
        self::assertSame(
            [
                'class' => 'top-search-input', 'id' => 'top-search', 'type' => 'search', 'name' => 'q', 'maxlength' => '200',
                'aria-label' => self::formatted('shell.search.label', ['project' => self::HOSTILE]),
                'placeholder' => self::formatted('shell.search.placeholder', ['project' => self::HOSTILE]),
            ],
            self::attributeMap($input),
        );
        self::assertStringNotContainsString('<script>alert', $html);
        HtmlAssertions::assertForm($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());

        $results = self::xpath(self::render('error', [...self::errorData(self::shell()), 'search' => self::search(self::HOSTILE)]));
        self::assertSame(self::HOSTILE, self::element($results, '//header/form/input')->getAttribute('value'), 'The search text is shown back on the results page.');
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function layouts(): iterable
    {
        yield 'absent' => [null, 'page'];
        yield 'record' => ['record', 'page page-record'];
        yield 'record-narrow' => ['record-narrow', 'page page-record page-record-narrow'];
        yield 'settings' => ['settings', 'page page-settings'];
        yield 'unknown' => ['other', 'page'];
    }

    #[DataProvider('layouts')]
    public function testLayoutVariants(?string $layout, string $class): void
    {
        $data = self::errorData(self::shell());
        if ($layout !== null) {
            $data['layout'] = $layout;
        }
        $html = self::render('error', $data);
        $xpath = self::xpath($html);

        self::assertSame($class, self::element($xpath, '/html/body/div[@class="app"]/main')->getAttribute('class'));
        self::assertSame(0, $xpath->query('//nav[contains(@class, "sidebar")] | //*[contains(@class, "app-columns")]')->length);
        HtmlAssertions::assertShell($html, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'));
    }

    /** @return array<string, mixed> */
    private static function sidebar(?string $current = 'mnopqrstuvwx'): array
    {
        $tables = [
            new TableSummary(new TableDef('mnopqrstuvwx', 'Items', 'item', 1, null), 3, 1234),
            new TableSummary(new TableDef('abcdefghijkm', self::HOSTILE, 'room', 2, null), 1, 9),
        ];

        return Sidebar::build('abcdefghijkl', 'Home Inventory', $tables, 3, $current);
    }

    public function testListLayoutHasTheSidebarBeforeMain(): void
    {
        $flash = ['key' => 'notice.password_changed', 'params' => []];
        $data = [...self::errorData(self::shell(flash: $flash)), 'layout' => 'list', 'sidebar' => self::sidebar(), 'search' => self::search()];
        $html = self::render('error', $data);
        $xpath = self::xpath($html);

        $columns = self::element($xpath, '/html/body/div[@class="app"]/div');
        self::assertSame('app-columns', $columns->getAttribute('class'));
        $children = [];
        foreach ($xpath->query('./*', $columns) as $child) {
            /** @var DOMElement $child */
            $children[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['nav.sidebar', 'main.page page-list'], $children, 'Sidebar before main.');
        self::assertSame('main', self::element($xpath, '//main')->getAttribute('id'));
        self::assertSame('notice', self::element($xpath, '//main/*[1]')->getAttribute('class'), 'The flash notice still opens main.');
        self::assertSame(['header', 'div', 'footer'], array_map(static fn (DOMElement $e): string => $e->nodeName, iterator_to_array($xpath->query('/html/body/div[@class="app"]/*'))));
        HtmlAssertions::assertShell($html, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'));
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());

        $withoutSidebar = self::xpath(self::render('error', [...self::errorData(self::shell()), 'layout' => 'list']));
        self::assertSame('page', self::element($withoutSidebar, '//main')->getAttribute('class'), 'The list layout needs sidebar data.');
    }

    public function testSidebarMarkup(): void
    {
        $html = self::render('error', [...self::errorData(self::shell()), 'layout' => 'list', 'sidebar' => self::sidebar()]);
        $xpath = self::xpath($html);

        $nav = self::element($xpath, '//nav[@class="sidebar"]');
        self::assertSame(self::string('sidebar.label'), $nav->getAttribute('aria-label'));
        $parts = [];
        foreach ($xpath->query('./*', $nav) as $child) {
            /** @var DOMElement $child */
            $parts[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['div.sidebar-project', 'div.sidebar-group', 'div.sidebar-foot'], $parts);

        self::assertSame(self::string('sidebar.project'), self::text(self::element($xpath, '//span[@class="sidebar-label"]')));
        self::assertSame('Home Inventory', self::text(self::element($xpath, '//span[@class="sidebar-project-name"]')));
        $switch = self::element($xpath, '//a[@class="sidebar-switch"]');
        self::assertSame('/projects', $switch->getAttribute('href'));
        self::assertSame(self::string('sidebar.switch'), self::text($switch));

        self::assertSame(self::string('sidebar.tables'), self::text(self::element($xpath, '//div[@class="sidebar-group"]/h2[@class="sidebar-heading"]')));
        $links = [];
        foreach ($xpath->query('//div[@class="sidebar-group"]/a') as $a) {
            /** @var DOMElement $a */
            $links[] = [$a->getAttribute('class'), $a->getAttribute('href'), self::text($a), $a->getAttribute('aria-current')];
        }
        self::assertSame(
            [
                ['sidebar-link', '/projects/abcdefghijkl/tables/mnopqrstuvwx', 'Items 1,234', 'page'],
                ['sidebar-link', '/projects/abcdefghijkl/tables/abcdefghijkm', self::HOSTILE . ' 9', ''],
            ],
            $links,
        );
        self::assertSame('1,234', self::text(self::element($xpath, '//a[@aria-current="page"]/span[@class="sidebar-count"]')));

        $schema = self::element($xpath, '//div[@class="sidebar-foot"]/a');
        self::assertSame(['sidebar-link', '/projects/abcdefghijkl/tables', self::string('sidebar.schema')], [$schema->getAttribute('class'), $schema->getAttribute('href'), self::text($schema)]);
        $trash = self::element($xpath, '//div[@class="sidebar-foot"]/div[@class="sidebar-item"]');
        self::assertSame(self::string('sidebar.trash') . ' 3', self::text($trash));
        self::assertSame(0, $xpath->query('//div[@class="sidebar-foot"]/div//a')->length, 'The trash count is not a link in M2.');
        self::assertStringNotContainsString('<script>alert', $html);

        $none = self::xpath(self::render('error', [...self::errorData(self::shell()), 'layout' => 'list', 'sidebar' => self::sidebar(null)]));
        self::assertSame(0, $none->query('//nav[@class="sidebar"]//*[@aria-current]')->length, 'No table is current on the search page.');
    }

    /** M3 spec part 2 section 4: the Data group only with canImportExport, never current. */
    public function testSidebarDataGroupOnlyWithImportAndExport(): void
    {
        $tables = [new TableSummary(new TableDef('mnopqrstuvwx', 'Items', 'item', 1, null), 3, 1234)];
        self::assertNull(Sidebar::build('abcdefghijkl', 'Home Inventory', $tables, 0, null)['data']);
        self::assertNull(Sidebar::build('abcdefghijkl', 'Home Inventory', $tables, 0, null, false)['data']);
        $sidebar = Sidebar::build('abcdefghijkl', 'Home Inventory', $tables, 0, 'mnopqrstuvwx', true);
        self::assertSame(['importPath' => '/projects/abcdefghijkl/import', 'exportPath' => '/projects/abcdefghijkl/export'], $sidebar['data']);

        $xpath = self::xpath(self::render('error', [...self::errorData(self::shell()), 'layout' => 'list', 'sidebar' => $sidebar], 'light', '/base'));
        $parts = [];
        foreach ($xpath->query('//nav[@class="sidebar"]/*') as $child) {
            /** @var DOMElement $child */
            $parts[] = $child->nodeName . '.' . $child->getAttribute('class');
        }
        self::assertSame(['div.sidebar-project', 'div.sidebar-group', 'div.sidebar-group', 'div.sidebar-foot'], $parts, 'The Data group sits after Tables, before the trash line.');
        self::assertSame(self::string('sidebar.data'), self::text(self::element($xpath, '//div[@class="sidebar-group"][2]/h2[@class="sidebar-heading"]')));
        $links = [];
        foreach ($xpath->query('//div[@class="sidebar-group"][2]/a') as $a) {
            /** @var DOMElement $a */
            $links[] = [$a->getAttribute('class'), $a->getAttribute('href'), self::text($a), $a->hasAttribute('aria-current')];
        }
        self::assertSame([
            ['sidebar-link', '/base/projects/abcdefghijkl/import', self::string('sidebar.import'), false],
            ['sidebar-link', '/base/projects/abcdefghijkl/export', self::string('sidebar.export'), false],
        ], $links);

        $without = self::xpath(self::render('error', [...self::errorData(self::shell()), 'layout' => 'list', 'sidebar' => self::sidebar()]));
        self::assertSame(1, $without->query('//nav[@class="sidebar"]/div[@class="sidebar-group"]')->length);
        self::assertSame(0, $without->query('//a[contains(@href, "/import") or contains(@href, "/export")]')->length);
    }

    /** M3 spec part 2 section 4: the io layout. */
    public function testIoLayout(): void
    {
        $xpath = self::xpath(self::render('error', [...self::errorData(self::shell()), 'layout' => 'io']));
        $main = self::element($xpath, '//main');
        self::assertSame('page page-io', $main->getAttribute('class'));
        self::assertSame('main', $main->getAttribute('id'));
        self::assertSame(0, $xpath->query('//nav[@class="sidebar"]')->length);
    }

    public function testShellRefusesAnUnknownArea(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Shell(self::NAME, 'EA', true, 'import', null);
    }

    public function testSidebarBuildRefusesBadKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sidebar::build('../etc', 'x', [], 0, null);
    }

    public function testUndoNoticeForAValidRestorePath(): void
    {
        $flash = ['key' => 'notice.signed_out', 'params' => ['title' => self::HOSTILE, 'undo' => self::UNDO]];
        $html = self::render('error', self::errorData(self::shell(flash: $flash)), 'light', '/sub dir');
        $xpath = self::xpath($html);

        $notice = self::element($xpath, '//main/*[1]');
        self::assertSame('notice notice-row', $notice->getAttribute('class'));
        self::assertSame('status', $notice->getAttribute('role'));
        self::assertSame(self::string('notice.signed_out'), self::text(self::element($xpath, '//main/div[1]/p')));
        $form = self::element($xpath, '//main/div[1]/form');
        self::assertSame('notice-form', $form->getAttribute('class'));
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame('/sub%20dir' . self::UNDO, $form->getAttribute('action'));
        self::assertSame('_csrf', self::element($xpath, '//main/div[1]/form/*[1]')->getAttribute('name'));
        $button = self::element($xpath, '//main/div[1]/form/button');
        self::assertSame(['button', 'submit'], [$button->getAttribute('class'), $button->getAttribute('type')]);
        self::assertSame(self::string('notice.undo'), self::text($button));
        self::assertSame(self::formatted('notice.undo_label', ['title' => self::HOSTILE]), $button->getAttribute('aria-label'));
        self::assertStringNotContainsString('<script>alert', $html);
        HtmlAssertions::assertForm($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
    }

    /** @return iterable<string, array{mixed}> */
    public static function badUndoPaths(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/projects'];
        yield 'dot segments' => ['/projects/abcdefghijkl/tables/mnopqrstuvwx/records/12/../12/restore'];
        yield 'extra segment' => [self::UNDO . '/more'];
        yield 'trailing newline' => [self::UNDO . "\n"];
        yield 'query' => [self::UNDO . '?x=1'];
        yield 'record id zero' => ['/projects/abcdefghijkl/tables/mnopqrstuvwx/records/0/restore'];
        yield 'other action' => ['/projects/abcdefghijkl/tables/mnopqrstuvwx/records/12/trash'];
        yield 'upper-case key' => ['/projects/ABCDEFGHIJKL/tables/mnopqrstuvwx/records/12/restore'];
        yield 'absolute URL' => ['https://evil.example' . self::UNDO];
        yield 'integer' => [12];
    }

    #[DataProvider('badUndoPaths')]
    public function testUndoIsDroppedForAnyOtherValue(mixed $undo): void
    {
        $flash = ['key' => 'notice.signed_out', 'params' => ['title' => 'Stand mixer', 'undo' => $undo]];
        $shell = self::shell(flash: $flash);
        self::assertSame(['title' => 'Stand mixer'], $shell->flash['params'] ?? null, 'Shell removes the undo parameter.');

        $xpath = self::xpath(self::render('error', self::errorData($shell)));
        self::assertSame('notice', self::element($xpath, '//main/*[1]')->getAttribute('class'));
        self::assertSame(0, $xpath->query('//main//form')->length, 'No Undo form.');
    }

    // The stylesheet and the script.

    public function testEveryComponentClassExistsInTheStylesheet(): void
    {
        $doc = RepoFiles::read('docs/design/m1/components.md');
        preg_match_all('/^\| `\.([a-z][a-z0-9-]*)/m', $doc, $m);
        $classes = array_values(array_unique($m[1]));
        self::assertGreaterThan(60, count($classes), 'The component list was read.');

        $css = (string) preg_replace('#/\*.*?\*/#s', '', RepoFiles::read('public/assets/css/app.css'));
        $missing = [];
        foreach ($classes as $class) {
            if (preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])[^{}]*\{/', $css) !== 1) {
                $missing[] = $class;
            }
        }
        self::assertSame([], $missing, 'Classes of components.md missing from app.css.');
    }

    public function testEveryM2ComponentClassExistsInTheStylesheet(): void
    {
        $doc = RepoFiles::read('docs/design/m2/components.md');
        preg_match_all('/^\| `\.([a-z][a-z0-9-]*)`(?:, `\.([a-z][a-z0-9-]*)`)*/m', $doc, $m);
        preg_match_all('/`\.((?:pv|size|radio-grid)-[a-z0-9-]+)`/', $doc, $extra);
        $classes = array_values(array_unique([...$m[1], ...$extra[1], 'text-multiline']));
        self::assertGreaterThan(70, count($classes), 'The M2 component list was read.');

        $css = (string) preg_replace('#/\*.*?\*/#s', '', RepoFiles::read('public/assets/css/app.css'));
        $missing = [];
        foreach ($classes as $class) {
            if (preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])[^{}]*\{/', $css) !== 1) {
                $missing[] = $class;
            }
        }
        self::assertSame([], $missing, 'Classes of docs/design/m2/components.md missing from app.css.');
    }

    /** M3 spec part 2 section 11: every class of docs/design/m3/components.md exists in app.css. */
    public function testEveryM3ComponentClassExistsInTheStylesheet(): void
    {
        $doc = RepoFiles::read('docs/design/m3/components.md');
        preg_match_all('/`\.([a-z][a-z0-9-]*)/', $doc, $m);
        // `.reduce-motion` in section 8 names the reduce setting, whose class is the M1 `.m-reduce`.
        $classes = array_values(array_diff(array_unique($m[1]), ['reduce-motion']));
        self::assertGreaterThan(25, count($classes), 'The M3 component list was read.');

        $css = (string) preg_replace('#/\*.*?\*/#s', '', RepoFiles::read('public/assets/css/app.css'));
        $missing = [];
        foreach ($classes as $class) {
            if (preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])[^{}]*\{/', $css) !== 1) {
                $missing[] = $class;
            }
        }
        self::assertSame([], $missing, 'Classes of docs/design/m3/components.md missing from app.css.');
    }

    /** M3 spec part 2 section 7: import-continue.js. */
    public function testTheContinueScriptFollowsItsRules(): void
    {
        $js = RepoFiles::read('public/assets/js/import-continue.js');

        self::assertStringStartsWith("// SPDX-License-Identifier: AGPL-3.0-or-later\n// Copyright (C) TiffinDB contributors\n", $js);
        self::assertMatchesRegularExpression("/^\\(function \\(\\) \\{\n  'use strict';$/m", $js, 'Strict mode inside an IIFE.');
        self::assertStringEndsWith("}());\n", $js);
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        foreach (
            [
                'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval', 'Function(', 'setInterval',
                'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon', 'import(', 'Storage', 'cookie',
                'indexedDB', '.style', 'setAttribute', 'className', 'location', 'http', 'src', 'href', 'postMessage',
                '.submit(', 'classList', 'createElement', 'appendChild',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $code, "The script must not use {$forbidden}.");
        }
        self::assertStringContainsString("querySelectorAll('form[data-auto-continue=\"1\"]')", $code);
        self::assertStringContainsString('forms.length !== 1', $code, 'Only when exactly one marked form exists.');
        self::assertStringContainsString("document.visibilityState !== 'visible'", $code);
        self::assertStringContainsString("getAttribute('data-working')", $code);
        self::assertStringContainsString('button.disabled = true;', $code);
        self::assertStringContainsString('form.requestSubmit();', $code);
        self::assertStringContainsString('var DELAY = 400;', $code);
        self::assertSame(1, substr_count($code, 'setTimeout('), 'One timer.');
        self::assertSame(1, substr_count($code, 'textContent'), 'Only the status text changes.');
        self::assertSame(1, substr_count($code, "addEventListener('DOMContentLoaded'"));
    }

    public function testPreviewSizesHaveOneRulePerRootSize(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', RepoFiles::read('public/assets/css/app.css'));
        $sizes = ['small' => 16, 'standard' => 18, 'large' => 21, 'larger' => 24, 'largest' => 28];
        $roots = ['' => 18, '.s-small' => 16, '.s-large' => 21, '.s-larger' => 24, '.s-largest' => 28];
        $count = 0;
        foreach ($roots as $root => $rootPx) {
            foreach ($sizes as $name => $px) {
                $expected = rtrim(rtrim(sprintf('%.4f', round($px / $rootPx, 4)), '0'), '.') . 'rem';
                self::assertMatchesRegularExpression(
                    '/:root' . preg_quote($root, '/') . ' \.pv-s-' . $name . ' \{\s*--pv-size: ' . preg_quote($expected, '/') . ';\s*\}/',
                    $css,
                    ":root{$root} .pv-s-{$name}",
                );
                $count++;
            }
        }
        self::assertSame(25, $count);
        self::assertSame(25, preg_match_all('/\.pv-s-[a-z]+ \{/', $css));
    }

    public function testThePreviewScriptFollowsItsRules(): void
    {
        $js = RepoFiles::read('public/assets/js/settings-preview.js');

        self::assertStringStartsWith("// SPDX-License-Identifier: AGPL-3.0-or-later\n// Copyright (C) TiffinDB contributors\n", $js);
        self::assertMatchesRegularExpression("/^\\(function \\(\\) \\{\n  'use strict';$/m", $js, 'Strict mode inside an IIFE.');
        self::assertStringEndsWith("}());\n", $js);
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        foreach (
            [
                'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval', 'Function(', 'setTimeout', 'setInterval',
                'requestAnimationFrame', 'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon', 'import(', 'Storage',
                'cookie', 'indexedDB', '.style', 'setAttribute', 'className', 'location', 'http', 'src', 'href', 'postMessage',
                'submit',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $code, "The script must not use {$forbidden}.");
        }
        self::assertStringContainsString("getElementById('settings-form')", $code);
        self::assertStringContainsString("getElementById('settings-preview')", $code);
        self::assertStringContainsString("getAttribute('data-preview')", $code);
        self::assertStringContainsString("['theme', 'accent', 'typeface', 'text_size', 'line_spacing']", $code);
        self::assertStringContainsString("['hc-light', 'hc-dark']", $code);
        self::assertSame(1, substr_count($code, "addEventListener('change'"));
        self::assertSame(0, substr_count($code, 'addEventListener(') - 1, 'One listener only.');
        // Only the preview's class list is changed.
        self::assertSame(1, preg_match_all('/classList\.add\(/', $code));
        self::assertSame(1, preg_match_all('/classList\.remove\(/', $code));
        self::assertSame(0, preg_match_all('/(?<!preview)\.classList\.(?:add|remove|toggle|replace)\(/', $code));
    }

    public function testTheProjectNameLinkIsAFullTarget(): void
    {
        $css = RepoFiles::read('public/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.project-name a \{[^}]*min-height: var\(--target-min\);[^}]*\}/', $css);
    }

    public function testEveryClassInTheTemplatesHasAStyleOrIsStructural(): void
    {
        // Classes the shared templates write that carry no style of their own.
        $unstyled = ['error-head', 'form-cancel', 'user-name'];
        // A class counts as styled when app.css or tokens.css (theme, accent, and typeface classes) defines it.
        $css = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            RepoFiles::read('public/assets/css/app.css') . "\n" . RepoFiles::read('public/assets/css/tokens.css'),
        );
        $missing = [];
        foreach (RepoFiles::withExtension(['php'], 'templates') as $file) {
            preg_match_all('/class="([^"<]+)"/', RepoFiles::read($file), $m);
            foreach ($m[1] as $list) {
                foreach (explode(' ', $list) as $class) {
                    if (!in_array($class, $unstyled, true) && preg_match('/\.' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $css) !== 1) {
                        $missing[] = "{$file}: .{$class}";
                    }
                }
            }
        }
        self::assertSame([], $missing, 'Template classes without a rule in app.css or tokens.css.');
    }

    public function testTheScriptFollowsItsRules(): void
    {
        $js = RepoFiles::read('public/assets/js/show-password.js');

        self::assertStringStartsWith("// SPDX-License-Identifier: AGPL-3.0-or-later\n// Copyright (C) TiffinDB contributors\n", $js);
        self::assertMatchesRegularExpression("/^\\(function \\(\\) \\{\n  'use strict';$/m", $js, 'Strict mode inside an IIFE.');
        self::assertStringEndsWith("}());\n", $js);
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
        foreach (
            [
                'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval', 'Function(', 'setTimeout', 'setInterval',
                'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon', 'import(', 'Storage', 'cookie', 'indexedDB',
                '.style', 'setAttribute', 'location', 'http', 'src', 'href', 'postMessage',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $code, "The script must not use {$forbidden}.");
        }
        self::assertStringContainsString("'.show-password[hidden]'", $code);
        self::assertStringContainsString("'input[data-show-password]'", $code);
        self::assertStringContainsString("'input[type=\"password\"]'", $code);
        self::assertStringContainsString("removeAttribute('hidden')", $code);
        self::assertStringContainsString("closest('form')", $code);
    }

    public function testTheScriptUnticksOnLoadAndRestoresPasswordsOnSubmit(): void
    {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', RepoFiles::read('public/assets/js/show-password.js'));

        // On load, before the row is shown: the box starts unticked.
        $load = strpos($code, 'box.checked = false;');
        $show = strpos($code, "row.removeAttribute('hidden');");
        self::assertNotFalse($load);
        self::assertNotFalse($show);
        self::assertLessThan($show, $load, 'The box is unticked before the row is shown.');

        // P63: on submit, every switched input is a password input again and the box is unticked.
        self::assertSame(
            1,
            preg_match(
                "/form\\.addEventListener\\('submit', function \\(\\) \\{\\s*switched\\.forEach\\(function \\(input\\) \\{\\s*input\\.type = 'password';\\s*\\}\\);\\s*box\\.checked = false;\\s*\\}\\);/",
                $code,
            ),
            'A submit handler restores every switched input to type password and unticks the box.',
        );
        self::assertSame(1, substr_count($code, "addEventListener('submit'"));
        self::assertSame(1, substr_count($code, "addEventListener('change'"));
    }

    // Self-checks: the shared assertions reject what they must.

    /** @return iterable<string, array{string}> */
    public static function inlineCodeCases(): iterable
    {
        $page = static fn (string $inner, string $head = ''): string => "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title>{$head}</head><body>{$inner}</body></html>";
        $script = static fn (string $attributes): string => $page('', "<script {$attributes}></script>");
        yield 'style attribute' => [$page('<p style="color: red">x</p>')];
        yield 'style element' => [$page('<style>p{}</style>')];
        yield 'script element in body' => [$page('<script src="/assets/js/show-password.js?v=1" defer="defer"></script>')];
        yield 'script without defer' => [$script('src="/assets/js/show-password.js?v=1"')];
        yield 'script with async too' => [$script('src="/assets/js/show-password.js?v=1" defer="defer" async="async"')];
        yield 'script outside assets/js' => [$script('src="/assets/show-password.js?v=1" defer="defer"')];
        yield 'external script' => [$script('src="https://example.org/assets/js/a.js?v=1" defer="defer"')];
        yield 'protocol-relative script' => [$script('src="//example.org/assets/js/a.js?v=1" defer="defer"')];
        yield 'slash-backslash script' => [$script('src="/\\example.org/assets/js/a.js?v=1" defer="defer"')];
        yield 'data script' => [$script('src="data:text/javascript,alert(1)" defer="defer"')];
        yield 'other script file' => [$script('src="/assets/js/other.js?v=1" defer="defer"')];
        yield 'script without version' => [$script('src="/assets/js/show-password.js" defer="defer"')];
        yield 'script with content' => [$page('', '<script src="/assets/js/show-password.js?v=1" defer="defer">alert(1)</script>')];
        yield 'two scripts' => [$page('', '<script src="/assets/js/a.js?v=1" defer="defer"></script><script src="/assets/js/b.js?v=1" defer="defer"></script>')];
        yield 'inline script' => [$page('', '<script>alert(1)</script>')];
        yield 'event handler' => [$page('<p onclick="x()">x</p>')];
        yield 'upper-case event handler' => [$page('<p ONLOAD="x()">x</p>')];
        yield 'javascript url' => [$page('<a href="javascript:x()">x</a>')];
        yield 'other external url' => [$page('<a href="https://example.org/">x</a>')];
        yield 'protocol-relative stylesheet' => [$page('<link rel="stylesheet" href="//example.org/x.css" />')];
        yield 'protocol-relative image with spaces' => [$page('<img src=" //example.org/x.png" />')];
        yield 'backslash-relative link' => [$page('<a href="\\\\example.org/">x</a>')];
        yield 'slash-backslash link' => [$page('<a href="/\\example.org/">x</a>')];
        yield 'character reference javascript url' => [$page('<a href="&#106;avascript:a()">x</a>')];
        yield 'external image in srcset' => [$page('<img src="/a.png" srcset="/a.png 1x, https://example.org/b.png 2x" />')];
        yield 'protocol-relative srcset' => [$page('<img src="/a.png" srcset="//example.org/b.png 2x" />')];
        yield 'external css url in an attribute' => [$page('<svg><rect fill="url(https://example.org/x.svg#g)" /></svg>')];
        yield 'external form action' => [$page('<form method="get" action="https://example.org/x"></form>')];
        yield 'external meta refresh' => [$page('', '<meta http-equiv="refresh" content="0; url=https://example.org/" />')];
        yield 'not well-formed' => [$page('<p>x')];
        yield 'no doctype' => ['<html lang="en"><head><title>x</title></head><body></body></html>'];
    }

    #[DataProvider('inlineCodeCases')]
    public function testInlineCodeAssertionRejects(string $html): void
    {
        $this->expectException(AssertionFailedError::class);
        HtmlAssertions::assertNoInlineCode($html, 'https://example.com/source');
    }

    public function testInlineCodeAssertionAcceptsTheScript(): void
    {
        foreach (['show-password', 'settings-preview', 'import-continue'] as $name) {
            $html = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title><script src=\"/base/assets/js/{$name}.js?v=1.0.0-dev\" defer=\"defer\"></script></head><body></body></html>";
            HtmlAssertions::assertNoInlineCode($html, 'https://example.com/source');
        }
        $this->addToAssertionCount(1);
    }

    /** Body text and non-address attributes may name a scheme; only markup that loads or links is checked. */
    public function testInlineCodeAssertionIgnoresText(): void
    {
        $html = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title></head><body>"
            . '<p class="help">The address must have https:// at the start, as in https://example.org/.</p>'
            . '<p>Never paste javascript: or //example.org addresses.</p>'
            . '<input class="input" type="url" placeholder="https://" aria-label="Address" />'
            . '<a href="https://example.com/source">Source code</a>'
            . '<svg><rect fill="url(#g)" /></svg>'
            . '</body></html>';
        HtmlAssertions::assertNoInlineCode($html, 'https://example.com/source');
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function badForms(): iterable
    {
        $csrf = '<input type="hidden" name="_csrf" value="t" />';
        $field = '<div class="field"><label class="label" for="a">A</label><input class="input" id="a" type="text" name="a" /></div>';
        $page = static fn (string $inner): string => "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title></head><body>{$inner}</body></html>";
        $form = static fn (string $inner, string $attributes = 'method="post" action="/x"'): string => $page("<form {$attributes}>{$inner}</form>");

        yield 'no method' => [$form($csrf . $field, 'action="/x"')];
        yield 'put method' => [$form($csrf . $field, 'method="put" action="/x"')];
        yield 'external action' => [$form($csrf . $field, 'method="post" action="https://example.org/x"')];
        yield 'protocol-relative action' => [$form($csrf . $field, 'method="post" action="//example.org/x"')];
        yield 'slash-backslash action' => [$form($csrf . $field, 'method="post" action="/\\example.org/x"')];
        yield 'novalidate' => [$form($csrf . $field, 'method="post" action="/x" novalidate="novalidate"')];
        yield 'no csrf' => [$form($field)];
        yield 'csrf not first' => [$form($field . $csrf)];
        yield 'empty csrf' => [$form('<input type="hidden" name="_csrf" value="" />' . $field)];
        yield 'two csrf fields' => [$form($csrf . $field . $csrf)];
        yield 'csrf in a get form' => [$form($csrf . $field, 'method="get" action="/x"')];
        yield 'input without id' => [$form($csrf . '<div class="field"><label class="label">A</label><input class="input" type="text" name="a" /></div>')];
        yield 'input without label' => [$form($csrf . '<div class="field"><input class="input" id="a" type="text" name="a" /></div>')];
        yield 'label not .label' => [$form($csrf . '<div class="field"><label for="a">A</label><input class="input" id="a" type="text" name="a" /></div>')];
        yield 'input outside div.field' => [$form($csrf . '<p><label class="label" for="a">A</label><input class="input" id="a" type="text" name="a" /></p>')];
        yield 'select without label' => [$form($csrf . '<div class="field"><select class="input" id="a" name="a"><option>x</option></select></div>')];
        yield 'describedby to nothing' => [$form($csrf . '<div class="field"><label class="label" for="a">A</label><input class="input" id="a" type="text" name="a" aria-describedby="a-help" /></div>')];
        yield 'error without aria-invalid' => [$form($csrf . '<div class="field"><label class="label" for="a">A</label><p class="error-text" id="a-error">E</p><input class="input" id="a" type="text" name="a" aria-describedby="a-error" /></div>')];
        yield 'aria-invalid without error' => [$form($csrf . '<div class="field"><label class="label" for="a">A</label><input class="input" id="a" type="text" name="a" aria-invalid="true" /></div>')];
        yield 'unnamed checkbox' => [$form($csrf . '<input type="checkbox" name="c" value="1" />')];
        yield 'duplicate ids' => [$form($csrf . $field . '<p id="a">x</p>')];
        yield 'input with neither label nor aria-label' => [$form($csrf . '<fieldset><input class="input" type="text" name="c" /></fieldset>')];
        yield 'input with an empty aria-label' => [$form($csrf . '<fieldset><input class="input" type="text" name="c" aria-label=" " /></fieldset>')];
        yield 'aria-label input without a name' => [$form($csrf . '<fieldset><input class="input" type="text" aria-label="Choice 1" /></fieldset>')];
        yield 'aria-label input with an empty name' => [$form($csrf . '<fieldset><input class="input" type="text" name="" aria-label="Choice 1" /></fieldset>')];
        yield 'button without type' => [$form($csrf . $field . '<button>Go</button>')];
        yield 'button without a name' => [$form($csrf . $field . '<button type="submit"></button>')];
    }

    #[DataProvider('badForms')]
    public function testFormAssertionRejects(string $html): void
    {
        $this->expectException(AssertionFailedError::class);
        HtmlAssertions::assertForm($html);
    }

    public function testFormAssertionAcceptsTheConventions(): void
    {
        $html = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title></head><body>"
            . '<form method="post" action="/sub/x"><input type="hidden" name="_csrf" value="t" />'
            . '<div class="field"><label class="label" for="a">A</label><p class="help" id="a-help">H</p><p class="error-text" id="a-error">E</p>'
            . '<input class="input" id="a" type="text" name="a" aria-describedby="a-help a-error" aria-invalid="true" /></div>'
            . '<ul class="check-list"><li><label class="check"><input type="checkbox" name="p[]" value="1" /> P</label></li></ul>'
            . '<button class="button" type="submit" aria-label="Move up">x</button></form>'
            . '<form method="get" action="/y"><fieldset><label class="radio-card"><input type="radio" name="t" value="text" /> T</label></fieldset>'
            . '<button type="submit">Next</button></form>'
            . '</body></html>';

        HtmlAssertions::assertForm($html);
        $this->addToAssertionCount(1);
    }

    /** M1 spec part 2 section 4.5 item 6: repeated inputs (the choice editor's existing choices) may be named by aria-label. */
    public function testFormAssertionAcceptsControlsNamedByAriaLabel(): void
    {
        $html = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>x</title></head><body>"
            . '<form method="post" action="/x"><input type="hidden" name="_csrf" value="t" />'
            . '<fieldset class="field"><legend class="legend-plain">Choices</legend>'
            . '<input class="input" type="text" name="opt_choice_a" value="Tool" aria-label="Choice 1" />'
            . '<input class="input" id="choice-b" type="text" name="opt_choice_b" value="Appliance" aria-label="Choice 2" />'
            . '<select class="input" name="opt_x" aria-label="Kind"><option>x</option></select>'
            . '</fieldset><button class="button" type="submit">Save</button></form>'
            . '</body></html>';

        HtmlAssertions::assertForm($html);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, string> every attribute of an element, by name */
    private static function attributeMap(DOMElement $element): array
    {
        $map = [];
        foreach ($element->attributes as $attribute) {
            /** @var \DOMAttr $attribute */
            $map[$attribute->nodeName] = (string) $attribute->nodeValue;
        }

        return $map;
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
