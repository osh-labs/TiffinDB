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
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Storage\ProjectLock;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\LockHolder;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\ProjectController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-88, T-WEB-94, and T-WEB-90 (deletion routes) of the M7 screens
 * spec section 11.1 (M7-WP4): the project settings links and the
 * deletion page of docs/design/m7/project-delete.md, through the kernel
 * with the real templates and strings and real core operations over a
 * CoreFixture instance. The page with its counts and the typed-name
 * field; a wrong, empty, malformed, or tampered name (422, the field
 * error, the typed text kept and escaped, nothing deleted); grown counts
 * (the page again with the notice); a missing folder (P239); a server
 * that cannot lock files and a project in use (nothing deleted); the
 * deletion (303 to the project list with the notice, the project gone);
 * a Member's 403, an ungranted person's 404, and every refused POST
 * changing nothing. Synthetic data only.
 */
#[CoversClass(ProjectController::class)]
final class ProjectDeleteScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const STRANGER = 'tokenCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
    private const NAME = 'Tools & "Spares"';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $itemName;
    /** @var list<LockHolder> */
    private array $holders = [];

    protected function setUp(): void
    {
        ProjectLock::$waitScaleForTests = 0.02;
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->core->addPerson('stranger', 'Example Stranger', Role::Member);
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->services->sessions[self::STRANGER] = $this->signedIn('stranger');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        $this->project = $this->ops->projects()->create($this->admin(), self::NAME, 'Items')->key;
        $this->items = $this->ops->schema()->tables($this->admin(), $this->project)[0]->table->key;
        $this->itemName = $this->ops->schema()->design($this->admin(), $this->project, $this->items)->fields[0]->field->key;
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    protected function tearDown(): void
    {
        foreach ($this->holders as $holder) {
            $holder->stop();
        }
        ProjectLock::$waitScaleForTests = 1.0;
        ProjectLock::$flockDisabledForTests = false;
        $this->core->remove();
    }

    // Project settings (components.md section 4).

    public function testProjectSettingsLinkToSnapshotsAndDeletion(): void
    {
        $x = $this->xpath($this->page(self::ADMIN, $this->p() . '/settings'));

        self::assertSame(['More for this project'], $this->texts($x, '//main/h2'));
        $links = '//main/ul[@class="settings-links"]/li';
        self::assertSame(['Snapshots', 'Delete this project'], $this->texts($x, $links . '/a'));
        self::assertSame([$this->p() . '/snapshots', $this->p() . '/delete'], $this->texts($x, $links . '/a/@href'));
        self::assertNull($this->attr($x, $links . '[1]/a', 'class'));
        self::assertSame('link-danger', $this->attr($x, $links . '[2]/a', 'class'));
        self::assertSame(
            ['Saved copies of the project, and going back to one.', 'Removes the project, its files, and its snapshots for good.'],
            $this->texts($x, $links . '/span[@class="help"]'),
        );
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->p() . '/settings')->status);
    }

    // The page (project-delete.md section 2).

    public function testThePageWithItsCounts(): void
    {
        $this->add('Stand mixer');
        $this->add('Kettle');
        $this->trash($this->add('Old lamp'));
        $field = $this->ops->schema()->addField($this->admin(), $this->project, $this->items, 'Photo', 'attachment', [], false, '')->key;
        $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $this->add('Drill'), $field, $this->core->upload(str_repeat('e', 2000)), 'drill.txt', null);
        $this->ops->snapshots()->take($this->admin(), $this->project);

        $html = $this->page(self::ADMIN, $this->p() . '/delete');
        $x = $this->xpath($html);
        self::assertSame('Delete Tools & "Spares"? - TiffinDB', $this->text($x, '//title'));
        self::assertStringContainsString('<h1>Delete Tools &amp; &quot;Spares&quot;?</h1>', $html);
        self::assertSame([self::NAME, 'Delete'], $this->crumbs($x));
        self::assertSame([$this->p()], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('page page-record page-record-narrow', $this->attr($x, '//main', 'class'));
        self::assertSame('This deletes the project for good. It cannot be undone in TiffinDB.', $this->text($x, '//main/p[@class="lead"]'));
        self::assertSame(['Records', 'In the trash', 'Stored files', 'Snapshots', 'Shared with'], $this->texts($x, '//main/dl[@class="facts"]/div/dt'));
        self::assertSame(['3 records', '1 record', '1 file, 2 KB', '1 snapshot', '1 Member'], $this->texts($x, '//main/dl[@class="facts"]/div/dd'));
        self::assertStringStartsWith('Its records, tables, forms, collections, stored files, and snapshots are removed', $this->text($x, '//main/p[not(@class)]'));
        self::assertSame(
            'Keep a copy first: download a project archive from the export page. A project archive can be imported again later. Go to Export',
            $this->text($x, '//main/div[@class="notice"][@role="status"]/p'),
        );
        self::assertSame($this->p() . '/export', $this->attr($x, '//main/div[@class="notice"]/p/a', 'href'));
        self::assertSame(0, $x->query('//div[contains(@class, "notice-warn")] | //div[@class="error-summary"]')->length);

        $form = '//main/div[@class="danger-zone"]/form';
        self::assertSame($this->p() . '/delete', $this->attr($x, $form, 'action'));
        self::assertSame(['_csrf' => FakeServices::SESSION_CSRF, 'expected' => '4'], $this->hidden($x, $form));
        self::assertSame('Type the project\'s name to confirm', $this->text($x, $form . '//label[@for="confirm-name"]'));
        self::assertSame('Type Tools & "Spares".', $this->text($x, $form . '//p[@id="confirm-name-help"]'));
        $input = $form . '//input[@id="confirm-name"]';
        foreach (['type' => 'text', 'name' => 'confirm_name', 'value' => '', 'autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'aria-describedby' => 'confirm-name-help'] as $name => $value) {
            self::assertSame($value, $this->attr($x, $input, $name), $name);
        }
        self::assertNull($this->attr($x, $input, 'required'), 'The button is never blocked; the core checks the name.');
        self::assertSame(['Delete for good'], $this->texts($x, $form . '/div[@class="form-actions"]/button[@class="button button-large button-danger"][@type="submit"]'));
        self::assertSame($this->p() . '/settings', $this->attr($x, $form . '/div[@class="form-actions"]/a[@class="form-cancel"]', 'href'));
        self::assertSame(0, $x->query('//button[contains(@class, "button-primary")] | //button[@disabled]')->length);
    }

    public function testAWrongNameIsRefusedAndKept(): void
    {
        $this->add('Stand mixer');
        $kept = [
            'Tools' => 'Tools', '' => '', '   ' => '   ', '<b>"x"</b>' => '<b>"x"</b>', 'Tools &amp; "Spares"' => 'Tools &amp; "Spares"',
            "Tools & \"Spares\"\xFF" => "Tools & \"Spares\"\u{FFFD}", 'Tools "Spares"' => 'Tools "Spares"',
        ];
        foreach ($kept as $typed => $shown) {
            $typed = (string) $typed;
            $response = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => $typed, 'expected' => '1']);
            self::assertSame(422, $response->status, $typed);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame('Error: Delete Tools & "Spares"? - TiffinDB', $this->text($x, '//title'));
            self::assertSame(['#confirm-name'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
            self::assertSame(['Type the project\'s name exactly as it is shown above.'], $this->texts($x, '//div[@class="error-summary"]//li'));
            self::assertSame('Type the project\'s name exactly as it is shown above.', $this->text($x, '//p[@class="error-text"][@id="confirm-name-error"]'));
            $input = '//input[@id="confirm-name"]';
            self::assertSame('confirm-name-help confirm-name-error', $this->attr($x, $input, 'aria-describedby'));
            self::assertSame('true', $this->attr($x, $input, 'aria-invalid'));
            self::assertSame($shown, $this->attr($x, $input, 'value'), 'The typed text is kept.');
            self::assertSame(0, $x->query('//main//b')->length, 'Typed markup stays text.');
            self::assertTrue($this->exists(), $typed);
        }
        // Tampered fields: a list, or no field at all.
        foreach ([['confirm_name' => ['x'], 'expected' => '1'], ['expected' => '1'], ['confirm_name' => [self::NAME], 'expected' => ['1']]] as $post) {
            self::assertSame(422, $this->request(self::ADMIN, 'POST', $this->p() . '/delete', $post)->status);
        }
        self::assertTrue($this->exists());
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash);
    }

    public function testGrownCountsAskAgain(): void
    {
        $this->add('Stand mixer');
        $this->page(self::ADMIN, $this->p() . '/delete');
        $this->trash($this->add('Kettle'));

        $response = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => self::NAME, 'expected' => '1']);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('The project changed while you were looking. Check the new numbers.', $this->text($x, '//main/div[@class="notice notice-warn"]/p'));
        self::assertSame(['1 record', '1 record'], array_slice($this->texts($x, '//main/dl[@class="facts"]/div/dd'), 0, 2));
        self::assertSame('2', $this->hidden($x, '//main//form')['expected']);
        self::assertSame(self::NAME, $this->attr($x, '//input[@id="confirm-name"]', 'value'));
        self::assertTrue($this->exists());

        foreach (['', 'x', '-1', '1e3', '0x10', ' 2', '99999999999'] as $tampered) {
            $again = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => self::NAME, 'expected' => $tampered]);
            self::assertSame(200, $again->status, "expected={$tampered}");
            self::assertTrue($this->exists());
        }
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash);
    }

    public function testTheNameIgnoresCaseAndSpacesAndTheProjectGoes(): void
    {
        $this->add('Stand mixer');
        $folder = $this->core->projectFolder($this->project);
        self::assertDirectoryExists($folder);

        $response = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => '  tools & "SPARES"  ', 'expected' => '1']);
        self::assertSame(303, $response->status);
        self::assertSame('/projects', $response->header('Location'));
        self::assertSame(['key' => 'notice.project_deleted', 'params' => ['name' => self::NAME]], $this->services->flash[self::ADMIN]);
        self::assertFalse($this->exists());
        self::assertDirectoryDoesNotExist($folder);

        $x = $this->xpath($this->page(self::ADMIN, '/projects'));
        self::assertSame('Tools & "Spares" has been deleted.', $this->text($x, '//main/div[@class="notice"][@role="status"]/p'));
        foreach ([['GET', '/delete'], ['POST', '/delete'], ['GET', '/settings'], ['GET', '/snapshots']] as [$method, $route]) {
            self::assertSame(404, $this->request(self::ADMIN, $method, $this->p() . $route, ['confirm_name' => self::NAME, 'expected' => '1'])->status, $route);
        }
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->p() . '/tables')->status, 'The Member lost access.');
    }

    public function testAMissingFolderCanStillBeDeleted(): void
    {
        $folder = $this->core->projectFolder($this->project);
        self::assertTrue(rename($folder, $this->core->temp->path('moved-away')));

        $x = $this->xpath($this->page(self::ADMIN, $this->p() . '/delete'));
        self::assertSame(
            'This project\'s folder is not in the data folder, so there is nothing to remove from the server. Deleting removes the project from the list and from everyone\'s access.',
            $this->text($x, '//main/div[@class="notice notice-warn"][@role="alert"]/p'),
        );
        self::assertSame(0, $x->query('//main/dl | //main/p[not(@class)] | //main/div[@class="notice"]')->length);
        self::assertSame('0', $this->hidden($x, '//main//form')['expected']);

        $response = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => self::NAME, 'expected' => '0']);
        self::assertSame(303, $response->status);
        self::assertFalse($this->exists());
        self::assertDirectoryExists($this->core->temp->path('moved-away'), 'Nothing outside the project folder is touched.');
    }

    public function testNothingIsDeletedWhereLocksDoNotWork(): void
    {
        $this->add('Stand mixer');
        $this->ops->close();
        ProjectLock::$flockDisabledForTests = true;

        [$response] = $this->handle(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => self::NAME, 'expected' => '1']);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame(
            ['This server cannot lock files, so TiffinDB cannot do this safely here. Nothing was changed. The host documentation describes how to do it by hand.'],
            $this->texts($x, '//div[@class="error-summary"]//li'),
        );
        self::assertSame(0, $x->query('//p[@class="error-text"]')->length, 'Not a field problem.');
        ProjectLock::$flockDisabledForTests = false;
        self::assertTrue($this->exists());
        self::assertDirectoryExists($this->core->projectFolder($this->project));
    }

    public function testNothingIsDeletedWhileTheProjectIsInUse(): void
    {
        $this->add('Stand mixer');
        $this->ops->close();
        $holder = LockHolder::start('shared', $this->core->projectFolder($this->project));
        $this->holders[] = $holder;
        self::assertSame('held', $holder->answer);

        $response = $this->request(self::ADMIN, 'POST', $this->p() . '/delete', ['confirm_name' => self::NAME, 'expected' => '1']);
        self::assertSame(503, $response->status);
        self::assertNotNull($response->header('Retry-After'));
        $holder->stop();
        self::assertTrue($this->exists());
        self::assertSame(200, $this->request(self::ADMIN, 'GET', $this->p() . '/delete')->status, 'The project is active again.');
    }

    // Access and hostile requests.

    public function testMembersAreRefusedAndUngrantedPeopleFindNothing(): void
    {
        $post = ['confirm_name' => self::NAME, 'expected' => '0'];
        foreach (['GET', 'POST'] as $method) {
            self::assertSame(403, $this->request(self::MEMBER, $method, $this->p() . '/delete', $post)->status, "Member {$method}");
            self::assertSame(404, $this->request(self::STRANGER, $method, $this->p() . '/delete', $post)->status, "Stranger {$method}");
            self::assertSame(404, $this->request(self::ADMIN, $method, '/projects/aaaaaaaaaaaa/delete', $post)->status, "Unknown {$method}");
        }
        self::assertTrue($this->exists());
        self::assertSame([], $this->services->flash);
    }

    public function testRefusedPostsDeleteNothing(): void
    {
        $uri = $this->p() . '/delete';
        $post = ['confirm_name' => self::NAME, 'expected' => '0'];
        foreach ([null, '', str_repeat('b', 64), ['x']] as $token) {
            $values = $token === null ? $post : $post + ['_csrf' => $token];
            $r = Fixtures::fullRequest('POST', $uri, $values, ['tdb_session' => self::ADMIN]);
            self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status);
        }
        foreach (['https://evil.example', 'null', "http://tiffindb.example\t", "http://tiffindb.example\xFF", 'http://tiffindb.example/' . str_repeat('a', 2048)] as $origin) {
            $server = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => $origin];
            $r = Fixtures::fullRequest('POST', $uri, $post + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $server);
            self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status, $origin);
        }
        $site = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
        $r = Fixtures::fullRequest('POST', $uri, $post + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $site);
        self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status);
        self::assertTrue($this->exists());
        self::assertSame([], $this->services->flash);

        $own = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => 'http://tiffindb.example'];
        $r = Fixtures::fullRequest('POST', $uri, $post + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $own);
        self::assertSame(303, Fixtures::handle($this->kernel, $r)[0]->status);
        self::assertFalse($this->exists());
    }

    // Helpers.

    private function admin(): Actor
    {
        return $this->core->actor('admin');
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

    private function add(string $name): int
    {
        return $this->ops->records()->create($this->admin(), $this->project, $this->items, [$this->itemName => $name]);
    }

    private function trash(int $id): void
    {
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $id);
    }

    /** Whether the Admin still sees the project. */
    private function exists(): bool
    {
        foreach ($this->ops->projects()->listFor($this->admin()) as $summary) {
            if ($summary->project->key === $this->project) {
                return true;
            }
        }

        return false;
    }

    private function p(): string
    {
        return '/projects/' . $this->project;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: Response, 1: string}
     */
    private function handle(string $token, string $method, string $uri, array $post = []): array
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        } else {
            $post = [];
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]));
    }

    /** @param array<string, mixed> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        [$response, $log] = $this->handle($token, $method, $uri, $post);
        self::assertNotSame(500, $response->status, "{$method} {$uri}: {$log}");

        return $response;
    }

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

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $x->query($query)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, $query);

        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /** @return array<string, string> the hidden inputs of a form, by name */
    private function hidden(DOMXPath $x, string $form): array
    {
        $values = [];
        foreach ($x->query($form . '//input[@type="hidden"]') as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $values;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $texts = $this->texts($x, $query);
        self::assertNotSame([], $texts, $query);

        return $texts[0];
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
            if ($text !== '' || !$node instanceof \DOMText) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
