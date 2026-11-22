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
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\LinkController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-51 (M4-WP6): the relation picker of docs/design/m4/picker.md
 * through the kernel with the real templates, strings, and core
 * operations over a CoreFixture instance: its states (one link, many
 * links, find, pages, a taken candidate, a link in the trash), Link,
 * Choose, and Unlink (303 with `q` and `page` kept, `back=record` to the
 * record), the refused links (422), tampered posts, and access.
 * Synthetic data only.
 */
#[CoversClass(LinkController::class)]
final class LinkScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const HOSTILE = '<b>"Kitchen" & \'co\'</b>';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $rooms;
    private string $warranties;
    private string $room;
    private string $warranty;
    private int $mixer;
    private int $kettle;
    /** @var array<string, int> room name => id */
    private array $roomIds = [];
    /** @var array<string, int> warranty name => id */
    private array $warrantyIds = [];

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $admin = $this->admin();
        $schema = $this->ops->schema();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->rooms = $schema->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
        $this->warranties = $schema->createTable($admin, $this->project, 'Warranties', 'warranty', true)->key;
        $this->room = $schema->addRelationField($admin, $this->project, $this->items, 'Room', $this->rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->warranty = $schema->addRelationField($admin, $this->project, $this->items, 'Warranties', $this->warranties, Cardinality::Many, Cardinality::One, 'Item', false, '')->key;
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);

        foreach (['Kitchen', 'Garage', 'Study'] as $name) {
            $this->roomIds[$name] = $this->add($this->rooms, $name);
        }
        foreach (['Stand mixer, manufacturer warranty', 'Kettle warranty', 'Extended cover'] as $name) {
            $this->warrantyIds[$name] = $this->add($this->warranties, $name);
        }
        $this->mixer = $this->add($this->items, 'Stand mixer');
        $this->kettle = $this->add($this->items, 'Kettle');
        $records = $this->ops->records();
        $records->setLinks($admin, $this->project, $this->items, (string) $this->mixer, $this->room, [$this->roomIds['Kitchen']]);
        $records->setLinks($admin, $this->project, $this->items, (string) $this->mixer, $this->warranty, [$this->warrantyIds['Stand mixer, manufacturer warranty']]);
        $records->setLinks($admin, $this->project, $this->items, (string) $this->kettle, $this->warranty, [$this->warrantyIds['Kettle warranty']]);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // Picker states (picker.md section 2).

    public function testPickerForAOneLinkField(): void
    {
        $html = $this->page(self::MEMBER, $this->picker($this->mixer, $this->room));
        $x = $this->xpath($html);

        self::assertSame('Room for Stand mixer - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-record page-record-narrow"]')->length);
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'Choose Rooms'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame(['/projects/' . $this->project, $this->l($this->items), $this->r($this->items, $this->mixer)], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('Room for Stand mixer', $this->text($x, '//div[@class="page-head"]//h1'));
        self::assertSame('Choose one room. Choosing replaces the current one.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));

        // Linked now: Kitchen, with its Unlink form.
        self::assertSame('Linked now', $this->text($x, '//section[@aria-labelledby="pk-now"]/h2[@id="pk-now"]'));
        self::assertSame(['Kitchen'], $this->texts($x, '//section[@aria-labelledby="pk-now"]/ul[@class="picker-list"]/li[@class="picker-row"]/span[@class="picker-title"]/a'));
        self::assertSame($this->r($this->rooms, $this->roomIds['Kitchen']), $this->attr($x, '//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]/a', 'href'));
        $unlink = $this->one($x, '//section[@aria-labelledby="pk-now"]//form[@class="inline-form"]');
        self::assertSame('post', $unlink->getAttribute('method'));
        self::assertSame($this->picker($this->mixer, $this->room) . '/remove', $unlink->getAttribute('action'));
        self::assertSame(['target' => (string) $this->roomIds['Kitchen'], 'back' => 'picker', 'q' => '', 'page' => '1'], $this->hidden($x, $unlink));
        $button = $this->one($x, '//section[@aria-labelledby="pk-now"]//button');
        self::assertSame(['Unlink', 'Unlink Kitchen', 'button button-plain', 'submit'], [$button->textContent, $button->getAttribute('aria-label'), $button->getAttribute('class'), $button->getAttribute('type')]);

        // Find box: a GET form with a visible label.
        $find = $this->one($x, '//form[@class="picker-find"]');
        self::assertSame(['get', $this->picker($this->mixer, $this->room)], [$find->getAttribute('method'), $find->getAttribute('action')]);
        self::assertSame('Find in Rooms', $this->text($x, '//form[@class="picker-find"]/div[@class="field"]/label[@class="label"][@for="f-q"]'));
        $box = $this->one($x, '//input[@id="f-q"]');
        self::assertSame(['search', 'q', '200', ''], [$box->getAttribute('type'), $box->getAttribute('name'), $box->getAttribute('maxlength'), $box->getAttribute('value')]);
        self::assertSame('Find', $this->text($x, '//form[@class="picker-find"]/button[@class="button"][@type="submit"]'));
        self::assertSame(0, $x->query('//form[@class="picker-find"]/a')->length, 'No "Show all" without find text.');

        // The candidates: the other rooms, each with Choose.
        self::assertSame('Rooms you can link 2 records', $this->text($x, '//h2[@id="pk-list"]'));
        self::assertSame('2 records', $this->text($x, '//h2[@id="pk-list"]/span[@class="help"]'));
        self::assertSame(['Garage', 'Study'], $this->texts($x, '//section[@aria-labelledby="pk-list"]/ul[@class="picker-list"]/li/span[@class="picker-title"]/a'));
        self::assertSame(['Choose Garage', 'Choose Study'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//button/@aria-label'));
        self::assertSame(['Choose', 'Choose'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//button'));
        $choose = $this->one($x, '//section[@aria-labelledby="pk-list"]//form');
        self::assertSame($this->picker($this->mixer, $this->room) . '/add', $choose->getAttribute('action'));
        self::assertSame(['target' => (string) $this->roomIds['Garage'], 'back' => 'picker', 'q' => '', 'page' => '1'], $this->hidden($x, $choose));
        foreach ($x->query('//ul[@class="picker-list"]/li') as $row) {
            self::assertLessThanOrEqual(1, $x->query('.//button', $row)->length, 'At most one button per row.');
            self::assertSame(1, $x->query('./span[@class="picker-title"]', $row)->length, 'One title per row.');
        }
        self::assertSame(0, $x->query('//nav[@class="pager"]')->length);

        // Actions: back to the record (primary), add a new room.
        self::assertSame(
            [['Back to Stand mixer', $this->r($this->items, $this->mixer), 'button button-primary button-large'], ['Add a new room and choose it', $this->l($this->rooms) . '/records/new?from=' . $this->room . '-' . $this->mixer, 'button button-large']],
            array_map(static fn (DOMElement $a): array => [trim($a->textContent), $a->getAttribute('href'), $a->getAttribute('class')], $this->elements($x, '//div[@class="form-actions"]/a')),
        );
        self::assertSame(1, $x->query('//main//*[contains(concat(" ", @class, " "), " button-primary ")]')->length, 'One primary action.');
    }

    public function testPickerForAManyLinkFieldWithATakenCandidate(): void
    {
        $html = $this->page(self::MEMBER, $this->picker($this->mixer, $this->warranty));
        $x = $this->xpath($html);

        self::assertSame('Warranties for Stand mixer', $this->text($x, '//h1'));
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer', 'Choose Warranties'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame('Link as many as you need. Each press saves at once.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Unlink Stand mixer, manufacturer warranty'], $this->texts($x, '//section[@aria-labelledby="pk-now"]//button/@aria-label'));

        // Kettle warranty is linked to the Kettle, and a warranty may be linked to one item only.
        self::assertSame(['Extended cover', 'Kettle warranty'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//li/span[@class="picker-title"]/a'));
        self::assertSame(['Link Extended cover'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//button/@aria-label'));
        self::assertSame(['Link'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//button'));
        self::assertSame('Linked to Kettle', $this->text($x, '//li[span/a[.="Kettle warranty"]]/span[@class="picker-taken"]'));
        self::assertSame(0, $x->query('//li[span/a[.="Kettle warranty"]]//form')->length);
        self::assertSame('Add a new warranty and link it', $this->text($x, '//div[@class="form-actions"]/a[2]'));
    }

    public function testPickerWithNothingLinkedAndNothingLeft(): void
    {
        $study = $this->add($this->items, 'Desk lamp');
        $x = $this->xpath($this->page(self::MEMBER, $this->picker($study, $this->room)));
        self::assertSame('Nothing is linked yet.', $this->text($x, '//section[@aria-labelledby="pk-now"]/p[@class="static-value"]'));
        self::assertSame(0, $x->query('//section[@aria-labelledby="pk-now"]//form')->length);
        self::assertSame(['Choose Garage', 'Choose Kitchen', 'Choose Study'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//button/@aria-label'));

        // A self-relation over a table holding only this record lists it; with it linked, none is left.
        $self = $this->ops->schema()->addRelationField($this->admin(), $this->project, $this->warranties, 'Replaces', $this->warranties, Cardinality::Many, Cardinality::Many, 'Replaced by', false, '')->key;
        $cover = $this->warrantyIds['Extended cover'];
        $this->ops->records()->setLinks($this->admin(), $this->project, $this->warranties, (string) $cover, $self, array_values($this->warrantyIds));
        $none = $this->xpath($this->page(self::MEMBER, $this->r($this->warranties, $cover) . '/links/' . $self));
        self::assertSame('No Warranties left to link.', $this->text($none, '//section[@aria-labelledby="pk-list"]/p[@class="static-value"]'));
        self::assertSame('Warranties you can link 0 records', $this->text($none, '//h2[@id="pk-list"]'));

        $lamp = $this->xpath($this->page(self::MEMBER, $this->picker($study, $this->room) . '?q=zzz'));
        self::assertSame('Nothing in Rooms matches "zzz".', $this->text($lamp, '//section[@aria-labelledby="pk-list"]/p[@class="static-value"]'));
        self::assertSame('Found in Rooms 0 records', $this->text($lamp, '//h2[@id="pk-list"]'));
    }

    public function testFindKeepsTheTextAndShowAll(): void
    {
        $path = $this->picker($this->mixer, $this->room);
        $x = $this->xpath($this->page(self::MEMBER, $path . '?q=gar'));

        self::assertSame('gar', $this->attr($x, '//input[@id="f-q"]', 'value'));
        self::assertSame('Found in Rooms 1 record', $this->text($x, '//h2[@id="pk-list"]'));
        self::assertSame(['Garage'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//li/span[@class="picker-title"]/a'));
        self::assertSame('Show all', $this->text($x, '//form[@class="picker-find"]/a'));
        self::assertSame($path, $this->attr($x, '//form[@class="picker-find"]/a', 'href'));
        foreach ($this->elements($x, '//main//form[@class="inline-form"]') as $form) {
            self::assertSame('gar', $this->hidden($x, $form)['q'], 'Every press posts the find text back.');
        }
    }

    public function testARefusedSearchShowsTheProblemUnderTheBox(): void
    {
        $long = str_repeat('a', 201);
        $html = $this->page(self::MEMBER, $this->picker($this->mixer, $this->room) . '?q=' . $long);
        $x = $this->xpath($html);

        self::assertNotSame('', $this->text($x, '//form[@class="picker-find"]/div[@class="field"]/p[@class="error-text"][@id="err-q"]'));
        self::assertSame(['true', 'err-q', $long], [$this->attr($x, '//input[@id="f-q"]', 'aria-invalid'), $this->attr($x, '//input[@id="f-q"]', 'aria-describedby'), $this->attr($x, '//input[@id="f-q"]', 'value')]);
        self::assertSame(['Garage', 'Study'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//li/span[@class="picker-title"]/a'), 'An unfiltered list.');
        self::assertSame('', $this->hidden($x, $this->one($x, '//section[@aria-labelledby="pk-list"]//form'))['q'], 'A refused text is not posted back.');
    }

    public function testPagesOfCandidates(): void
    {
        for ($n = 1; $n <= 55; $n++) {
            $this->add($this->rooms, sprintf('Shed %02d', $n));
        }
        $path = $this->picker($this->mixer, $this->room);
        $one = $this->xpath($this->page(self::MEMBER, $path . '?q=shed'));
        self::assertSame(50, $one->query('//section[@aria-labelledby="pk-list"]//li')->length);
        self::assertSame('Showing 1 to 50 of 55', $this->text($one, '//nav[@class="pager"]/span[@class="pager-status"]'));
        self::assertSame($path . '?page=2&q=shed', $this->attr($one, '//nav[@class="pager"]//a[@rel="next"]', 'href'));

        $two = $this->xpath($this->page(self::MEMBER, $path . '?q=shed&page=2'));
        self::assertSame('Showing 51 to 55 of 55', $this->text($two, '//nav[@class="pager"]/span[@class="pager-status"]'));
        self::assertSame(['q' => 'shed', 'page' => '2'], array_intersect_key($this->hidden($two, $this->one($two, '//section[@aria-labelledby="pk-list"]//form')), ['q' => 1, 'page' => 1]));

        // A page past the end clamps to the last one; a tampered page is page 1.
        $past = $this->xpath($this->page(self::MEMBER, $path . '?q=shed&page=99'));
        self::assertSame('Showing 51 to 55 of 55', $this->text($past, '//nav[@class="pager"]/span[@class="pager-status"]'));
        foreach (['abc', '0', '-1', '1e3', '99999999'] as $page) {
            $tampered = $this->xpath($this->page(self::MEMBER, $path . '?q=shed&page=' . $page));
            self::assertSame('Showing 1 to 50 of 55', $this->text($tampered, '//nav[@class="pager"]/span[@class="pager-status"]'), $page);
        }
    }

    public function testALinkToARecordInTheTrash(): void
    {
        $this->ops->records()->trash($this->admin(), $this->project, $this->rooms, (string) $this->roomIds['Kitchen']);
        $x = $this->xpath($this->page(self::MEMBER, $this->picker($this->mixer, $this->room)));

        self::assertSame('Nothing is linked yet.', $this->text($x, '//section[@aria-labelledby="pk-now"]/p[@class="static-value"]'));
        self::assertSame('1 linked record is in the trash. Links to records in the trash come back when they are restored.', $this->text($x, '//section[@aria-labelledby="pk-now"]/p[@class="help"]'));
        self::assertSame(
            'The linked room is in the trash. Restore it and unlink it here, or empty the trash, before choosing another.',
            $this->text($x, '//section[@aria-labelledby="pk-now"]/p[@class="notice notice-warn"]'),
        );
        self::assertSame(['Garage', 'Study'], $this->texts($x, '//section[@aria-labelledby="pk-list"]//li/span[@class="picker-title"]/a'));
        self::assertSame(0, $x->query('//section[@aria-labelledby="pk-list"]//form')->length, 'The candidates are listed without buttons.');

        // A forged Choose is refused with the reason (422).
        $refused = $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->room) . '/add', ['target' => (string) $this->roomIds['Garage'], 'back' => 'picker']);
        self::assertSame(422, $refused->status);
        $this->assertScreen($refused->body);
        self::assertSame(
            ['This item is linked to a record in the trash. Restore that record and unlink it, or empty the trash, before choosing another.'],
            $this->texts($this->xpath($refused->body), '//div[@class="error-summary"]//li'),
        );
        self::assertSame([], $this->linked($this->mixer, $this->room));

        // A many-link field says how many links wait in the trash; linking still works.
        $this->ops->records()->trash($this->admin(), $this->project, $this->warranties, (string) $this->warrantyIds['Stand mixer, manufacturer warranty']);
        $many = $this->xpath($this->page(self::MEMBER, $this->picker($this->mixer, $this->warranty)));
        self::assertSame('1 linked record is in the trash. Links to records in the trash come back when they are restored.', $this->text($many, '//section[@aria-labelledby="pk-now"]/p[@class="help"]'));
        self::assertSame(0, $many->query('//p[@class="notice notice-warn"]')->length);
        self::assertSame(['Link Extended cover'], $this->texts($many, '//section[@aria-labelledby="pk-list"]//button/@aria-label'));
    }

    // Link, choose, unlink (picker.md section 3).

    public function testChoosingReplacesAndKeepsFindAndPage(): void
    {
        $response = $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->room) . '/add', [
            'target' => (string) $this->roomIds['Garage'], 'back' => 'picker', 'q' => 'gar', 'page' => '1', 'sort' => 'name', 'extra' => 'x',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame($this->picker($this->mixer, $this->room) . '?q=gar', $response->header('Location'), 'Only q and page (when not 1) are copied.');
        self::assertSame(['key' => 'notice.link_chosen', 'params' => ['title' => 'Garage']], $this->services->flash[self::MEMBER]);
        self::assertSame([$this->roomIds['Garage']], $this->linked($this->mixer, $this->room));

        $page = $this->xpath($this->page(self::MEMBER, $this->picker($this->mixer, $this->room)));
        self::assertSame('Garage is now chosen.', $this->text($page, '//div[@class="notice"][@role="status"]'));
        self::assertSame(['Garage'], $this->texts($page, '//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]/a'));
        self::assertSame(['Kitchen', 'Study'], $this->texts($page, '//section[@aria-labelledby="pk-list"]//span[@class="picker-title"]/a'));
    }

    public function testLinkingAndUnlinkingManyWithPageAndBack(): void
    {
        $path = $this->picker($this->mixer, $this->warranty);
        $add = $this->request(self::MEMBER, 'POST', $path . '/add', ['target' => (string) $this->warrantyIds['Extended cover'], 'back' => 'picker', 'q' => 'cover', 'page' => '3']);
        self::assertSame(303, $add->status);
        self::assertSame($path . '?q=cover&page=3', $add->header('Location'));
        self::assertSame(['key' => 'notice.link_added', 'params' => ['title' => 'Extended cover']], $this->services->flash[self::MEMBER]);
        self::assertSame([$this->warrantyIds['Stand mixer, manufacturer warranty'], $this->warrantyIds['Extended cover']], $this->linked($this->mixer, $this->warranty));

        // Linking again changes nothing (idempotent).
        self::assertSame(303, $this->request(self::MEMBER, 'POST', $path . '/add', ['target' => (string) $this->warrantyIds['Extended cover']])->status);
        self::assertCount(2, $this->linked($this->mixer, $this->warranty));

        $remove = $this->request(self::MEMBER, 'POST', $path . '/remove', ['target' => (string) $this->warrantyIds['Extended cover'], 'back' => 'picker', 'page' => '2']);
        self::assertSame(303, $remove->status);
        self::assertSame($path . '?page=2', $remove->header('Location'));
        self::assertSame(['key' => 'notice.link_removed', 'params' => ['title' => 'Extended cover']], $this->services->flash[self::MEMBER]);

        // From the record form: back=record answers with the record, no state.
        $back = $this->request(self::MEMBER, 'POST', $path . '/remove', ['target' => (string) $this->warrantyIds['Stand mixer, manufacturer warranty'], 'back' => 'record', 'q' => 'x', 'page' => '4']);
        self::assertSame(303, $back->status);
        self::assertSame($this->r($this->items, $this->mixer), $back->header('Location'));
        self::assertSame(['key' => 'notice.link_removed', 'params' => ['title' => 'Stand mixer, manufacturer warranty']], $this->services->flash[self::MEMBER]);
        self::assertSame([], $this->linked($this->mixer, $this->warranty));

        // Unlinking what is not linked is no error.
        self::assertSame(303, $this->request(self::MEMBER, 'POST', $path . '/remove', ['target' => (string) $this->warrantyIds['Kettle warranty']])->status);
        self::assertSame([$this->warrantyIds['Kettle warranty']], $this->linked($this->kettle, $this->warranty));
    }

    /** @return iterable<string, array{mixed}> */
    public static function tamperedBack(): iterable
    {
        yield 'other word' => ['list'];
        yield 'address' => ['//example.org/'];
        yield 'upper case' => ['RECORD'];
        yield 'array' => [['record']];
        yield 'empty' => [''];
    }

    #[DataProvider('tamperedBack')]
    public function testATamperedBackGoesToThePicker(mixed $back): void
    {
        $path = $this->picker($this->mixer, $this->warranty);
        $response = $this->request(self::MEMBER, 'POST', $path . '/add', ['target' => (string) $this->warrantyIds['Extended cover'], 'back' => $back, 'page' => 'abc', 'q' => ['x']]);
        self::assertSame(303, $response->status);
        self::assertSame($path, $response->header('Location'), 'A tampered page or q is dropped.');
    }

    public function testATakenTargetIsRefused(): void
    {
        $response = $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->warranty) . '/add', ['target' => (string) $this->warrantyIds['Kettle warranty'], 'back' => 'picker']);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame(['Kettle warranty is already linked to another record, and it can only be linked once.'], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame('Warranties for Stand mixer', $this->text($x, '//h1'));
        self::assertSame([$this->warrantyIds['Kettle warranty']], $this->linked($this->kettle, $this->warranty));
    }

    /** @return iterable<string, array{string}> */
    public static function badTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'leading zero' => ['01'];
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'sql' => ['1 OR 1=1'];
        yield 'too long' => [str_repeat('9', 20)];
    }

    #[DataProvider('badTargets')]
    public function testANonCanonicalTargetIsNotFound(string $target): void
    {
        foreach (['/add', '/remove'] as $action) {
            $response = $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->warranty) . $action, ['target' => $target, 'back' => 'picker']);
            self::assertSame(404, $response->status, $action);
        }
        self::assertSame([$this->warrantyIds['Stand mixer, manufacturer warranty']], $this->linked($this->mixer, $this->warranty));
    }

    public function testATargetThatIsNotARecordOfTheOtherTableIsRefused(): void
    {
        // An id of no warranty (the core's target_missing), and a room id given to the warranty field.
        $missing = $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->warranty) . '/add', ['target' => '999']);
        self::assertContains($missing->status, [404, 422]);
        self::assertSame([$this->warrantyIds['Stand mixer, manufacturer warranty']], $this->linked($this->mixer, $this->warranty));
    }

    // Access (M4 spec part 1 section 10.7).

    public function testUngrantedTrashedWrongFieldAndUnknownAreNotFound(): void
    {
        $this->ops->projects()->setSharing($this->admin(), $this->project, []);
        foreach ([['GET', ''], ['POST', '/add'], ['POST', '/remove']] as [$method, $suffix]) {
            $response = $this->request(self::MEMBER, $method, $this->picker($this->mixer, $this->room) . $suffix, ['target' => (string) $this->roomIds['Garage']]);
            self::assertSame(404, $response->status, "ungranted {$method} {$suffix}");
        }
        self::assertSame([$this->roomIds['Kitchen']], $this->linked($this->mixer, $this->room));
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);

        $name = $this->fieldKey($this->items, 'Name');
        $other = $this->ops->schema()->addRelationField($this->admin(), $this->project, $this->rooms, 'Neighbour', $this->rooms, Cardinality::One, Cardinality::One, 'Neighbour of', false, '')->key;
        $paths = [
            'field of another table' => $this->picker($this->mixer, $other),
            'text field' => $this->picker($this->mixer, $name),
            'unknown field' => $this->picker($this->mixer, 'abcdefghijkl'),
            'unknown record' => $this->picker(999, $this->room),
            'non-canonical record' => $this->l($this->items) . '/records/01/links/' . $this->room,
            'unknown table' => '/projects/' . $this->project . '/tables/abcdefghijkl/records/' . $this->mixer . '/links/' . $this->room,
            'record of another table' => $this->l($this->rooms) . '/records/' . $this->mixer . '/links/' . $this->room,
        ];
        foreach ($paths as $what => $path) {
            self::assertSame(404, $this->request(self::MEMBER, 'GET', $path)->status, $what);
            self::assertSame(404, $this->request(self::MEMBER, 'POST', $path . '/add', ['target' => (string) $this->roomIds['Garage']])->status, $what);
        }

        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $this->mixer);
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->picker($this->mixer, $this->room))->status, 'record in the trash');
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->room) . '/add', ['target' => (string) $this->roomIds['Garage']])->status);
    }

    public function testAdminsUseThePickerAndPressesNeedTheCsrfToken(): void
    {
        $this->page(self::ADMIN, $this->picker($this->mixer, $this->room));
        $request = Fixtures::fullRequest('POST', $this->picker($this->mixer, $this->room) . '/add', ['target' => (string) $this->roomIds['Garage']], ['tdb_session' => self::MEMBER]);
        self::assertSame(403, Fixtures::handle($this->kernel, $request)[0]->status);
        self::assertSame([$this->roomIds['Kitchen']], $this->linked($this->mixer, $this->room));
    }

    public function testHostileTitlesAreEscapedOnce(): void
    {
        $hostile = $this->add($this->rooms, self::HOSTILE);
        $html = $this->page(self::MEMBER, $this->picker($this->mixer, $this->room));
        $x = $this->xpath($html);
        self::assertContains(self::HOSTILE, $this->texts($x, '//section[@aria-labelledby="pk-list"]//span[@class="picker-title"]/a'));
        self::assertContains('Choose ' . self::HOSTILE, $this->texts($x, '//button/@aria-label'));
        self::assertStringNotContainsString('<b>"Kitchen"', $html);

        $this->request(self::MEMBER, 'POST', $this->picker($this->mixer, $this->room) . '/add', ['target' => (string) $hostile]);
        self::assertSame(['key' => 'notice.link_chosen', 'params' => ['title' => self::HOSTILE]], $this->services->flash[self::MEMBER]);
        $chosen = $this->page(self::MEMBER, $this->picker($this->mixer, $this->room));
        self::assertSame(self::HOSTILE . ' is now chosen.', HtmlAssertions::text($chosen, '//div[@class="notice"]'));
        self::assertStringNotContainsString('<b>"Kitchen"', $chosen);
    }

    public function testUntitledRecordsAreNamedByItemAndId(): void
    {
        $untitled = $this->ops->records()->create($this->admin(), $this->project, $this->rooms, []);
        $x = $this->xpath($this->page(self::MEMBER, $this->picker($this->mixer, $this->room)));
        self::assertContains('Room ' . $untitled, $this->texts($x, '//span[@class="picker-title"]/a'));
        self::assertContains('Choose Room ' . $untitled, $this->texts($x, '//button/@aria-label'));
    }

    // Helpers.

    private function picker(int $record, string $field): string
    {
        return $this->r($this->items, $record) . '/links/' . $field;
    }

    private function l(string $table): string
    {
        return '/projects/' . $this->project . '/tables/' . $table;
    }

    private function r(string $table, int $id): string
    {
        return $this->l($table) . '/records/' . $id;
    }

    private function add(string $table, string $name): int
    {
        return $this->ops->records()->create($this->admin(), $this->project, $table, [$this->fieldKey($table, 'Name') => $name]);
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

    /** @return list<int> the live linked ids of a record's relation field, in link order */
    private function linked(int $record, string $field): array
    {
        $ids = [];
        foreach ($this->ops->records()->picker($this->admin(), $this->project, $this->items, (string) $record, $field, null, 1)->page->linked as $link) {
            $ids[] = $link->id;
        }

        return $ids;
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

    /** @param array<string, mixed> $post */
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

    private function one(DOMXPath $x, string $query): DOMElement
    {
        $node = $x->query($query)->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $x, string $query): array
    {
        $list = [];
        foreach ($x->query($query) as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $list[] = $node;
        }

        return $list;
    }

    /** @return array<string, string> the hidden inputs of a form other than the CSRF field, name => value */
    private function hidden(DOMXPath $x, DOMElement $form): array
    {
        $values = [];
        foreach ($x->query('.//input[@type="hidden"][@name!="_csrf"]', $form) as $input) {
            self::assertInstanceOf(DOMElement::class, $input);
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $values;
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $this->one($x, $query);

        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $texts = $this->texts($x, $query);
        self::assertNotSame([], $texts, $query);

        return $texts[0];
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
}
