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
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\AuditController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;
use TiffinDB\Web\Strings;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-86 (M7 screens spec section 11.1, M7-WP5): the activity page
 * (docs/design/m7/audit.md) through the kernel with the real templates,
 * strings, and core operations over a CoreFixture. Hostile display names,
 * project names, and detail values appear escaped and make no element;
 * the filters and the pager keep each other in their links; a Member gets
 * 403; tampered query values and stored rows the page does not expect
 * never give a 500. Synthetic data only.
 */
#[CoversClass(AuditController::class)]
final class AuditScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const HOSTILE_NAME = '<b>"x"</b>';
    private const HOSTILE_PROJECT = 'Tom & "Jerry\'s" <i>Box</i>';
    private const HOSTILE_DETAIL = "<script>alert(1)</script>\u{202E}fdp.exe";

    private CoreFixture $core;
    private Operations $ops;
    private Kernel $kernel;
    private AuditLog $audit;

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
        $this->audit = new AuditLog($this->core->system, $this->core->clock);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    private function get(string $token, string $uri): Response
    {
        [$response, $log] = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => $token]));
        self::assertNotSame(500, $response->status, "GET {$uri}: {$log}");

        return $response;
    }

    private function page(string $uri): DOMXPath
    {
        $response = $this->get(self::ADMIN, $uri);
        self::assertSame(200, $response->status, $response->body);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);
        self::assertSame('Activity - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame('Activity', HtmlAssertions::text($response->body, '//h1'));

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

    // ---------------------------------------------------------------- tests

    public function testHostileValuesAreEscapedAndMakeNoElement(): void
    {
        $person = $this->core->addPerson('hostile', self::HOSTILE_NAME, Role::Member);
        $project = $this->ops->projects()->create($this->core->actor('admin'), self::HOSTILE_PROJECT, 'Items')->key;
        $projectId = (int) $this->core->system->db()->value('SELECT id FROM projects WHERE "key" = ?', [$project]);
        $this->audit->record(AuditAction::IMPORT_COMPLETED, $person->userId, $projectId, 'table', 'tbl<x>', ['file' => self::HOSTILE_DETAIL, 'count' => 7, 'ok' => true, 'none' => null]);

        $response = $this->get(self::ADMIN, '/admin/activity');
        $x = $this->page('/admin/activity');
        self::assertContains('Example Admin created ' . self::HOSTILE_PROJECT . '.', self::texts($x, '//li[@class="audit-entry"]/p[@class="audit-what"]'));
        $first = '(//li[@class="audit-entry"])[1]';
        self::assertSame([self::HOSTILE_NAME . ' imported rows into ' . self::HOSTILE_PROJECT . '.'], self::texts($x, $first . '/p[@class="audit-what"]'));
        self::assertSame(['file', 'count', 'ok', 'none'], self::texts($x, $first . '//dl[@class="audit-detail"]/dt'));
        self::assertSame([self::HOSTILE_DETAIL, '7', 'true', ''], array_map(static fn ($n): string => (string) $n->textContent, iterator_to_array($x->query($first . '//dl[@class="audit-detail"]/dd'))));
        self::assertSame(0, $x->query('//main//b | //main//i | //main//script | //main//x')->length, 'No element from a value.');
        self::assertStringNotContainsString('<script>alert', $response->body);
        self::assertStringContainsString('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $response->body);
        self::assertStringContainsString('Tom &amp; &quot;Jerry&apos;s&quot; &lt;i&gt;Box&lt;/i&gt;', $response->body);
        self::assertSame(1, $x->query('//select[@name="person"]/option[normalize-space(.)=\'' . self::HOSTILE_NAME . '\']')->length, 'The person menu escapes the name too.');
        self::assertSame(FakeServices::NOW, (string) $x->query($first . '//time/@datetime')->item(0)?->nodeValue);
    }

    public function testEveryActionHasASentenceAndAShortForm(): void
    {
        $strings = new Strings(Fixtures::appRoot() . '/strings/en.php');
        foreach (AuditAction::ALL as $action) {
            self::assertTrue($strings->has('audit.action.' . $action), "audit.action.{$action}");
            self::assertTrue($strings->has('audit.action.' . $action . '.short'), "audit.action.{$action}.short");
        }
        $keys = array_filter(array_keys(require Fixtures::appRoot() . '/strings/en.php'), static fn (string $k): bool => str_starts_with($k, 'audit.action.'));
        self::assertCount(2 * count(AuditAction::ALL), $keys, 'No sentence for an action that does not exist.');
    }

    public function testEachActionIsShownAsItsSentence(): void
    {
        $admin = $this->core->person('admin')->id;
        $project = $this->ops->projects()->create($this->core->actor('admin'), 'Home Inventory', 'Items')->key;
        $projectId = (int) $this->core->system->db()->value('SELECT id FROM projects WHERE "key" = ?', [$project]);
        $this->audit->record(AuditAction::RECOVERED, null, null, 'user', $this->core->person('admin')->key);
        $this->audit->record(AuditAction::PROJECT_DELETED, $admin, null, 'project', 'aaaaaaaaaaaa', ['name' => 'Garden Log', 'records' => 3]);
        $this->audit->record(AuditAction::SNAPSHOT_TAKEN, $admin, $projectId);
        $this->audit->record(AuditAction::SNAPSHOT_TAKEN, $admin, null);

        $x = $this->page('/admin/activity');
        self::assertSame([
            'Example Admin saved a snapshot of a deleted project.',
            'Example Admin saved a snapshot of Home Inventory.',
            'Example Admin deleted Garden Log.',
            'An Admin password was changed by recovery.',
        ], array_slice(self::texts($x, '//p[@class="audit-what"]'), 0, 4));
        $deleted = $this->page('/admin/activity?project=deleted');
        self::assertSame(['Example Admin deleted Garden Log.'], self::texts($deleted, '//p[@class="audit-what"]'));
        self::assertSame(['1 entry'], self::texts($deleted, '//main/p[@class="help"]'));
        self::assertSame('deleted', (string) $deleted->query('//select[@name="project"]/option[@selected]/@value')->item(0)?->nodeValue);
    }

    public function testFiltersAndPagesKeepEachOtherInLinks(): void
    {
        $admin = $this->core->person('admin');
        for ($i = 0; $i < 120; $i++) {
            $this->audit->record($i % 2 === 0 ? AuditAction::SNAPSHOT_TAKEN : AuditAction::TRASH_EMPTIED, $admin->id);
        }
        $x = $this->page('/admin/activity?action=snapshot.taken&person=' . $admin->key);
        self::assertSame(['60 entries'], self::texts($x, '//main/p[@class="help"]'));
        self::assertSame(50, $x->query('//li[@class="audit-entry"]')->length);
        $next = (string) $x->query('//nav[@class="pager"]//a[@rel="next"]/@href')->item(0)?->nodeValue;
        self::assertSame('/admin/activity?page=2&person=' . $admin->key . '&action=snapshot.taken', $next);
        self::assertSame(['Showing 1 to 50 of 60'], self::texts($x, '//span[@class="pager-status"]'));
        self::assertSame(1, $x->query('//a[@class="form-cancel"][@href="/admin/activity"][normalize-space(.)="Show everything"]')->length);
        self::assertSame($admin->key, (string) $x->query('//select[@name="person"]/option[@selected]/@value')->item(0)?->nodeValue);
        self::assertSame('Example Admin', trim((string) $x->query('//select[@name="person"]/option[@selected]')->item(0)?->textContent));
        self::assertSame(0, $x->query('//form[@class="audit-filters"][@method="get"]//input[@name="_csrf"]')->length, 'The filter form changes nothing and has no token.');

        $last = $this->page($next);
        self::assertSame(10, $last->query('//li[@class="audit-entry"]')->length);
        self::assertSame('/admin/activity?page=1&person=' . $admin->key . '&action=snapshot.taken', (string) $last->query('//nav[@class="pager"]//a[@rel="prev"]/@href')->item(0)?->nodeValue);
        self::assertSame(1, $last->query('//nav[@class="pager"]//span[@aria-disabled="true"]')->length, 'No next page.');
        self::assertSame(['Showing 51 to 60 of 60'], self::texts($last, '//span[@class="pager-status"]'));

        // A page past the end is the last page; nothing unfiltered shows Show everything.
        self::assertSame(['Showing 51 to 60 of 60'], self::texts($this->page('/admin/activity?action=snapshot.taken&person=' . $admin->key . '&page=99'), '//span[@class="pager-status"]'));
        self::assertSame(0, $this->page('/admin/activity')->query('//a[normalize-space(.)="Show everything"]')->length);
    }

    public function testDeactivatedPeopleAreNamedSoInThePersonMenu(): void
    {
        $this->core->setActive('member2', false);
        $x = $this->page('/admin/activity');
        self::assertSame(['Anyone', 'Another Member (no longer active)', 'Example Admin', 'Example Member'], self::texts($x, '//select[@name="person"]/option'));
        self::assertSame(['Any project', 'Deleted projects'], self::texts($x, '//select[@name="project"]/option'));
        self::assertSame('Anything', self::texts($x, '//select[@name="action"]/option')[0]);
        self::assertCount(1 + count(AuditAction::ALL), self::texts($x, '//select[@name="action"]/option'));
    }

    public function testAMemberIsRefused(): void
    {
        self::assertSame(403, $this->get(self::MEMBER, '/admin/activity')->status);
        self::assertSame(403, $this->get(self::MEMBER, '/admin/activity?person=' . $this->core->person('admin')->key)->status);
        self::assertSame(303, Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', '/admin/activity'))[0]->status, 'Signed out: to sign in.');
    }

    public function testTamperedQueriesShowAPageAndAreNotReflected(): void
    {
        $queries = [
            '?person[]=x&project[]=y&action[]=z&page[]=2',
            '?person=<script>&project=%22%3E%3Cb%3E&action=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
            '?page=0', '?page=-1', '?page=1e3', '?page=99999999999999999999', '?page=%00', '?action=auth.sign_in%00',
            '?person=' . str_repeat('a', 5000), '?project=deleted&project=x', '?unknown=1',
        ];
        foreach ($queries as $query) {
            $response = $this->get(self::ADMIN, '/admin/activity' . $query);
            self::assertSame(200, $response->status, $query);
            HtmlAssertions::assertWellFormed($response->body);
            self::assertStringNotContainsString('<script', $response->body, $query);
            self::assertStringNotContainsString('<b>', $response->body, $query);
            self::assertSame(0, (new DOMXPath(HtmlAssertions::assertWellFormed($response->body)))->query('//a[normalize-space(.)="Show everything"]')->length, "{$query}: no filter applied.");
        }
    }

    public function testStoredRowsThePageDoesNotExpectAreShownAsText(): void
    {
        $this->core->system->db()->write(static function ($db): void {
            $db->run(
                'INSERT INTO audit_log (at, actor_user_id, action, project_id, target_kind, target_key, detail) VALUES (?, ?, ?, ?, ?, ?, ?)',
                ['2099-13-45T99:99:99Z', null, 'future.<b>action</b>', null, null, null, '{}'],
            );
            $db->run(
                'INSERT INTO audit_log (at, actor_user_id, action, project_id, target_kind, target_key, detail) VALUES (?, ?, ?, ?, ?, ?, ?)',
                ['not a time " onmouseover="x', null, 'snapshot.taken', null, null, null, '{}'],
            );
        });
        $response = $this->get(self::ADMIN, '/admin/activity');
        self::assertSame(200, $response->status, $response->body);
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($response->body));
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        self::assertContains('TiffinDB: future.<b>action</b>.', self::texts($x, '//p[@class="audit-what"]'));
        self::assertContains('not a time " onmouseover="x', self::texts($x, '//p[@class="audit-when"]'));
        self::assertSame(0, $x->query('//main//b | //*[@onmouseover]')->length);
        foreach ($x->query('//time/@datetime') as $datetime) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', (string) $datetime->nodeValue);
        }
        self::assertSame(0, $x->query('//time[@datetime="2099-13-45T99:99:99Z"]')->length, 'An impossible date gets no datetime attribute.');
    }
}
