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
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\TrashController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-59 (M4-WP8): the trash screen and the empty-the-trash
 * confirmation of docs/design/m4/trash.md, through the kernel with the
 * real templates and strings and real core operations over a CoreFixture
 * instance: every table's trash, one table's trash with its pager, the
 * empty state, restore and restore all (303 and notice), the confirmation
 * with its record and file counts, emptying (303), the changed-counts
 * re-ask, the Member 403 on both empty pages, and 404 for an ungranted
 * person. Synthetic data only.
 */
#[CoversClass(TrashController::class)]
final class TrashScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const STRANGER = 'tokenCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $rooms;
    private string $itemName;
    private string $roomName;

    protected function setUp(): void
    {
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
        $this->project = $this->ops->projects()->create($this->admin(), 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($this->admin(), $this->project)[0]->table->key;
        $this->itemName = $this->fieldKey($this->items, 'Name');
        $this->rooms = $this->ops->schema()->createTable($this->admin(), $this->project, 'Rooms', 'room', true)->key;
        $this->roomName = $this->fieldKey($this->rooms, 'Name');
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // The trash (trash.md section 2).

    public function testEmptyTrash(): void
    {
        $this->add($this->items, $this->itemName, 'Stand mixer');
        $html = $this->page(self::MEMBER, $this->t());
        $x = $this->xpath($html);

        self::assertSame('Trash - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Trash'], $this->crumbs($x));
        self::assertSame(['/projects/' . $this->project], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('Trash', $this->text($x, '//main//h1'));
        self::assertSame('The trash is empty.', $this->text($x, '//div[@class="empty-state"]/p[@class="empty-state-title"]'));
        self::assertSame('Back to Home Inventory', $this->text($x, '//div[@class="empty-state"]/a'));
        self::assertSame('/projects/' . $this->project, $this->attr($x, '//div[@class="empty-state"]/a', 'href'));
        self::assertSame(0, $x->query('//section | //main//form | //div[@class="danger-zone"] | //p[@class="lead"]')->length);
    }

    public function testEveryTableWithRecordsInTheTrash(): void
    {
        $this->add($this->items, $this->itemName, 'Kept lamp');
        $older = $this->add($this->items, $this->itemName, 'Stand mixer');
        $this->trash($this->items, $older, 'admin');
        $this->core->clock->set('2026-11-03T10:05:00Z');
        $untitled = $this->add($this->items, $this->itemName, '');
        $this->trash($this->items, $untitled, 'member');
        $room = $this->add($this->rooms, $this->roomName, 'Garage <b>');
        $this->trash($this->rooms, $room, 'admin');
        $this->ops->schema()->createTable($this->admin(), $this->project, 'Notes', 'note', true);

        $html = $this->page(self::ADMIN, $this->t());
        $x = $this->xpath($html);

        self::assertSame(1, $x->query('//main[@id="main"]')->length);
        self::assertSame(
            '3 records are in the trash. Records stay here until an Admin empties the trash. Restoring a record brings back its links and files.',
            $this->text($x, '//p[@class="lead"]'),
        );
        self::assertSame(['Items 2 records', 'Rooms 1 record'], $this->texts($x, '//section[@class="panel trash-section"]//h2'), 'Only tables with records in the trash, in table order.');
        foreach ([$this->items, $this->rooms] as $key) {
            $section = '//section[@aria-labelledby="trash-' . $key . '"]';
            self::assertSame(1, $x->query($section . '/div[@class="trash-head"]/h2[@id="trash-' . $key . '"]')->length);
        }
        $items = '//section[@aria-labelledby="trash-' . $this->items . '"]';
        self::assertSame('2 records', $this->text($x, $items . '//h2/span[@class="help"]'));
        self::assertSame(['Item ' . $untitled, 'Stand mixer'], $this->texts($x, $items . '//li[@class="trash-row"]/span[@class="trash-title"]/text()'), 'Newest first; untitled records are named by item and id.');
        self::assertSame(
            ['Moved to the trash today by Example Member.', 'Moved to the trash today by Example Admin.'],
            $this->texts($x, $items . '//span[@class="trash-meta"]'),
        );
        self::assertSame(0, $x->query($items . '//li//a')->length, 'A trashed record is plain text, not a link.');
        self::assertSame(['Restore Item ' . $untitled, 'Restore Stand mixer'], $this->texts($x, $items . '//li//button/@aria-label'));
        self::assertSame(['Restore', 'Restore'], $this->texts($x, $items . '//li//button'));

        $restore = $items . '//li[2]/form';
        self::assertSame('post', $this->attr($x, $restore, 'method'));
        self::assertSame('inline-form', $this->attr($x, $restore, 'class'));
        self::assertSame($this->t() . '/restore', $this->attr($x, $restore, 'action'));
        self::assertSame(
            ['_csrf' => FakeServices::SESSION_CSRF, 'table' => $this->items, 'record' => (string) $older, 'view' => 'all', 'page' => '1'],
            $this->hidden($x, $restore),
        );
        self::assertSame('button button-plain', $this->attr($x, $restore . '/button', 'class'));

        $all = $items . '/div[@class="trash-head"]/form';
        self::assertSame($this->t() . '/restore-all', $this->attr($x, $all, 'action'));
        self::assertSame(['_csrf' => FakeServices::SESSION_CSRF, 'table' => $this->items], $this->hidden($x, $all));
        self::assertSame('Restore all in Items', $this->text($x, $all . '/button'));

        $rooms = '//section[@aria-labelledby="trash-' . $this->rooms . '"]';
        self::assertSame(['Garage <b>'], $this->texts($x, $rooms . '//span[@class="trash-title"]/text()'), 'Titles are escaped.');
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame(0, $x->query('//nav[@class="pager"] | //section//p/a')->length, 'No pager and no "Show all" for 50 or fewer.');

        self::assertSame('Emptying the trash deletes these records and the files only they hold, for good.', $this->text($x, '//div[@class="danger-zone"]/p'));
        self::assertSame('Empty the trash', $this->text($x, '//div[@class="danger-zone"]/a[@class="button button-danger button-large"]'));
        self::assertSame($this->t() . '/empty', $this->attr($x, '//div[@class="danger-zone"]/a', 'href'));
    }

    public function testMembersRestoreButSeeNoEmptyLink(): void
    {
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Stand mixer'), 'admin');
        $x = $this->xpath($this->page(self::MEMBER, $this->t()));

        self::assertSame(['Restore all in Items'], $this->texts($x, '//div[@class="trash-head"]//button'));
        self::assertSame(['Restore Stand mixer'], $this->texts($x, '//li//button/@aria-label'));
        self::assertSame(0, $x->query('//div[@class="danger-zone"] | //a[contains(@href, "/trash/empty")]')->length);
    }

    public function testMoreThanFiftyShowsTheFirstFiftyAndALink(): void
    {
        $this->trashedRows($this->items, 52);
        $x = $this->xpath($this->page(self::ADMIN, $this->t()));

        $items = '//section[@aria-labelledby="trash-' . $this->items . '"]';
        self::assertSame(50, $x->query($items . '//li[@class="trash-row"]')->length);
        self::assertSame('52 records', $this->text($x, $items . '//h2/span'));
        self::assertSame('Show all 52 Items in the trash', $this->text($x, $items . '/p/a'));
        self::assertSame($this->t() . '?table=' . $this->items, $this->attr($x, $items . '/p/a', 'href'));
        self::assertSame('Moved to the trash today.', $this->text($x, $items . '//span[@class="trash-meta"]'), 'Nobody recorded: no name.');
        self::assertSame(0, $x->query('//nav[@class="pager"]')->length);
    }

    public function testOneTablesTrashIsPaged(): void
    {
        $this->trashedRows($this->items, 52);
        $this->trash($this->rooms, $this->add($this->rooms, $this->roomName, 'Garage'), 'admin');

        $html = $this->page(self::ADMIN, $this->t() . '?table=' . $this->items . '&page=2');
        $x = $this->xpath($html);
        self::assertSame(['Home Inventory', 'Trash'], $this->crumbs($x));
        self::assertSame(['Items 52 records'], $this->texts($x, '//section//h2'), 'Only that table.');
        self::assertSame('Show every table', $this->text($x, '//main/p/a'));
        self::assertSame($this->t(), $this->attr($x, '//main/p/a', 'href'));
        self::assertSame(2, $x->query('//li[@class="trash-row"]')->length);
        self::assertSame(['view' => 'table', 'page' => '2'], array_intersect_key($this->hidden($x, '//li[1]/form'), ['view' => 1, 'page' => 1]));
        self::assertSame('Showing 51 to 52 of 52', $this->text($x, '//nav[@class="pager"]/span[@class="pager-status"]'));
        self::assertSame($this->t() . '?page=1&table=' . $this->items, $this->attr($x, '//nav[@class="pager"]//a[@rel="prev"]', 'href'));
        self::assertSame(0, $x->query('//nav[@class="pager"]//a[@rel="next"]')->length);
        self::assertSame(0, $x->query('//section/p/a')->length, 'No "Show all" on the table page.');
        self::assertSame(1, $x->query('//div[@class="danger-zone"]')->length);

        $clamped = $this->xpath($this->page(self::ADMIN, $this->t() . '?table=' . $this->items . '&page=99'));
        self::assertSame('Showing 51 to 52 of 52', $this->text($clamped, '//span[@class="pager-status"]'));
        $first = $this->xpath($this->page(self::ADMIN, $this->t() . '?table=' . $this->items . '&page=x'));
        self::assertSame('Showing 1 to 50 of 52', $this->text($first, '//span[@class="pager-status"]'));

        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->t() . '?table=missing')->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->t() . '?table[]=' . $this->items)->status);
    }

    // Results (trash.md section 3).

    public function testRestoreOneRecord(): void
    {
        $id = $this->add($this->items, $this->itemName, 'Stand mixer');
        $this->trash($this->items, $id, 'admin');

        $response = $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => (string) $id, 'view' => 'all', 'page' => '1']);
        self::assertSame(303, $response->status);
        self::assertSame($this->t(), $response->header('Location'));
        self::assertSame(['key' => 'notice.record_restored', 'params' => ['title' => 'Stand mixer']], $this->services->flash[self::MEMBER]);
        self::assertSame(0, $this->ops->trash()->count($this->admin(), $this->project, null));
        $html = $this->page(self::MEMBER, $this->t());
        self::assertSame('Stand mixer has been restored.', HtmlAssertions::text($html, '//div[@class="notice"][@role="status"]/p'));

        // A record no longer in the trash: no change, the same notice.
        $again = $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => (string) $id, 'view' => 'table', 'page' => '3']);
        self::assertSame(303, $again->status);
        self::assertSame($this->t() . '?table=' . $this->items . '&page=3', $again->header('Location'));
        self::assertSame('notice.record_restored', $this->services->flash[self::MEMBER]['key']);

        $untitled = $this->add($this->items, $this->itemName, '');
        $this->trash($this->items, $untitled, 'admin');
        $tampered = $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => (string) $untitled, 'view' => 'other', 'page' => '-1']);
        self::assertSame($this->t(), $tampered->header('Location'), 'Any view but "table" goes back to every table.');
        self::assertSame(['title' => 'Item ' . $untitled], $this->services->flash[self::MEMBER]['params']);
        $badPage = $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => (string) $untitled, 'view' => 'table', 'page' => '1&x=2']);
        self::assertSame($this->t() . '?table=' . $this->items . '&page=1', $badPage->header('Location'));

        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => 'missing', 'record' => (string) $id])->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => '999'])->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->t() . '/restore', ['table' => $this->items, 'record' => 'x'])->status);
    }

    public function testRestoreAllInATable(): void
    {
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Stand mixer'), 'admin');
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Drill'), 'admin');
        $this->trash($this->rooms, $this->add($this->rooms, $this->roomName, 'Garage'), 'admin');

        $response = $this->request(self::MEMBER, 'POST', $this->t() . '/restore-all', ['table' => $this->items]);
        self::assertSame(303, $response->status);
        self::assertSame($this->t(), $response->header('Location'));
        self::assertSame(['key' => 'notice.trash_restored_all', 'params' => ['count' => 2, 'table' => 'Items']], $this->services->flash[self::MEMBER]);
        self::assertSame(0, $this->ops->trash()->count($this->admin(), $this->project, $this->items));
        self::assertSame(1, $this->ops->trash()->count($this->admin(), $this->project, $this->rooms));

        $x = $this->xpath($this->page(self::MEMBER, $this->t()));
        self::assertSame('2 records in Items have been restored.', $this->text($x, '//div[@class="notice"]/p'));
        self::assertSame(['Rooms 1 record'], $this->texts($x, '//section//h2'));

        $this->request(self::MEMBER, 'POST', $this->t() . '/restore-all', ['table' => $this->rooms]);
        self::assertSame('1 record in Rooms has been restored.', $this->text($this->xpath($this->page(self::MEMBER, $this->t())), '//div[@class="notice"]/p'));
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->t() . '/restore-all', ['table' => 'missing'])->status);
    }

    // Empty the trash (trash.md section 4).

    public function testEmptyConfirmationStatesRecordsAndFiles(): void
    {
        $files = $this->fieldKeyAdded('Files');
        $mixer = $this->add($this->items, $this->itemName, 'Stand mixer');
        $this->attach($files, $mixer, FileFixture::bytes(64, 'receipt'), 'receipt.pdf');
        $this->attach($files, $mixer, FileFixture::bytes(64, 'manual'), 'manual.pdf');
        $this->trash($this->items, $mixer, 'admin');
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Drill'), 'admin');
        $this->trash($this->rooms, $this->add($this->rooms, $this->roomName, 'Garage'), 'admin');

        $html = $this->page(self::ADMIN, $this->t() . '/empty');
        $x = $this->xpath($html);
        self::assertSame('Empty the trash? - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Trash', 'Empty the trash?'], $this->crumbs($x));
        self::assertSame('Empty the trash?', $this->text($x, '//main//h1'));
        self::assertSame(
            ['3 records will be deleted for good. A copy of the project is kept as a snapshot first, but files are not part of snapshots.', '2 files that only these records hold will be deleted.'],
            $this->texts($x, '//main/p'),
        );
        self::assertSame(['Items: 2 records', 'Rooms: 1 record'], $this->texts($x, '//main/ul/li'));
        self::assertSame(0, $x->query('//div[@class="notice"]')->length);
        $form = '//main/form';
        self::assertSame($this->t() . '/empty', $this->attr($x, $form, 'action'));
        self::assertSame(['_csrf' => FakeServices::SESSION_CSRF, 'expected' => '3'], $this->hidden($x, $form));
        self::assertSame('Empty the trash', $this->text($x, $form . '/div[@class="form-actions"]/button[@class="button button-large button-danger"][@type="submit"]'));
        self::assertSame($this->t(), $this->attr($x, $form . '/div[@class="form-actions"]/a[@class="form-cancel"]', 'href'));

        $response = $this->request(self::ADMIN, 'POST', $this->t() . '/empty', ['expected' => '3']);
        self::assertSame(303, $response->status);
        self::assertSame($this->t(), $response->header('Location'));
        self::assertSame(['key' => 'notice.trash_emptied', 'params' => ['count' => 3, 'files' => 2]], $this->services->flash[self::ADMIN]);
        self::assertSame(0, $this->ops->trash()->count($this->admin(), $this->project, null));
        self::assertSame([], $this->storedFiles(), 'The freed files are gone from the attachments folder.');

        $after = $this->xpath($this->page(self::ADMIN, $this->t()));
        self::assertSame('The trash has been emptied: 3 records and 2 files deleted.', $this->text($after, '//div[@class="notice"]/p'));
        self::assertSame('The trash is empty.', $this->text($after, '//p[@class="empty-state-title"]'));
    }

    public function testNoFilesLineWithoutFiles(): void
    {
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Drill'), 'admin');
        $x = $this->xpath($this->page(self::ADMIN, $this->t() . '/empty'));

        self::assertSame(['1 record will be deleted for good. A copy of the project is kept as a snapshot first, but files are not part of snapshots.'], $this->texts($x, '//main/p'));
        self::assertSame(['Items: 1 record'], $this->texts($x, '//main/ul/li'));
    }

    public function testChangedCountsAskAgain(): void
    {
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Drill'), 'admin');
        $this->page(self::ADMIN, $this->t() . '/empty');
        $this->trash($this->rooms, $this->add($this->rooms, $this->roomName, 'Garage'), 'member');

        $response = $this->request(self::ADMIN, 'POST', $this->t() . '/empty', ['expected' => '1']);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('The trash changed while you were looking. Check the new numbers.', $this->text($x, '//main/div[@class="notice"][@role="status"]/p'));
        self::assertSame(['Items: 1 record', 'Rooms: 1 record'], $this->texts($x, '//main/ul/li'));
        self::assertSame('2', $this->hidden($x, '//main/form')['expected']);
        self::assertSame(2, $this->ops->trash()->count($this->admin(), $this->project, null), 'Nothing was deleted.');
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash);

        foreach (['', 'x', '-1', '1e3', '99999999999'] as $tampered) {
            $again = $this->request(self::ADMIN, 'POST', $this->t() . '/empty', ['expected' => $tampered]);
            self::assertSame(200, $again->status, "expected={$tampered}");
            self::assertSame(2, $this->ops->trash()->count($this->admin(), $this->project, null));
        }

        // Fewer waiting than shown (someone restored meanwhile) goes ahead.
        $this->request(self::MEMBER, 'POST', $this->t() . '/restore-all', ['table' => $this->rooms]);
        $done = $this->request(self::ADMIN, 'POST', $this->t() . '/empty', ['expected' => '2']);
        self::assertSame(303, $done->status);
        self::assertSame(['key' => 'notice.trash_emptied', 'params' => ['count' => 1, 'files' => 0]], $this->services->flash[self::ADMIN]);
    }

    public function testNothingToEmpty(): void
    {
        $get = $this->request(self::ADMIN, 'GET', $this->t() . '/empty');
        self::assertSame(303, $get->status);
        self::assertSame($this->t(), $get->header('Location'));

        $post = $this->request(self::ADMIN, 'POST', $this->t() . '/empty', ['expected' => '0']);
        self::assertSame(303, $post->status);
        self::assertSame($this->t(), $post->header('Location'));
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash);
    }

    public function testMembersCannotEmpty(): void
    {
        $this->trash($this->items, $this->add($this->items, $this->itemName, 'Drill'), 'admin');

        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t() . '/empty')->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t() . '/empty', ['expected' => '1'])->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t() . '/empty', ['expected' => '0'])->status);
        self::assertSame(1, $this->ops->trash()->count($this->admin(), $this->project, null));
        self::assertArrayNotHasKey(self::MEMBER, $this->services->flash);
    }

    public function testUngrantedPeopleGetNotFound(): void
    {
        $id = $this->add($this->items, $this->itemName, 'Drill');
        $this->trash($this->items, $id, 'admin');

        foreach (
            [
                ['GET', $this->t(), []],
                ['GET', $this->t() . '?table=' . $this->items, []],
                ['POST', $this->t() . '/restore', ['table' => $this->items, 'record' => (string) $id]],
                ['POST', $this->t() . '/restore-all', ['table' => $this->items]],
                ['GET', $this->t() . '/empty', []],
                ['POST', $this->t() . '/empty', ['expected' => '1']],
                ['GET', '/projects/missing/trash', []],
            ] as [$method, $uri, $post]
        ) {
            self::assertSame(404, $this->request(self::STRANGER, $method, $uri, $post)->status, "{$method} {$uri}");
        }
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/projects/missing/trash/empty')->status);
        self::assertSame(1, $this->ops->trash()->count($this->admin(), $this->project, null));
    }

    public function testPostsNeedTheCsrfToken(): void
    {
        $id = $this->add($this->items, $this->itemName, 'Drill');
        $this->trash($this->items, $id, 'admin');
        foreach (['/restore' => ['table' => $this->items, 'record' => (string) $id], '/restore-all' => ['table' => $this->items], '/empty' => ['expected' => '1']] as $path => $post) {
            $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $this->t() . $path, $post, ['tdb_session' => self::ADMIN]))[0];
            self::assertNotSame(303, $response->status, $path);
        }
        self::assertSame(1, $this->ops->trash()->count($this->admin(), $this->project, null));
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

    private function fieldKey(string $table, string $name): string
    {
        foreach ($this->ops->schema()->design($this->admin(), $this->project, $table)->fields as $summary) {
            if ($summary->field->name === $name) {
                return $summary->field->key;
            }
        }
        self::fail("No field {$name}.");
    }

    private function fieldKeyAdded(string $name): string
    {
        return $this->ops->schema()->addField($this->admin(), $this->project, $this->items, $name, 'attachment', [], false, '')->key;
    }

    private function add(string $table, string $nameField, string $name): int
    {
        return $this->ops->records()->create($this->admin(), $this->project, $table, $name === '' ? [] : [$nameField => $name]);
    }

    private function trash(string $table, int $id, string $username): void
    {
        $this->ops->records()->trash($this->core->actor($username), $this->project, $table, (string) $id);
    }

    /** $count records put straight into the trash, with nobody recorded. */
    private function trashedRows(string $table, int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $this->core->insertRecord($this->project, $table, [$this->itemName => sprintf('Example item %02d', $n)], true);
        }
    }

    private function attach(string $field, int $record, string $contents, string $name): void
    {
        $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $record, $field, $this->core->upload($contents), $name, null);
    }

    /** @return list<string> files under the project's attachments folder */
    private function storedFiles(): array
    {
        $root = $this->core->projectFolder($this->project) . '/attachments';
        $found = [];
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $found[] = $file->getFilename();
                }
            }
        }

        return $found;
    }

    private function t(): string
    {
        return '/projects/' . $this->project . '/trash';
    }

    /** @param array<string, string> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
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
