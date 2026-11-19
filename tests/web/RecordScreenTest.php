<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\RecordController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-30 (M2-WP7): the record screens of docs/design/m2/record.md (record
 * form, new record, record in the trash, edit conflict, move to trash and
 * restore), through the kernel with the real templates and strings and
 * real core operations over a CoreFixture instance. The core clock is set
 * to the web clock, so saves happen "today". Synthetic data only.
 */
#[CoversClass(RecordController::class)]
final class RecordScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $name;

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
        $this->project = $this->ops->projects()->create($this->admin(), 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($this->admin(), $this->project)[0]->table->key;
        $this->name = $this->fieldKey($this->items, 'Name');
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // Record form (record.md section 2), generated layout.

    public function testRecordFormForEveryType(): void
    {
        $keys = $this->allTypes();
        $id = $this->add([
            $this->name => 'Stand mixer',
            $keys['Serial number'] => 'EX-000-12345',
            $keys['Category'] => 'Appliance',
            $keys['Purchased'] => '2025-03-14',
            $keys['Value'] => '329',
            $keys['Condition'] => '5',
            $keys['Under warranty'] => '1',
            $keys['Manual'] => 'https://example.com/manuals/stand-mixer',
            $keys['Notes'] => "Comes with a whisk.\nSpare bowl on the top shelf.",
            $keys['Started'] => '08:30',
            $keys['Delivered'] => '2025-03-15T09:45',
            $keys['Run time'] => '1:30',
            $keys['Weight'] => '7.25',
            $keys['Count'] => '2',
            $keys['Contact'] => 'user@example.com',
            $keys['Phone'] => '555-0100',
        ]);
        $kitchen = $this->add(['Name' => 'Kitchen'], $keys['rooms']);
        $warranty = $this->add([], $keys['warranties']);
        $this->ops->records()->setLinks($this->admin(), $this->project, $this->items, (string) $id, $keys['Room'], [$kitchen]);
        $this->ops->records()->setLinks($this->admin(), $this->project, $this->items, (string) $id, $keys['Warranties'], [$warranty]);
        $this->attach($keys['Receipt'], $id, 'receipt-stand-mixer.pdf', 'application/pdf', 86016);

        $html = $this->page(self::MEMBER, $this->r($id));
        $x = $this->xpath($html);

        self::assertSame('Stand mixer - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-record"]')->length, 'The Details grid gives the wide layout.');
        self::assertSame(['Home Inventory', 'Items', 'Stand mixer'], $this->crumbs($x));
        self::assertSame(['/projects/' . $this->project, $this->l()], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('Stand mixer', HtmlAssertions::text($html, '//div[@class="page-head"]//h1'));
        self::assertSame('Item 1 of 1. Last saved today at 10:00.', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Details', 'Files', 'Warranties'], $this->texts($x, '//form[@id="record-form"]/fieldset/legend'));
        self::assertSame(1, $x->query('//form/fieldset[1][@class="fieldset-card record-section record-grid"]')->length);
        self::assertSame(1, $x->query('//form/fieldset[2][@class="fieldset-card record-section"]')->length);

        // Inputs per type (M2 spec part 2 section 5.1).
        $input = fn (string $field): string => '//*[@id="f-' . $keys[$field] . '"]';
        self::assertSame('Stand mixer', $this->attr($x, '//input[@id="f-' . $this->name . '"]', 'value'));
        self::assertSame('text', $this->attr($x, '//input[@id="f-' . $this->name . '"]', 'type'));
        self::assertNull($this->attr($x, '//input[@id="f-' . $this->name . '"]', 'required'));
        self::assertSame('Name', $this->text($x, '//label[@for="f-' . $this->name . '"]'));
        $serial = $keys['Serial number'];
        self::assertSame('required', $this->attr($x, '//input[@id="f-' . $serial . '"]', 'required'));
        self::assertSame('Serial number (required)', $this->text($x, '//label[@for="f-' . $serial . '"]'));
        self::assertSame('(required)', $this->text($x, '//label[@for="f-' . $serial . '"]/span[@class="label-optional"]'));
        self::assertSame('select', $x->query($input('Category'))->item(0)?->nodeName);
        self::assertSame(['Not chosen', 'Appliance', 'Tool'], $this->texts($x, $input('Category') . '/option'));
        self::assertSame('Appliance', $this->text($x, $input('Category') . '/option[@selected="selected"]'));
        self::assertSame('Category', $this->text($x, '//label[@for="f-' . $keys['Category'] . '"]'), 'Optional fields carry no marker.');
        self::assertSame('date', $this->attr($x, $input('Purchased'), 'type'));
        self::assertSame('2025-03-14', $this->attr($x, $input('Purchased'), 'value'));
        self::assertSame('Value (US dollars)', $this->text($x, '//label[@for="f-' . $keys['Value'] . '"]'));
        self::assertSame('decimal', $this->attr($x, $input('Value'), 'inputmode'));
        self::assertSame('329.00', $this->attr($x, $input('Value'), 'value'));
        self::assertSame('Price (MXN)', $this->text($x, '//label[@for="f-' . $keys['Price'] . '"]'), 'A currency without a name shows its code.');
        self::assertSame('Condition', $this->text($x, '//fieldset[@class="field-group span-full"]/legend[@class="legend-plain"]'));
        self::assertSame(
            ['1, lowest', '2', '3', '4', '5, highest', 'Not rated'],
            $this->texts($x, '//fieldset[@class="field-group span-full"]/div[@class="choice-row"]/label'),
        );
        self::assertSame('5', $this->attr($x, '//input[@name="f-' . $keys['Condition'] . '" or @name="f_' . $keys['Condition'] . '"][@checked="checked"]', 'value'));
        self::assertSame('radio', $this->attr($x, $input('Condition'), 'type'), 'The first radio carries the control id.');
        self::assertSame('Under warranty', $this->text($x, '//div[@class="field"]/label[@class="check label"][input[@id="f-' . $keys['Under warranty'] . '"]]'));
        self::assertSame('checked', $this->attr($x, $input('Under warranty'), 'checked'));
        self::assertSame('url', $this->attr($x, $input('Manual'), 'type'));
        self::assertSame('textarea', $x->query($input('Notes'))->item(0)?->nodeName);
        self::assertSame("Comes with a whisk.\nSpare bowl on the top shelf.", $x->query($input('Notes'))->item(0)?->textContent);
        self::assertSame(1, $x->query('//div[@class="field span-full"][label[@for="f-' . $keys['Notes'] . '"]]')->length);
        self::assertSame('time', $this->attr($x, $input('Started'), 'type'));
        self::assertSame('08:30', $this->attr($x, $input('Started'), 'value'));
        self::assertSame('60', $this->attr($x, $input('Started'), 'step'));
        self::assertSame('datetime-local', $this->attr($x, $input('Delivered'), 'type'));
        self::assertSame('2025-03-15T09:45', $this->attr($x, $input('Delivered'), 'value'));
        self::assertSame('1:30', $this->attr($x, $input('Run time'), 'value'));
        self::assertSame('How long it ran. Hours and minutes, for example 1:30.', $this->text($x, '//p[@id="help-' . $keys['Run time'] . '"]'));
        self::assertSame('help-' . $keys['Run time'], $this->attr($x, $input('Run time'), 'aria-describedby'));
        self::assertSame('decimal', $this->attr($x, $input('Weight'), 'inputmode'));
        self::assertSame('numeric', $this->attr($x, $input('Count'), 'inputmode'));
        self::assertSame('email', $this->attr($x, $input('Contact'), 'type'));
        self::assertSame('tel', $this->attr($x, $input('Phone'), 'type'));

        // Shown, never edited (P105).
        self::assertSame('Item number', $this->text($x, '//p[@class="label"][@id="f-' . $keys['Item number'] . '"]'));
        self::assertSame('1', $this->text($x, '//div[p[@id="f-' . $keys['Item number'] . '"]]/p[@class="static-value"]'));
        self::assertSame(['Kitchen'], $this->texts($x, '//div[p[@id="f-' . $keys['Room'] . '"]]/ul[@class="link-list"]/li[@class="link-row"]/a'));
        self::assertSame($this->p('/tables/' . $keys['rooms'] . '/records/' . $kitchen), $this->attr($x, '//div[p[@id="f-' . $keys['Room'] . '"]]//a', 'href'));
        self::assertSame('Linked to the Rooms table.', $this->text($x, '//div[p[@id="f-' . $keys['Room'] . '"]]/p[@class="help"]'));
        self::assertSame(
            'receipt-stand-mixer.pdf (PDF, 84 KB)',
            $this->text($x, '//div[p[@id="f-' . $keys['Receipt'] . '"]]/div[@class="file-row"]'),
        );
        self::assertSame('(PDF, 84 KB)', $this->text($x, '//div[@class="file-row"]/span[@class="help"]'));
        self::assertSame('No files', $this->text($x, '//div[p[@id="f-' . $keys['Photo'] . '"]]/p[@class="static-value"]'));
        self::assertSame(1, $x->query('//p[@class="label visually-hidden"][@id="f-' . $keys['Warranties'] . '"]')->length, 'The only slot of a links section hides its label.');
        self::assertSame(['Warranty 1'], $this->texts($x, '//div[p[@id="f-' . $keys['Warranties'] . '"]]//li/a'), 'Untitled links show "{Item} {id}".');
        self::assertSame(0, $x->query('//form[@id="record-form"]//*[@name="f_' . $keys['Room'] . '" or @name="f_' . $keys['Receipt'] . '" or @name="f_' . $keys['Item number'] . '"]')->length);

        // Actions and meta line.
        self::assertSame('record-form', $this->attr($x, '//div[@class="record-actions-main"]/button', 'form'));
        self::assertSame('Save', $this->text($x, '//div[@class="record-actions-main"]/button[@class="button button-primary button-large"][@type="submit"]'));
        self::assertSame('Back to list', $this->text($x, '//div[@class="record-actions-main"]/a[@class="button button-large"]'));
        self::assertSame($this->l(), $this->attr($x, '//div[@class="record-actions-main"]/a', 'href'));
        self::assertSame($this->r($id) . '/trash', $this->attr($x, '//form[@class="trash-form"]', 'action'));
        self::assertSame('Move to trash', $this->text($x, '//form[@class="trash-form"]/button[@class="button button-danger button-large"]'));
        self::assertSame(
            'Added today by Example Admin. Last changed today by Example Admin. Records in the trash can be restored.',
            $this->text($x, '//p[@class="record-meta"]'),
        );
        self::assertSame(0, $x->query('//form[@id="record-form"]//button')->length, 'The Save button is outside the form.');
        self::assertSame(0, $x->query('//form//form')->length);
        self::assertSame(
            $this->ops->records()->screen($this->admin(), $this->project, $this->items, (string) $id, ListRequest::none())->version,
            $this->attr($x, '//form[@id="record-form"]/input[@name="version"]', 'value'),
        );
    }

    public function testNewRecordForm(): void
    {
        $keys = $this->allTypes();
        $html = $this->page(self::MEMBER, $this->l() . '/records/new?q=mixer');
        $x = $this->xpath($html);

        self::assertSame('New item - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'New item'], $this->crumbs($x));
        self::assertSame('New item', $this->text($x, '//h1'));
        self::assertSame('Fill in what you know. You can change it later.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(0, $x->query('//div[@class="record-nav"]')->length);
        self::assertSame(0, $x->query('//input[@name="version"]')->length);
        self::assertSame(0, $x->query('//form[@class="trash-form"]')->length);
        self::assertSame(0, $x->query('//p[@class="record-meta"]')->length);
        self::assertSame($this->l() . '/records?q=mixer', $this->attr($x, '//form[@id="record-form"]', 'action'));
        self::assertSame('Save', $this->text($x, '//div[@class="record-actions-main"]/button'));
        self::assertSame('Cancel', $this->text($x, '//div[@class="record-actions-main"]/a[@class="button button-large"]'));
        self::assertSame($this->l() . '?q=mixer', $this->attr($x, '//div[@class="record-actions-main"]/a', 'href'));
        self::assertSame('Given when you save', $this->text($x, '//div[p[@id="f-' . $keys['Item number'] . '"]]/p[@class="static-value"]'));
        self::assertSame(0, $x->query('//input[@checked]')->length - 1, 'Only "Not rated" starts checked.');
        self::assertSame('', $this->attr($x, '//input[@checked="checked"]', 'value'));
        self::assertSame('', $this->attr($x, '//input[@id="f-' . $this->name . '"]', 'value'));
        self::assertSame('No linked records', $this->text($x, '//div[p[@id="f-' . $keys['Room'] . '"]]/p[@class="static-value"]'));
    }

    public function testPositionAndNeighboursFollowTheListState(): void
    {
        $a = $this->add([$this->name => 'Anvil']);
        $b = $this->add([$this->name => 'Bench']);
        $c = $this->add([$this->name => 'Chisel']);

        $x = $this->xpath($this->page(self::MEMBER, $this->r($b)));
        self::assertSame('Item 2 of 3. Last saved today at 10:00.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Previous', 'Next'], $this->texts($x, '//div[@class="record-nav"]/a[@class="button"]'));
        self::assertSame($this->r($a), $this->attr($x, '//div[@class="record-nav"]/a[@rel="prev"]', 'href'));
        self::assertSame($this->r($c), $this->attr($x, '//div[@class="record-nav"]/a[@rel="next"]', 'href'));
        self::assertSame('Previous item: Anvil', $this->attr($x, '//a[@rel="prev"]', 'aria-label'));
        self::assertSame('Next item: Chisel', $this->attr($x, '//a[@rel="next"]', 'aria-label'));

        $x = $this->xpath($this->page(self::MEMBER, $this->r($a)));
        self::assertSame('true', $this->attr($x, '//div[@class="record-nav"]/span[@class="button"]', 'aria-disabled'));
        self::assertSame('Previous', $this->text($x, '//div[@class="record-nav"]/span'));
        self::assertSame(0, $x->query('//div[@class="record-nav"]/span[@href]')->length);

        // A search narrows the list: Bench is first of one; the state is carried.
        $x = $this->xpath($this->page(self::MEMBER, $this->r($b) . '?q=ben'));
        self::assertSame('Item 1 of 1. Last saved today at 10:00.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(2, $x->query('//div[@class="record-nav"]/span[@aria-disabled="true"]')->length);
        self::assertSame($this->r($b) . '?q=ben', $this->attr($x, '//form[@id="record-form"]', 'action'));
        self::assertSame($this->r($b) . '/trash?q=ben', $this->attr($x, '//form[@class="trash-form"]', 'action'));
        self::assertSame($this->l() . '?q=ben', $this->attr($x, '//div[@class="record-actions-main"]/a', 'href'));
        self::assertSame($this->l() . '?q=ben', $this->attr($x, '//nav[@class="breadcrumb"]/a[2]', 'href'), 'The table link keeps the list state.');

        // Outside the list's search: no position, "Last saved" alone.
        $x = $this->xpath($this->page(self::MEMBER, $this->r($a) . '?q=ben'));
        self::assertSame('Last saved today at 10:00.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(0, $x->query('//div[@class="record-nav"]')->length);

        // Sorted the other way round.
        $x = $this->xpath($this->page(self::MEMBER, $this->r($a) . '?sort=' . $this->name . '.desc'));
        self::assertSame('Item 3 of 3. Last saved today at 10:00.', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame($this->r($b) . '?sort=' . $this->name . '.desc', $this->attr($x, '//a[@rel="prev"]', 'href'));
    }

    public function testUntitledRecordAndYesterday(): void
    {
        $this->core->clock->set('2026-11-02T18:05:00Z');
        $id = $this->add([]);
        $html = $this->page(self::MEMBER, $this->r($id));
        $x = $this->xpath($html);

        self::assertSame('Item ' . $id . ' - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Item ' . $id, $this->text($x, '//h1'));
        self::assertSame('Item 1 of 1. Last saved yesterday at 18:05.', $this->text($x, '//p[@class="lead"]'));
        self::assertSame('Added yesterday by Example Admin. Last changed yesterday by Example Admin. Records in the trash can be restored.', $this->text($x, '//p[@class="record-meta"]'));
    }

    // Stored forms (ADR 0014; record.md section 2.1).

    public function testStoredFormWithHeadingsAndParagraphs(): void
    {
        $serial = $this->addField('Serial number', 'text', [], false, 'On the back.');
        $hidden = $this->addField('Secret', 'text');
        $id = $this->add([$this->name => 'Drill', $serial => 'EX-1', $hidden => 'kept']);
        $this->storeForm(
            '<div data-tiffin-form="1">'
            . '<h2>About <em>this</em> item</h2>'
            . '<p>Read the <a href="https://example.com/help">help page</a> first.<br/>Then <strong>save</strong>.</p>'
            . '<fieldset data-tiffin-section="main"><legend>Main</legend>'
            . '<div data-tiffin-field="' . $this->name . '" data-tiffin-label="What it is"></div>'
            . '<h3>Small print</h3><hr/>'
            . '<div data-tiffin-field="' . $serial . '"></div>'
            . '<div data-tiffin-field="zzzzzzzzzzzz"></div>'
            . '</fieldset></div>',
        );

        $html = $this->page(self::MEMBER, $this->r($id));
        $x = $this->xpath($html);

        self::assertSame(1, $x->query('//main[@class="page page-record page-record-narrow"]')->length, 'No Details grid: the narrow layout.');
        self::assertSame('About this item', $this->text($x, '//form[@id="record-form"]/h2[@class="section-title"]'));
        self::assertSame('this', $this->text($x, '//form/h2/em'));
        self::assertSame('Read the help page first.Then save.', $this->text($x, '//form[@id="record-form"]/p'));
        self::assertSame(0, $x->query('//form[@id="record-form"]//a')->length, 'Links in a stored form are shown as text.');
        self::assertSame(1, $x->query('//form/p/br')->length);
        self::assertSame('save', $this->text($x, '//form/p/strong'));
        self::assertSame('Main', $this->text($x, '//form/fieldset[@class="fieldset-card record-section"]/legend'));
        self::assertSame('Small print', $this->text($x, '//form/fieldset/h3'));
        self::assertSame(1, $x->query('//form/fieldset/hr')->length);
        self::assertSame('What it is', $this->text($x, '//label[@for="f-' . $this->name . '"]'));
        self::assertSame('On the back.', $this->text($x, '//p[@id="help-' . $serial . '"]'));
        self::assertSame(0, $x->query('//*[@name="f_' . $hidden . '"]')->length, 'A field the form does not name is not shown.');

        // Saving leaves the unnamed field unchanged.
        $version = $this->attr($x, '//input[@name="version"]', 'value');
        $response = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $version, 'f_' . $this->name => 'Hammer drill', 'f_' . $serial => 'EX-2', 'f_' . $hidden => 'changed']);
        self::assertSame(303, $response->status);
        self::assertSame('Hammer drill', $this->stored($this->name, $id));
        self::assertSame('EX-2', $this->stored($serial, $id));
        self::assertSame('kept', $this->stored($hidden, $id));
    }

    public function testStoredFormWithScriptShowsTheGeneratedLayout(): void
    {
        $id = $this->add([$this->name => 'Kettle']);
        $this->storeForm('<div data-tiffin-form="1"><script>alert(1)</script><p onclick="x()">Hi</p><div data-tiffin-field="' . $this->name . '"></div></div>');

        [$response, $log] = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $this->r($id), [], ['tdb_session' => self::MEMBER]));
        self::assertSame(200, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertSame(['Details'], $this->texts($x, '//form[@id="record-form"]/fieldset/legend'));
        self::assertSame('Kettle', $this->attr($x, '//input[@id="f-' . $this->name . '"]', 'value'));
        self::assertStringContainsString($this->items, $log, 'The rejected form is logged by table key.');
        self::assertStringNotContainsString('alert', $log, 'The log never holds the content.');
    }

    // Create (record.md section 3).

    public function testCreatingARecord(): void
    {
        $count = $this->addField('Count', 'number', ['decimals' => 0]);
        $done = $this->addField('Done', 'checkbox');
        $response = $this->request(self::MEMBER, 'POST', $this->l() . '/records?q=Dr', ['f_' . $this->name => 'Drill', 'f_' . $count => '3']);

        self::assertSame(303, $response->status);
        $id = (int) $this->core->projectDb($this->project)->value('SELECT max("id") FROM ' . Identifier::table($this->items));
        self::assertSame($this->r($id) . '?q=Dr', $response->header('Location'));
        self::assertSame(['key' => 'notice.record_added', 'params' => ['item' => 'item']], $this->services->flash[self::MEMBER]);
        self::assertSame('Drill', $this->stored($this->name, $id));
        self::assertSame(3, $this->stored($count, $id));
        self::assertSame(0, $this->stored($done, $id), 'A missing checkbox is unticked.');

        $html = $this->page(self::MEMBER, $this->r($id) . '?q=Dr');
        self::assertSame('The item has been added.', HtmlAssertions::text($html, '//div[@class="notice"]/p'));
    }

    public function testNewRecordErrors(): void
    {
        $code = $this->addField('Code', 'text', [], true);
        $count = $this->addField('Count', 'number', ['decimals' => 0]);
        $done = $this->addField('Done', 'checkbox');
        $response = $this->request(self::MEMBER, 'POST', $this->l() . '/records', ['f_' . $this->name => 'Drill', 'f_' . $code => '', 'f_' . $count => 'three <b>', 'f_' . $done => '1']);

        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame('Error: New item - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['#f-' . $code, '#f-' . $count], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        foreach ([$code, $count] as $key) {
            self::assertSame(1, $x->query('//*[@id="f-' . $key . '"]')->length, 'Each summary link names a control on the page.');
            self::assertSame('true', $this->attr($x, '//*[@id="f-' . $key . '"]', 'aria-invalid'));
            self::assertSame('err-' . $key, $this->attr($x, '//*[@id="f-' . $key . '"]', 'aria-describedby'));
        }
        self::assertSame('Fill this in.', $this->text($x, '//p[@class="error-text"][@id="err-' . $code . '"]'));
        self::assertSame('three <b>', $this->attr($x, '//input[@id="f-' . $count . '"]', 'value'), 'Typed text comes back as typed.');
        self::assertStringNotContainsString('three <b>', $html);
        self::assertSame('checked', $this->attr($x, '//input[@id="f-' . $done . '"]', 'checked'));
        self::assertSame(0, (int) $this->core->projectDb($this->project)->value('SELECT count(*) FROM ' . Identifier::table($this->items)));
    }

    // Update (record.md section 2).

    public function testSavingARecord(): void
    {
        $id = $this->add([$this->name => 'Drill']);
        $version = $this->version($id);
        $response = $this->request(self::MEMBER, 'POST', $this->r($id) . '?q=dri', ['version' => $version, 'f_' . $this->name => 'Hammer drill']);

        self::assertSame(303, $response->status);
        self::assertSame($this->r($id) . '?q=dri', $response->header('Location'));
        self::assertSame(['key' => 'notice.record_saved', 'params' => []], $this->services->flash[self::MEMBER]);
        self::assertSame('Hammer drill', $this->stored($this->name, $id));
        $html = $this->page(self::MEMBER, $this->r($id) . '?q=dri');
        self::assertSame('Your changes have been saved.', HtmlAssertions::text($html, '//div[@class="notice"]/p'));
    }

    public function testSaveErrorsKeepThePostedVersion(): void
    {
        $count = $this->addField('Count', 'number', ['decimals' => 0]);
        $id = $this->add([$this->name => 'Drill']);
        $version = $this->version($id);
        $response = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $version, 'f_' . $this->name => 'Drill', 'f_' . $count => 'x']);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('Error: Drill - TiffinDB', HtmlAssertions::text($response->body, '//title'));
        self::assertSame(['#f-' . $count], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame($version, $this->attr($x, '//input[@name="version"]', 'value'));

        // Someone else saves meanwhile: the corrected form meets the conflict.
        $this->ops->records()->update($this->admin(), $this->project, $this->items, (string) $id, [$this->name => 'Drill press'], $version);
        $again = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $version, 'f_' . $this->name => 'Drill', 'f_' . $count => '2']);
        self::assertSame(409, $again->status);
    }

    // Edit conflict (record.md section 5).

    public function testConflictPageAndSavingAnyway(): void
    {
        $count = $this->addField('Count', 'number', ['decimals' => 0]);
        $notes = $this->addField('Notes', 'long_text');
        $id = $this->add([$this->name => 'Drill', $count => '1']);
        $old = $this->version($id);
        $this->core->clock->set('2026-11-03T09:15:00Z');
        $this->ops->records()->update($this->admin(), $this->project, $this->items, (string) $id, [$this->name => 'Drill press'], $old);
        $current = $this->version($id);

        $response = $this->request(self::MEMBER, 'POST', $this->r($id) . '?q=dr', ['version' => $old, 'f_' . $this->name => 'Cordless drill', 'f_' . $count => '1', 'f_' . $notes => "Two\nlines", '_extra' => 'x']);
        self::assertSame(409, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame('Drill press - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@class="page page-record page-record-narrow"]')->length);
        self::assertSame(['Home Inventory', 'Items', 'Drill press'], $this->crumbs($x));
        self::assertSame('This item was changed while you were editing it', $this->text($x, '//h1'));
        self::assertSame('Example Admin saved a change today at 09:15, after you opened it. What you typed has not been saved yet.', $this->text($x, '//main/p[1]'));
        self::assertSame('Fields that differ', $this->text($x, '//table[@class="data-table compare-table"]/caption[@class="visually-hidden"]'));
        self::assertSame(['Field', 'Saved version', 'Your version'], $this->texts($x, '//thead/tr/th[@scope="col"]'));
        self::assertSame(['Name', 'Drill press', 'Cordless drill', 'Notes', '(empty)', "Two lines"], $this->texts($x, '//tbody/tr/*'));
        self::assertSame("Two\nlines", $x->query('//tbody/tr[2]/td[2]/span[@class="text-multiline"]')->item(0)?->textContent);
        self::assertSame(['Keep the saved version'], $this->texts($x, '//div[@class="form-actions"]/a[@class="button button-primary button-large"]'));
        self::assertSame($this->r($id) . '?q=dr', $this->attr($x, '//div[@class="form-actions"]/a', 'href'));

        $form = '//div[@class="form-actions"]/form';
        self::assertSame($this->r($id) . '?q=dr', $this->attr($x, $form, 'action'));
        self::assertSame('Save my version anyway', $this->text($x, $form . '/button[@class="button button-danger button-large"]'));
        self::assertSame($current, $this->attr($x, $form . '/input[@name="version"]', 'value'));
        self::assertSame(
            ['_csrf', 'version', 'f_' . $this->name, 'f_' . $count, 'f_' . $notes],
            $this->texts($x, $form . '/input[@type="hidden"]/@name'),
        );
        self::assertSame(FakeServices::SESSION_CSRF, $this->attr($x, $form . '/input[@name="_csrf"]', 'value'));
        // Line breaks stay in the attribute (an XML parser would fold them; HTML keeps them).
        self::assertStringContainsString('name="f_' . $notes . '" value="Two' . "\n" . 'lines"', $html);
        self::assertStringNotContainsString('_extra', $html);

        // "Save my version anyway" re-posts exactly that form.
        $post = [];
        foreach ($x->query($form . '/input[@type="hidden"]') as $hidden) {
            $post[$hidden->getAttribute('name')] = $hidden->getAttribute('value');
        }
        unset($post['_csrf']);
        $post['f_' . $notes] = "Two\nlines";
        $saved = $this->request(self::MEMBER, 'POST', $this->r($id) . '?q=dr', $post);
        self::assertSame(303, $saved->status);
        self::assertSame('Cordless drill', $this->stored($this->name, $id));
        self::assertSame("Two\nlines", $this->stored($notes, $id));
    }

    public function testConflictWithNoDifferingFields(): void
    {
        $count = $this->addField('Count', 'number', ['decimals' => 0]);
        $id = $this->add([$this->name => 'Drill', $count => '1']);
        $old = $this->version($id);
        $this->ops->records()->update($this->admin(), $this->project, $this->items, (string) $id, [$count => '2'], $old);
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');

        $response = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $old, 'f_' . $this->name => 'Drill', 'f_' . $count => '2']);
        self::assertSame(409, $response->status);
        $x = $this->xpath($response->body);
        self::assertSame(0, $x->query('//table')->length);
        self::assertSame('The other change was to fields you did not change here, so saving keeps both.', $this->text($x, '//main/p[2]'));
    }

    public function testConflictWithAnUnknownPerson(): void
    {
        $id = $this->add([$this->name => 'Drill']);
        $old = $this->version($id);
        $this->ops->records()->update($this->admin(), $this->project, $this->items, (string) $id, [$this->name => 'Saw'], $old);
        $this->core->projectDb($this->project)->run('UPDATE ' . Identifier::table($this->items) . ' SET "_updated_by" = ? WHERE "id" = ?', ['zzzzzzzzzzzz', $id]);

        $response = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $old, 'f_' . $this->name => 'Drill']);
        self::assertSame(409, $response->status);
        self::assertSame(
            'It was saved again today at 10:00, after you opened it. What you typed has not been saved yet.',
            $this->text($this->xpath($response->body), '//main/p[1]'),
        );
    }

    // Move to trash, restore, record in the trash (record.md sections 4 and 6).

    public function testTrashWithUndoThenRestore(): void
    {
        $id = $this->add([$this->name => 'Drill']);
        $response = $this->request(self::MEMBER, 'POST', $this->r($id) . '/trash?q=dr&page=2');

        self::assertSame(303, $response->status);
        self::assertSame($this->l() . '?q=dr&page=2', $response->header('Location'));
        self::assertSame(
            ['key' => 'notice.record_trashed', 'params' => ['title' => 'Drill', 'undo' => $this->r($id) . '/restore']],
            $this->services->flash[self::MEMBER],
        );
        self::assertNotNull($this->core->projectDb($this->project)->value('SELECT "_trashed_at" FROM ' . Identifier::table($this->items) . ' WHERE "id" = ?', [$id]));

        // The notice with Undo, on the next signed-in page.
        $html = $this->page(self::MEMBER, $this->r($id));
        $x = $this->xpath($html);
        self::assertSame('Drill has been moved to the trash.', $this->text($x, '//div[@class="notice notice-row"]/p'));
        self::assertSame($this->r($id) . '/restore', $this->attr($x, '//form[@class="notice-form"]', 'action'));
        self::assertSame('Undo: restore Drill', $this->attr($x, '//form[@class="notice-form"]/button', 'aria-label'));

        // The record in the trash.
        self::assertSame('Drill - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@class="page page-record page-record-narrow"]')->length);
        self::assertSame(['Home Inventory', 'Items', 'Drill'], $this->crumbs($x));
        self::assertSame('This item is in the trash', $this->text($x, '//h1'));
        self::assertSame('Drill was moved to the trash today. Restore it to see and change it again.', $this->text($x, '//main/p[not(@class)]'));
        self::assertSame($this->r($id) . '/restore', $this->attr($x, '//div[@class="form-actions"]/form', 'action'));
        self::assertSame('Restore', $this->text($x, '//div[@class="form-actions"]/form/button[@class="button button-primary button-large"]'));
        self::assertSame('Back to list', $this->text($x, '//div[@class="form-actions"]/a[@class="button button-large"]'));
        self::assertSame(0, $x->query('//form[@id="record-form"]')->length);

        // Trashing again changes nothing and answers the same.
        self::assertSame(303, $this->request(self::MEMBER, 'POST', $this->r($id) . '/trash')->status);
        self::assertSame('Drill', $this->services->flash[self::MEMBER]['params']['title']);

        // A stale form saved to the trashed record: 404, nothing changes.
        $saved = $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => 'abc', 'f_' . $this->name => 'Saw']);
        self::assertSame(404, $saved->status);
        self::assertSame('Drill', $this->stored($this->name, $id));

        // Restore.
        $restored = $this->request(self::MEMBER, 'POST', $this->r($id) . '/restore?q=dr');
        self::assertSame(303, $restored->status);
        self::assertSame($this->r($id) . '?q=dr', $restored->header('Location'));
        self::assertSame(['key' => 'notice.record_restored', 'params' => ['title' => 'Drill']], $this->services->flash[self::MEMBER]);
        self::assertNull($this->core->projectDb($this->project)->value('SELECT "_trashed_at" FROM ' . Identifier::table($this->items) . ' WHERE "id" = ?', [$id]));
        $html = $this->page(self::MEMBER, $this->r($id) . '?q=dr');
        self::assertSame('Drill has been restored.', HtmlAssertions::text($html, '//div[@class="notice"]/p'));
        self::assertSame(1, $this->xpath($html)->query('//form[@id="record-form"]')->length);
    }

    public function testTrashingAnUntitledRecordNamesItByItemAndId(): void
    {
        $id = $this->add([]);
        $this->request(self::MEMBER, 'POST', $this->r($id) . '/trash');
        self::assertSame('Item ' . $id, $this->services->flash[self::MEMBER]['params']['title']);
    }

    // Not found and access.

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'leading zero' => ['01'];
        yield 'letters' => ['abc'];
        yield 'negative' => ['-1'];
        yield 'too large' => ['99999999999999999999'];
        yield 'absent' => ['999'];
    }

    #[DataProvider('badIds')]
    public function testInvalidOrAbsentRecordsAreNotFound(string $id): void
    {
        $this->add([$this->name => 'Drill']);
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->l() . '/records/' . $id)->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->l() . '/records/' . $id, ['version' => 'x'])->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->l() . '/records/' . $id . '/trash')->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->l() . '/records/' . $id . '/restore')->status);
    }

    public function testAMemberWithoutAGrantGetsNotFound(): void
    {
        $id = $this->add([$this->name => 'Drill']);
        $this->ops->projects()->setSharing($this->admin(), $this->project, []);

        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->r($id))->status);
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->l() . '/records/new')->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->l() . '/records', ['f_' . $this->name => 'Saw'])->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->r($id), ['version' => $this->version($id), 'f_' . $this->name => 'Saw'])->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->r($id) . '/trash')->status);
        self::assertSame(404, $this->request(self::MEMBER, 'POST', $this->r($id) . '/restore')->status);
        self::assertSame('Drill', $this->stored($this->name, $id));
        self::assertSame(1, (int) $this->core->projectDb($this->project)->value('SELECT count(*) FROM ' . Identifier::table($this->items)));
    }

    public function testAnUnknownTableIsNotFound(): void
    {
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->p('/tables/aaaaaaaaaaaa/records/new'))->status);
    }

    // Escaping.

    public function testValuesAndNamesAreEscapedOnce(): void
    {
        $nasty = '<script>alert("x")</script> \'&amp; {name}';
        $notes = $this->addField('Notes', 'long_text', [], false, 'Help <b>{item}</b>');
        $id = $this->add([$this->name => $nasty, $notes => $nasty]);
        $html = $this->page(self::MEMBER, $this->r($id));
        $x = $this->xpath($html);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame($nasty . ' - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame($nasty, $this->text($x, '//h1'));
        self::assertSame($nasty, $this->attr($x, '//input[@id="f-' . $this->name . '"]', 'value'));
        self::assertSame($nasty, $x->query('//textarea[@id="f-' . $notes . '"]')->item(0)?->textContent);
        self::assertSame('Help <b>{item}</b>', $this->text($x, '//p[@id="help-' . $notes . '"]'));
        self::assertSame($nasty, $this->text($x, '//nav[@class="breadcrumb"]/span[@aria-current="page"]'));
    }

    // Helpers.

    /**
     * Fields of every type on Items, plus the tables Rooms (one link) and
     * Warranties (many links): field name => key, and `rooms`, `warranties`.
     *
     * @return array<string, string>
     */
    private function allTypes(): array
    {
        $keys = [];
        $schema = $this->ops->schema();
        $keys['rooms'] = $schema->createTable($this->admin(), $this->project, 'Rooms', 'room', true)->key;
        $keys['warranties'] = $schema->createTable($this->admin(), $this->project, 'Warranties', 'warranty', false)->key;
        $keys['Room'] = $schema->addRelationField($this->admin(), $this->project, $this->items, 'Room', $keys['rooms'], Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $keys['Serial number'] = $this->addField('Serial number', 'text', [], true);
        $keys['Category'] = $this->addField('Category', 'choice', ['choices' => ['Appliance', 'Tool']]);
        $keys['Purchased'] = $this->addField('Purchased', 'date');
        $keys['Value'] = $this->addField('Value', 'currency', ['code' => 'USD']);
        $keys['Price'] = $this->addField('Price', 'currency', ['code' => 'MXN']);
        $keys['Condition'] = $this->addField('Condition', 'rating', ['max' => 5]);
        $keys['Receipt'] = $this->addField('Receipt', 'attachment');
        $keys['Under warranty'] = $this->addField('Under warranty', 'checkbox');
        $keys['Manual'] = $this->addField('Manual', 'url');
        $keys['Notes'] = $this->addField('Notes', 'long_text');
        $keys['Photo'] = $this->addField('Photo', 'attachment');
        $keys['Started'] = $this->addField('Started', 'time', ['seconds' => false]);
        $keys['Delivered'] = $this->addField('Delivered', 'date_time', ['seconds' => false]);
        $keys['Run time'] = $this->addField('Run time', 'duration', ['seconds' => false], false, 'How long it ran.');
        $keys['Weight'] = $this->addField('Weight', 'number', ['decimals' => 2]);
        $keys['Count'] = $this->addField('Count', 'number', ['decimals' => 0]);
        $keys['Contact'] = $this->addField('Contact', 'email');
        $keys['Phone'] = $this->addField('Phone', 'phone');
        $keys['Item number'] = $this->addField('Item number', 'auto_number');
        $keys['Warranties'] = $schema->addRelationField($this->admin(), $this->project, $this->items, 'Warranties', $keys['warranties'], Cardinality::Many, Cardinality::One, 'Item', false, '')->key;

        return $keys;
    }

    /** @param array<string, mixed> $options */
    private function addField(string $name, string $type, array $options = [], bool $required = false, string $help = ''): string
    {
        return $this->ops->schema()->addField($this->admin(), $this->project, $this->items, $name, $type, $options, $required, $help)->key;
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

    /**
     * Adds a record through the core as the Admin. Keys of $text are field
     * keys, or `Name` for the Name field of $table (when not Items).
     *
     * @param array<string, string> $text
     */
    private function add(array $text, ?string $table = null): int
    {
        $table ??= $this->items;
        if (isset($text['Name'])) {
            $text[$this->fieldKey($table, 'Name')] = $text['Name'];
            unset($text['Name']);
        }

        return $this->ops->records()->create($this->admin(), $this->project, $table, $text);
    }

    private function attach(string $fieldKey, int $recordId, string $name, string $mediaType, int $size): void
    {
        $db = $this->core->projectDb($this->project);
        $db->run(
            'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
            [str_repeat('c', 64), $size, $mediaType, $name, CoreFixture::START],
        );
        $db->run(
            'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, 1)',
            [$fieldKey, $recordId, $db->lastInsertId()],
        );
    }

    /** Replaces the Items table's default form with $html, as an edited (not generated) form, by plain SQL. */
    private function storeForm(string $html): void
    {
        $db = $this->core->projectDb($this->project);
        $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "table_key" = ? AND "is_default" = 1', [$html, $this->items]);
        self::assertSame(1, (int) $db->value('SELECT count(*) FROM "_tiffin_forms" WHERE "table_key" = ? AND "html" = ?', [$this->items, $html]));
    }

    private function version(int $id): string
    {
        $version = $this->ops->records()->screen($this->admin(), $this->project, $this->items, (string) $id, ListRequest::none())->version;
        self::assertIsString($version);

        return $version;
    }

    private function stored(string $fieldKey, int $id): mixed
    {
        return $this->core->projectDb($this->project)->value(
            'SELECT ' . Identifier::column($fieldKey) . ' FROM ' . Identifier::table($this->items) . ' WHERE "id" = ?',
            [$id],
        );
    }

    private function admin(): \TiffinDB\Core\Auth\Actor
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

    private function p(string $path): string
    {
        return '/projects/' . $this->project . $path;
    }

    /** The Items list path. */
    private function l(): string
    {
        return $this->p('/tables/' . $this->items);
    }

    /** A record's path in Items. */
    private function r(int $id): string
    {
        return $this->l() . '/records/' . $id;
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
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }
}
