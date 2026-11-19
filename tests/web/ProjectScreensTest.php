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
use TiffinDB\Web\Controller\ProjectController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP11: the project list (docs/design/m1/sign-in-and-projects.md
 * section 3), new project (docs/design/m1/new-project.md), and project
 * settings (docs/design/m1/schema-editor.md section 2), through the kernel
 * with the real templates and strings and real core operations over a
 * CoreFixture instance. Synthetic data only.
 */
#[CoversClass(ProjectController::class)]
final class ProjectScreensTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
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

    // Project list.

    public function testAdminProjectList(): void
    {
        $home = $this->project('Home Inventory', 'Items');
        $recipes = $this->project('Recipes');
        $garden = $this->project('Garden Log');
        $this->ops->projects()->setSharing($this->core->actor('admin'), $recipes, [$this->core->person('member')->key]);
        $items = $this->ops->schema()->tables($this->core->actor('admin'), $home)[0]->table->key;
        $this->core->insertRecord($home, $items);
        $this->core->insertRecord($home, $items);
        // A project whose file is gone: its card says so, the others are unaffected.
        rename($this->core->projectFolder($garden), $this->core->projectFolder($garden) . '-moved');
        // A fresh request opens the files again.
        $this->services->operations = $this->core->operations();

        $html = $this->page(self::ADMIN, '/projects');
        $x = $this->xpath($html);

        self::assertSame('Your projects - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Your projects', HtmlAssertions::text($html, '//h1'));
        self::assertSame('A project is one database: its tables, forms, and files.', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        $new = $x->query('//div[@class="page-actions"]/a[contains(@class, "button-primary")]')->item(0);
        self::assertNotNull($new);
        self::assertSame('/projects/new', $new->attributes?->getNamedItem('href')?->nodeValue);
        self::assertSame('New project', trim($new->textContent));
        self::assertSame(1, $x->query('//div[@class="page-actions"]//svg[@class="button-icon"][@aria-hidden="true"]')->length);

        $cards = $x->query('//ul[@class="project-grid"]/li[@class="card project-card"]');
        self::assertSame(3, $cards->length);
        self::assertSame(['Garden Log', 'Home Inventory', 'Recipes'], $this->texts($x, '//li/h2[@class="project-name"]'));

        $card = '//li[h2/a="Home Inventory"]';
        self::assertSame('/projects/' . $home, $x->query($card . '/h2/a/@href')->item(0)?->nodeValue);
        self::assertSame(['Tables', 'Records', 'Last changed', 'Shared with'], $this->texts($x, $card . '/dl[@class="facts"]/dt'));
        self::assertSame(['Items', '2', '2 Mar 2026, by Example Admin', 'Admins only'], $this->texts($x, $card . '/dl/dd'));
        self::assertSame(['Open', 'Sharing'], $this->texts($x, $card . '/div[@class="card-links link-row"]/a'));
        self::assertSame('Open Home Inventory', $x->query($card . '//a[@class="link-strong"]/@aria-label')->item(0)?->nodeValue);
        self::assertSame('Sharing for Home Inventory', $x->query($card . '//a[.="Sharing"]/@aria-label')->item(0)?->nodeValue);
        self::assertSame('/projects/' . $home . '/sharing', $x->query($card . '//a[.="Sharing"]/@href')->item(0)?->nodeValue);

        self::assertSame(['None yet', '0', '2 Mar 2026, by Example Admin', '1 person'], $this->texts($x, '//li[h2/a="Recipes"]/dl/dd'));

        $broken = '//li[h2="Garden Log"]';
        self::assertSame(0, $x->query($broken . '/h2/a')->length, 'No link to a project that cannot be opened.');
        self::assertSame(0, $x->query($broken . '/dl')->length);
        self::assertSame(
            "TiffinDB cannot open this project's file. The person who looks after this TiffinDB can check the data folder.",
            HtmlAssertions::text($html, $broken . '/p[@class="notice notice-warn"]'),
        );
        self::assertSame(['Sharing'], $this->texts($x, $broken . '//a'));

        self::assertStringStartsWith('Signed in as an Admin.', HtmlAssertions::text($html, '//p[@class="notice notice-muted"]'));
        self::assertSame('page', $x->query('//nav[@class="top-nav"]/a[.="Projects"]/@aria-current')->item(0)?->nodeValue);
    }

    public function testMemberSeesOnlySharedProjectsAndNoAdminControls(): void
    {
        $this->project('Home Inventory', 'Items');
        $recipes = $this->project('Recipes');
        $this->ops->projects()->setSharing($this->core->actor('admin'), $recipes, [$this->core->person('member')->key]);

        $html = $this->page(self::MEMBER, '/projects');
        $x = $this->xpath($html);

        self::assertSame(['Recipes'], $this->texts($x, '//li/h2'));
        self::assertSame(0, $x->query('//div[@class="page-actions"]')->length, 'No New project.');
        self::assertSame(['Tables', 'Records', 'Last changed'], $this->texts($x, '//dl/dt'));
        self::assertSame(['Open'], $this->texts($x, '//li//a[not(ancestor::h2)]'));
        self::assertSame(0, $x->query('//p[contains(@class, "notice-muted")]')->length, 'No Admin note.');
        self::assertStringNotContainsString('/sharing', $html);
    }

    public function testEmptyProjectLists(): void
    {
        $admin = $this->page(self::ADMIN, '/projects');
        self::assertSame(0, $this->xpath($admin)->query('//ul[@class="project-grid"]')->length);
        self::assertSame('There are no projects yet. Select New project to make the first one.', HtmlAssertions::text($admin, '//p[@class="notice"]'));

        $member = $this->page(self::MEMBER, '/projects');
        self::assertSame(
            'No projects have been shared with you yet. Ask the person who looks after this TiffinDB to share one with you.',
            HtmlAssertions::text($member, '//p[@class="notice"]'),
        );
    }

    public function testFlashNoticeOnTheProjectList(): void
    {
        $this->services->flash[self::ADMIN] = ['key' => 'notice.project_created', 'params' => ['name' => 'Recipes']];
        $html = $this->page(self::ADMIN, '/projects');

        self::assertStringContainsString('Recipes', HtmlAssertions::text($html, '//main/div[@class="notice"][@role="status"]'));
    }

    public function testProjectNamesAreEscaped(): void
    {
        $this->project('<b>Tom & "Jerry"</b>');
        $html = $this->page(self::ADMIN, '/projects');

        self::assertStringNotContainsString('<b>Tom', $html);
        self::assertStringContainsString('&lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;', $html);
        self::assertSame('<b>Tom & "Jerry"</b>', HtmlAssertions::text($html, '//h2/a'));
    }

    public function testAnUnknownLastChangePersonShowsTheDateOnly(): void
    {
        $key = $this->project('Recipes');
        $this->core->projectDb($key)->run("UPDATE \"_tiffin_meta\" SET \"value\" = 'NOBODY000001' WHERE \"key\" = 'updated_by'");

        $html = $this->page(self::ADMIN, '/projects');
        self::assertSame(['None yet', '0', '2 Mar 2026', 'Admins only'], $this->texts($this->xpath($html), '//dl/dd'));
    }

    // New project.

    public function testNewProjectForm(): void
    {
        $html = $this->page(self::ADMIN, '/projects/new');
        $x = $this->xpath($html);

        self::assertSame('New project - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'New project'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame('New project', HtmlAssertions::text($html, '//h1'));
        self::assertSame('A project is one database. Add tables to it, then share it with the people who will use it.', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame('/projects', $x->query('//form[@class="form form-card form-wide"]/@action')->item(0)?->nodeValue);
        self::assertSame('Project name', HtmlAssertions::text($html, '//label[@for="project-name"]'));
        self::assertSame('For example Home Inventory or Recipes.', HtmlAssertions::text($html, '//p[@id="project-name-help"]'));
        self::assertSame('First table (optional)', HtmlAssertions::text($html, '//label[@for="project-first-table"]'));
        self::assertSame('project-name-help', $x->query('//input[@id="project-name"]/@aria-describedby')->item(0)?->nodeValue);
        self::assertSame('required', $x->query('//input[@id="project-name"]/@required')->item(0)?->nodeValue);
        self::assertSame(['Create project'], $this->texts($x, '//main//form//button'));
        self::assertSame('/projects', $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//div[@class="error-summary"]')->length);
    }

    public function testNewProjectIsForAdminsOnly(): void
    {
        self::assertSame(403, $this->request(self::MEMBER, 'GET', '/projects/new')->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', '/projects', ['name' => 'Recipes'])->status);
        self::assertSame([], $this->ops->projects()->listFor($this->core->actor('admin')));
    }

    public function testNewProjectErrorsKeepValuesAndMapToFields(): void
    {
        $response = $this->request(self::ADMIN, 'POST', '/projects', ['name' => '  ', 'first_table' => str_repeat('x', 61)]);
        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame('Error: New project - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['#project-name', '#project-first-table'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Enter a name.', HtmlAssertions::text($html, '//p[@id="project-name-error"]'));
        self::assertSame('Use at most 60 characters.', HtmlAssertions::text($html, '//p[@id="project-first-table-error"]'));
        self::assertSame('true', $x->query('//input[@id="project-name"]/@aria-invalid')->item(0)?->nodeValue);
        self::assertSame('project-first-table-help project-first-table-error', $x->query('//input[@id="project-first-table"]/@aria-describedby')->item(0)?->nodeValue);
        self::assertSame(str_repeat('x', 61), $x->query('//input[@id="project-first-table"]/@value')->item(0)?->nodeValue);
        // The summary comes after the breadcrumb and before the h1.
        self::assertSame(['nav', 'div', 'div', 'form'], $this->names($x, '//main/*'));
    }

    public function testNewProjectNameAlreadyUsed(): void
    {
        $this->project('Recipes');
        $response = $this->request(self::ADMIN, 'POST', '/projects', ['name' => 'Recipes', 'first_table' => '']);

        self::assertSame(422, $response->status);
        self::assertSame('Another project already has this name.', HtmlAssertions::text($response->body, '//p[@id="project-name-error"]'));
        self::assertSame('Recipes', $this->xpath($response->body)->query('//input[@id="project-name"]/@value')->item(0)?->nodeValue);
    }

    public function testCreatingAProject(): void
    {
        $response = $this->request(self::ADMIN, 'POST', '/projects', ['name' => 'Recipes', 'first_table' => '']);
        $key = $this->keyOf('Recipes');

        self::assertSame(303, $response->status);
        self::assertSame('/projects/' . $key . '/tables', $response->header('Location'));
        self::assertSame(['key' => 'notice.project_created', 'params' => ['name' => 'Recipes']], $this->services->flash[self::ADMIN]);
        self::assertSame([], $this->ops->schema()->tables($this->core->actor('admin'), $key));
    }

    public function testCreatingAProjectWithAFirstTable(): void
    {
        $response = $this->request(self::ADMIN, 'POST', '/projects', ['name' => ' Home Inventory ', 'first_table' => ' Items ']);

        self::assertSame(303, $response->status);
        self::assertSame(
            ['key' => 'notice.project_created_table', 'params' => ['name' => 'Home Inventory', 'table' => 'Items']],
            $this->services->flash[self::ADMIN],
        );
        $tables = $this->ops->schema()->tables($this->core->actor('admin'), $this->keyOf('Home Inventory'));
        self::assertSame('Items', $tables[0]->table->name);
    }

    public function testOpenWithoutTablesRedirectsToTheTables(): void
    {
        $key = $this->project('Recipes');

        self::assertSame('/projects/' . $key . '/tables', $this->request(self::ADMIN, 'GET', '/projects/' . $key)->header('Location'));
        self::assertSame(404, $this->request(self::MEMBER, 'GET', '/projects/' . $key)->status, 'Not shared: not found.');
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/projects/NOSUCHKEY000')->status);
    }

    public function testOpenRedirectsToTheFirstTableList(): void
    {
        $key = $this->project('Home Inventory', 'Items');
        $admin = $this->core->actor('admin');
        $this->ops->schema()->createTable($admin, $key, 'Rooms', 'room', true);
        $first = $this->ops->schema()->tables($admin, $key)[0]->table;
        self::assertSame('Items', $first->name);

        $response = $this->request(self::ADMIN, 'GET', '/projects/' . $key . '?sort=added.desc');
        self::assertSame(303, $response->status);
        self::assertSame('/projects/' . $key . '/tables/' . $first->key, $response->header('Location'), 'No list state is carried.');
        self::assertSame(404, $this->request(self::MEMBER, 'GET', '/projects/' . $key)->status, 'Not shared: not found.');
    }

    // Project settings.

    public function testProjectSettingsForm(): void
    {
        $key = $this->project('Recipes');
        $html = $this->page(self::ADMIN, '/projects/' . $key . '/settings');
        $x = $this->xpath($html);

        self::assertSame('Project settings - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'Recipes', 'Settings'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame('/projects/' . $key . '/tables', $x->query('//nav[@class="breadcrumb"]/a[.="Recipes"]/@href')->item(0)?->nodeValue);
        self::assertSame('Project settings', HtmlAssertions::text($html, '//h1'));
        self::assertSame('Recipes', $x->query('//input[@id="project-name"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Save'], $this->texts($x, '//form[@class="form form-card form-wide"]//button'));
        self::assertSame('/projects/' . $key . '/tables', $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
    }

    public function testProjectSettingsAreForAdminsOnly(): void
    {
        $key = $this->project('Recipes');
        $this->ops->projects()->setSharing($this->core->actor('admin'), $key, [$this->core->person('member')->key]);

        self::assertSame(403, $this->request(self::MEMBER, 'GET', '/projects/' . $key . '/settings')->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', '/projects/' . $key . '/settings', ['name' => 'Other'])->status);
        self::assertSame('Recipes', $this->ops->projects()->get($this->core->actor('admin'), $key)->name);
    }

    public function testRenamingAProject(): void
    {
        $key = $this->project('Recipes');
        $response = $this->request(self::ADMIN, 'POST', '/projects/' . $key . '/settings', ['name' => 'Family Recipes']);

        self::assertSame(303, $response->status);
        self::assertSame('/projects/' . $key . '/tables', $response->header('Location'));
        self::assertSame(['key' => 'notice.project_renamed', 'params' => ['name' => 'Family Recipes']], $this->services->flash[self::ADMIN]);
        self::assertSame('Family Recipes', $this->ops->projects()->get($this->core->actor('admin'), $key)->name);
    }

    public function testRenamingErrors(): void
    {
        $this->project('Garden Log');
        $key = $this->project('Recipes');
        $response = $this->request(self::ADMIN, 'POST', '/projects/' . $key . '/settings', ['name' => 'Garden Log']);

        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertSame('Another project already has this name.', HtmlAssertions::text($response->body, '//p[@id="project-name-error"]'));
        self::assertSame('Garden Log', $this->xpath($response->body)->query('//input[@id="project-name"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Projects', 'Recipes', 'Settings'], $this->texts($this->xpath($response->body), '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
    }

    // Helpers.

    private function signedIn(string $username): SignedIn
    {
        $actor = $this->core->actor($username);

        return new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
    }

    private function project(string $name, ?string $firstTable = null): string
    {
        return $this->ops->projects()->create($this->core->actor('admin'), $name, $firstTable)->key;
    }

    private function keyOf(string $name): string
    {
        foreach ($this->ops->projects()->listFor($this->core->actor('admin')) as $summary) {
            if ($summary->project->name === $name) {
                return $summary->project->key;
            }
        }
        self::fail("No project {$name}.");
    }

    /** @param array<string, string> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

    /** A 200 page, checked against the shell and form conventions. */
    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, 'GET', $uri);
        self::assertSame(200, $response->status, $uri);
        $this->assertScreen($response->body);

        return $response->body;
    }

    private function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
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
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }

    /** @return list<string> */
    private function names(DOMXPath $x, string $query): array
    {
        $names = [];
        foreach ($x->query($query) as $node) {
            $names[] = $node->nodeName;
        }

        return $names;
    }
}
