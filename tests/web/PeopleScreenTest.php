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
use TiffinDB\Core\Auth\Role;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\Controller\PeopleController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP10: the people screens (docs/design/m1/people-and-sharing.md
 * sections 2 to 5) through the kernel with the real templates, strings,
 * and core operations over a CoreFixture. Synthetic data only.
 */
#[CoversClass(PeopleController::class)]
final class PeopleScreenTest extends TestCase
{
    private const LICENCE_TEXT = 'TiffinDB, free software under AGPL-3.0-or-later';
    private const TEMPORARY = 'temporary words 42';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private string $admin;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->admin = $this->signInAs('admin');
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ---------------------------------------------------------------- harness

    /** @param array<string, mixed> $post */
    private function send(string $method, string $uri, array $post = [], ?string $token = null): Response
    {
        $token ??= $this->admin;
        if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = $this->services->sessions[$token]->session->csrfToken;
        }
        $kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        [$response] = Fixtures::handle($kernel, Fixtures::fullRequest($method, $uri, $post, [Context::SESSION_COOKIE => $token]));

        return $response;
    }

    private function signInAs(string $username): string
    {
        $token = $this->core->signIn($this->ops, $username)->token();
        $signedIn = $this->ops->auth()->resume($token);
        self::assertNotNull($signedIn);
        $this->services->withSession($signedIn, $token);

        return $token;
    }

    private function key(string $username): string
    {
        return $this->core->person($username)->key;
    }

    private function project(string $name): string
    {
        return $this->ops->projects()->create($this->core->actor('admin'), $name, null)->key;
    }

    private static function assertRedirect(Response $response, string $location): void
    {
        self::assertSame(303, $response->status, $response->body);
        self::assertSame($location, $response->header('Location'));
    }

    private static function assertPage(Response $response, int $status, string $title, string $h1): DOMXPath
    {
        self::assertSame($status, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, self::LICENCE_TEXT, 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame($title, HtmlAssertions::text($response->body, '//title'));
        self::assertSame($h1, HtmlAssertions::text($response->body, '//h1'));
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
        self::assertSame(1, $x->query('//nav[@class="top-nav"]/a[@href="/admin/people"][@aria-current="page"]')->length, 'nav = people.');
        self::assertSame(0, $x->query('//script')->length, 'No script on the people screens.');

        return $x;
    }

    /** @return list<string> */
    private static function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $texts;
    }

    /** @return list<array{?string, string}> [href or null, text] */
    private static function summary(DOMXPath $x): array
    {
        $items = [];
        foreach ($x->query('//div[@class="error-summary"]//li') as $li) {
            $a = $x->query('./a', $li)->item(0);
            $items[] = [$a?->getAttribute('href'), trim($li->textContent)];
        }

        return $items;
    }

    private function flash(string $token = ''): ?array
    {
        return $this->services->flash[$token === '' ? $this->admin : $token] ?? null;
    }

    // ---------------------------------------------------------------- access

    public function testMembersGetTheForbiddenPage(): void
    {
        $member = $this->signInAs('member');
        $key = $this->key('member2');
        $requests = [
            ['GET', '/admin/people'], ['GET', '/admin/people/new'], ['POST', '/admin/people'],
            ['GET', "/admin/people/{$key}"], ['POST', "/admin/people/{$key}"], ['POST', "/admin/people/{$key}/password"],
            ['POST', "/admin/people/{$key}/projects"], ['GET', "/admin/people/{$key}/deactivate"],
            ['POST', "/admin/people/{$key}/deactivate"], ['POST', "/admin/people/{$key}/reactivate"],
        ];
        foreach ($requests as [$method, $uri]) {
            $response = $this->send($method, $uri, ['display_name' => 'Example Intruder', 'username' => 'intruder', 'role' => 'admin', 'temporary_password' => self::TEMPORARY], $member);
            self::assertSame(403, $response->status, "{$method} {$uri}");
        }
        self::assertNull($this->core->users->byUsername('intruder'));
        self::assertTrue($this->core->person('member2')->active);
    }

    public function testUnknownPersonIsNotFound(): void
    {
        foreach ([['GET', '/admin/people/ZZZZZZZZZZZZ'], ['GET', '/admin/people/nope/deactivate'], ['POST', '/admin/people/ZZZZZZZZZZZZ/reactivate']] as [$method, $uri]) {
            self::assertSame(404, $this->send($method, $uri)->status, $uri);
        }
    }

    // ---------------------------------------------------------------- list

    public function testPeopleList(): void
    {
        $garden = $this->project('Garden Log');
        $this->project('Recipes');
        $this->ops->people()->setProjectAccess($this->core->actor('admin'), $this->key('member'), [$garden]);
        $this->ops->people()->deactivate($this->core->actor('admin'), $this->key('member2'));

        $response = $this->send('GET', '/admin/people');
        $x = self::assertPage($response, 200, 'People - TiffinDB', 'People');

        self::assertSame('Everyone who can sign in to this TiffinDB.', HtmlAssertions::text($response->body, '//div[@class="page-head"]//p[@class="lead"]'));
        $add = $x->query('//div[@class="page-actions"]/a[@class="button button-primary"]')->item(0);
        self::assertSame('/admin/people/new', $add?->getAttribute('href'));
        self::assertSame('Add a person', trim((string) $add?->textContent));
        self::assertSame(['People who can sign in, and their roles'], self::texts($x, '//table[@class="data-table"]/caption[@class="visually-hidden"]'));
        self::assertSame(['Name', 'Username', 'Role', 'Projects', 'Signing in'], self::texts($x, '//thead//th[@scope="col"]'));
        $rows = [];
        foreach ($x->query('//tbody/tr') as $tr) {
            $rows[] = array_map(
                static fn ($n): string => trim((string) preg_replace('/\s+/u', ' ', $n->textContent)),
                iterator_to_array($x->query('./*', $tr)),
            );
        }
        self::assertSame([
            ['Another Member', 'member2', 'Member', 'None', 'Stopped'],
            ['Example Admin (you)', 'admin', 'Admin', 'Every project', 'Can sign in'],
            ['Example Member', 'member', 'Member', '1 project', 'Can sign in'],
        ], $rows);
        self::assertSame('/admin/people/' . $this->key('admin'), $x->query('//tbody/tr[2]/th[@scope="row"]/a')->item(0)?->getAttribute('href'));
        self::assertSame(
            'There must always be at least one Admin who can sign in. Two Admins are safer: if one forgets their password, the other can set a temporary one.',
            HtmlAssertions::text($response->body, '//p[@class="notice notice-muted"]'),
        );
    }

    public function testProjectCountForSeveralProjects(): void
    {
        $a = $this->project('Garden Log');
        $b = $this->project('Recipes');
        $this->ops->people()->setProjectAccess($this->core->actor('admin'), $this->key('member'), [$a, $b]);
        $response = $this->send('GET', '/admin/people');
        self::assertStringContainsString('<td>2 projects</td>', $response->body);
    }

    public function testFlashNoticeOnTheList(): void
    {
        $this->services->setFlash($this->admin, 'notice.person_stopped', ['name' => 'Example Member']);
        $response = $this->send('GET', '/admin/people');
        self::assertSame('Example Member can no longer sign in.', HtmlAssertions::text($response->body, '//main/div[@class="notice"][@role="status"]'));
    }

    // ---------------------------------------------------------------- add a person

    public function testAddAPersonForm(): void
    {
        $this->project('Recipes');
        $this->project('Garden Log');
        $response = $this->send('GET', '/admin/people/new');
        $x = self::assertPage($response, 200, 'Add a person - TiffinDB', 'Add a person');

        self::assertSame(['People', '/', 'Add a person'], self::texts($x, '//nav[@class="breadcrumb"]/*'));
        self::assertSame('/admin/people', $x->query('//nav[@class="breadcrumb"]/a')->item(0)?->getAttribute('href'));
        $form = '//form[@class="form form-card form-wide"][@action="/admin/people"]';
        self::assertSame(1, $x->query($form)->length);
        self::assertSame('Shown to others, for example Example Member.', HtmlAssertions::text($response->body, '//p[@id="person-name-help"]'));
        self::assertSame('Used to sign in. Letters, numbers, dots, dashes, and underscores.', HtmlAssertions::text($response->body, '//p[@id="person-username-help"]'));
        self::assertSame('display_name', $x->query('//input[@id="person-name"]')->item(0)?->getAttribute('name'));
        self::assertSame('input input-medium', $x->query('//input[@id="person-username"]')->item(0)?->getAttribute('class'));
        self::assertSame(['Role', 'Projects they can use'], self::texts($x, '//fieldset/legend[@class="legend-plain"]'));
        self::assertSame(
            ['MemberCan view and change records in the projects shared with them.', 'AdminCan also add people, create and share projects, and change tables and fields.'],
            self::texts($x, '//div[@class="radio-grid"]/label[@class="radio-card"]'),
        );
        self::assertSame('checked', $x->query('//input[@id="person-role"][@value="member"]')->item(0)?->getAttribute('checked'));
        $password = $x->query('//input[@id="person-password"]')->item(0);
        self::assertSame('text', $password?->getAttribute('type'));
        self::assertSame('temporary_password', $password?->getAttribute('name'));
        self::assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', (string) $password?->getAttribute('value'));
        self::assertSame(['Garden Log', 'Recipes'], self::texts($x, '//ul[@class="check-list"]/li/label[@class="check"]'));
        self::assertSame('Admins can use every project, so this matters only for Members.', HtmlAssertions::text($response->body, '//fieldset[2]/p[@class="help"]'));
        self::assertSame('Add person', HtmlAssertions::text($response->body, $form . '//div[@class="form-actions"]/button[@class="button button-large button-primary"]'));
        self::assertSame('/admin/people', $x->query($form . '//div[@class="form-actions"]/a')->item(0)?->getAttribute('href'));
    }

    public function testAddAPersonFormWithNoProjects(): void
    {
        $response = $this->send('GET', '/admin/people/new');
        $x = self::assertPage($response, 200, 'Add a person - TiffinDB', 'Add a person');
        self::assertSame(0, $x->query('//ul[@class="check-list"]')->length);
        self::assertSame(['There are no projects yet.'], self::texts($x, '//fieldset[2]/p[not(@class)]'));
    }

    public function testAddAPerson(): void
    {
        $garden = $this->project('Garden Log');
        $response = $this->send('POST', '/admin/people', [
            'display_name' => 'Example Newcomer', 'username' => 'newcomer', 'role' => 'member',
            'temporary_password' => self::TEMPORARY, 'projects' => [$garden],
        ]);
        $person = $this->core->users->byUsername('newcomer');
        self::assertNotNull($person);
        self::assertRedirect($response, '/admin/people/' . $person->key);
        self::assertTrue($person->mustChangePassword);
        self::assertSame(Role::Member, $person->role);
        self::assertSame(['key' => 'notice.person_added', 'params' => ['name' => 'Example Newcomer']], $this->flash());
        $access = $this->ops->people()->projectAccess($this->core->actor('admin'), $person->key);
        self::assertTrue($access[0]->granted);

        $page = $this->send('GET', '/admin/people/' . $person->key);
        self::assertSame(
            'Example Newcomer has been added. Give them their username and temporary password.',
            HtmlAssertions::text($page->body, '//main/div[@class="notice"][@role="status"]'),
        );
    }

    public function testAddAPersonProblemsMapToFields(): void
    {
        $garden = $this->project('Garden Log');
        $response = $this->send('POST', '/admin/people', [
            'display_name' => '', 'username' => 'member', 'role' => 'admin',
            'temporary_password' => 'short', 'projects' => [$garden],
        ]);
        $x = self::assertPage($response, 422, 'Error: Add a person - TiffinDB', 'Add a person');

        self::assertSame([
            ['#person-username', 'Someone already uses this username.'],
            ['#person-name', 'Enter a name.'],
            ['#person-password', 'Use at least 10 characters.'],
        ], self::summary($x));
        self::assertSame('Enter a name.', HtmlAssertions::text($response->body, '//p[@id="person-name-error"]'));
        self::assertSame('Someone already uses this username.', HtmlAssertions::text($response->body, '//p[@id="person-username-error"]'));
        self::assertSame('Use at least 10 characters.', HtmlAssertions::text($response->body, '//p[@id="person-password-error"]'));
        self::assertSame('person-password-help person-password-error', $x->query('//input[@id="person-password"]')->item(0)?->getAttribute('aria-describedby'));
        // Submitted values come back, including the visible temporary password.
        self::assertSame('member', $x->query('//input[@id="person-username"]')->item(0)?->getAttribute('value'));
        self::assertSame('short', $x->query('//input[@id="person-password"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $x->query('//input[@name="role"][@value="admin"][@checked="checked"]')->length);
        self::assertSame(1, $x->query('//input[@name="projects[]"][@checked="checked"]')->length);
        self::assertSame(1, $x->query('//nav[@class="breadcrumb"]/following-sibling::div[1][@class="error-summary"]')->length, 'Breadcrumb, then summary.');
    }

    public function testAddAPersonWithAnUnknownRole(): void
    {
        $response = $this->send('POST', '/admin/people', [
            'display_name' => 'Example Newcomer', 'username' => 'newcomer', 'role' => 'owner', 'temporary_password' => self::TEMPORARY,
        ]);
        $x = self::assertPage($response, 422, 'Error: Add a person - TiffinDB', 'Add a person');
        self::assertSame([['#person-role', 'Choose one of the options shown.']], self::summary($x));
        self::assertSame('Choose one of the options shown.', HtmlAssertions::text($response->body, '//p[@id="person-role-error"]'));
        self::assertNull($this->core->users->byUsername('newcomer'));
    }

    // ---------------------------------------------------------------- person page

    public function testMemberPage(): void
    {
        $garden = $this->project('Garden Log');
        $this->project('Recipes');
        $key = $this->key('member');
        $this->ops->people()->setProjectAccess($this->core->actor('admin'), $key, [$garden]);
        $response = $this->send('GET', "/admin/people/{$key}");
        $x = self::assertPage($response, 200, 'Example Member - TiffinDB', 'Example Member');

        self::assertSame(['People', '/', 'Example Member'], self::texts($x, '//nav[@class="breadcrumb"]/*'));
        self::assertSame('member, Member.', HtmlAssertions::text($response->body, '//main/div/p[@class="lead"]'));
        // Details form.
        $details = "//form[@action=\"/admin/people/{$key}\"]";
        self::assertSame('Details', HtmlAssertions::text($response->body, $details . '/fieldset[@class="fieldset-card"]/legend[@class="legend"]'));
        self::assertSame('Example Member', $x->query('//input[@id="edit-name"]')->item(0)?->getAttribute('value'));
        self::assertSame('member', $x->query('//input[@id="edit-username"]')->item(0)?->getAttribute('value'));
        self::assertSame('member', $x->query('//input[@id="edit-role"][@checked="checked"]')->item(0)?->getAttribute('value'));
        self::assertSame('Save details', HtmlAssertions::text($response->body, $details . '//button[@class="button button-primary"]'));
        self::assertSame(1, $x->query('//button[contains(@class,"button-primary")]')->length, 'One primary button.');
        // Access form.
        $access = "//form[@action=\"/admin/people/{$key}/projects\"]";
        self::assertSame('Projects they can use', HtmlAssertions::text($response->body, $access . '//legend[@class="legend"]'));
        self::assertSame(['Garden Log', 'Recipes'], self::texts($x, $access . '//li'));
        self::assertSame($garden, $x->query($access . '//input[@checked="checked"]')->item(0)?->getAttribute('value'));
        self::assertSame(1, $x->query($access . '//input[@checked="checked"]')->length);
        self::assertSame('Save projects', HtmlAssertions::text($response->body, $access . '//button[@class="button"]'));
        // Password form.
        $password = "//form[@action=\"/admin/people/{$key}/password\"]";
        self::assertSame(
            'If Example Member has forgotten their password, set a temporary one. They will be signed out everywhere and will choose a new password when they next sign in.',
            HtmlAssertions::text($response->body, $password . '//fieldset/p[1]'),
        );
        self::assertStringNotContainsString('This is your own account', $response->body);
        self::assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', (string) $x->query('//input[@id="reset-password"]')->item(0)?->getAttribute('value'));
        self::assertSame('Set temporary password', HtmlAssertions::text($response->body, $password . '//button[@class="button"]'));
        // Signing in.
        self::assertSame(['Signing in Example Member can sign in. Stop Example Member from signing in'], self::texts($x, '//section[h2="Signing in"]'));
        self::assertSame("/admin/people/{$key}/deactivate", $x->query('//section/a[@class="button button-danger"]')->item(0)?->getAttribute('href'));
    }

    public function testOwnAdminPage(): void
    {
        $key = $this->key('admin');
        $response = $this->send('GET', "/admin/people/{$key}");
        $x = self::assertPage($response, 200, 'Example Admin - TiffinDB', 'Example Admin');

        self::assertSame('admin, Admin.', HtmlAssertions::text($response->body, '//main/div/p[@class="lead"]'));
        self::assertSame(0, $x->query("//form[@action=\"/admin/people/{$key}/projects\"]")->length);
        self::assertSame(['Projects they can use Admins can use every project.'], self::texts($x, '//section[@class="fieldset-card"][1]'));
        self::assertSame('This is your own account: you will be signed out too.', HtmlAssertions::text($response->body, "//form[@action=\"/admin/people/{$key}/password\"]//fieldset/p[2]"));
        self::assertSame('You cannot stop yourself from signing in.', HtmlAssertions::text($response->body, '//section[h2="Signing in"]/p[@class="help"]'));
        self::assertSame(0, $x->query('//a[contains(@class,"button-danger")]')->length);
    }

    /** A second Admin whose temporary password has been replaced, signed in; returns [key, token]. */
    private function secondAdmin(): array
    {
        $other = $this->ops->people()->create($this->core->actor('admin'), 'admin2', 'Second Admin', Role::Admin, self::TEMPORARY, []);
        $first = $this->core->signIn($this->ops, 'admin2', self::TEMPORARY)->token();
        $signedIn = $this->ops->auth()->resume($first);
        self::assertNotNull($signedIn);
        $token = $this->ops->auth()->changeOwnPassword($signedIn->actor, $first, self::TEMPORARY, 'second admin words 9')->token();
        $resumed = $this->ops->auth()->resume($token);
        self::assertNotNull($resumed);
        $this->services->withSession($resumed, $token);

        return [$other->key, $token];
    }

    public function testAnotherActiveAdminCanBeStopped(): void
    {
        [$other] = $this->secondAdmin();
        $response = $this->send('GET', '/admin/people/' . $other);
        $x = self::assertPage($response, 200, 'Second Admin - TiffinDB', 'Second Admin');
        self::assertSame('Stop Second Admin from signing in', HtmlAssertions::text($response->body, '//section/a[@class="button button-danger"]'));
        self::assertStringNotContainsString('is the only Admin who can sign in', $response->body);
        self::assertSame(['Projects they can use Admins can use every project.'], self::texts($x, '//section[@class="fieldset-card"][1]'));
    }

    public function testTheOnlyActiveAdminCannotBeStopped(): void
    {
        // Second Admin is stopped by Example Admin while Second Admin's page
        // request is under way (the one-request window of P78): Example Admin
        // is then the only active Admin, and the page says so.
        [$other, $token] = $this->secondAdmin();
        $this->ops->people()->deactivate($this->core->actor('admin'), $other);
        $response = $this->send('GET', '/admin/people/' . $this->key('admin'), [], $token);
        $x = self::assertPage($response, 200, 'Example Admin - TiffinDB', 'Example Admin');
        self::assertSame(
            'Example Admin is the only Admin who can sign in, so they cannot be stopped.',
            HtmlAssertions::text($response->body, '//section[h2="Signing in"]/p[@class="help"]'),
        );
        self::assertSame(0, $x->query('//a[contains(@class,"button-danger")]')->length);
    }

    public function testInactivePersonPage(): void
    {
        $key = $this->key('member2');
        $this->ops->people()->deactivate($this->core->actor('admin'), $key);
        $response = $this->send('GET', "/admin/people/{$key}");
        $x = self::assertPage($response, 200, 'Another Member - TiffinDB', 'Another Member');
        self::assertSame('member2, Member. Cannot sign in at the moment.', HtmlAssertions::text($response->body, '//main/div/p[@class="lead"]'));
        self::assertSame('Another Member cannot sign in. Their name stays on the changes they made.', HtmlAssertions::text($response->body, '//section[h2="Signing in"]/p'));
        $restart = "//section/form[@action=\"/admin/people/{$key}/reactivate\"]";
        self::assertSame('Let Another Member sign in again', HtmlAssertions::text($response->body, $restart . '/div[@class="form-actions"]/button[@class="button"]'));
    }

    // ---------------------------------------------------------------- person page POSTs

    public function testSaveDetails(): void
    {
        $key = $this->key('member');
        $response = $this->send('POST', "/admin/people/{$key}", ['display_name' => 'Example Member Renamed', 'username' => 'member', 'role' => 'member']);
        self::assertRedirect($response, "/admin/people/{$key}");
        self::assertSame(['key' => 'notice.person_saved', 'params' => ['name' => 'Example Member Renamed']], $this->flash());
        self::assertSame('Example Member Renamed', $this->core->person('member')->displayName);
    }

    public function testSaveDetailsProblemsMapToFields(): void
    {
        $key = $this->key('member');
        $response = $this->send('POST', "/admin/people/{$key}", ['display_name' => '', 'username' => 'not valid!', 'role' => 'member']);
        $x = self::assertPage($response, 422, 'Error: Example Member - TiffinDB', 'Example Member');
        self::assertSame([
            ['#edit-username', 'Use 3 to 32 letters, numbers, dots, dashes, or underscores, with no spaces.'],
            ['#edit-name', 'Enter a name.'],
        ], self::summary($x));
        self::assertSame('not valid!', $x->query('//input[@id="edit-username"]')->item(0)?->getAttribute('value'));
        self::assertSame('Enter a name.', HtmlAssertions::text($response->body, '//p[@id="edit-name-error"]'));
        self::assertSame('true', $x->query('//input[@id="edit-name"]')->item(0)?->getAttribute('aria-invalid'));
    }

    public function testDemotingTheLastAdminMapsToRole(): void
    {
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}", ['display_name' => 'Example Admin', 'username' => 'admin', 'role' => 'member']);
        $x = self::assertPage($response, 422, 'Error: Example Admin - TiffinDB', 'Example Admin');
        $text = 'There must always be at least one Admin who can sign in. Make someone else an Admin first.';
        self::assertSame([['#edit-role', $text]], self::summary($x));
        self::assertSame($text, HtmlAssertions::text($response->body, '//p[@id="edit-role-error"]'));
        self::assertSame(1, $x->query('//input[@id="edit-role"][@value="member"][@checked="checked"]')->length, 'Submitted role printed back.');
        self::assertSame(Role::Admin, $this->core->person('admin')->role);

        $bad = $this->send('POST', "/admin/people/{$key}", ['display_name' => 'Example Admin', 'username' => 'admin', 'role' => '']);
        self::assertSame([['#edit-role', 'Choose one of the options shown.']], self::summary(new DOMXPath(HtmlAssertions::assertWellFormed($bad->body))));
    }

    public function testChangingOwnUsernameSignsOut(): void
    {
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}", ['display_name' => 'Example Admin', 'username' => 'admin1', 'role' => 'admin']);
        self::assertRedirect($response, '/sign-in');
        self::assertSame(0, $response->cookie(Context::SESSION_COOKIE)?->maxAge);
        self::assertNull($this->ops->auth()->resume($this->admin));
        self::assertNull($this->flash());
    }

    public function testRenamingOneselfKeepsTheSession(): void
    {
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}", ['display_name' => 'Example Admin Two', 'username' => 'admin', 'role' => 'admin']);
        self::assertRedirect($response, "/admin/people/{$key}");
        self::assertNull($response->cookie(Context::SESSION_COOKIE));
    }

    public function testSetTemporaryPassword(): void
    {
        $key = $this->key('member');
        $memberToken = $this->core->signIn($this->ops, 'member')->token();
        $response = $this->send('POST', "/admin/people/{$key}/password", ['temporary_password' => self::TEMPORARY]);
        self::assertRedirect($response, "/admin/people/{$key}");
        self::assertSame(['key' => 'notice.password_set', 'params' => ['name' => 'Example Member']], $this->flash());
        self::assertTrue($this->core->person('member')->mustChangePassword);
        self::assertNull($this->ops->auth()->resume($memberToken), 'The person is signed out everywhere.');
    }

    public function testSetTemporaryPasswordProblems(): void
    {
        $key = $this->key('member');
        $response = $this->send('POST', "/admin/people/{$key}/password", ['temporary_password' => 'member']);
        $x = self::assertPage($response, 422, 'Error: Example Member - TiffinDB', 'Example Member');
        self::assertSame([
            ['#reset-password', 'Use at least 10 characters.'],
            ['#reset-password', 'Do not use the username as the password.'],
        ], self::summary($x));
        self::assertSame('Use at least 10 characters.', HtmlAssertions::text($response->body, '//p[@id="reset-password-error"]'));
        self::assertSame('member', $x->query('//input[@id="reset-password"]')->item(0)?->getAttribute('value'));
        self::assertSame('Example Member', $x->query('//input[@id="edit-name"]')->item(0)?->getAttribute('value'), 'Other forms show stored values.');
    }

    public function testSetOwnTemporaryPasswordSignsOut(): void
    {
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}/password", ['temporary_password' => self::TEMPORARY]);
        self::assertRedirect($response, '/sign-in');
        self::assertSame(0, $response->cookie(Context::SESSION_COOKIE)?->maxAge);
        self::assertNull($this->ops->auth()->resume($this->admin));
    }

    public function testSaveProjects(): void
    {
        $garden = $this->project('Garden Log');
        $recipes = $this->project('Recipes');
        $key = $this->key('member');
        $response = $this->send('POST', "/admin/people/{$key}/projects", ['projects' => [$recipes]]);
        self::assertRedirect($response, "/admin/people/{$key}");
        self::assertSame(['key' => 'notice.access_saved', 'params' => ['name' => 'Example Member']], $this->flash());
        $granted = [];
        foreach ($this->ops->people()->projectAccess($this->core->actor('admin'), $key) as $row) {
            $granted[$row->project->key] = $row->granted;
        }
        self::assertSame([$garden => false, $recipes => true], $granted);
    }

    public function testMalformedProjectListsAreRefused(): void
    {
        $garden = $this->project('Garden Log');
        $key = $this->key('member');
        $this->ops->people()->setProjectAccess($this->core->actor('admin'), $key, [$garden]);
        $before = $this->core->systemRows();
        foreach (['notalist', [[$garden]], ['a' => $garden], [$garden, 7]] as $bad) {
            $response = $this->send('POST', "/admin/people/{$key}/projects", ['projects' => $bad]);
            self::assertSame(400, $response->status, json_encode($bad));
            $create = $this->send('POST', '/admin/people', [
                'display_name' => 'Example Newcomer', 'username' => 'newcomer', 'role' => 'member',
                'temporary_password' => self::TEMPORARY, 'projects' => $bad,
            ]);
            self::assertSame(400, $create->status, json_encode($bad));
        }
        self::assertSame($before, $this->core->systemRows(), 'Nothing changed.');
        self::assertNull($this->core->users->byUsername('newcomer'));

        // An absent field still means none ticked.
        self::assertSame(303, $this->send('POST', "/admin/people/{$key}/projects")->status);
        self::assertFalse($this->ops->people()->projectAccess($this->core->actor('admin'), $key)[0]->granted);
    }

    public function testSaveProjectsForAnAdminGoesToTheSummary(): void
    {
        $this->project('Garden Log');
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}/projects", ['projects' => []]);
        $x = self::assertPage($response, 422, 'Error: Example Admin - TiffinDB', 'Example Admin');
        self::assertSame([[null, 'Admins can use every project, so there is nothing to choose.']], self::summary($x));
    }

    // ---------------------------------------------------------------- stop and restart

    public function testStopConfirmationPage(): void
    {
        $key = $this->key('member');
        $response = $this->send('GET', "/admin/people/{$key}/deactivate");
        $x = self::assertPage($response, 200, 'Stop Example Member from signing in? - TiffinDB', 'Stop Example Member from signing in?');
        self::assertSame(['People', '/', 'Example Member', '/', 'Stop signing in'], self::texts($x, '//nav[@class="breadcrumb"]/*'));
        self::assertSame("/admin/people/{$key}", $x->query('//nav[@class="breadcrumb"]/a[2]')->item(0)?->getAttribute('href'));
        self::assertSame(
            'Example Member will be signed out now and will not be able to sign in. Nothing they made is deleted, and you can let them sign in again later.',
            HtmlAssertions::text($response->body, '//main/h1/following-sibling::p[1]'),
        );
        $form = "//form[@action=\"/admin/people/{$key}/deactivate\"]";
        self::assertSame('Stop Example Member from signing in', HtmlAssertions::text($response->body, $form . '//button[@class="button button-large button-danger"]'));
        self::assertSame("/admin/people/{$key}", $x->query($form . '//div[@class="form-actions"]/a')->item(0)?->getAttribute('href'));
        self::assertSame('Cancel', HtmlAssertions::text($response->body, $form . '//div[@class="form-actions"]/a'));
        self::assertTrue($this->core->person('member')->active, 'The GET changes nothing.');
    }

    public function testStopAndRestart(): void
    {
        $key = $this->key('member');
        $memberToken = $this->core->signIn($this->ops, 'member')->token();
        $response = $this->send('POST', "/admin/people/{$key}/deactivate");
        self::assertRedirect($response, "/admin/people/{$key}");
        self::assertSame(['key' => 'notice.person_stopped', 'params' => ['name' => 'Example Member']], $this->flash());
        self::assertFalse($this->core->person('member')->active);
        self::assertNull($this->ops->auth()->resume($memberToken));

        $this->services->flash = [];
        $restart = $this->send('POST', "/admin/people/{$key}/reactivate");
        self::assertRedirect($restart, "/admin/people/{$key}");
        self::assertSame(['key' => 'notice.person_restarted', 'params' => ['name' => 'Example Member']], $this->flash());
        self::assertTrue($this->core->person('member')->active);
    }

    public function testStoppingOneselfIsRefusedOnThePersonPage(): void
    {
        $key = $this->key('admin');
        $response = $this->send('POST', "/admin/people/{$key}/deactivate");
        $x = self::assertPage($response, 422, 'Error: Example Admin - TiffinDB', 'Example Admin');
        self::assertSame([[null, 'You cannot stop yourself from signing in.']], self::summary($x));
        self::assertTrue($this->core->person('admin')->active);
    }
}
