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
use TiffinDB\Web\Controller\SharingController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP10: sharing a project (docs/design/m1/people-and-sharing.md
 * section 6) through the kernel with the real templates, strings, and
 * core operations over a CoreFixture. Synthetic data only.
 */
#[CoversClass(SharingController::class)]
final class SharingScreenTest extends TestCase
{
    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private string $admin;
    private string $project;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->admin = $this->signInAs('admin');
        $this->project = $this->ops->projects()->create($this->core->actor('admin'), 'Garden Log', null)->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

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

    private function uri(): string
    {
        return "/projects/{$this->project}/sharing";
    }

    private static function page(Response $response): DOMXPath
    {
        self::assertSame(200, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame('Share Garden Log - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame('Share Garden Log', HtmlAssertions::text($response->body, '//h1'));
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
        self::assertSame(1, $x->query('//nav[@class="top-nav"]/a[@href="/projects"][@aria-current="page"]')->length, 'nav = projects.');

        return $x;
    }

    /** @return array<string, bool> username => granted */
    private function grants(): array
    {
        $grants = [];
        foreach ($this->ops->projects()->sharing($this->core->actor('admin'), $this->project) as $row) {
            $grants[$row->member->username] = $row->granted;
        }

        return $grants;
    }

    public function testSharingForm(): void
    {
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->project, [$this->core->person('member')->key]);
        $response = $this->send('GET', $this->uri());
        $x = self::page($response);

        $crumbs = [];
        foreach ($x->query('//nav[@class="breadcrumb"]/*[not(@aria-hidden)]') as $node) {
            $crumbs[] = [trim($node->textContent), $node->getAttribute('href')];
        }
        self::assertSame([['Projects', '/projects'], ['Garden Log', "/projects/{$this->project}/tables"], ['Sharing', '']], $crumbs);
        self::assertSame(
            'Admins can always use every project. Choose which Members can use this one. They will be able to view and change its records.',
            HtmlAssertions::text($response->body, '//main/div/p[@class="lead"]'),
        );
        $form = "//form[@class=\"form form-wide\"][@action=\"{$this->uri()}\"]";
        self::assertSame('Members', HtmlAssertions::text($response->body, $form . '/fieldset[@class="fieldset-card"]/legend[@class="legend"]'));
        $rows = [];
        foreach ($x->query($form . '//ul[@class="check-list"]/li/label[@class="check"]') as $label) {
            $input = $x->query('./input[@type="checkbox"][@name="members[]"]', $label)->item(0);
            $rows[] = [
                trim((string) $x->query('./span/text()', $label)->item(0)?->textContent),
                HtmlAssertions::text($response->body, '//input[@value="' . $input?->getAttribute('value') . '"]/following-sibling::span/span[@class="check-hint"]'),
                $input?->getAttribute('checked'),
            ];
        }
        self::assertSame([['Another Member', 'member2', ''], ['Example Member', 'member', 'checked']], $rows, 'Active Members by name; Admins are not listed.');
        self::assertSame(0, $x->query('//p[@class="help"]')->length, 'No inactive grants.');
        self::assertSame('Save sharing', HtmlAssertions::text($response->body, $form . '//div[@class="form-actions"]/button[@class="button button-large button-primary"]'));
        self::assertSame('/projects', $x->query($form . '//div[@class="form-actions"]/a')->item(0)?->getAttribute('href'));
    }

    public function testInactiveMembersHoldingAGrant(): void
    {
        $admin = $this->core->actor('admin');
        $third = $this->ops->people()->create($admin, 'member3', 'Third Member', Role::Member, 'temporary words 42', []);
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member2')->key, $third->key]);
        $this->ops->people()->deactivate($admin, $this->core->person('member2')->key);

        $one = self::page($this->send('GET', $this->uri()));
        self::assertSame(['Also shared with 1 person who cannot sign in at the moment.'], array_map(
            static fn ($n): string => trim($n->textContent),
            iterator_to_array($one->query('//form//p[@class="help"]')),
        ));
        self::assertSame(0, $one->query('//input[@value="' . $this->core->person('member2')->key . '"]')->length, 'Inactive Members are not listed.');

        $this->ops->people()->deactivate($admin, $third->key);
        $many = $this->send('GET', $this->uri());
        self::assertStringContainsString('Also shared with 2 people who cannot sign in at the moment.', $many->body);
    }

    public function testNoActiveMembers(): void
    {
        $admin = $this->core->actor('admin');
        foreach (['member', 'member2'] as $username) {
            $this->ops->people()->deactivate($admin, $this->core->person($username)->key);
        }
        $response = $this->send('GET', $this->uri());
        $x = self::page($response);
        self::assertSame(0, $x->query('//main//form')->length);
        self::assertSame(
            'There are no Members yet. Add people first, then share the project with them.',
            HtmlAssertions::text($response->body, '//main/div[@class="notice"]/p'),
        );
        $add = $x->query('//main/div[@class="notice"]/a[@class="button button-primary"]')->item(0);
        self::assertSame('/admin/people/new', $add?->getAttribute('href'));
        self::assertSame('Add a person', trim((string) $add?->textContent));
    }

    public function testSaveSharing(): void
    {
        $member2 = $this->core->person('member2')->key;
        $response = $this->send('POST', $this->uri(), ['members' => [$member2, $this->core->person('admin')->key, 'UNKNOWNKEY12']]);
        self::assertSame(303, $response->status);
        self::assertSame('/projects', $response->header('Location'));
        self::assertSame(['key' => 'notice.sharing_saved', 'params' => ['project' => 'Garden Log']], $this->services->flash[$this->admin] ?? null);
        self::assertSame(['member2' => true, 'member' => false], $this->grants());

        $none = $this->send('POST', $this->uri(), []);
        self::assertSame(303, $none->status);
        self::assertSame(['member2' => false, 'member' => false], $this->grants());
    }

    public function testMalformedMemberListsAreRefused(): void
    {
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->project, [$this->core->person('member')->key]);
        foreach (['notalist', [['x']], ['a' => 'b']] as $bad) {
            self::assertSame(400, $this->send('POST', $this->uri(), ['members' => $bad])->status);
        }
        self::assertSame(['member2' => false, 'member' => true], $this->grants());
    }

    public function testMembersAreRefusedByTheCore(): void
    {
        $member = $this->signInAs('member');
        // Not shared with them: the project does not exist for them.
        self::assertSame(404, $this->send('GET', $this->uri(), [], $member)->status);
        self::assertSame(404, $this->send('POST', $this->uri(), ['members' => [$this->core->person('member')->key]], $member)->status);

        // Shared with them: they may use it, but not share it.
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->project, [$this->core->person('member')->key]);
        self::assertSame(403, $this->send('GET', $this->uri(), [], $member)->status);
        self::assertSame(403, $this->send('POST', $this->uri(), ['members' => [$this->core->person('member2')->key]], $member)->status);
        self::assertSame(['member2' => false, 'member' => true], $this->grants());
    }

    public function testUnknownProjectIsNotFound(): void
    {
        self::assertSame(404, $this->send('GET', '/projects/ZZZZZZZZZZZZ/sharing')->status);
        self::assertSame(404, $this->send('POST', '/projects/ZZZZZZZZZZZZ/sharing')->status);
    }
}
