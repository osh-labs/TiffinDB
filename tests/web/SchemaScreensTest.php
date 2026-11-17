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
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FieldController;
use TiffinDB\Web\Controller\TableController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M1-WP11: tables, new table, table fields, delete table, and the field
 * editor (docs/design/m1/schema-editor.md sections 3 to 11), through the
 * kernel with the real templates and strings and real core operations
 * over a CoreFixture instance. Synthetic data only.
 */
#[CoversClass(TableController::class)]
#[CoversClass(FieldController::class)]
final class SchemaScreensTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
        $this->project = $this->ops->projects()->create($this->admin(), 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($this->admin(), $this->project)[0]->table->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // Tables (section 3).

    public function testTablesForAnAdmin(): void
    {
        $this->core->insertRecord($this->project, $this->items);
        $html = $this->page(self::ADMIN, $this->p('/tables'));
        $x = $this->xpath($html);

        self::assertSame('Home Inventory - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'Home Inventory'], $this->crumbs($x));
        self::assertSame('page', $x->query('//nav[@class="breadcrumb"]/span[.="Home Inventory"]/@aria-current')->item(0)?->nodeValue);
        self::assertSame('Home Inventory', HtmlAssertions::text($html, '//h1'));
        self::assertSame('1 table', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Add a table', 'Sharing', 'Project settings'], $this->texts($x, '//div[@class="page-actions"]/a'));
        self::assertSame(
            [$this->p('/tables/new'), $this->p('/sharing'), $this->p('/settings')],
            $this->texts($x, '//div[@class="page-actions"]/a/@href'),
        );
        self::assertSame(1, $x->query('//div[@class="page-actions"]/a[contains(@class, "button-primary")]')->length);
        self::assertSame('Tables in Home Inventory', HtmlAssertions::text($html, '//table/caption[@class="visually-hidden"]'));
        self::assertSame(['Table', 'Fields', 'Records'], $this->texts($x, '//thead/tr/th'));
        self::assertSame(['Items', '1', '1'], $this->texts($x, '//tbody/tr/*'));
        self::assertSame('Change the fields of Items', $x->query('//tbody/tr/th[@scope="row"]/a/@aria-label')->item(0)?->nodeValue);
        self::assertSame($this->t('/fields'), $x->query('//tbody/tr/th/a/@href')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//p[contains(@class, "notice-muted")]')->length);
    }

    public function testTablesForAMemberHaveNoEditingControls(): void
    {
        $this->share();
        $html = $this->page(self::MEMBER, $this->p('/tables'));
        $x = $this->xpath($html);

        self::assertSame(0, $x->query('//div[@class="page-actions"]')->length);
        self::assertSame(0, $x->query('//tbody//a')->length);
        self::assertSame(['Items', '1', '0'], $this->texts($x, '//tbody/tr/*'));
        self::assertSame('Only Admins can change tables and fields.', HtmlAssertions::text($html, '//p[@class="notice notice-muted"]'));
        self::assertStringNotContainsString('/fields', $html);
    }

    public function testTablesWithoutTables(): void
    {
        $empty = $this->ops->projects()->create($this->admin(), 'Recipes', null)->key;
        $this->ops->projects()->setSharing($this->admin(), $empty, [$this->core->person('member')->key]);

        $admin = $this->page(self::ADMIN, '/projects/' . $empty . '/tables');
        self::assertSame('No tables yet', HtmlAssertions::text($admin, '//p[@class="lead"]'));
        self::assertSame('This project has no tables yet. Select Add a table to make one.', HtmlAssertions::text($admin, '//p[@class="notice"]'));

        $member = $this->page(self::MEMBER, '/projects/' . $empty . '/tables');
        self::assertSame('This project has no tables yet.', HtmlAssertions::text($member, '//p[@class="notice"]'));
    }

    public function testTablesOfAProjectNotSharedIsNotFound(): void
    {
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->p('/tables'))->status);
    }

    // New table (section 4).

    public function testNewTableForm(): void
    {
        $html = $this->page(self::ADMIN, $this->p('/tables/new'));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'New table'], $this->crumbs($x));
        self::assertSame('New table', HtmlAssertions::text($html, '//h1'));
        self::assertSame('A table holds one kind of thing, such as items, rooms, or recipes.', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame(['Table name', 'One record is called'], $this->texts($x, '//main//label[@class="label"]'));
        self::assertSame('item', $x->query('//input[@id="table-item"]/@value')->item(0)?->nodeValue);
        self::assertSame('input input-medium', $x->query('//input[@id="table-item"]/@class')->item(0)?->nodeValue);
        self::assertSame('checked', $x->query('//input[@id="table-with-name"]/@checked')->item(0)?->nodeValue);
        self::assertSame('Start with a Name field', HtmlAssertions::text($html, '//label[@class="check"]'));
        self::assertSame(['Create table'], $this->texts($x, '//main//button'));
        self::assertSame($this->p('/tables'), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
    }

    public function testCreatingATable(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->p('/tables'), ['name' => 'Rooms', 'item_name' => 'room']);
        $rooms = $this->tableKey('Rooms');

        self::assertSame(303, $response->status);
        self::assertSame($this->p('/tables/' . $rooms . '/fields'), $response->header('Location'));
        self::assertSame(['key' => 'notice.table_created', 'params' => ['table' => 'Rooms']], $this->services->flash[self::ADMIN]);
        self::assertSame([], $this->ops->schema()->design($this->admin(), $this->project, $rooms)->fields, 'Unticked: no Name field.');
    }

    public function testNewTableErrors(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->p('/tables'), ['name' => 'items', 'item_name' => '']);
        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame(['#table-name', '#table-item'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Another table in this project already has this name.', HtmlAssertions::text($html, '//p[@id="table-name-error"]'));
        self::assertSame('Enter a name.', HtmlAssertions::text($html, '//p[@id="table-item-error"]'));
        self::assertSame('items', $x->query('//input[@id="table-name"]/@value')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//input[@id="table-with-name"]/@checked')->length, 'The unticked box stays unticked.');
    }

    public function testNewTableIsForAdminsOnly(): void
    {
        $this->share();
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->p('/tables/new'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->p('/tables'), ['name' => 'Rooms', 'item_name' => 'room'])->status);
        self::assertCount(1, $this->ops->schema()->tables($this->admin(), $this->project));
    }

    // Table fields (section 5).

    public function testTableFields(): void
    {
        $price = $this->addField('Price', 'currency', ['code' => 'EUR'], true);
        $this->addRooms();
        $this->core->insertRecord($this->project, $this->items);
        $html = $this->page(self::ADMIN, $this->t('/fields'));
        $x = $this->xpath($html);

        self::assertSame('Items - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'Home Inventory', 'Items'], $this->crumbs($x));
        self::assertSame('Items', HtmlAssertions::text($html, '//h1'));
        self::assertSame('3 fields. 1 record.', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Add a field'], $this->texts($x, '//div[@class="page-actions"]/a'));
        self::assertSame($this->t('/fields/new'), $x->query('//div[@class="page-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertSame('Fields', HtmlAssertions::text($html, '//section[@aria-labelledby="fields-title"]/h2[@id="fields-title"]'));
        self::assertSame('Fields in Items, in the order they appear', HtmlAssertions::text($html, '//section//caption'));
        self::assertSame(['Field', 'Type', 'Must be filled in', 'Order'], $this->texts($x, '//section//thead/tr/th'));

        $rows = $x->query('//section//tbody/tr');
        self::assertSame(3, $rows->length);
        self::assertSame(['Name (shown as the title)', 'Text', 'No', 'Down'], $this->texts($x, '//section//tbody/tr[1]/*'));
        self::assertSame(['Price', 'Currency', 'Yes', 'Up Down'], $this->texts($x, '//section//tbody/tr[2]/*'));
        self::assertSame(['Room', 'No', 'Up'], $this->texts($x, '//section//tbody/tr[3]/*[position() != 2]'));
        self::assertSame(['Link to Rooms'], $this->texts($x, '//section//tbody/tr[3]/td[1]/text()[normalize-space()]'));
        self::assertSame(['one each'], $this->texts($x, '//section//tbody/tr[3]/td[1]/span[@class="help"]'));
        self::assertSame($this->t('/fields/' . $price), $x->query('//section//tbody/tr[2]/th/a/@href')->item(0)?->nodeValue);
        self::assertSame(
            ['Move Price up', 'Move Price down'],
            $this->texts($x, '//section//tbody/tr[2]//form/button/@aria-label'),
        );
        self::assertSame(['up', 'down'], $this->texts($x, '//section//tbody/tr[2]//form/input[@name="direction"]/@value'));
        self::assertSame($this->t('/fields/' . $price . '/move'), $x->query('//section//tbody/tr[2]//form/@action')->item(0)?->nodeValue);

        self::assertSame('Table settings', HtmlAssertions::text($html, '//fieldset[@class="fieldset-card"]/legend'));
        self::assertSame('Items', $x->query('//input[@id="settings-name"]/@value')->item(0)?->nodeValue);
        self::assertSame('item', $x->query('//input[@id="settings-item"]/@value')->item(0)?->nodeValue);
        self::assertSame(['None', 'Name', 'Price'], $this->texts($x, '//select[@id="settings-title"]/option'), 'Only types that can be a title.');
        self::assertSame(['Name'], $this->texts($x, '//select[@id="settings-title"]/option[@selected]'));
        self::assertSame(['Save table settings'], $this->texts($x, '//fieldset//button'));
        self::assertSame(0, $x->query('//fieldset//button[contains(@class, "button-primary")]')->length);

        self::assertSame('Delete this table', HtmlAssertions::text($html, '//section[@class="fieldset-card"]/h2[@class="legend"]'));
        self::assertSame($this->t('/delete'), $x->query('//section[@class="fieldset-card"]//a[@class="button button-danger"]/@href')->item(0)?->nodeValue);
    }

    public function testTableWithoutFields(): void
    {
        $this->ops->schema()->createTable($this->admin(), $this->project, 'Rooms', 'room', false);
        $html = $this->page(self::ADMIN, $this->p('/tables/' . $this->tableKey('Rooms') . '/fields'));

        self::assertSame('0 fields. 0 records.', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame('This table has no fields yet. Select Add a field to make one.', HtmlAssertions::text($html, '//section/p[@class="notice"]'));
    }

    public function testTableFieldsAreForAdminsOnly(): void
    {
        $this->share();
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/settings'), ['name' => 'Things', 'item_name' => 'thing', 'title_field' => ''])->status);
    }

    public function testSavingTableSettings(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/settings'), ['name' => 'Things', 'item_name' => 'thing', 'title_field' => '']);

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields'), $response->header('Location'));
        self::assertSame(['key' => 'notice.table_saved', 'params' => []], $this->services->flash[self::ADMIN]);
        $table = $this->ops->schema()->design($this->admin(), $this->project, $this->items)->table;
        self::assertSame(['Things', 'thing', null], [$table->name, $table->itemName, $table->titleFieldKey]);
    }

    public function testTableSettingsErrors(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/settings'), ['name' => '', 'item_name' => 'thing', 'title_field' => 'NOSUCHFIELD1']);
        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame(['#settings-name', '#settings-title'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Enter a name.', HtmlAssertions::text($html, '//p[@id="settings-name-error"]'));
        self::assertSame('Choose a field that holds text, a number, or a date.', HtmlAssertions::text($html, '//p[@id="settings-title-error"]'));
        self::assertSame('true', $x->query('//select[@id="settings-title"]/@aria-invalid')->item(0)?->nodeValue);
        self::assertSame('thing', $x->query('//input[@id="settings-item"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Projects', 'Home Inventory', 'Items'], $this->crumbs($x));
    }

    public function testMovingAField(): void
    {
        $price = $this->addField('Price', 'number');
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $price . '/move'), ['direction' => 'up']);

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields'), $response->header('Location'));
        self::assertArrayNotHasKey(self::ADMIN, $this->services->flash, 'Moving a field has no notice.');
        self::assertSame(['Price', 'Name'], $this->fieldNames());

        // An unknown direction changes nothing.
        $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $price . '/move'), ['direction' => 'sideways']);
        self::assertSame(['Price', 'Name'], $this->fieldNames());

        $this->share();
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/fields/' . $price . '/move'), ['direction' => 'down'])->status);
        self::assertSame(['Price', 'Name'], $this->fieldNames());
    }

    public function testNamesAreEscaped(): void
    {
        $this->addField('<i>Odd & "quoted"</i>', 'text');
        $html = $this->page(self::ADMIN, $this->t('/fields'));

        self::assertStringNotContainsString('<i>Odd', $html);
        self::assertStringContainsString('&lt;i&gt;Odd &amp; &quot;quoted&quot;&lt;/i&gt;', $html);
    }

    // Delete table (section 6).

    public function testDeleteTableConfirmation(): void
    {
        $rooms = $this->addRooms();
        $this->core->insertRecord($this->project, $rooms);
        $this->core->insertRecord($this->project, $rooms, [], true);
        $html = $this->page(self::ADMIN, $this->p('/tables/' . $rooms . '/delete'));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'Rooms', 'Delete'], $this->crumbs($x));
        self::assertSame('Delete the table Rooms?', HtmlAssertions::text($html, '//h1'));
        self::assertSame(
            [
                'This deletes 2 records and every field in the table.',
                'These fields in other tables link to it and will be deleted too:',
                'TiffinDB saves a copy of the project before deleting. The person who looks after this TiffinDB can restore it from that copy.',
            ],
            $this->texts($x, '//main/p'),
        );
        self::assertSame(['Room in Items'], $this->texts($x, '//main/ul/li'));
        self::assertSame('I understand that 2 records will be deleted.', HtmlAssertions::text($html, '//label[@class="check"]'));
        self::assertSame('required', $x->query('//input[@id="delete-confirm"]/@required')->item(0)?->nodeValue);
        self::assertSame(['Delete table'], $this->texts($x, '//main//button[@class="button button-large button-danger"]'));
        self::assertSame($this->p('/tables/' . $rooms . '/fields'), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
    }

    public function testDeletingATableNeedsTheBox(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/delete'));
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);

        self::assertSame(['#delete-confirm'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Tick the box to confirm.', HtmlAssertions::text($response->body, '//p[@id="delete-confirm-error"]'));
        self::assertSame('true', $x->query('//input[@id="delete-confirm"]/@aria-invalid')->item(0)?->nodeValue);
        self::assertCount(1, $this->ops->schema()->tables($this->admin(), $this->project));
    }

    public function testDeletingATable(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/delete'), ['confirm' => '1']);

        self::assertSame(303, $response->status);
        self::assertSame($this->p('/tables'), $response->header('Location'));
        self::assertSame(['key' => 'notice.table_deleted', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame([], $this->ops->schema()->tables($this->admin(), $this->project));
    }

    public function testDeletingATableIsForAdminsOnly(): void
    {
        $this->share();
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/delete'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/delete'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/delete'), ['confirm' => '1'])->status);
        self::assertCount(1, $this->ops->schema()->tables($this->admin(), $this->project));
    }

    // Add a field (section 7).

    public function testChooseType(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/fields/new'));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Add a field'], $this->crumbs($x));
        self::assertSame('Add a field to Items', HtmlAssertions::text($html, '//h1'));
        self::assertSame('get', $x->query('//main//form/@method')->item(0)?->nodeValue);
        self::assertSame($this->t('/fields/new'), $x->query('//main//form/@action')->item(0)?->nodeValue);
        self::assertSame('What will this field hold?', HtmlAssertions::text($html, '//fieldset[@class="fieldset-card"]/legend'));
        self::assertSame(
            ['Words', 'Numbers and money', 'Dates and times', 'Yes or no, and choices', 'Contact and web', 'Files and links'],
            $this->texts($x, '//fieldset/fieldset/legend[@class="legend-plain"]'),
        );
        self::assertSame(
            ['text', 'long_text', 'number', 'currency', 'rating', 'auto_number', 'date', 'time', 'date_time', 'duration',
                'checkbox', 'choice', 'email', 'phone', 'url', 'attachment', 'relation'],
            $this->texts($x, '//div[@class="radio-grid"]/label[@class="radio-card"]/input[@type="radio"][@name="type"]/@value'),
        );
        self::assertSame('Date and time', HtmlAssertions::text($html, '//label[input/@value="date_time"]//span[@class="radio-card-title"]'));
        self::assertSame('A day and a time, such as an appointment.', HtmlAssertions::text($html, '//label[input/@value="date_time"]//span[@class="radio-card-hint"]'));
        self::assertSame(['Next'], $this->texts($x, '//main//button'));
        self::assertSame($this->t('/fields'), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//p[@class="error-text"]')->length);
    }

    public function testChooseTypeWithAnUnknownType(): void
    {
        $response = $this->request(self::ADMIN, 'GET', $this->t('/fields/new?type=spreadsheet'));
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);

        self::assertSame('Choose what the field will hold.', HtmlAssertions::text($response->body, '//fieldset[@class="fieldset-card"]/p[@id="field-type-error"]'));
        self::assertSame(['#field-type'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//a/@href'));
    }

    /** @return iterable<string, array{string, string, list<string>}> type, title, option element ids */
    public static function types(): iterable
    {
        yield 'text' => ['text', 'New field: Text', ['opt-max-length']];
        yield 'long_text' => ['long_text', 'New field: Long text', ['opt-max-length']];
        yield 'number' => ['number', 'New field: Number', ['opt-decimals', 'opt-min', 'opt-max']];
        yield 'currency' => ['currency', 'New field: Currency', ['opt-code', 'opt-minor-digits']];
        yield 'date' => ['date', 'New field: Date', []];
        yield 'time' => ['time', 'New field: Time', ['opt-seconds']];
        yield 'date_time' => ['date_time', 'New field: Date and time', ['opt-seconds']];
        yield 'duration' => ['duration', 'New field: Duration', ['opt-seconds']];
        yield 'checkbox' => ['checkbox', 'New field: Checkbox', []];
        yield 'choice' => ['choice', 'New field: Choice', ['opt-choices']];
        yield 'rating' => ['rating', 'New field: Rating', ['opt-max']];
        yield 'url' => ['url', 'New field: Web address', []];
        yield 'email' => ['email', 'New field: Email address', []];
        yield 'phone' => ['phone', 'New field: Phone number', []];
        yield 'attachment' => ['attachment', 'New field: File or image', ['opt-images-only']];
        yield 'relation' => ['relation', 'New field: Link to another table', ['opt-target', 'opt-this-max', 'opt-other-max', 'opt-reverse-name']];
        yield 'auto_number' => ['auto_number', 'New field: Auto-number', ['opt-start']];
    }

    /** @param list<string> $optionIds */
    #[DataProvider('types')]
    public function testNewFieldFormForEachType(string $type, string $title, array $optionIds): void
    {
        $html = $this->page(self::ADMIN, $this->t('/fields/new?type=' . $type));
        $x = $this->xpath($html);

        self::assertSame($title . ' - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame($title, HtmlAssertions::text($html, '//h1'));
        self::assertSame('In the table Items. Choose a different type', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame($this->t('/fields'), $x->query('//main//form/@action')->item(0)?->nodeValue);
        self::assertSame($type, $x->query('//main//form/input[@name="type"]/@value')->item(0)?->nodeValue);
        self::assertSame('Hint shown under the field (optional)', HtmlAssertions::text($html, '//label[@for="field-help"]'));
        $required = $x->query('//input[@id="field-required"]')->length;
        self::assertSame(in_array($type, ['checkbox', 'auto_number'], true) ? 0 : 1, $required);
        $ids = [];
        foreach ($x->query('//main//form//*[starts-with(@id, "opt-")][not(contains(@id, "-help"))]') as $node) {
            $ids[] = $node->attributes?->getNamedItem('id')?->nodeValue;
        }
        self::assertSame($optionIds, $ids);
        if ($optionIds === []) {
            self::assertSame('This type has no settings.', HtmlAssertions::text($html, '//main//form/p[@class="help"]'));
        }
        self::assertSame(['Add field'], $this->texts($x, '//main//button'));
    }

    public function testNewFieldDefaults(): void
    {
        $this->addRooms();
        $x = $this->xpath($this->page(self::ADMIN, $this->t('/fields/new?type=text')));
        self::assertSame(['500', '10000'], [$x->query('//input[@id="opt-max-length"]/@value')->item(0)?->nodeValue, $x->query('//input[@id="opt-max-length"]/@max')->item(0)?->nodeValue]);

        $x = $this->xpath($this->page(self::ADMIN, $this->t('/fields/new?type=currency')));
        self::assertSame('USD', $x->query('//input[@id="opt-code"]/@value')->item(0)?->nodeValue);
        self::assertSame(['2'], $this->texts($x, '//select[@id="opt-minor-digits"]/option[@selected]'));

        $x = $this->xpath($this->page(self::ADMIN, $this->t('/fields/new?type=rating')));
        self::assertSame(['5'], $this->texts($x, '//select[@id="opt-max"]/option[@selected]'));

        $html = $this->page(self::ADMIN, $this->t('/fields/new?type=relation'));
        $x = $this->xpath($html);
        self::assertSame(['Items (this table)', 'Rooms'], $this->texts($x, '//select[@id="opt-target"]/option'));
        self::assertSame(['Each item can link to', 'Each record in the linked table can link to'], $this->texts($x, '//fieldset/legend[@class="legend-plain"]'));
        self::assertSame(['One record', 'Several records', 'One item', 'Several of these'], $this->texts($x, '//fieldset/label[@class="check"]'));
        self::assertSame(['one', 'many'], $this->texts($x, '//input[@type="radio"][@checked]/@value'));
        self::assertSame('Items', $x->query('//input[@id="opt-reverse-name"]/@value')->item(0)?->nodeValue);
    }

    public function testAddingAField(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), [
            'type' => 'number', 'name' => 'Weight', 'help' => 'In kilograms', 'required' => '1',
            'opt_decimals' => '2', 'opt_min' => '0', 'opt_max' => '',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields'), $response->header('Location'));
        self::assertSame(['key' => 'notice.field_added', 'params' => ['field' => 'Weight']], $this->services->flash[self::ADMIN]);
        $field = $this->field('Weight');
        self::assertSame(['number', true, 'In kilograms', 2, 0, null], [$field->type, $field->required, $field->help, $field->options['decimals'], $field->options['min'], $field->options['max']]);
    }

    public function testAddingAChoiceField(): void
    {
        $this->request(self::ADMIN, 'POST', $this->t('/fields'), ['type' => 'choice', 'name' => 'Kind', 'opt_choices' => "Appliance\r\nTool\r\n\r\nFurniture"]);

        self::assertSame(['Appliance', 'Tool', 'Furniture'], array_column($this->field('Kind')->options['choices'], 'label'));
    }

    public function testAddingALinkField(): void
    {
        $rooms = $this->ops->schema()->createTable($this->admin(), $this->project, 'Rooms', 'room', true)->key;
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), [
            'type' => 'relation', 'name' => 'Room', 'opt_target' => $rooms, 'opt_this_max' => 'one', 'opt_other_max' => 'many', 'opt_reverse_name' => 'Items',
        ]);

        self::assertSame(303, $response->status);
        $summary = $this->ops->schema()->design($this->admin(), $this->project, $this->items)->fields[1];
        self::assertSame(['Room', 'Rooms', Cardinality::One], [$summary->field->name, $summary->linkedTableName, $summary->links]);
    }

    public function testNewFieldErrorsMapToFields(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), ['type' => 'text', 'name' => 'Name', 'help' => 'Short', 'opt_max_length' => '0']);
        self::assertSame(422, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame(['#field-name', '#opt-max-length'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Another field in this table already has this name.', HtmlAssertions::text($html, '//p[@id="field-name-error"]'));
        self::assertSame('Choose a number from 1 to 10000.', HtmlAssertions::text($html, '//p[@id="opt-max-length-error"]'));
        self::assertSame('0', $x->query('//input[@id="opt-max-length"]/@value')->item(0)?->nodeValue);
        self::assertSame('Short', $x->query('//input[@id="field-help"]/@value')->item(0)?->nodeValue);
        self::assertSame('New field: Text', HtmlAssertions::text($html, '//h1'));
    }

    public function testNewLinkFieldErrorsMapToFields(): void
    {
        $rooms = $this->ops->schema()->createTable($this->admin(), $this->project, 'Rooms', 'room', true)->key;
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), [
            'type' => 'relation', 'name' => 'Room', 'opt_target' => $rooms, 'opt_this_max' => 'one', 'opt_other_max' => 'many', 'opt_reverse_name' => 'Name',
        ]);
        self::assertSame(422, $response->status);
        self::assertSame('The table Rooms already has a field with this name. Choose another name.', HtmlAssertions::text($response->body, '//p[@id="opt-reverse-name-error"]'));
        self::assertSame([$rooms], $this->texts($this->xpath($response->body), '//select[@id="opt-target"]/option[@selected]/@value'));

        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), [
            'type' => 'relation', 'name' => 'Room', 'opt_target' => $rooms, 'opt_this_max' => 'lots', 'opt_other_max' => 'many', 'opt_reverse_name' => 'Items',
        ]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertSame(['#opt-this-max'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//a/@href'));
    }

    public function testPostingAnUnknownTypeShowsTheTypePicker(): void
    {
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields'), ['type' => 'spreadsheet', 'name' => 'X']);

        self::assertSame(422, $response->status);
        self::assertSame('Choose what the field will hold.', HtmlAssertions::text($response->body, '//p[@id="field-type-error"]'));
    }

    public function testAddingAFieldIsForAdminsOnly(): void
    {
        $this->share();
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields/new'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields/new?type=text'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/fields'), ['type' => 'text', 'name' => 'Notes'])->status);
        self::assertSame(['Name'], $this->fieldNames());
    }

    // Change a field (section 8).

    public function testEditField(): void
    {
        $name = $this->field('Name')->key;
        $this->core->insertRecord($this->project, $this->items, [$name => 'Kettle']);
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $name));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Name'], $this->crumbs($x));
        self::assertSame('Change the field Name', HtmlAssertions::text($html, '//h1'));
        self::assertSame('Type: Text. Change the type', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame($this->t('/fields/' . $name . '/type'), $x->query('//main/div/p[@class="lead"]/a/@href')->item(0)?->nodeValue);
        self::assertSame('1 record has a value in this field.', HtmlAssertions::text($html, '//main/div/p[not(@class)]'));
        self::assertSame('Name', $x->query('//input[@id="field-name"]/@value')->item(0)?->nodeValue);
        self::assertSame('500', $x->query('//input[@id="opt-max-length"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Save field'], $this->texts($x, '//main//form//button'));
        self::assertSame('Delete this field', HtmlAssertions::text($html, '//section/h2'));
        self::assertSame($this->t('/fields/' . $name . '/delete'), $x->query('//section//a[@class="button button-danger"]/@href')->item(0)?->nodeValue);
    }

    public function testEditFieldValueCountsAndFixedTypes(): void
    {
        $count = $this->addField('Count', 'auto_number', ['start' => 10]);
        $this->addRooms();
        $room = $this->field('Room')->key;

        $html = $this->page(self::ADMIN, $this->t('/fields/' . $count));
        self::assertSame('No records have a value in this field yet.', HtmlAssertions::text($html, '//main/div/p[not(@class)]'));
        self::assertSame('Numbers start at 10. The next record gets 10.', HtmlAssertions::text($html, '//main//form/p'));
        self::assertSame(0, $this->xpath($html)->query('//input[@id="field-required"]')->length);

        $html = $this->page(self::ADMIN, $this->t('/fields/' . $room));
        $x = $this->xpath($html);
        self::assertSame('Type: Link to Rooms. The type of this field cannot be changed.', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame(0, $x->query('//main/div/p[not(@class)]')->length, 'No value count for links.');
        self::assertSame('Links to records in Rooms.', HtmlAssertions::text($html, '//main//form/p'));
        self::assertSame(['one'], $this->texts($x, '//input[@name="opt_this_max"][@checked]/@value'));

        $three = [];
        foreach ([1, 2, 3] as $n) {
            $three[] = $this->core->insertRecord($this->project, $this->items, [$this->field('Name')->key => 'Item ' . $n]);
        }
        self::assertCount(3, $three);
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $this->field('Name')->key));
        self::assertSame('3 records have a value in this field.', HtmlAssertions::text($html, '//main/div/p[not(@class)]'));

        $price = $this->addField('Price', 'currency', ['code' => 'EUR', 'minor_digits' => 0]);
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $price));
        self::assertSame('Digits after the point: 0. This cannot be changed.', HtmlAssertions::text($html, '//main//form/p[not(@class)]'));
        self::assertSame('EUR', $this->xpath($html)->query('//input[@id="opt-code"]/@value')->item(0)?->nodeValue);
    }

    public function testSavingAField(): void
    {
        $price = $this->addField('Price', 'currency', ['code' => 'EUR']);
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $price), ['name' => 'Cost', 'help' => 'Paid', 'required' => '1', 'opt_code' => 'gbp']);

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields'), $response->header('Location'));
        self::assertSame(['key' => 'notice.field_saved', 'params' => ['field' => 'Cost']], $this->services->flash[self::ADMIN]);
        $field = $this->field('Cost');
        self::assertSame(['GBP', 2, true, 'Paid'], [$field->options['code'], $field->options['minor_digits'], $field->required, $field->help]);
    }

    public function testSavingAFieldErrors(): void
    {
        $weight = $this->addField('Weight', 'number');
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $weight), ['name' => '', 'help' => '', 'opt_decimals' => '9', 'opt_min' => '5', 'opt_max' => '1']);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);

        self::assertSame(['#field-name', '#opt-decimals', '#opt-min'], $this->texts($x, '//div[@class="error-summary"]//a/@href'));
        self::assertSame('5', $x->query('//input[@id="opt-min"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Weight'], $this->crumbs($x));
    }

    public function testChoiceEditRenamesAndRewritesByLabel(): void
    {
        $kind = $this->addField('Kind', 'choice', ['choices' => ['Appliance', 'Tool']]);
        [$appliance, $tool] = array_column($this->field('Kind')->options['choices'], 'key');
        $id = $this->core->insertRecord($this->project, $this->items, [$kind => 'Tool']);
        $html = $this->request(self::ADMIN, 'GET', $this->t('/fields/' . $kind))->body;
        // The repeated choice boxes are named by aria-label (rule 4.5 item 6).
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame('Choices', HtmlAssertions::text($html, '//fieldset[@class="field"]/legend'));
        self::assertSame(['Choice 1', 'Choice 2'], $this->texts($x, '//fieldset[@class="field"]/input/@aria-label'));
        self::assertSame(['opt_choice_' . $appliance, 'opt_choice_' . $tool], $this->texts($x, '//fieldset[@class="field"]/input/@name'));
        self::assertSame(['Appliance', 'Tool'], $this->texts($x, '//fieldset[@class="field"]/input/@value'));
        self::assertSame('Add more choices, one per line (optional)', HtmlAssertions::text($html, '//label[@for="opt-choices-new"]'));

        // Removing "Tool" while adding "tool" rewrites the value, so nothing is cleared (P74).
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $kind), [
            'name' => 'Kind', 'help' => '', 'opt_choice_' . $appliance => 'Appliances', 'opt_choice_' . $tool => '', 'opt_choices' => "tool\nFurniture",
        ]);
        self::assertSame(303, $response->status);
        self::assertSame(['Appliances', 'tool', 'Furniture'], array_column($this->field('Kind')->options['choices'], 'label'));
        self::assertSame('tool', $this->stored($kind, $id));
    }

    public function testRemovingAChoiceInUseNeedsConfirmation(): void
    {
        $kind = $this->addField('Kind', 'choice', ['choices' => ['Appliance', 'Tool']]);
        [$appliance, $tool] = array_column($this->field('Kind')->options['choices'], 'key');
        $first = $this->core->insertRecord($this->project, $this->items, [$kind => 'Tool']);
        $this->core->insertRecord($this->project, $this->items, [$kind => 'Tool']);
        $post = ['name' => 'Kind', 'help' => 'A <kind>', 'opt_choice_' . $appliance => 'Appliance', 'opt_choice_' . $tool => '', 'opt_choices' => ''];

        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $kind), $post);
        self::assertSame(409, $response->status);
        $html = $response->body;
        $this->assertScreen($html);
        $x = $this->xpath($html);

        self::assertSame('Clear 2 values? - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Kind'], $this->crumbs($x));
        self::assertSame('Clear 2 values?', HtmlAssertions::text($html, '//h1'));
        self::assertSame(
            'With this change, 2 values in 2 records no longer fit and will be cleared. TiffinDB saves a copy of the project first.',
            HtmlAssertions::text($html, '//main/p'),
        );
        self::assertSame($this->t('/fields/' . $kind), $x->query('//main//form/@action')->item(0)?->nodeValue);
        $hidden = [];
        foreach ($x->query('//main//form/input[@type="hidden"]') as $input) {
            $hidden[] = [$input->attributes?->getNamedItem('name')?->nodeValue, $input->attributes?->getNamedItem('value')?->nodeValue];
        }
        self::assertSame(
            [['_csrf', FakeServices::SESSION_CSRF], ['name', 'Kind'], ['help', 'A <kind>'], ['opt_choice_' . $appliance, 'Appliance'], ['opt_choice_' . $tool, ''], ['opt_choices', ''], ['confirm', '1']],
            $hidden,
            'Every submitted field again, the current token first, and confirm (P59).',
        );
        self::assertStringContainsString('value="A &lt;kind&gt;"', $html);
        self::assertSame(['Clear 2 values and save'], $this->texts($x, '//main//button[@class="button button-large button-danger"]'));
        self::assertSame('Go back without saving', HtmlAssertions::text($html, '//div[@class="form-actions"]/a'));
        self::assertSame('Tool', $this->stored($kind, $first), 'Nothing changed yet.');

        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $kind), $post + ['confirm' => '1']);
        self::assertSame(303, $response->status);
        self::assertNull($this->stored($kind, $first));
        self::assertSame(['Appliance'], array_column($this->field('Kind')->options['choices'], 'label'));
    }

    public function testLoweringARatingForOneValue(): void
    {
        $stars = $this->addField('Stars', 'rating');
        $this->core->insertRecord($this->project, $this->items, [$stars => 5]);
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $stars), ['name' => 'Stars', 'help' => '', 'opt_max' => '3']);

        self::assertSame(409, $response->status);
        self::assertSame('Clear 1 value?', HtmlAssertions::text($response->body, '//h1'));
        self::assertSame(['Clear 1 value and save'], $this->texts($this->xpath($response->body), '//main//button'));
        self::assertSame(
            'With this change, 1 value in 1 record no longer fits and will be cleared. TiffinDB saves a copy of the project first.',
            HtmlAssertions::text($response->body, '//main/p'),
        );
        self::assertSame('Go back without saving', HtmlAssertions::text($response->body, '//div[@class="form-actions"]/a'));
    }

    public function testChangingAFieldIsForAdminsOnly(): void
    {
        $this->share();
        $name = $this->field('Name')->key;
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields/' . $name))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/fields/' . $name), ['name' => 'Title', 'opt_max_length' => '500'])->status);
        self::assertSame(['Name'], $this->fieldNames());
    }

    // Change a field's type (section 9).

    public function testTypeForm(): void
    {
        $name = $this->field('Name')->key;
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $name . '/type'));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Name', 'Type'], $this->crumbs($x));
        self::assertSame($this->t('/fields/' . $name), $x->query('//nav[@class="breadcrumb"]/a[.="Name"]/@href')->item(0)?->nodeValue);
        self::assertSame('Change the type of Name', HtmlAssertions::text($html, '//h1'));
        self::assertSame('It is now: Text.', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertStringStartsWith('TiffinDB converts the values it can.', HtmlAssertions::text($html, '//main/p'));
        self::assertSame('Change it to', HtmlAssertions::text($html, '//fieldset/legend'));
        $values = $this->texts($x, '//label[@class="radio-card"]/input/@value');
        self::assertSame(['long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'checkbox', 'choice', 'rating', 'url', 'email', 'phone'], $values);
        self::assertSame('long_text', $x->query('//input[@id="type-choice"]/@value')->item(0)?->nodeValue);
        self::assertSame(['Change type'], $this->texts($x, '//main//button'));
        self::assertSame($this->t('/fields/' . $name), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
    }

    public function testTypeFormForAFieldThatCannotChangeGoesBack(): void
    {
        $this->addRooms();
        $room = $this->field('Room')->key;

        self::assertSame($this->t('/fields/' . $room), $this->request(self::ADMIN, 'GET', $this->t('/fields/' . $room . '/type'))->header('Location'));
    }

    public function testChangingTheTypeWithoutLoss(): void
    {
        $name = $this->field('Name')->key;
        $this->core->insertRecord($this->project, $this->items, [$name => 'Kettle']);
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $name . '/type'), ['type' => 'long_text']);

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields/' . $name), $response->header('Location'));
        self::assertSame(['key' => 'notice.type_changed', 'params' => ['field' => 'Name', 'type' => 'Long text']], $this->services->flash[self::ADMIN]);
    }

    public function testChangingTheTypeWithLossNeedsConfirmation(): void
    {
        $name = $this->field('Name')->key;
        $kettle = $this->core->insertRecord($this->project, $this->items, [$name => 'Kettle']);
        $this->core->insertRecord($this->project, $this->items, [$name => '12']);

        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $name . '/type'), ['type' => 'number']);
        self::assertSame(409, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('Clear 1 value?', HtmlAssertions::text($response->body, '//h1'));
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Name', 'Type'], $this->crumbs($x));
        self::assertSame($this->t('/fields/' . $name . '/type'), $x->query('//main//form/@action')->item(0)?->nodeValue);
        self::assertSame($this->t('/fields/' . $name . '/type'), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertSame(['_csrf', 'type', 'confirm'], $this->texts($x, '//main//form/input[@type="hidden"]/@name'));
        self::assertSame('text', $this->field('Name')->type, 'Nothing changed yet.');

        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $name . '/type'), ['type' => 'number', 'confirm' => '1']);
        self::assertSame(303, $response->status);
        self::assertSame(
            ['key' => 'notice.type_changed_cleared', 'params' => ['field' => 'Name', 'type' => 'Number', 'count' => '1']],
            $this->services->flash[self::ADMIN],
        );
        self::assertSame('number', $this->field('Name')->type);
        self::assertNull($this->stored($name, $kettle));
    }

    public function testChangingTheTypeWithoutAChoice(): void
    {
        $name = $this->field('Name')->key;
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $name . '/type'), []);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);

        self::assertSame(['#type-choice'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//a/@href'));
        self::assertSame('Choose a type.', HtmlAssertions::text($response->body, '//p[@id="type-choice-error"]'));
    }

    public function testChangingTheTypeIsForAdminsOnly(): void
    {
        $this->share();
        $name = $this->field('Name')->key;
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields/' . $name . '/type'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/fields/' . $name . '/type'), ['type' => 'long_text', 'confirm' => '1'])->status);
        self::assertSame('text', $this->field('Name')->type);
    }

    // Delete a field (section 10).

    public function testDeleteFieldConfirmation(): void
    {
        $name = $this->field('Name')->key;
        $this->core->insertRecord($this->project, $this->items, [$name => 'Kettle']);
        $this->core->insertRecord($this->project, $this->items, [$name => 'Lamp'], true);
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $name . '/delete'));
        $x = $this->xpath($html);

        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Name', 'Delete'], $this->crumbs($x));
        self::assertSame('Delete the field Name?', HtmlAssertions::text($html, '//h1'));
        self::assertSame(
            ['Its values in 2 records will be deleted.', 'TiffinDB saves a copy of the project before deleting. The person who looks after this TiffinDB can restore it from that copy.'],
            $this->texts($x, '//main/p'),
        );
        self::assertSame(['Delete field'], $this->texts($x, '//main//button[@class="button button-large button-danger"]'));
        self::assertSame($this->t('/fields/' . $name), $x->query('//div[@class="form-actions"]/a/@href')->item(0)?->nodeValue);
    }

    public function testDeleteLinkFieldConfirmation(): void
    {
        $this->addRooms();
        $html = $this->page(self::ADMIN, $this->t('/fields/' . $this->field('Room')->key . '/delete'));

        self::assertSame(
            ['Every link or file in this field will be removed.', 'The matching field Items in Rooms is deleted too.'],
            array_slice($this->texts($this->xpath($html), '//main/p'), 0, 2),
        );
    }

    public function testDeletingAField(): void
    {
        $price = $this->addField('Price', 'number');
        $response = $this->request(self::ADMIN, 'POST', $this->t('/fields/' . $price . '/delete'));

        self::assertSame(303, $response->status);
        self::assertSame($this->t('/fields'), $response->header('Location'));
        self::assertSame(['key' => 'notice.field_deleted', 'params' => ['field' => 'Price']], $this->services->flash[self::ADMIN]);
        self::assertSame(['Name'], $this->fieldNames());
    }

    public function testDeletingAFieldIsForAdminsOnly(): void
    {
        $this->share();
        $name = $this->field('Name')->key;
        self::assertSame(403, $this->request(self::MEMBER, 'GET', $this->t('/fields/' . $name . '/delete'))->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $this->t('/fields/' . $name . '/delete'))->status);
        self::assertSame(['Name'], $this->fieldNames());
    }

    public function testUnknownTableOrFieldIsNotFound(): void
    {
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->p('/tables/NOSUCHTABLE1/fields'))->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->t('/fields/NOSUCHFIELD1'))->status);
    }

    // Helpers.

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

    private function share(): void
    {
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    private function p(string $path): string
    {
        return '/projects/' . $this->project . $path;
    }

    private function t(string $path): string
    {
        return $this->p('/tables/' . $this->items . $path);
    }

    private function tableKey(string $name): string
    {
        foreach ($this->ops->schema()->tables($this->admin(), $this->project) as $summary) {
            if ($summary->table->name === $name) {
                return $summary->table->key;
            }
        }
        self::fail("No table {$name}.");
    }

    /** Adds the table Rooms and the link field Room (Items to Rooms, one each); returns the Rooms key. */
    private function addRooms(): string
    {
        $rooms = $this->ops->schema()->createTable($this->admin(), $this->project, 'Rooms', 'room', true)->key;
        $this->ops->schema()->addRelationField($this->admin(), $this->project, $this->items, 'Room', $rooms, Cardinality::One, Cardinality::Many, 'Items', false, '');

        return $rooms;
    }

    /** @param array<string, mixed> $options */
    private function addField(string $name, string $type, array $options = [], bool $required = false): string
    {
        return $this->ops->schema()->addField($this->admin(), $this->project, $this->items, $name, $type, $options, $required, '')->key;
    }

    private function field(string $name): \TiffinDB\Core\Schema\FieldDef
    {
        foreach ($this->ops->schema()->design($this->admin(), $this->project, $this->items)->fields as $summary) {
            if ($summary->field->name === $name) {
                return $summary->field;
            }
        }
        self::fail("No field {$name}.");
    }

    /** @return list<string> */
    private function fieldNames(): array
    {
        $names = [];
        foreach ($this->ops->schema()->design($this->admin(), $this->project, $this->items)->fields as $summary) {
            $names[] = $summary->field->name;
        }

        return $names;
    }

    private function stored(string $fieldKey, int $id): mixed
    {
        return $this->core->projectDb($this->project)->value(
            'SELECT ' . Identifier::column($fieldKey) . ' FROM ' . Identifier::table($this->items) . ' WHERE "id" = ?',
            [$id],
        );
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
