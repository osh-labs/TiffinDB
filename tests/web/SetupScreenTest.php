<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Core\Install\Requirement;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Context;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Controller\SetupController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Services;
use TiffinDB\Web\Response;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\View;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP9: the installer screens (docs/design/m1/installer.md; M1 spec
 * part 2 section 3.2) through the kernel with the real routes, templates,
 * and strings, FakeServices, a real Installer over a temporary folder, and
 * a fake web server as the probe. No network and no real server; synthetic
 * data only.
 */
#[CoversClass(SetupController::class)]
final class SetupScreenTest extends TestCase
{
    private const ANON = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const PASSWORD = 'correct horse 1';
    private const SERVER = [
        'HTTP_HOST' => 'example.test',
        'SERVER_ADDR' => '192.0.2.10',
        'SERVER_PORT' => '80',
    ];

    private TempDir $temp;
    private ?string $configured = null;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        mkdir($this->temp->path('www/app/public'), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    // ------------------------------------------------------------ helpers

    private function www(): string
    {
        return (string) realpath($this->temp->path('www'));
    }

    private function app(): string
    {
        return $this->www() . '/app';
    }

    private function publicRoot(): string
    {
        return $this->app() . '/public';
    }

    private function sibling(): string
    {
        return $this->www() . '/tiffindb-data';
    }

    /** A new Installer, as each request gets one. */
    private function installer(?string $appRoot = null): Installer
    {
        return new Installer(
            $appRoot ?? $this->app(),
            $this->configured,
            new FixedClock(),
            new SeededRandom('tiffindb-setup-screen-seed-1'),
            new PasswordHasher(['bcrypt' => 4]),
            '0.1.0',
        );
    }

    private function services(InstallState $state, ?HttpProbe $probe = null, ?string $appRoot = null): FakeServices
    {
        $services = new FakeServices($state);
        if ($state !== InstallState::Installed) {
            $services->installer = $this->installer($appRoot);
        }
        $services->probe = $probe ?? self::spy();

        return $services;
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $server
     */
    private static function send(
        Services $services,
        string $method,
        string $path,
        array $post = [],
        array $server = [],
        string $base = '',
        bool $withCsrf = true,
        array $cookies = [],
        array $config = [],
    ): Response {
        if ($method === 'POST' && $withCsrf) {
            $post = ['_csrf' => self::ANON] + $post;
        }
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $base . $path, 'SCRIPT_NAME' => $base . '/index.php'] + $server,
            [],
            $post,
            $cookies + ['tdb_csrf' => self::ANON],
        );
        $kernel = new Kernel($config + Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $services);

        return Fixtures::handle($kernel, $request)[0];
    }

    /** Server values with the document root set. */
    private static function server(string $documentRoot, array $extra = []): array
    {
        return $extra + self::SERVER + ['DOCUMENT_ROOT' => $documentRoot];
    }

    /** A probe that must never be asked. */
    private static function spy(): HttpProbe
    {
        return new class implements HttpProbe {
            public int $calls = 0;

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                $this->calls++;

                return null;
            }
        };
    }

    /**
     * A fake web server over a document root: files are served as they
     * are, except that a path under `/app/data/` is refused (`deny`),
     * served (`serve`), or not answered (`silent`).
     */
    private static function webServer(string $documentRoot, string $mode): HttpProbe
    {
        return new class ($documentRoot, $mode) implements HttpProbe {
            /** @var list<string> */
            public array $paths = [];
            /** @var list<ProbeTarget> */
            public array $targets = [];

            public function __construct(private readonly string $root, private readonly string $mode)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                $this->paths[] = $path;
                $this->targets[] = $target;
                if (str_starts_with($path, '/app/data/')) {
                    if ($this->mode === 'deny') {
                        return new ProbeResponse(403, 'Forbidden');
                    }
                    if ($this->mode === 'silent') {
                        return null;
                    }
                }
                $file = $this->root . rawurldecode($path);
                if (!str_contains($path, '..') && is_file($file)) {
                    return new ProbeResponse(200, (string) file_get_contents($file));
                }

                return new ProbeResponse(404, 'Not Found');
            }
        };
    }

    /** Prepares the data folder outside the document root (no probe), giving NeedsAdmin. */
    private function prepareSafely(): void
    {
        $result = $this->installer()->prepareDataFolder($this->publicRoot(), null, self::spy());
        self::assertSame('safe', $result->outcome);
    }

    private function setupCode(): string
    {
        $code = trim((string) file_get_contents($this->sibling() . '/setup-code.txt'));
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/', $code);

        return $code;
    }

    private static function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    /** @return list<string> normalised text of every match */
    private static function texts(string $html, string $query): array
    {
        $texts = [];
        foreach (self::xpath($html)->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $texts;
    }

    private static function assertPage(Response $response, int $status, string $base = ''): string
    {
        self::assertSame($status, $response->status, $response->body);
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $html = $response->body;
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code', $base);
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);

        // Plain shell, wide main; the shared top of every step.
        self::assertSame(['page'], self::attributes($html, '//main/@class'));
        self::assertSame(['lockup-bar'], self::attributes($html, '//header/@class'));
        self::assertSame(1, self::xpath($html)->query('//footer[contains(concat(" ", @class, " "), " site-footer-centered ")]')->length);
        self::assertSame(['Set up TiffinDB'], self::texts($html, '//main/div[h1]/h1'));
        self::assertSame(['Three short steps. Nothing is saved until step 2 makes the data folder.'], self::texts($html, '//main/div[h1]/p[@class="lead"]'));
        self::assertSame(['Setup steps'], self::attributes($html, '//main/ol[@class="steps"]/@aria-label'));

        return $html;
    }

    /** @return list<string> */
    private static function attributes(string $html, string $query): array
    {
        $values = [];
        foreach (self::xpath($html)->query($query) as $node) {
            $values[] = (string) $node->nodeValue;
        }

        return $values;
    }

    /** @param list<string> $expected the three step texts */
    private static function assertSteps(string $html, array $expected, int $current): void
    {
        self::assertSame($expected, self::texts($html, '//ol[@class="steps"]/li[@class="step"]'));
        $names = self::texts($html, '//ol[@class="steps"]/li/span[@class="step-name"]');
        self::assertSame(['Check this server', 'Make the data folder safe', 'Create the first Admin'], $names);
        $xpath = self::xpath($html);
        $currentItems = $xpath->query('//ol[@class="steps"]/li[@aria-current]');
        self::assertSame(1, $currentItems->length);
        self::assertSame('step', $currentItems->item(0)?->attributes?->getNamedItem('aria-current')?->nodeValue);
        self::assertSame($current, 1 + $xpath->query('//ol[@class="steps"]/li[@aria-current]/preceding-sibling::li')->length);
    }

    /** The element names of main's children, in order, with their main class. */
    private static function mainOutline(string $html): array
    {
        $outline = [];
        foreach (self::xpath($html)->query('//main/*') as $node) {
            /** @var DOMElement $node */
            $outline[] = $node->nodeName . ($node->getAttribute('class') === '' ? '' : '.' . str_replace(' ', '.', $node->getAttribute('class')));
        }

        return $outline;
    }

    // ------------------------------------------------------------ step 1

    public function testStepOneListsEveryCheckInOrderAndOffersToContinue(): void
    {
        $services = $this->services(InstallState::NeedsDataFolder);
        $html = self::assertPage(self::send($services, 'GET', '/setup', [], self::server($this->publicRoot(), ['HTTPS' => 'on'])), 200);

        self::assertStringContainsString('<title>Set up TiffinDB - TiffinDB</title>', $html);
        self::assertSteps($html, ['Step 1, now Check this server', 'Step 2 Make the data folder safe', 'Step 3 Create the first Admin'], 1);

        // The rows follow the installer's report exactly.
        $report = $this->installer()->requirements(true, $this->publicRoot());
        $ids = array_map(static fn (Requirement $r): string => $r->id, $report->requirements);
        self::assertSame(['php', 'sqlite', 'zip', 'xmlreader', 'dom', 'filter', 'libxml', 'random', 'passwords', 'https', 'data'], $ids);
        $names = ['PHP version', 'SQLite database support', 'Zip archives', 'Reading spreadsheet files', 'XML documents', 'Data filtering', 'XML library', 'Secure random numbers', 'Password protection', 'Secure connection (HTTPS)', 'A place for the data folder'];
        self::assertSame($names, self::texts($html, '//table[@class="data-table"]/tbody/tr/th[@scope="row"]'));
        $strings = new Strings(Fixtures::appRoot() . '/strings/en.php');
        $words = ['ok' => 'Ready', 'warning' => 'Works, with a note', 'failed' => 'Needs fixing'];
        $results = self::texts($html, '//tbody/tr/td[1]/span[contains(@class, "status-word")]');
        $meanings = self::texts($html, '//tbody/tr/td[2]');
        foreach ($report->requirements as $i => $requirement) {
            self::assertSame($words[$requirement->status], $results[$i], $requirement->id);
            self::assertSame($strings->get($requirement->key, $requirement->params), $meanings[$i], $requirement->id);
        }
        self::assertSame('This page was loaded over HTTPS.', $meanings[9]);
        self::assertSame('TiffinDB will keep its data in ../tiffindb-data, outside the web site.', $meanings[10]);
        self::assertSame(['Check', 'Result', 'What this means'], self::texts($html, '//table/thead/tr/th'));
        self::assertSame(['Server checks'], self::texts($html, '//table/caption[@class="visually-hidden"]'));
        self::assertSame(['Step 1: Check this server'], self::texts($html, '//section[@aria-labelledby="setup-check-title"]/h2[@id="setup-check-title"][@class="section-title"]'));

        if (!$report->canContinue()) {
            self::markTestIncomplete('This PHP lacks an extension the requirement check needs; the continue form is covered by the template test.');
        }
        self::assertSame(['div', 'ol.steps', 'section.section', 'form.form'], self::mainOutline($html));
        self::assertSame(['/setup/data-folder'], self::attributes($html, '//form[@method="post"]/@action'));
        self::assertSame(
            ['Next, TiffinDB makes its data folder and checks that it cannot be opened from the web.'],
            self::texts($html, '//form/p'),
        );
        self::assertSame(['Make the data folder'], self::texts($html, '//form/div[@class="form-actions"]/button[@class="button button-large button-primary"][@type="submit"]'));
        self::assertSame([], self::texts($html, '//*[@role="alert"]'));
        self::assertSame(0, self::xpath($html)->query('//script')->length, 'Step 1 loads no script.');
    }

    public function testStepOneWithAFailedCheckShowsTheFixNoticeAndNoForm(): void
    {
        // The application folder's parent is missing, so no data folder can be made.
        $missing = $this->temp->path('missing/app');
        $services = $this->services(InstallState::NeedsDataFolder, null, $missing);
        $html = self::assertPage(self::send($services, 'GET', '/setup', [], self::server($this->publicRoot())), 200);

        $rows = self::texts($html, '//tbody/tr');
        self::assertSame('A place for the data folder Needs fixing TiffinDB cannot make its data folder at ../tiffindb-data. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php.', $rows[10]);
        self::assertSame(
            'Secure connection (HTTPS) Works, with a note This page was not loaded over HTTPS, so passwords could be read by others on the network. Ask your host to turn on HTTPS, then open this page with https:// at the start.',
            $rows[9],
        );
        self::assertSame(['Needs fixing'], self::texts($html, '//span[@class="status-word status-failed"]'));
        self::assertSame(['div', 'ol.steps', 'section.section', 'div.notice.notice-warn', 'div.form-actions'], self::mainOutline($html));
        self::assertSame(0, self::xpath($html)->query('//form')->length);
        self::assertSame(
            ['Fix the checks marked Needs fixing, then check again. Your host\'s control panel or support can help with the PHP settings.'],
            self::texts($html, '//div[@class="notice notice-warn"][@role="alert"]/p'),
        );
        self::assertSame(['Check again'], self::texts($html, '//div[@class="form-actions"]/a[@class="button button-primary"][@href="/setup"]'));
    }

    public function testStepOneWithAConfiguredFolderUsesItsOwnWording(): void
    {
        $this->configured = $this->www() . '/configured-data';
        $html = self::send($this->services(InstallState::NeedsDataFolder), 'GET', '/setup', [], self::server($this->publicRoot()))->body;
        self::assertSame(
            'TiffinDB will keep its data in the folder set as data_dir in config/local.php, outside the web site.',
            self::texts($html, '//tbody/tr/td[2]')[10],
        );

        $this->configured = $this->temp->path('missing/deeper/data');
        $html = self::send($this->services(InstallState::NeedsDataFolder), 'GET', '/setup', [], self::server($this->publicRoot()))->body;
        self::assertStringStartsWith('TiffinDB cannot make its data folder at the path set as data_dir in config/local.php.', self::texts($html, '//tbody/tr/td[2]')[10]);
    }

    /** @return iterable<string, array{string, string, array<string, string>, string, string}> */
    public static function checkTexts(): iterable
    {
        $rows = [
            ['php', 'ok', 'setup.check.php.ok', ['version' => '8.3.6'], 'PHP version', 'PHP 8.3.6. TiffinDB needs 8.3 or later.'],
            ['php', 'failed', 'setup.check.php.failed', ['version' => '8.2.0'], 'PHP version', 'PHP 8.2.0 is too old. Ask your host to switch this site to PHP 8.3 or later.'],
            ['sqlite', 'ok', 'setup.check.sqlite.ok', ['version' => '3.45.1'], 'SQLite database support', 'SQLite 3.45.1 is available.'],
            ['sqlite', 'failed', 'setup.check.sqlite.missing', [], 'SQLite database support', 'PHP\'s SQLite support (pdo_sqlite) is not turned on. Ask your host to turn it on.'],
            ['sqlite', 'failed', 'setup.check.sqlite.old', ['version' => '3.31.0'], 'SQLite database support', 'SQLite 3.31.0 is too old. TiffinDB needs 3.35.5 or later.'],
            ['zip', 'ok', 'setup.check.zip.ok', [], 'Zip archives', 'Available. Needed to save and bring in whole projects.'],
            ['zip', 'failed', 'setup.check.zip.failed', [], 'Zip archives', 'PHP\'s zip support is not turned on. Ask your host to turn it on.'],
            ['xmlreader', 'ok', 'setup.check.xmlreader.ok', [], 'Reading spreadsheet files', 'Available. Needed to bring in XLSX spreadsheets.'],
            ['xmlreader', 'failed', 'setup.check.xmlreader.failed', [], 'Reading spreadsheet files', 'PHP\'s XMLReader support is not turned on. Ask your host to turn it on.'],
            ['dom', 'ok', 'setup.check.dom.ok', [], 'XML documents', 'Available. Needed to bring in and save XLSX spreadsheets.'],
            ['dom', 'warning', 'setup.check.dom.warning', [], 'XML documents', 'PHP\'s DOM support is not turned on. TiffinDB works without it, but cannot bring in or save XLSX spreadsheets. Ask your host to turn it on.'],
            ['filter', 'ok', 'setup.check.filter.ok', [], 'Data filtering', 'Available. Needed to bring in and save XLSX spreadsheets.'],
            ['filter', 'warning', 'setup.check.filter.warning', [], 'Data filtering', 'PHP\'s filter support is not turned on. TiffinDB works without it, but cannot bring in or save XLSX spreadsheets. Ask your host to turn it on.'],
            ['libxml', 'ok', 'setup.check.libxml.ok', [], 'XML library', 'Available. Needed to bring in and save XLSX spreadsheets.'],
            ['libxml', 'warning', 'setup.check.libxml.warning', [], 'XML library', 'PHP\'s libxml support is not turned on. TiffinDB works without it, but cannot bring in or save XLSX spreadsheets. Ask your host to turn it on.'],
            ['random', 'ok', 'setup.check.random.ok', [], 'Secure random numbers', 'Available.'],
            ['random', 'failed', 'setup.check.random.failed', [], 'Secure random numbers', 'PHP cannot make secure random numbers on this server. TiffinDB cannot run safely without them.'],
            ['passwords', 'ok', 'setup.check.passwords.argon2id', [], 'Password protection', 'Passwords will be stored with Argon2id.'],
            ['passwords', 'warning', 'setup.check.passwords.bcrypt', [], 'Password protection', 'Passwords will be stored with bcrypt. This is safe. Argon2id is better and is used where PHP offers it.'],
            ['https', 'ok', 'setup.check.https.ok', [], 'Secure connection (HTTPS)', 'This page was loaded over HTTPS.'],
            ['data', 'ok', 'setup.check.data.outside', ['label' => '../tiffindb-data'], 'A place for the data folder', 'TiffinDB will keep its data in ../tiffindb-data, outside the web site.'],
            ['data', 'ok', 'setup.check.data.in-tree', ['label' => 'data'], 'A place for the data folder', 'TiffinDB will keep its data in the folder data inside the TiffinDB folder, protected by a server rule. Step 2 tests that the rule works.'],
            ['data', 'failed', 'setup.check.data.failed', ['label' => '../tiffindb-data'], 'A place for the data folder', 'TiffinDB cannot make its data folder at ../tiffindb-data. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php.'],
            ['data', 'ok', 'setup.check.data.configured-outside', [], 'A place for the data folder', 'TiffinDB will keep its data in the folder set as data_dir in config/local.php, outside the web site.'],
            ['data', 'ok', 'setup.check.data.configured-in-tree', [], 'A place for the data folder', 'TiffinDB will keep its data in the folder set as data_dir in config/local.php, which is inside the web site and protected by a server rule. Step 2 tests that the rule works.'],
            ['data', 'failed', 'setup.check.data.configured-failed', [], 'A place for the data folder', 'TiffinDB cannot make its data folder at the path set as data_dir in config/local.php. Check the path, and let the web server write to that folder or to the folder that holds it.'],
        ];
        foreach ($rows as [$id, $status, $key, $params, $name, $text]) {
            yield $key => [$id, $status, $key, $params, $name, $text];
        }
    }

    /**
     * Every result word and explanation the installer can report, rendered
     * by the step 1 template with that row (the real environment shows only
     * some of them).
     *
     * @param array<string, string> $params
     */
    #[DataProvider('checkTexts')]
    public function testEveryCheckResultIsWorded(string $id, string $status, string $key, array $params, string $name, string $text): void
    {
        $words = ['ok' => 'Ready', 'warning' => 'Works, with a note', 'failed' => 'Needs fixing'];
        $html = self::renderCheck([['nameKey' => 'setup.check.' . $id, 'status' => $status, 'key' => $key, 'params' => $params]], $status !== 'failed');

        self::assertSame([$name . ' ' . $words[$status] . ' ' . $text], self::texts($html, '//tbody/tr'));
        $class = $status === 'failed' ? 'status-word status-failed' : 'status-word';
        self::assertSame([$words[$status]], self::texts($html, '//tbody/tr/td[1]/span[@class="' . $class . '"]'));
    }

    public function testTheCheckTemplateShowsTheContinueFormOnlyWhenNothingFailed(): void
    {
        $row = [['nameKey' => 'setup.check.zip', 'status' => 'ok', 'key' => 'setup.check.zip.ok', 'params' => []]];
        $passing = self::renderCheck($row, true);
        HtmlAssertions::assertForm($passing);
        self::assertSame(['form.form'], array_slice(self::mainOutline($passing), -1));
        self::assertSame([self::ANON], self::attributes($passing, '//form/*[1][@type="hidden"][@name="_csrf"]/@value'));
        self::assertSame(['Make the data folder'], self::texts($passing, '//form//button'));

        $failing = self::renderCheck($row, false);
        self::assertSame(0, self::xpath($failing)->query('//form')->length);
        self::assertSame(['Check again'], self::texts($failing, '//a[@href="/setup"]'));
    }

    /** @param list<array<string, mixed>> $checks */
    private static function renderCheck(array $checks, bool $canContinue): string
    {
        $strings = new Strings(Fixtures::appRoot() . '/strings/en.php');
        $view = (new View(Fixtures::appRoot() . '/templates', $strings, new UrlGenerator('', Fixtures::VERSION), Appearance::default(), Fixtures::config(false)))
            ->withCsrfToken(self::ANON);
        $steps = [['number' => 1, 'state' => 'now'], ['number' => 2, 'state' => 'later'], ['number' => 3, 'state' => 'later']];

        return $view->page('setup/check', ['title' => 'Set up TiffinDB', 'wide' => true, 'steps' => $steps, 'checks' => $checks, 'canContinue' => $canContinue])->body;
    }

    // ------------------------------------------------------------ POST /setup/data-folder

    public function testASafeDataFolderRedirectsToSetupWithoutAProbe(): void
    {
        $probe = self::spy();
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $response = self::send($services, 'POST', '/setup/data-folder', [], self::server($this->publicRoot()));

        self::assertSame(303, $response->status);
        self::assertSame('/setup', $response->header('Location'));
        self::assertSame(0, $probe->calls);
        self::assertFileExists($this->sibling() . '/system.sqlite');
        self::assertFileExists($this->sibling() . '/setup-code.txt');
    }

    public function testAnInTreeFolderThatTheServerRefusesIsSafe(): void
    {
        $probe = self::webServer($this->www(), 'deny');
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $response = self::send($services, 'POST', '/setup/data-folder', [], self::server($this->www()), '/app/public');

        self::assertSame(303, $response->status);
        self::assertSame('/app/public/setup', $response->header('Location'));
        self::assertFileExists($this->app() . '/data/system.sqlite');
        // The positive control first, then the data folder, on the request's own server.
        self::assertStringStartsWith('/app/public/probe-', $probe->paths[0]);
        self::assertStringStartsWith('/app/data/probe-', $probe->paths[1]);
        $target = $probe->targets[0];
        self::assertSame(['example.test', '192.0.2.10', 80, false, $this->www(), '/app/public'], [$target->host, $target->serverAddress, $target->serverPort, $target->secure, $target->documentRoot, $target->basePath]);
    }

    public function testAnExposedFolderStopsSetupWith409(): void
    {
        $services = $this->services(InstallState::NeedsDataFolder, self::webServer($this->www(), 'serve'));
        $response = self::send($services, 'POST', '/setup/data-folder', [], self::server($this->www()), '/app/public');
        $html = self::assertPage($response, 409, '/app/public');

        self::assertSteps($html, ['Step 1, done Check this server', 'Step 2, now Make the data folder safe', 'Step 3 Create the first Admin'], 2);
        self::assertSame(['div', 'ol.steps', 'div.notice.notice-warn', 'section.section', 'form.form'], self::mainOutline($html));
        self::assertSame(['Your data folder can be opened from the web'], self::texts($html, '//div[@class="notice notice-warn"][@role="alert"]/h2[@class="notice-title"]'));
        self::assertSame(['Anyone could download TiffinDB\'s data from data. Setup stops here until this is fixed.'], self::texts($html, '//div[@role="alert"]/p'));
        $this->assertFixSection($html);
        self::assertSame(['/app/public/setup/data-folder'], self::attributes($html, '//form[@method="post"]/@action'));
        self::assertSame(['Test again'], self::texts($html, '//form/div[@class="form-actions"]/button[@type="submit"]'));
        self::assertFileDoesNotExist($this->app() . '/data/system.sqlite');
        self::assertFileDoesNotExist($this->app() . '/data/setup-code.txt');
    }

    public function testAnUnclearAnswerStopsSetupWith409(): void
    {
        $services = $this->services(InstallState::NeedsDataFolder, self::webServer($this->www(), 'silent'));
        $html = self::assertPage(self::send($services, 'POST', '/setup/data-folder', [], self::server($this->www()), '/app/public'), 409, '/app/public');

        $this->assertInconclusive($html);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function untrustedServers(): iterable
    {
        yield 'no Host header' => [['HTTP_HOST' => '']];
        yield 'no server address' => [['SERVER_ADDR' => 'localhost']];
        yield 'no server port' => [['SERVER_PORT' => '']];
        yield 'a port the Host header does not name' => [['SERVER_PORT' => '22']];
        yield 'a Host port that differs from SERVER_PORT' => [['HTTP_HOST' => 'example.test:8080', 'SERVER_PORT' => '8081']];
        yield 'the TLS port on a plain request' => [['SERVER_PORT' => '443']];
    }

    /**
     * Without trustworthy server values the probe is not run at all, and
     * the verdict is inconclusive.
     *
     * @param array<string, string> $override
     */
    #[DataProvider('untrustedServers')]
    public function testUntrustedServerValuesGiveInconclusiveWithoutAProbe(array $override): void
    {
        $probe = self::spy();
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $server = $override + self::server($this->www());
        $html = self::assertPage(self::send($services, 'POST', '/setup/data-folder', [], $server, '/app/public'), 409, '/app/public');

        $this->assertInconclusive($html);
        self::assertSame(0, $probe->calls);
    }

    public function testANonDefaultPortNamedByTheHostIsProbed(): void
    {
        $probe = self::webServer($this->www(), 'deny');
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $server = ['HTTP_HOST' => 'example.test:8080', 'SERVER_PORT' => '8080'] + self::server($this->www());
        $response = self::send($services, 'POST', '/setup/data-folder', [], $server, '/app/public');

        self::assertSame(303, $response->status);
        self::assertSame(8080, $probe->targets[0]->serverPort);
        self::assertSame('example.test:8080', $probe->targets[0]->host);
    }

    /**
     * T-CF7-2: with `probe_port` set, the probe connects to that port and
     * neither SERVER_PORT nor the Host header's port is consulted, so the
     * server values the port rule distrusts no longer stop the probe.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function serversWithAConfiguredPort(): iterable
    {
        yield 'default values' => [[]];
        yield 'no server port' => [['SERVER_PORT' => '']];
        yield 'a port the Host header does not name' => [['SERVER_PORT' => '22']];
        yield 'a Host port that differs from SERVER_PORT' => [['HTTP_HOST' => 'example.test:8080', 'SERVER_PORT' => '8081']];
        yield 'a Host port equal to SERVER_PORT' => [['HTTP_HOST' => 'example.test:9000', 'SERVER_PORT' => '9000']];
        yield 'the TLS port on a plain request' => [['SERVER_PORT' => '443']];
    }

    /** @param array<string, string> $override */
    #[DataProvider('serversWithAConfiguredPort')]
    public function testAConfiguredProbePortIsUsedWhateverTheRequestSays(array $override): void
    {
        $probe = self::webServer($this->www(), 'deny');
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $server = $override + self::server($this->www());
        $response = self::send($services, 'POST', '/setup/data-folder', [], $server, '/app/public', config: ['probe_port' => 8443]);

        self::assertSame(303, $response->status);
        self::assertNotSame([], $probe->targets);
        foreach ($probe->targets as $target) {
            self::assertSame(8443, $target->serverPort);
            self::assertSame($server['HTTP_HOST'], $target->host, 'The Host header is sent as received.');
        }
    }

    /** T-CF7-2: a configured port does not make a missing Host or server address trustworthy. */
    public function testAConfiguredProbePortStillNeedsTheHostAndAddress(): void
    {
        foreach ([['HTTP_HOST' => ''], ['SERVER_ADDR' => 'localhost']] as $override) {
            $probe = self::spy();
            $services = $this->services(InstallState::NeedsDataFolder, $probe);
            $server = $override + self::server($this->www());
            $html = self::assertPage(self::send($services, 'POST', '/setup/data-folder', [], $server, '/app/public', config: ['probe_port' => 8443]), 409, '/app/public');

            $this->assertInconclusive($html);
            self::assertSame(0, $probe->calls);
        }
    }

    /** T-CF7-2: a probe_port the kernel would refuse never reaches the probe. */
    public function testAnInvalidProbePortIsRefusedBeforeAnyProbe(): void
    {
        foreach ([0, 65536, '8443', -1] as $bad) {
            $probe = self::spy();
            $services = $this->services(InstallState::NeedsDataFolder, $probe);
            $response = self::send($services, 'POST', '/setup/data-folder', [], self::server($this->www()), '/app/public', config: ['probe_port' => $bad]);

            self::assertSame(500, $response->status);
            self::assertSame(0, $probe->calls);
        }
    }

    public function testASecureRequestIsProbedOverTlsOnItsPort(): void
    {
        $probe = self::webServer($this->www(), 'deny');
        $services = $this->services(InstallState::NeedsDataFolder, $probe);
        $server = ['HTTPS' => 'on', 'SERVER_PORT' => '443'] + self::server($this->www());
        self::assertSame(303, self::send($services, 'POST', '/setup/data-folder', [], $server, '/app/public')->status);
        self::assertTrue($probe->targets[0]->secure);
        self::assertSame(443, $probe->targets[0]->serverPort);
    }

    public function testAFolderWithForeignFilesCannotBeUsed(): void
    {
        mkdir($this->sibling());
        file_put_contents($this->sibling() . '/notes.txt', 'synthetic');
        $services = $this->services(InstallState::NeedsDataFolder);
        $html = self::assertPage(self::send($services, 'POST', '/setup/data-folder', [], self::server($this->publicRoot())), 409);

        self::assertSteps($html, ['Step 1, done Check this server', 'Step 2, now Make the data folder safe', 'Step 3 Create the first Admin'], 2);
        self::assertSame(['div', 'ol.steps', 'div.notice.notice-warn', 'div.form-actions'], self::mainOutline($html));
        self::assertSame(['TiffinDB could not make its data folder'], self::texts($html, '//div[@role="alert"]/h2[@class="notice-title"]'));
        self::assertSame(
            ['TiffinDB found files that it did not put there in ../tiffindb-data. Empty that folder, or set data_dir in config/local.php to another folder.'],
            self::texts($html, '//div[@role="alert"]/p'),
        );
        self::assertSame(0, self::xpath($html)->query('//form')->length);
        self::assertSame(0, self::xpath($html)->query('//section')->length);
        self::assertSame(['Check again'], self::texts($html, '//div[@class="form-actions"]/a[@href="/setup"]'));
    }

    public function testAConfiguredFolderWithForeignFilesReadsNaturally(): void
    {
        $this->configured = $this->www() . '/configured-data';
        mkdir($this->configured);
        file_put_contents($this->configured . '/notes.txt', 'synthetic');
        $html = self::assertPage(self::send($this->services(InstallState::NeedsDataFolder), 'POST', '/setup/data-folder', [], self::server($this->publicRoot())), 409);

        self::assertSame(
            ['TiffinDB found files that it did not put there in the folder set in config/local.php. Empty that folder, or set data_dir in config/local.php to another folder.'],
            self::texts($html, '//div[@role="alert"]/p'),
        );
    }

    public function testNoWritableFolderCannotBeUsed(): void
    {
        $services = $this->services(InstallState::NeedsDataFolder, null, $this->temp->path('missing/app'));
        $html = self::assertPage(self::send($services, 'POST', '/setup/data-folder', [], self::server($this->publicRoot())), 409);

        self::assertSame(['TiffinDB could not make its data folder'], self::texts($html, '//div[@role="alert"]/h2'));
        self::assertSame(
            ['There is no folder TiffinDB can write to. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php.'],
            self::texts($html, '//div[@role="alert"]/p'),
        );
        self::assertSame(['Check again'], self::texts($html, '//a[@href="/setup"]'));
    }

    public function testALateDataFolderPostRedirectsWithoutTheInstaller(): void
    {
        foreach ([InstallState::NeedsAdmin] as $state) {
            $services = new FakeServices($state);
            $response = self::send($services, 'POST', '/setup/data-folder', [], self::server($this->publicRoot()));
            self::assertSame(303, $response->status);
            self::assertSame('/setup', $response->header('Location'));
        }
    }

    private function assertInconclusive(string $html): void
    {
        self::assertSame(['div', 'ol.steps', 'div.notice.notice-warn', 'section.section', 'form.form'], self::mainOutline($html));
        self::assertSame(['TiffinDB could not test the data folder'], self::texts($html, '//div[@class="notice notice-warn"][@role="alert"]/h2[@class="notice-title"]'));
        self::assertSame(
            ['TiffinDB asked this web server for a test file in data, to make sure the folder cannot be opened from the web, but did not get a clear answer. To be safe, setup stops here.'],
            self::texts($html, '//div[@role="alert"]/p'),
        );
        $this->assertFixSection($html);
        self::assertSame(['Test again'], self::texts($html, '//form//button'));
        self::assertFileDoesNotExist($this->app() . '/data/system.sqlite');
    }

    private function assertFixSection(string $html): void
    {
        self::assertSame(['How to fix it'], self::texts($html, '//section[@aria-labelledby="setup-fix-title"]/h2[@id="setup-fix-title"][@class="section-title"]'));
        self::assertSame(
            [
                'Best: make the public folder inside the TiffinDB folder the root of your web site, so that the data folder is outside it. Hosting control panels call this the document root or web directory.',
                'Or: make sure the web server reads .htaccess files in the TiffinDB folder. Ask your host if you are not sure.',
                'Or: put the data folder outside the web site, and write its full path as data_dir in config/local.php.',
            ],
            self::texts($html, '//section/ol/li'),
        );
    }

    // ------------------------------------------------------------ step 3

    public function testStepThreeShowsTheFirstAdminForm(): void
    {
        $this->prepareSafely();
        unlink($this->sibling() . '/setup-code.txt');
        $html = self::assertPage(self::send($this->services(InstallState::NeedsAdmin), 'GET', '/setup', [], self::server($this->publicRoot())), 200);

        // ensureSetupCode() replaced the deleted file.
        $this->setupCode();
        self::assertStringContainsString('<title>Set up TiffinDB - TiffinDB</title>', $html);
        self::assertSteps($html, ['Step 1, done Check this server', 'Step 2, done Make the data folder safe', 'Step 3, now Create the first Admin'], 3);
        self::assertSame(['div', 'ol.steps', 'div.notice', 'form.form.form-card.form-wide'], self::mainOutline($html));
        self::assertSame(['Step 2 is done: the data folder is ready, and it cannot be opened from the web.'], self::texts($html, '//main/div[@class="notice"][@role="status"]/p'));
        self::assertSame(['/setup/admin'], self::attributes($html, '//form[@method="post"]/@action'));
        self::assertSame(['Step 3: Create the first Admin'], self::texts($html, '//form/h2[@class="section-title"]'));
        self::assertSame(['The first Admin can add other people, create projects, share them, and change tables and fields.'], self::texts($html, '//form/p'));
        self::assertSame(
            ['Setup code', 'Your name', 'Username', 'Password', 'Type the password again'],
            self::texts($html, '//form/div[@class="field"]/label[@class="label"]'),
        );
        self::assertSame(
            [
                'To show that you look after this server, open the file ../tiffindb-data/setup-code.txt with your hosting file manager or FTP program, and copy the code from it.',
                'Shown to others, for example on the project list.',
                'Used to sign in. Letters, numbers, dots, dashes, and underscores.',
                'At least 10 characters. A few unrelated words make a good password.',
            ],
            self::texts($html, '//form/div[@class="field"]/p[@class="help"]'),
        );
        self::assertSame(0, self::xpath($html)->query('//*[@class="error-text"] | //*[@aria-invalid] | //div[@class="error-summary"]')->length);
        $this->assertInputs($html, '', '');
        self::assertSame(['Show passwords'], self::texts($html, '//form/div[@class="check show-password"][@hidden="hidden"]'));
        self::assertSame(['Create Admin and finish'], self::texts($html, '//form/div[@class="form-actions"]/button[@class="button button-large button-primary"][@type="submit"]'));
        self::assertSame(1, self::xpath($html)->query('/html/head/script[@defer="defer"][starts-with(@src, "/assets/js/show-password.js?v=")]')->length);
    }

    public function testStepThreeNamesTheConfiguredFolder(): void
    {
        $this->configured = $this->www() . '/configured-data';
        $result = $this->installer()->prepareDataFolder($this->publicRoot(), null, self::spy());
        self::assertSame('safe', $result->outcome);

        $html = self::send($this->services(InstallState::NeedsAdmin), 'GET', '/setup', [], self::server($this->publicRoot()))->body;
        self::assertSame(
            ['To show that you look after this server, open the file setup-code.txt in the data folder set as data_dir in config/local.php with your hosting file manager or FTP program, and copy the code from it.'],
            self::texts($html, '//p[@id="setup-code-help"]'),
        );
    }

    private function assertInputs(string $html, string $name, string $username): void
    {
        $xpath = self::xpath($html);
        $expected = [
            'setup-code' => ['input input-medium', 'text', 'setup_code', 'off', null],
            'setup-name' => ['input', 'text', 'display_name', 'name', $name],
            'setup-username' => ['input input-medium', 'text', 'username', 'username', $username],
            'setup-password' => ['input', 'password', 'password', 'new-password', null],
            'setup-repeat' => ['input', 'password', 'repeat', 'new-password', null],
        ];
        foreach ($expected as $id => [$class, $type, $field, $autocomplete, $value]) {
            $input = $xpath->query('//form/div[@class="field"]/input[@id="' . $id . '"]')->item(0);
            self::assertInstanceOf(DOMElement::class, $input, $id);
            self::assertSame([$class, $type, $field, $autocomplete, 'required'], [
                $input->getAttribute('class'), $input->getAttribute('type'), $input->getAttribute('name'),
                $input->getAttribute('autocomplete'), $input->getAttribute('required'),
            ], $id);
            if ($value === null) {
                self::assertFalse($input->hasAttribute('value'), "{$id} is never printed back.");
            } else {
                self::assertSame($value, $input->getAttribute('value'), $id);
            }
        }
        $code = $xpath->query('//input[@id="setup-code"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $code);
        self::assertSame(['characters', 'false'], [$code->getAttribute('autocapitalize'), $code->getAttribute('spellcheck')]);
        $user = $xpath->query('//input[@id="setup-username"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $user);
        self::assertSame(['none', 'false'], [$user->getAttribute('autocapitalize'), $user->getAttribute('spellcheck')]);
    }

    // ------------------------------------------------------------ POST /setup/admin

    public function testCreatingTheFirstAdminSignsInAndRedirects(): void
    {
        $this->prepareSafely();
        $services = $this->services(InstallState::NeedsAdmin);
        $response = self::send($services, 'POST', '/setup/admin', [
            'setup_code' => strtolower($this->setupCode()),
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD,
        ], self::server($this->publicRoot()));

        self::assertSame(303, $response->status);
        self::assertSame('/projects', $response->header('Location'));
        self::assertSame('', $response->body);

        $session = $response->cookie(Context::SESSION_COOKIE);
        self::assertNotNull($session);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $session->value);
        self::assertSame([Context::SESSION_MAX_AGE, 'Lax', '/'], [$session->maxAge, $session->sameSite, $session->path]);
        $csrf = $response->cookie(Context::CSRF_COOKIE);
        self::assertNotNull($csrf);
        self::assertSame([0, ''], [$csrf->maxAge, $csrf->value], 'tdb_csrf is deleted.');

        self::assertContains('setFlash:notice.setup.done', $services->calls);
        self::assertSame(['key' => 'notice.setup.done', 'params' => []], $services->flash[$session->value] ?? null);
        self::assertSame(InstallState::Installed, $this->installer()->state());
        self::assertFileDoesNotExist($this->sibling() . '/setup-code.txt');
    }

    public function testProblemsShowStepThreeAgainWith422AndNothingSecretPrintedBack(): void
    {
        $this->prepareSafely();
        $code = $this->setupCode();
        $wrong = 'WRNG-WRNG-WRNG-WRNG';
        $services = $this->services(InstallState::NeedsAdmin);
        $response = self::send($services, 'POST', '/setup/admin', [
            'setup_code' => $wrong,
            'display_name' => 'Example "Admin" <b>',
            'username' => 'a',
            'password' => 'short1',
            'repeat' => 'short1',
        ], self::server($this->publicRoot()));
        $html = self::assertPage($response, 422);

        self::assertStringContainsString('<title>Error: Set up TiffinDB - TiffinDB</title>', $html);
        self::assertSame(['div.error-summary', 'div', 'ol.steps', 'div.notice', 'form.form.form-card.form-wide'], self::mainOutline($html));
        self::assertSame(['There are 3 problems'], self::texts($html, '//div[@class="error-summary"]/h2'));
        self::assertSame(['#setup-code', '#setup-username', '#setup-password'], self::attributes($html, '//div[@class="error-summary"]//a/@href'));
        self::assertSame(
            [
                'That setup code is not right. Open the file again and copy the code exactly.',
                'Use 3 to 32 letters, numbers, dots, dashes, or underscores, with no spaces.',
                'Use at least 10 characters.',
            ],
            self::texts($html, '//div[@class="error-summary"]//li'),
        );
        self::assertSame(['setup-code-error', 'setup-username-error', 'setup-password-error'], self::attributes($html, '//p[@class="error-text"]/@id'));
        self::assertSame(['setup-code-help setup-code-error'], self::attributes($html, '//input[@id="setup-code"]/@aria-describedby'));
        self::assertSame(['true', 'true', 'true'], self::attributes($html, '//input/@aria-invalid'));
        $this->assertInputs($html, 'Example "Admin" <b>', 'a');

        foreach ([$wrong, 'short1', $code] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertSame(InstallState::NeedsAdmin, $this->installer()->state());
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
        self::assertNotContains('setFlash:notice.setup.done', $services->calls);
    }

    public function testDifferentPasswordsAreRefusedBeforeTheCore(): void
    {
        $this->prepareSafely();
        $code = $this->setupCode();
        $response = self::send($this->services(InstallState::NeedsAdmin), 'POST', '/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Admin',
            // Invalid for the core too; only the mismatch is reported, so the core was not asked.
            'username' => 'a b',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD . 'x',
        ], self::server($this->publicRoot()));
        $html = self::assertPage($response, 422);

        self::assertSame(['The two passwords are different.'], self::texts($html, '//div[@class="error-summary"]//li'));
        self::assertSame(['#setup-repeat'], self::attributes($html, '//div[@class="error-summary"]//a/@href'));
        self::assertSame(['The two passwords are different.'], self::texts($html, '//p[@class="error-text"][@id="setup-repeat-error"]'));
        self::assertSame(['setup-repeat-error'], self::attributes($html, '//input[@id="setup-repeat"]/@aria-describedby'));
        self::assertSame(['true'], self::attributes($html, '//input/@aria-invalid'));
        $this->assertInputs($html, 'Example Admin', 'a b');
        foreach ([$code, self::PASSWORD] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
        self::assertSame(InstallState::NeedsAdmin, $this->installer()->state());
    }

    public function testTooManyWrongCodesAreThrottled(): void
    {
        $this->prepareSafely();
        $post = ['setup_code' => 'WRNG-WRNG-WRNG-WRNG', 'display_name' => 'Example Admin', 'username' => 'admin', 'password' => self::PASSWORD, 'repeat' => self::PASSWORD];
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, self::send($this->services(InstallState::NeedsAdmin), 'POST', '/setup/admin', $post, self::server($this->publicRoot()))->status);
        }
        $post['setup_code'] = $this->setupCode();
        $html = self::assertPage(self::send($this->services(InstallState::NeedsAdmin), 'POST', '/setup/admin', $post, self::server($this->publicRoot())), 422);

        self::assertMatchesRegularExpression('/^Too many tries\. Wait \d+ (second|seconds|minute|minutes), then try again\.$/', self::texts($html, '//div[@class="error-summary"]//li')[0]);
        self::assertSame(['#setup-code'], self::attributes($html, '//div[@class="error-summary"]//a/@href'));
        self::assertStringNotContainsString($post['setup_code'], $html);
        self::assertSame(InstallState::NeedsAdmin, $this->installer()->state());
    }

    /**
     * T-CF-3: a POST that read NeedsAdmin but lost the race to another
     * request that created the first Admin is sent to sign in, not a 500.
     */
    public function testALosingFirstAdminPostRedirectsToSignIn(): void
    {
        $this->prepareSafely();
        $code = $this->setupCode();
        $services = $this->services(InstallState::NeedsAdmin);
        $this->installer()->createFirstAdmin($code, 'Example Admin', 'admin', self::PASSWORD, '192.0.2.1');

        $response = self::send($services, 'POST', '/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Person',
            'username' => 'person',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD,
        ], self::server($this->publicRoot()));

        self::assertSame(303, $response->status, $response->body);
        self::assertSame('/sign-in', $response->header('Location'));
        self::assertSame('', $response->body);
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
        self::assertNotContains('setFlash:notice.setup.done', $services->calls);
        self::assertSame(InstallState::Installed, $this->installer()->state());
    }

    /** T-CF-3: an installer refusal that is not a lost race is still a 500. */
    public function testAnInstallerRefusalBeforeTheDataFolderIsStillA500(): void
    {
        $services = $this->services(InstallState::NeedsAdmin);
        $response = self::send($services, 'POST', '/setup/admin', [
            'setup_code' => 'WRNG-WRNG-WRNG-WRNG',
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD,
        ], self::server($this->publicRoot()));

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Location'));
    }

    public function testALateOrEarlyAdminPostRedirectsWithoutTheInstaller(): void
    {
        $response = self::send(new FakeServices(InstallState::NeedsDataFolder), 'POST', '/setup/admin', ['setup_code' => 'x'], self::server($this->publicRoot()));
        self::assertSame(303, $response->status);
        self::assertSame('/setup', $response->header('Location'));
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
    }

    /**
     * Review F1: the whole first-Admin path on the real CoreServices. The
     * data folder is outside the document root, so no probe (and no
     * socket) is used. The flash is written to the new session in the
     * request that finished the installation, and shown by the next GET.
     */
    public function testTheFirstAdminPathWorksOnTheRealServices(): void
    {
        $services = new CoreServices(Fixtures::config(false), $this->app(), new FixedClock(), new SeededRandom('tiffindb-setup-core-seed-1'), new PasswordHasher(['bcrypt' => 4]));
        $server = self::server($this->publicRoot());

        $folder = self::send($services, 'POST', '/setup/data-folder', [], $server);
        self::assertSame([303, '/setup'], [$folder->status, $folder->header('Location')]);
        self::assertSame(200, self::send($services, 'GET', '/setup', [], $server)->status);

        $response = self::send($services, 'POST', '/setup/admin', [
            'setup_code' => $this->setupCode(),
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD,
        ], $server);
        self::assertSame(303, $response->status, $response->body);
        self::assertSame('/projects', $response->header('Location'));
        $session = $response->cookie(Context::SESSION_COOKIE);
        self::assertNotNull($session);
        self::assertSame(Context::SESSION_MAX_AGE, $session->maxAge);
        $csrf = $response->cookie(Context::CSRF_COOKIE);
        self::assertNotNull($csrf);
        self::assertSame(0, $csrf->maxAge);

        $cookies = [Context::SESSION_COOKIE => $session->value];
        $next = self::send($services, 'GET', '/account/password', [], $server, '', true, $cookies);
        self::assertSame(200, $next->status, $next->body);
        self::assertSame(
            ['TiffinDB is ready. Select New project to make your first project.'],
            self::texts($next->body, '//main/div[@class="notice"][@role="status"]/p'),
        );
        $again = self::send($services, 'GET', '/account/password', [], $server, '', true, $cookies);
        self::assertSame([], self::texts($again->body, '//main/div[@class="notice"][@role="status"]/p'), 'The flash is shown once.');
        self::assertSame(404, self::send($services, 'GET', '/setup', [], $server)->status);
    }

    /** Review F2: the controller's own state checks, without the kernel gate in front. */
    public function testTheControllerChecksTheStateItself(): void
    {
        $cases = [
            [InstallState::Installed, 'dataFolder'],
            [InstallState::Installed, 'admin'],
            [InstallState::NeedsAdmin, 'dataFolder'],
            [InstallState::NeedsDataFolder, 'admin'],
        ];
        foreach ($cases as [$state, $action]) {
            // No installer: any installer call would throw.
            $services = new FakeServices($state);
            $response = (new SetupController(self::context($services)))->$action(self::directRequest());
            self::assertSame([303, '/setup'], [$response->status, $response->header('Location')], "{$state->name} {$action}");
        }
        $response = (new SetupController(self::context(new FakeServices(InstallState::Installed))))->show(self::directRequest());
        self::assertSame(404, $response->status);
    }

    private static function directRequest(): Request
    {
        return Request::fromGlobals(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/setup', 'SCRIPT_NAME' => '/index.php'], [], ['_csrf' => self::ANON], []);
    }

    private static function context(Services $services): Context
    {
        $urls = new UrlGenerator('', Fixtures::VERSION);
        $view = (new View(Fixtures::appRoot() . '/templates', new Strings(Fixtures::appRoot() . '/strings/en.php'), $urls, Appearance::default(), Fixtures::config(false)))
            ->withCsrfToken(self::ANON);

        return new Context(self::directRequest(), $view, $services, null, $urls, self::ANON, false, null);
    }

    public function testFakeServicesRefuseAFlashBeforeInstallation(): void
    {
        $this->expectException(\LogicException::class);
        (new FakeServices(InstallState::NeedsAdmin))->setFlash(FakeServices::TOKEN, 'notice.setup.done');
    }

    // ------------------------------------------------------------ gate

    public function testSetupIsNotFoundOnceInstalled(): void
    {
        foreach ([['GET', '/setup'], ['POST', '/setup/data-folder'], ['POST', '/setup/admin']] as [$method, $path]) {
            $response = self::send(new FakeServices(InstallState::Installed), $method, $path, [], self::server($this->publicRoot()));
            self::assertSame(404, $response->status, "{$method} {$path}");
        }
    }

    public function testSetupFormsNeedTheirCsrfToken(): void
    {
        foreach ([[InstallState::NeedsDataFolder, '/setup/data-folder'], [InstallState::NeedsAdmin, '/setup/admin']] as [$state, $path]) {
            $probe = self::spy();
            $response = self::send($this->services($state, $probe), 'POST', $path, [], self::server($this->publicRoot()), '', false);
            self::assertSame(403, $response->status, $path);
            self::assertStringContainsString('This form has expired', $response->body);
            self::assertSame(0, $probe->calls);
        }
        self::assertDirectoryDoesNotExist($this->sibling());
    }

    // ------------------------------------------------------------ port rule

    /** @return iterable<string, array{int, string, bool, bool}> */
    public static function ports(): iterable
    {
        yield 'http default' => [80, 'example.test', false, true];
        yield 'https default' => [443, 'example.test', true, true];
        yield 'http default with a Host port' => [80, 'example.test:8080', false, true];
        yield 'named by the Host' => [8080, 'example.test:8080', false, true];
        yield 'named by an IPv6 Host' => [8443, '[2001:db8::1]:8443', true, true];
        yield 'IPv6 Host without a port' => [8443, '[2001:db8::1]', true, false];
        yield 'not named' => [22, 'example.test', false, false];
        yield 'TLS port on plain http' => [443, 'example.test', false, false];
        yield 'plain port on https' => [80, 'example.test', true, false];
        yield 'another port named' => [22, 'example.test:2222', false, false];
    }

    #[DataProvider('ports')]
    public function testThePortRule(int $port, string $host, bool $secure, bool $acceptable): void
    {
        self::assertSame($acceptable, SetupController::portAcceptable($port, $host, $secure));
    }

    public function testHostPort(): void
    {
        self::assertSame(8080, SetupController::hostPort('example.test:8080'));
        self::assertSame(8443, SetupController::hostPort('[2001:db8::1]:8443'));
        self::assertNull(SetupController::hostPort('[2001:db8::1]'));
        self::assertNull(SetupController::hostPort('example.test'));
        self::assertNull(SetupController::hostPort('example.test:'));
    }
}
