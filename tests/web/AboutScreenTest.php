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
use TiffinDB\Core\App\AboutOperations;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\AboutController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-89 (M7 screens spec section 11.1, M7-WP5): the About page and the
 * licence pages (docs/design/m7/about.md) through the kernel with the
 * real templates, strings, and core operations over a CoreFixture. A
 * Member sees no PHP or SQLite version and no plugin that is off; an
 * Admin sees both; a licence text holding markup is escaped inside its
 * `pre`; an unknown or malformed licence id is the 404 page. Hostile
 * component texts come from a temporary application root given to
 * AboutOperations (its test seam, M7 spec section 10.1). Synthetic data
 * only.
 */
#[CoversClass(AboutController::class)]
final class AboutScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private Kernel $kernel;
    private ?TempDir $root = null;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $services = new FakeServices();
        $services->operations = $this->ops;
        $services->sessions[self::ADMIN] = $this->signedIn('admin');
        $services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $services);
    }

    protected function tearDown(): void
    {
        $this->root?->remove();
        $this->core->remove();
    }

    private function get(string $token, string $uri): Response
    {
        [$response, $log] = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => $token]));
        self::assertNotSame(500, $response->status, "GET {$uri}: {$log}");

        return $response;
    }

    private function page(string $token, string $uri, string $title): DOMXPath
    {
        $response = $this->get($token, $uri);
        self::assertSame(200, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame($title . ' - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame($title, HtmlAssertions::text($response->body, '//h1'));

        return new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
    }

    /** @return list<string> */
    private static function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
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

    /**
     * Points the operations' About facts at a temporary application root
     * holding $notices and the given files.
     *
     * @param list<array<string, mixed>> $notices
     * @param array<string, string>      $files path => contents
     */
    private function useRoot(array $notices, array $files): void
    {
        $this->root = TempDir::create('tiffindb-about-');
        mkdir($this->root->path('config'), 0700);
        file_put_contents($this->root->path('config/notices.json'), json_encode($notices, JSON_THROW_ON_ERROR));
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($this->root->path($path)))) {
                mkdir(dirname($this->root->path($path)), 0700, true);
            }
            file_put_contents($this->root->path($path), $contents);
        }
        $context = (new \ReflectionProperty(Operations::class, 'context'))->getValue($this->ops);
        (new \ReflectionProperty(Operations::class, 'about'))->setValue($this->ops, new AboutOperations($context, $this->root->path()));
    }

    // ---------------------------------------------------------------- tests

    public function testTheAdminSeesTheServerFactsAndEveryPlugin(): void
    {
        $this->ops->plugins()->disable($this->core->actor('admin'), 'export', 'json');
        $x = $this->page(self::ADMIN, '/about', 'About TiffinDB');
        self::assertSame(1, $x->query('//main[@class="page page-settings"]')->length);
        self::assertSame(['Made with', 'Plugins', 'This server'], self::texts($x, '//main//h2'));
        self::assertSame([PHP_VERSION], self::texts($x, '//main//dl[@class="facts"]/div[dt[normalize-space(.)="PHP"]]/dd'));
        $sqlite = (string) $this->core->system->db()->value('SELECT sqlite_version()');
        self::assertSame([$sqlite], self::texts($x, '//main//dl[@class="facts"]/div[dt[normalize-space(.)="SQLite"]]/dd'));
        self::assertSame(['Only Admins see this part.'], self::texts($x, '//main/p[@class="help"][last()]'));
        self::assertSame([CoreFixture::VERSION], self::texts($x, '//main//dl[@class="facts"]/div[dt[normalize-space(.)="Version"]]/dd'));
        self::assertSame([Fixtures::SOURCE_URL], self::texts($x, '//main//dl[@class="facts"]/div[dt[normalize-space(.)="Source code"]]/dd'));
        self::assertSame(1, $x->query('//main//a[@href="/about/licences/tiffindb"][normalize-space(.)="Read the licence"]')->length);

        $plugins = self::texts($x, '//main//dl[@class="about-list"][preceding-sibling::h2[1][normalize-space(.)="Plugins"]]/dt');
        self::assertContains('JSON', $plugins, 'A plugin that is off is listed for an Admin.');
        $json = self::texts($x, '//main//dl[@class="about-list"]/dt[normalize-space(.)="JSON"]/following-sibling::dd[position() <= 2]');
        self::assertMatchesRegularExpression('/^Export, version [^,]+, [A-Za-z0-9.+-]+/', $json[0]);
        self::assertSame('Off', $json[1]);
        self::assertSame('On', self::texts($x, '//main//dl[@class="about-list"]/dt[normalize-space(.)="CSV"][1]/following-sibling::dd[2]')[0]);

        // Components: every one but TiffinDB itself, each with its licence link named after it.
        self::assertSame(['OpenSpout', 'Composer class loader', 'Inter', 'Atkinson Hyperlegible', 'Lexend', 'Source Serif 4'], self::texts($x, '//main//dl[@class="about-list"][preceding-sibling::h2[1][normalize-space(.)="Made with"]]/dt'));
        self::assertSame('Licence text of OpenSpout', (string) $x->query('//a[@href="/about/licences/openspout"]/@aria-label')->item(0)?->nodeValue);
        self::assertStringStartsWith('Version 5.2.2, MIT AND Apache-2.0', self::texts($x, '//dt[normalize-space(.)="OpenSpout"]/following-sibling::dd[1]')[0]);
        self::assertStringStartsWith('MIT ', self::texts($x, '//dt[normalize-space(.)="Composer class loader"]/following-sibling::dd[1]')[0]);
    }

    public function testAMemberSeesNoServerFactsAndOnlyPluginsThatAreOn(): void
    {
        $this->ops->plugins()->disable($this->core->actor('admin'), 'export', 'json');
        $response = $this->get(self::MEMBER, '/about');
        $x = $this->page(self::MEMBER, '/about', 'About TiffinDB');
        self::assertSame(['Made with', 'Plugins'], self::texts($x, '//main//h2'));
        self::assertSame(0, $x->query('//dt[normalize-space(.)="PHP" or normalize-space(.)="SQLite"]')->length);
        self::assertStringNotContainsString('Only Admins see this part.', $response->body);
        self::assertStringNotContainsString((string) $this->core->system->db()->value('SELECT sqlite_version()'), $response->body);
        $plugins = self::texts($x, '//main//dl[@class="about-list"][preceding-sibling::h2[1][normalize-space(.)="Plugins"]]/dt');
        self::assertNotContains('JSON', $plugins, 'A plugin that is off is not listed for a Member.');
        self::assertContains('CSV', $plugins);
        self::assertSame(0, $x->query('//dd[normalize-space(.)="On" or normalize-space(.)="Off"]')->length, 'No on or off for a Member.');
    }

    public function testLicencePagesShowEachFileAndUnknownIdsAreNotFound(): void
    {
        $x = $this->page(self::MEMBER, '/about/licences/tiffindb', 'Licence: TiffinDB');
        self::assertSame(1, $x->query('//main//pre[@class="licence-text"]')->length);
        self::assertSame(0, $x->query('//main//h2')->length, 'One file: no file heading.');
        self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', (string) $x->query('//pre')->item(0)?->textContent);
        self::assertSame(1, $x->query('//main//a[@href="/about"][normalize-space(.)="Back to About TiffinDB"]')->length);

        $x = $this->page(self::ADMIN, '/about/licences/openspout', 'Licence: OpenSpout');
        self::assertSame(['LICENSE', 'LICENSE-for-cc42c1d'], self::texts($x, '//main//h2'));
        self::assertSame(2, $x->query('//main//pre[@class="licence-text"]')->length);

        foreach (['/about/licences/no-such-part', '/about/licences/OpenSpout', '/about/licences/' . str_repeat('a', 41), '/about/licences/a.b', '/about/licences/%2E%2E'] as $uri) {
            self::assertSame(404, $this->get(self::ADMIN, $uri)->status, $uri);
        }
        self::assertSame(303, Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', '/about'))[0]->status, 'Signed out: to sign in.');
        self::assertSame(303, Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', '/about/licences/tiffindb'))[0]->status);
    }

    public function testHostileComponentAndLicenceTextsAreEscaped(): void
    {
        $text = "Line one\n</pre><script>alert(1)</script><b>bold</b> & \"quoted\"\n" . str_repeat('x', 300);
        $this->useRoot([
            ['id' => 'tiffindb', 'name' => 'TiffinDB', 'version' => '', 'licence' => 'AGPL-3.0-or-later', 'files' => ['LICENSE']],
            ['id' => 'hostile', 'name' => 'Evil <b>Part</b> & "Co"', 'version' => '1.0 <i>x</i>', 'licence' => 'MIT', 'files' => ['vendor/evil/LICENSE', 'vendor/evil/NOTICE.md']],
        ], [
            'LICENSE' => 'Licence of TiffinDB.',
            'vendor/evil/LICENSE' => $text,
            'vendor/evil/NOTICE.md' => '<img src=x onerror=alert(1)>',
        ]);

        $about = $this->get(self::ADMIN, '/about');
        $x = $this->page(self::ADMIN, '/about', 'About TiffinDB');
        self::assertSame(['Evil <b>Part</b> & "Co"'], self::texts($x, '//main//dl[@class="about-list"][preceding-sibling::h2[1][normalize-space(.)="Made with"]]/dt'));
        self::assertSame('Licence text of Evil <b>Part</b> & "Co"', (string) $x->query('//a[@href="/about/licences/hostile"]/@aria-label')->item(0)?->nodeValue);
        self::assertStringStartsWith('Version 1.0 <i>x</i>, MIT', self::texts($x, '//dl[@class="about-list"]/dt[1]/following-sibling::dd[1]')[0]);
        self::assertSame(0, $x->query('//main//b | //main//i')->length);
        self::assertStringNotContainsString('<b>Part', $about->body);

        $licence = $this->get(self::ADMIN, '/about/licences/hostile');
        $x = $this->page(self::ADMIN, '/about/licences/hostile', 'Licence: Evil <b>Part</b> & "Co"');
        self::assertSame(['LICENSE', 'NOTICE.md'], self::texts($x, '//main//h2'));
        self::assertSame($text, (string) $x->query('//pre[@class="licence-text"]')->item(0)?->textContent);
        self::assertSame('<img src=x onerror=alert(1)>', (string) $x->query('//pre[@class="licence-text"]')->item(1)?->textContent);
        self::assertSame(0, $x->query('//main//script | //main//b | //main//img | //*[@onerror]')->length);
        self::assertSame(2, substr_count($licence->body, '<pre'), 'No pre from the text.');
        self::assertSame(2, substr_count($licence->body, '</pre>'), 'The text cannot close its pre.');
        self::assertStringNotContainsString('<script>', $licence->body);
    }

    public function testALongLicenceIsCutWithANote(): void
    {
        $this->useRoot([
            ['id' => 'big', 'name' => 'Big Part', 'version' => '', 'licence' => 'MIT', 'files' => ['vendor/big/LICENSE']],
        ], ['vendor/big/LICENSE' => str_repeat("Long licence line.\n", 20000)]);
        $x = $this->page(self::MEMBER, '/about/licences/big', 'Licence: Big Part');
        self::assertSame(AboutOperations::MAX_LICENCE_BYTES, strlen((string) $x->query('//pre')->item(0)?->textContent));
        self::assertSame(['(The rest of this file is not shown here. It is in the file vendor/big/LICENSE of this installation.)'], self::texts($x, '//main/p[@class="help"]'));
        $x = $this->page(self::MEMBER, '/about', 'About TiffinDB');
        self::assertSame(['MIT Licence text'], self::texts($x, '//dt[normalize-space(.)="Big Part"]/following-sibling::dd[1]'), 'No version: the licence alone.');
    }
}
