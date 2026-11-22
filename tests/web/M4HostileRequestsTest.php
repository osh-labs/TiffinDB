<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * Hostile requests against the M4 wave 4 screens (security review
 * M4-SEC-WP4-W4, docs/build/reviews/M4-sec-wp4-wave4.md): CSRF on every
 * new state-changing route with nothing changed, IDOR across records,
 * tables, fields, and projects on the file and picker pages, the
 * Admin-only empty-trash with a forged `expected`, open-redirect attempts
 * through `from` and `back`, hostile rule input on the collection editor,
 * hostile names escaped on every new page, and a deactivated person's
 * old Actor refused by the core before any bytes. Synthetic data only.
 */
#[CoversNothing]
final class M4HostileRequestsTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const XSS = '<img src=x onerror=alert(1)>';

    private CoreFixture $core;
    private Operations $ops;
    private Kernel $kernel;
    private string $project;
    private string $other;
    private string $items;
    private string $notes;
    private string $name;
    private string $files;
    private string $rel;
    private int $mixer;
    private int $kettle;
    private int $note;
    private int $file;

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

        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home ' . self::XSS, 'Items ' . self::XSS)->key;
        $this->other = $this->ops->projects()->create($admin, 'Garden Log', 'Plants')->key;
        $this->items = $this->ops->schema()->tables($admin, $this->project)[0]->table->key;
        $this->name = $this->ops->schema()->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $this->notes = $this->ops->schema()->createTable($admin, $this->project, 'Notes ' . self::XSS, 'note', true)->key;
        $noteName = $this->ops->schema()->design($admin, $this->project, $this->notes)->fields[0]->field->key;
        $this->files = $this->ops->schema()->addField($admin, $this->project, $this->items, 'Files ' . self::XSS, 'attachment', [], false, '')->key;
        $this->rel = $this->ops->schema()->addRelationField($admin, $this->project, $this->items, 'Notes link', $this->notes, Cardinality::Many, Cardinality::Many, 'Items back', false, '')->key;
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);
        $this->mixer = $this->ops->records()->create($admin, $this->project, $this->items, [$this->name => 'Mixer ' . self::XSS]);
        $this->kettle = $this->ops->records()->create($admin, $this->project, $this->items, [$this->name => 'Kettle']);
        $this->note = $this->ops->records()->create($admin, $this->project, $this->notes, [$noteName => 'Note ' . self::XSS]);
        $this->file = $this->ops->attachments()->add($admin, $this->project, $this->items, (string) $this->mixer, $this->files, $this->core->upload('<script>alert(1)</script>'), self::XSS . '.html', null)->id;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testEveryNewStateChangingRouteRefusesAMissingOrForeignCsrfTokenAndChangesNothing(): void
    {
        $trashed = $this->ops->records()->create($this->admin(), $this->project, $this->items, [$this->name => 'Old']);
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $trashed);
        $collection = $this->collection('Kept');
        $record = $this->record($this->mixer);
        $before = $this->state();

        $posts = [
            ['/projects/' . $this->project . '/trash/restore', ['table' => $this->items, 'record' => (string) $trashed]],
            ['/projects/' . $this->project . '/trash/restore-all', ['table' => $this->items]],
            ['/projects/' . $this->project . '/trash/empty', ['expected' => '999999']],
            ['/projects/' . $this->project . '/collections/' . $collection . '/delete', []],
            ['/projects/' . $this->project . '/collections/' . $collection . '/edit', ['action' => 'save', 'name' => 'Changed', 'match' => 'all']],
            ['/projects/' . $this->project . '/tables/' . $this->items . '/collections', ['action' => 'save', 'name' => 'New one', 'match' => 'all']],
            [$record . '/links/' . $this->rel . '/add', ['target' => (string) $this->note]],
            [$record . '/links/' . $this->rel . '/remove', ['target' => (string) $this->note]],
            [$record . '/files/' . $this->files . '/' . $this->file . '/remove', []],
            [$record . '/files/' . $this->files, []],
            ['/projects/' . $this->project . '/tables/' . $this->notes . '/records', ['from' => $this->rel . '-' . $this->mixer]],
        ];
        foreach ($posts as [$uri, $post]) {
            foreach ([null, str_repeat('b', 64), ''] as $token) {
                $sent = $token === null ? $post : $post + ['_csrf' => $token];
                foreach ([self::ADMIN, self::MEMBER] as $who) {
                    $response = $this->send($who, 'POST', $uri, $sent);
                    self::assertSame(403, $response->status, $uri);
                }
            }
        }
        self::assertSame($before, $this->state(), 'No refused POST changed anything.');
    }

    public function testFilePagesRefuseEveryAddressThatDoesNotFitTheRecordAndField(): void
    {
        $id = (string) $this->file;
        $ok = $this->record($this->mixer) . '/files/' . $this->files . '/' . $id;
        self::assertSame(200, $this->get(self::MEMBER, $ok)->status);

        $refused = [
            'other record' => $this->record($this->kettle) . '/files/' . $this->files . '/' . $id,
            'other table' => '/projects/' . $this->project . '/tables/' . $this->notes . '/records/' . $this->note . '/files/' . $this->files . '/' . $id,
            'relation field' => $this->record($this->mixer) . '/files/' . $this->rel . '/' . $id,
            'text field' => $this->record($this->mixer) . '/files/' . $this->name . '/' . $id,
            'leading zero' => $this->record($this->mixer) . '/files/' . $this->files . '/0' . $id,
            'other project' => '/projects/' . $this->other . '/tables/' . $this->items . '/records/' . $this->mixer . '/files/' . $this->files . '/' . $id,
            'replace of a file the record does not hold' => $this->record($this->kettle) . '/files/' . $this->files . '/new?replace=' . $id,
            'remove form of a file the record does not hold' => $this->record($this->kettle) . '/files/' . $this->files . '/' . $id . '/remove',
            'picker through an attachment field' => $this->record($this->mixer) . '/links/' . $this->files,
        ];
        foreach ($refused as $what => $uri) {
            self::assertSame(404, $this->get(self::MEMBER, $uri)->status, $what);
        }
        // Removing through another record's address leaves the file alone.
        self::assertSame(404, $this->send(self::MEMBER, 'POST', $this->record($this->kettle) . '/files/' . $this->files . '/' . $id . '/remove', ['_csrf' => FakeServices::SESSION_CSRF])->status);
        self::assertSame(200, $this->get(self::MEMBER, '/projects/' . $this->project . '/files/' . $id)->status);

        // A record in the trash offers no file page, picker, upload, or removal.
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $this->mixer);
        foreach ([$ok, $ok . '/remove', $this->record($this->mixer) . '/files/' . $this->files . '/new', $this->record($this->mixer) . '/links/' . $this->rel] as $uri) {
            self::assertSame(404, $this->get(self::ADMIN, $uri)->status, $uri);
        }
        foreach ([$ok . '/remove' => [], $this->record($this->mixer) . '/links/' . $this->rel . '/add' => ['target' => (string) $this->note]] as $uri => $post) {
            self::assertSame(404, $this->send(self::ADMIN, 'POST', $uri, $post + ['_csrf' => FakeServices::SESSION_CSRF])->status, $uri);
        }
    }

    public function testOnlyAnAdminEmptiesTheTrashAndAMissingOrSmallerExpectedAsksAgain(): void
    {
        $old = $this->ops->records()->create($this->admin(), $this->project, $this->items, [$this->name => 'Old']);
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $old);
        $path = '/projects/' . $this->project . '/trash/empty';

        self::assertSame(403, $this->get(self::MEMBER, $path)->status);
        self::assertSame(403, $this->send(self::MEMBER, 'POST', $path, ['_csrf' => FakeServices::SESSION_CSRF, 'expected' => '999999'])->status);
        foreach ([null, '', '0', '-1', '1e9', ' 1'] as $expected) {
            $post = ['_csrf' => FakeServices::SESSION_CSRF] + ($expected === null ? [] : ['expected' => $expected]);
            $response = $this->send(self::ADMIN, 'POST', $path, $post);
            self::assertSame(200, $response->status, 'Asked again for expected=' . var_export($expected, true));
        }
        $response = $this->send(self::ADMIN, 'POST', $path, ['_csrf' => FakeServices::SESSION_CSRF, 'expected' => ['1']]);
        self::assertSame(200, $response->status, 'An array is no count.');
        self::assertSame(1, $this->ops->trash()->count($this->admin(), $this->project, null), 'Nothing was deleted.');

        // Intended (review finding L-1): a count larger than the real one is accepted without a second page.
        self::assertSame(303, $this->send(self::ADMIN, 'POST', $path, ['_csrf' => FakeServices::SESSION_CSRF, 'expected' => '999999'])->status);
        self::assertSame(0, $this->ops->trash()->count($this->admin(), $this->project, null));
    }

    public function testFromAndBackCannotRedirectOffTheApplication(): void
    {
        $notesNew = '/projects/' . $this->project . '/tables/' . $this->notes . '/records/new';
        foreach (['//evil.example', 'https://evil.example', $this->rel . '-' . $this->mixer . '/../x', $this->rel . '-0', $this->name . '-' . $this->mixer, $this->rel . '-999'] as $from) {
            self::assertSame(404, $this->get(self::MEMBER, $notesNew . '?from=' . rawurlencode($from))->status, $from);
        }
        // The relation seen from the wrong side.
        self::assertSame(404, $this->get(self::MEMBER, '/projects/' . $this->project . '/tables/' . $this->items . '/records/new?from=' . $this->rel . '-' . $this->mixer)->status);

        $picker = $this->record($this->mixer) . '/links/' . $this->rel;
        foreach (['https://evil.example', '//evil.example', '/projects', 'record '] as $back) {
            $response = $this->send(self::MEMBER, 'POST', $picker . '/add', ['_csrf' => FakeServices::SESSION_CSRF, 'target' => (string) $this->note, 'back' => $back, 'q' => '"><script>', 'page' => '//2']);
            self::assertSame(303, $response->status, $back);
            $location = (string) $response->header('Location');
            self::assertStringStartsWith($picker . '?', $location, $back);
            self::assertStringNotContainsString('evil', $location);
            self::assertStringNotContainsString('<', $location);
        }
    }

    public function testHostileRuleInputNeverFailsTheEditorOrReachesTheStore(): void
    {
        $new = '/projects/' . $this->project . '/tables/' . $this->items . '/collections';
        $noteField = $this->ops->schema()->design($this->admin(), $this->project, $this->notes)->fields[0]->field->key;
        $hostile = [
            ['action' => 'preview', 'rules' => [1 => ['field' => $noteField, 'test' => 'contains', 'value' => 'x']]],
            ['action' => 'preview', 'rules' => 'not a list', 'name' => ['x'], 'match' => ['any'], 'sort' => ['x']],
            ['action' => 'preview', 'rules' => [1 => ['field' => ['x'], 'test' => ['y'], 'value' => ['z']], 2 => 'c']],
            ['action' => 'remove-0'],
            ['action' => 'remove-99999999999999999999'],
            ['action' => 'add', 'rules' => array_fill(1, 40, ['field' => $this->name, 'test' => 'contains', 'value' => 'a'])],
            ['action' => 'preview', 'rules' => [1 => ['field' => $this->name, 'test' => "contains') OR 1=1 --", 'value' => 'x']]],
            ['action' => 'preview', 'rules' => [1 => ['field' => $this->rel, 'test' => 'is', 'record' => '-1 OR 1']]],
            ['action' => 'preview', 'rules' => [1 => ['field' => $this->name, 'test' => 'contains', 'value' => str_repeat('a', 100000)]]],
            ['action' => 'save', 'name' => 'Foreign', 'rules' => [1 => ['field' => $noteField, 'test' => 'contains', 'value' => 'x']]],
            ['action' => 'save', 'name' => 'Op', 'rules' => [1 => ['field' => $this->name, 'test' => "contains') OR 1=1 --", 'value' => 'x']]],
            ['action' => 'save', 'name' => str_repeat('a', 5000), 'rules' => [1 => ['field' => $this->name, 'test' => 'contains', 'value' => 'x']]],
        ];
        foreach ($hostile as $i => $post) {
            $response = $this->send(self::MEMBER, 'POST', $new, $post + ['_csrf' => FakeServices::SESSION_CSRF]);
            self::assertContains($response->status, [200, 422], 'Case ' . $i);
            self::assertStringNotContainsString(self::XSS, $response->body(), 'Case ' . $i);
        }
        self::assertSame([], $this->ops->collections()->summaries($this->admin(), $this->project), 'Nothing hostile was stored.');

        // Wildcards and quotes are values, not SQL: a stored collection matches nothing here.
        $key = $this->collection('Quotes', "%' OR '1'='1");
        $page = $this->get(self::MEMBER, '/projects/' . $this->project . '/collections/' . $key);
        self::assertSame(200, $page->status);
        self::assertSame(0, $this->ops->collections()->summaries($this->admin(), $this->project)[0]->count);
        // A sort token that is not offered is dropped, never stored.
        $this->send(self::MEMBER, 'POST', '/projects/' . $this->project . '/collections/' . $key . '/edit', ['_csrf' => FakeServices::SESSION_CSRF, 'action' => 'save', 'name' => 'Quotes', 'match' => 'all', 'sort' => $this->name . '.asc; DROP TABLE x', 'rules' => [1 => ['field' => $this->name, 'test' => 'contains', 'value' => 'x']]]);
        self::assertNull($this->core->projectDb($this->project)->value('SELECT "sort" FROM "_tiffin_collections" WHERE "key" = ?', [$key]));

        // A collection of one project is not found through another.
        foreach (['', '/edit', '/delete'] as $suffix) {
            self::assertSame(404, $this->get(self::ADMIN, '/projects/' . $this->other . '/collections/' . $key . $suffix)->status, $suffix);
        }
    }

    public function testRulesEditedByAnotherToolDoNotBreakAnyPage(): void
    {
        $key = $this->collection('Edited');
        $this->core->projectDb($this->project)->run('UPDATE "_tiffin_collections" SET "rules" = ?, "match_mode" = ?, "sort" = ?, "name" = ? WHERE "key" = ?', ['not json', 'weird', 'zz;--', self::XSS, $key]);
        $pages = [
            '/projects/' . $this->project . '/collections/' . $key,
            '/projects/' . $this->project . '/collections/' . $key . '/edit',
            '/projects/' . $this->project . '/collections/' . $key . '/delete',
            '/projects/' . $this->project . '/tables/' . $this->items,
            '/projects/' . $this->project . '/trash',
            $this->record($this->mixer),
        ];
        foreach ($pages as $uri) {
            $response = $this->get(self::MEMBER, $uri);
            self::assertSame(200, $response->status, $uri);
            self::assertStringNotContainsString(self::XSS, $response->body(), $uri);
        }
    }

    public function testHostileNamesAreEscapedOnEveryNewPage(): void
    {
        $this->ops->records()->link($this->admin(), $this->project, $this->items, (string) $this->mixer, $this->rel, (string) $this->note);
        $old = $this->ops->records()->create($this->admin(), $this->project, $this->notes, [$this->ops->schema()->design($this->admin(), $this->project, $this->notes)->fields[0]->field->key => 'Old ' . self::XSS]);
        $this->ops->records()->trash($this->admin(), $this->project, $this->notes, (string) $old);
        $file = $this->record($this->mixer) . '/files/' . $this->files;
        $pages = [
            '/projects/' . $this->project . '/trash',
            '/projects/' . $this->project . '/trash?table=' . $this->notes,
            '/projects/' . $this->project . '/trash/empty',
            $this->record($this->mixer),
            $file . '/' . $this->file,
            $file . '/' . $this->file . '/remove',
            $file . '/new',
            $file . '/new?replace=' . $this->file,
            $this->record($this->mixer) . '/links/' . $this->rel,
            $this->record($this->mixer) . '/links/' . $this->rel . '?q=' . rawurlencode(self::XSS),
            '/projects/' . $this->project . '/tables/' . $this->notes . '/records/new?from=' . $this->rel . '-' . $this->mixer,
            '/projects/' . $this->project . '/tables/' . $this->items . '/collections/new',
        ];
        foreach ($pages as $uri) {
            $response = $this->get(self::ADMIN, $uri);
            self::assertSame(200, $response->status, $uri);
            self::assertStringNotContainsString(self::XSS, $response->body(), $uri);
            self::assertStringNotContainsString('<script>', $response->body(), $uri);
        }
    }

    public function testADeactivatedPersonsOldActorGetsNoFile(): void
    {
        $member = $this->core->actor('member');
        $this->ops->people()->deactivate($this->admin(), $this->core->person('member')->key);
        foreach ([
            fn () => $this->ops->attachments()->serve($member, $this->project, (string) $this->file, false),
            fn () => $this->ops->attachments()->file($member, $this->project, $this->items, (string) $this->mixer, $this->files, (string) $this->file),
            fn () => $this->ops->attachments()->add($member, $this->project, $this->items, (string) $this->mixer, $this->files, $this->core->upload('x'), 'x.txt', null),
        ] as $i => $call) {
            try {
                $call();
                self::fail('Case ' . $i . ' was allowed.');
            } catch (AccessDenied) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFailedUploadsLeaveNoRowAndNoFile(): void
    {
        $before = $this->state();
        $path = $this->record($this->mixer) . '/files/' . $this->files;
        self::assertSame(422, $this->send(self::MEMBER, 'POST', $path, ['_csrf' => FakeServices::SESSION_CSRF])->status, 'No file.');
        self::assertSame(404, $this->send(self::MEMBER, 'POST', $path, ['_csrf' => FakeServices::SESSION_CSRF, 'replace' => '999'])->status, 'Replace of a file it does not hold.');
        $empty = $this->ops->attachments();
        try {
            $empty->add($this->core->actor('member'), $this->project, $this->items, (string) $this->mixer, $this->files, $this->core->upload(''), 'zero.bin', null);
            self::fail('A zero-byte upload was stored.');
        } catch (\TiffinDB\Core\Error\ValidationFailed $failed) {
            self::assertSame('problem.upload.empty', $failed->problems[0]->key);
        }
        self::assertSame($before, $this->state());
        self::assertSame(1, count(glob($this->core->projectFolder($this->project) . '/attachments/*/*') ?: []), 'Only the first file is stored.');
    }

    // Helpers.

    /** @return array<string, mixed> attachments, links, trash, collections, record versions */
    private function state(): array
    {
        $db = $this->core->projectDb($this->project);

        return [
            'attachments' => $db->all('SELECT * FROM "_tiffin_attachments" ORDER BY "id"'),
            'attachment links' => $db->all('SELECT * FROM "_tiffin_attachment_links" ORDER BY 1, 2, 3'),
            'collections' => $db->all('SELECT * FROM "_tiffin_collections" ORDER BY "key"'),
            'trash' => $this->ops->trash()->count($this->admin(), $this->project, null),
            'links' => $this->ops->records()->screen($this->admin(), $this->project, $this->items, (string) $this->mixer, \TiffinDB\Core\App\ListRequest::fromQuery([]))->version,
            'records' => [$this->ops->records()->listing($this->admin(), $this->project, $this->notes, \TiffinDB\Core\App\ListRequest::fromQuery([]))->total],
        ];
    }

    private function collection(string $name, string $value = 'x'): string
    {
        return $this->ops->collections()->save($this->admin(), $this->project, $this->items, null, ['name' => $name, 'match' => 'all', 'rules' => [1 => ['field' => $this->name, 'test' => 'contains', 'value' => $value]]]);
    }

    private function record(int $id): string
    {
        return '/projects/' . $this->project . '/tables/' . $this->items . '/records/' . $id;
    }

    private function get(string $token, string $uri): Response
    {
        return $this->send($token, 'GET', $uri);
    }

    /** @param array<string, mixed> $post */
    private function send(string $token, string $method, string $uri, array $post = []): Response
    {
        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

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
}
