<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\PluginController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-46 (M3-WP10): the plugins list, a plugin's page, and the
 * confirmation before turning on an added plugin
 * (docs/design/m3/plugins.md), through the kernel with the real templates,
 * strings, and core operations over a temporary `plugins/` folder: the
 * shipped plugins plus CoreFixture's synthetic ones (valid, needs a
 * missing extension, broken) and one whose texts hold markup. Scanning
 * only; no plugin code runs. Synthetic data only.
 */
#[CoversClass(PluginController::class)]
final class PluginScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const HOSTILE_NAME = '<script>alert(1)</script> & "Q"';
    private const HOSTILE_DESCRIPTION = '"><img src=x onerror=alert(1)> plugins.title';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $root = $this->core->pluginsRoot();
        mkdir($root . '/import/hostile/src', 0750, true);
        file_put_contents($root . '/import/hostile/plugin.json', (string) json_encode([
            'api' => 1, 'id' => 'hostile', 'kind' => 'import', 'name' => self::HOSTILE_NAME, 'version' => '2.0.1',
            'class' => 'TiffinDB\\Plugin\\Import\\Hostile\\HostileImporter', 'licence' => 'MIT', 'extensions' => ['hst'],
            'requires_extensions' => [], 'description' => self::HOSTILE_DESCRIPTION,
        ]));
        file_put_contents($root . '/import/hostile/src/HostileImporter.php', "<?php\n\nnamespace TiffinDB\\Plugin\\Import\\Hostile;\n\nfinal class HostileImporter\n{\n}\n");
        $this->ops = $this->core->operations();
        $this->ops->usePluginsRoot($root);
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testListWithEveryStatus(): void
    {
        $html = $this->page(self::ADMIN, '/admin/plugins');
        $x = $this->xpath($html);

        self::assertSame('Plugins - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Plugins', HtmlAssertions::text($html, '//h1'));
        self::assertSame('page', $x->query('//nav[@class="top-nav"]/a[.="Plugins"]/@aria-current')->item(0)?->nodeValue);
        self::assertSame(['Import formats', 'Export formats'], $this->texts($x, '//main//section/h2'));

        $import = '//section[@aria-labelledby="x-import"]//li[@class="plugin-row"]';
        self::assertSame(['CSV', 'Example format', self::HOSTILE_NAME, 'Needs an extension', 'Excel workbook'], $this->texts($x, $import . '//h3/a'));
        self::assertSame(['On', 'Off', 'Off', 'Cannot be used', 'On'], $this->texts($x, $import . '/span[contains(@class, "plugin-status")]'));
        self::assertSame(1, $x->query($import . '/span[@class="plugin-status plugin-status-unavailable"]')->length);
        self::assertSame('/admin/plugins/import/example', $x->query($import . '//a[.="Example format"]/@href')->item(0)?->nodeValue);
        self::assertSame(['Version 1.0.0. Reads .csv, .tsv, .txt.', 'Comes with TiffinDB.'], $this->texts($x, $import . '[1]//p'));

        $export = '//section[@aria-labelledby="x-export"]//li[@class="plugin-row"]';
        self::assertSame(['Plugin in folder broken', 'CSV', 'JSON', 'Excel workbook'], $this->texts($x, $export . '//h3/a'));
        self::assertSame(['Damaged', 'On', 'On', 'On'], $this->texts($x, $export . '/span'));
        self::assertSame(1, $x->query($export . '/span[@class="plugin-status plugin-status-broken"]')->length);
        self::assertSame(['Version 1.0.0. Writes .csv.', 'Comes with TiffinDB.'], $this->texts($x, '//li[.//a="CSV"][.//p[contains(., "Writes")]]//p'));

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('To add a plugin, copy its folder', $html);
    }

    public function testShippedPluginPageAndTurningOffAndOn(): void
    {
        $html = $this->page(self::ADMIN, '/admin/plugins/export/json');
        $x = $this->xpath($html);
        self::assertSame('JSON - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Plugins', 'JSON'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame('On', HtmlAssertions::text($html, '//main//span[@class="plugin-status plugin-status-enabled"]'));
        self::assertSame(['Kind', 'Version', 'Licence', 'File types', 'Folder', 'Origin'], $this->texts($x, '//dl[@class="facts"]//dt'));
        self::assertSame(['Export', '1.0.0', 'AGPL-3.0-or-later', '.json (application/json)', 'plugins/export/json', 'Comes with TiffinDB'], $this->texts($x, '//dl[@class="facts"]//dd'));
        self::assertSame('/admin/plugins/export/json/disable', $x->query('//main//form/@action')->item(0)?->nodeValue);
        self::assertSame('Turn off', HtmlAssertions::text($html, '//main//form/button'));
        self::assertSame('/admin/plugins', $x->query('//main//a[.="All plugins"]/@href')->item(0)?->nodeValue);

        $off = $this->request(self::ADMIN, 'POST', '/admin/plugins/export/json/disable');
        self::assertSame(303, $off->status);
        self::assertSame('/admin/plugins/export/json', $off->header('Location'));
        $after = $this->page(self::ADMIN, '/admin/plugins/export/json');
        self::assertSame('JSON is turned off.', HtmlAssertions::text($after, '//div[@class="notice"]/p'));
        self::assertSame('Off', HtmlAssertions::text($after, '//main//span[@class="plugin-status"]'));
        // A shipped plugin turns on without a confirmation page.
        self::assertSame('/admin/plugins/export/json/enable', $this->xpath($after)->query('//main//form/@action')->item(0)?->nodeValue);
        self::assertSame(303, $this->request(self::ADMIN, 'GET', '/admin/plugins/export/json/enable')->status, 'No confirmation page for a shipped plugin.');

        $on = $this->request(self::ADMIN, 'POST', '/admin/plugins/export/json/enable');
        self::assertSame(303, $on->status);
        self::assertSame('JSON is turned on.', HtmlAssertions::text($this->page(self::ADMIN, '/admin/plugins/export/json'), '//div[@class="notice"]/p'));
        self::assertTrue($this->enabled('export/json'));
    }

    public function testAddedPluginNeedsTheConfirmation(): void
    {
        $html = $this->page(self::ADMIN, '/admin/plugins/import/example');
        $x = $this->xpath($html);
        self::assertSame(0, $x->query('//main//form')->length, 'No direct form for an added plugin.');
        self::assertSame('/admin/plugins/import/example/enable', $x->query('//main//a[.="Turn on"]/@href')->item(0)?->nodeValue);
        self::assertSame('Added to this installation', $this->texts($x, '//dl[@class="facts"]//dd')[5]);
        self::assertSame('A synthetic plugin for tests.', HtmlAssertions::text($html, '//main/p[1]'));

        $confirm = $this->page(self::ADMIN, '/admin/plugins/import/example/enable');
        $c = $this->xpath($confirm);
        self::assertSame('Turn on Example format? - TiffinDB', HtmlAssertions::text($confirm, '//title'));
        self::assertSame('Turn on Example format?', HtmlAssertions::text($confirm, '//h1'));
        self::assertStringStartsWith('Example format did not come with TiffinDB.', HtmlAssertions::text($confirm, '//main/p[1]'));
        self::assertSame(['Version', 'Licence', 'Folder'], $this->texts($c, '//dl[@class="facts"]//dt'));
        self::assertSame('1', $c->query('//main//form/input[@name="confirm"]/@value')->item(0)?->nodeValue);
        self::assertSame('Turn it on', HtmlAssertions::text($confirm, '//main//form//button[contains(@class, "button-danger")]'));
        self::assertSame('/admin/plugins/import/example', $c->query('//main//form//a[.="Cancel"]/@href')->item(0)?->nodeValue);

        // Without the confirmation field the POST only leads to the confirmation page.
        $direct = $this->request(self::ADMIN, 'POST', '/admin/plugins/import/example/enable');
        self::assertSame(303, $direct->status);
        self::assertSame('/admin/plugins/import/example/enable', $direct->header('Location'));
        self::assertFalse($this->enabled('import/example'));

        $done = $this->request(self::ADMIN, 'POST', '/admin/plugins/import/example/enable', ['confirm' => '1']);
        self::assertSame(303, $done->status);
        self::assertTrue($this->enabled('import/example'));
        self::assertSame('Example format is turned on.', HtmlAssertions::text($this->page(self::ADMIN, '/admin/plugins/import/example'), '//div[@class="notice"]/p'));
        // Once on, the confirmation page is not offered again.
        self::assertSame(303, $this->request(self::ADMIN, 'GET', '/admin/plugins/import/example/enable')->status);
    }

    public function testUnavailableAndBrokenHaveNoButtons(): void
    {
        $needs = $this->page(self::ADMIN, '/admin/plugins/import/needs');
        self::assertSame(0, $this->xpath($needs)->query('//main//form | //main//a[contains(@class, "button")]')->length);
        self::assertSame(
            'This plugin needs PHP extensions that this server does not have: tiffindbmissingext. Ask your hosting provider to turn them on.',
            HtmlAssertions::text($needs, '//main/p[@class="notice"]'),
        );

        $broken = $this->page(self::ADMIN, '/admin/plugins/export/broken');
        $x = $this->xpath($broken);
        self::assertSame('Plugin in folder broken', HtmlAssertions::text($broken, '//h1'));
        self::assertSame(0, $x->query('//main//form | //main//a[contains(@class, "button")]')->length);
        self::assertSame('Damaged', HtmlAssertions::text($broken, '//main//span[@class="plugin-status plugin-status-broken"]'));
        self::assertSame(['Kind', 'Folder', 'Origin'], $this->texts($x, '//dl[@class="facts"]//dt'));
        self::assertSame('The description file is missing an entry TiffinDB needs.', HtmlAssertions::text($broken, '//main/p[@class="help"]'));

        // Forced POSTs are refused by the core with the page and its problem.
        foreach (['/admin/plugins/export/broken/enable', '/admin/plugins/import/needs/enable', '/admin/plugins/import/needs/disable'] as $path) {
            $response = $this->request(self::ADMIN, 'POST', $path, ['confirm' => '1']);
            self::assertSame(422, $response->status, $path);
            self::assertSame('This plugin cannot be turned on.', HtmlAssertions::text($response->body, '//div[@class="error-summary"]//li'), $path);
        }
        self::assertSame(303, $this->request(self::ADMIN, 'GET', '/admin/plugins/export/broken/enable')->status);
    }

    public function testPluginTextIsEscapedEverywhere(): void
    {
        foreach (['/admin/plugins', '/admin/plugins/import/hostile', '/admin/plugins/import/hostile/enable'] as $path) {
            $html = $this->page(self::ADMIN, $path);
            self::assertStringNotContainsString('<script>alert', $html, $path);
            self::assertStringNotContainsString('<img src=x', $html, $path);
        }
        $html = $this->page(self::ADMIN, '/admin/plugins/import/hostile');
        self::assertSame(self::HOSTILE_NAME, HtmlAssertions::text($html, '//h1'));
        self::assertSame(self::HOSTILE_NAME . ' - TiffinDB', HtmlAssertions::text($html, '//title'));
        // The description is shown as text, never looked up as a string key.
        self::assertSame(self::HOSTILE_DESCRIPTION, HtmlAssertions::text($html, '//main/p[1]'));
        $confirm = $this->page(self::ADMIN, '/admin/plugins/import/hostile/enable');
        self::assertSame('Turn on ' . self::HOSTILE_NAME . '?', HtmlAssertions::text($confirm, '//h1'));

        self::assertSame(303, $this->request(self::ADMIN, 'POST', '/admin/plugins/import/hostile/enable', ['confirm' => '1'])->status);
        self::assertSame(self::HOSTILE_NAME . ' is turned on.', HtmlAssertions::text($this->page(self::ADMIN, '/admin/plugins/import/hostile'), '//div[@class="notice"]/p'));
    }

    public function testUnknownPluginsAreNotFound(): void
    {
        foreach (['/admin/plugins/import/nothere', '/admin/plugins/other/csv', '/admin/plugins/import/nothere/enable'] as $path) {
            self::assertSame(404, $this->request(self::ADMIN, 'GET', $path)->status, $path);
        }
        self::assertSame(404, $this->request(self::ADMIN, 'POST', '/admin/plugins/export/nothere/disable')->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/admin/plugins/import/..%2Fcsv')->status);
    }

    public function testMemberGets403OnEveryRouteWithoutLearningWhatExists(): void
    {
        foreach (
            [
                ['GET', '/admin/plugins'], ['GET', '/admin/plugins/export/csv'], ['GET', '/admin/plugins/import/nothere'],
                ['GET', '/admin/plugins/import/example/enable'], ['POST', '/admin/plugins/import/example/enable'],
                ['POST', '/admin/plugins/export/csv/disable'], ['POST', '/admin/plugins/export/nothere/disable'],
            ] as [$method, $path]
        ) {
            self::assertSame(403, $this->request(self::MEMBER, $method, $path, ['confirm' => '1'])->status, $method . ' ' . $path);
        }
        self::assertTrue($this->enabled('export/csv'));
        self::assertFalse($this->enabled('import/example'));
    }

    public function testChangesNeedPostAndCsrf(): void
    {
        self::assertContains($this->request(self::ADMIN, 'GET', '/admin/plugins/export/csv/disable')->status, [404, 405]);
        $noToken = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', '/admin/plugins/export/csv/disable', [], ['tdb_session' => self::ADMIN]))[0];
        self::assertSame(403, $noToken->status);
        self::assertTrue($this->enabled('export/csv'));
    }

    // Helpers.

    private function enabled(string $ref): bool
    {
        foreach ($this->ops->plugins()->list($this->core->actor('admin')) as $plugin) {
            if ($plugin->ref() === $ref) {
                return $plugin->status->value === 'enabled';
            }
        }
        self::fail("No plugin {$ref}.");
    }

    private function signedIn(string $username): SignedIn
    {
        $actor = $this->core->actor($username);

        return new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
    }

    /** @param array<string, mixed> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        } else {
            $post = [];
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, 'GET', $uri);
        self::assertSame(200, $response->status, $uri);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);

        return $response->body;
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
