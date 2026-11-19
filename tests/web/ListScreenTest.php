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
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\ProjectController;
use TiffinDB\Web\Controller\RecordListController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-29 (M2-WP6): the list view and the filter page
 * (docs/design/m2/list-and-search.md sections 2 and 3), through the kernel
 * with the real templates and strings and real core operations over a
 * CoreFixture instance. Synthetic data only.
 */
#[CoversClass(RecordListController::class)]
#[CoversClass(ProjectController::class)]
final class ListScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $rooms;
    /** @var array<string, string> field name => key */
    private array $f = [];
    /** @var array<string, int> record name => id */
    private array $r = [];

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $schema = $this->ops->schema();
        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->f['Name'] = $schema->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $this->rooms = $schema->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
        $this->f['Room name'] = $schema->design($admin, $this->project, $this->rooms)->fields[0]->field->key;
        $this->f['Room'] = $schema->addRelationField($admin, $this->project, $this->items, 'Room', $this->rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->f['Category'] = $this->addField('Category', 'choice', ['choices' => ['Appliance', 'Cookware', 'Tool']]);
        $this->f['Purchased'] = $this->addField('Purchased', 'date');
        $this->f['Value'] = $this->addField('Value', 'currency', ['code' => 'USD', 'minor_digits' => 2]);
        $this->f['Condition'] = $this->addField('Condition', 'rating', ['max' => 5]);
        $this->f['Receipt'] = $this->addField('Receipt', 'attachment');
        $this->f['Under warranty'] = $this->addField('Under warranty', 'checkbox');
        $this->f['Notes'] = $this->addField('Notes', 'long_text');

        $kitchen = $this->core->addRecord($this->project, $this->rooms, [$this->f['Room name'] => 'Kitchen']);
        $this->r['Kitchen'] = $kitchen;
        $this->r['Stand mixer'] = $this->add(['Name' => 'Stand mixer', 'Category' => 'Appliance', 'Purchased' => '2025-03-14', 'Value' => '329.00', 'Condition' => '5', 'Under warranty' => '1', 'Notes' => "Bowl and hook\nSecond line"]);
        $this->r['Dutch oven'] = $this->add(['Name' => 'Dutch oven', 'Category' => 'Cookware', 'Purchased' => '2022-12-25', 'Value' => '95.00', 'Condition' => '4']);
        $this->r['Cordless drill'] = $this->add(['Name' => 'Cordless drill', 'Category' => 'Tool', 'Purchased' => '2023-11-02', 'Value' => '149.00']);
        $this->ops->records()->setLinks($admin, $this->project, $this->items, (string) $this->r['Stand mixer'], $this->f['Room'], [$kitchen]);
        $this->attach($this->r['Cordless drill']);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // List view with records (section 2).

    public function testListForAnAdmin(): void
    {
        $html = $this->page(self::ADMIN, $this->t(''));
        $x = $this->xpath($html);

        self::assertSame('Items, Home Inventory - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//div[@class="app-columns"]/nav[@class="sidebar"]/following-sibling::main[@id="main"][@class="page page-list"]')->length);
        self::assertSame('page', $x->query('//nav[@class="sidebar"]//a[@class="sidebar-link"][@aria-current="page"]/@aria-current')->item(0)?->nodeValue);
        self::assertSame('Items 3', HtmlAssertions::text($html, '//nav[@class="sidebar"]//a[@aria-current="page"]'));
        self::assertSame(1, $x->query('//form[@class="top-search"][@role="search"]')->length);
        self::assertSame($this->p('/search'), $x->query('//form[@class="top-search"]/@action')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//nav[@class="top-nav"]//*[@aria-current]')->length, 'No top-nav item is current.');

        self::assertSame('Items', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('3 records, sorted by Name, A to Z', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame('Add item', HtmlAssertions::text($html, '//div[@class="page-actions"]/a[@class="button button-primary"]'));
        self::assertSame($this->t('/records/new'), $x->query('//div[@class="page-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertSame(1, $x->query('//div[@class="page-actions"]/a/svg[@class="button-icon"][@aria-hidden="true"]')->length);

        // Controls (item 3).
        self::assertSame($this->t(''), $x->query('//form[@class="list-controls"]/@action')->item(0)?->nodeValue);
        self::assertSame('get', $x->query('//form[@class="list-controls"]/@method')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//form[@class="list-controls"]/input[@type="hidden"]')->length);
        self::assertSame(['Find in Items', 'Sort by'], $this->texts($x, '//form[@class="list-controls"]//label'));
        self::assertSame('Type part of a name or other text', $x->query('//input[@id="find"]/@placeholder')->item(0)?->nodeValue);
        self::assertSame(['search', 'q', '200', ''], [$this->attr($x, '//input[@id="find"]', 'type'), $this->attr($x, '//input[@id="find"]', 'name'), $this->attr($x, '//input[@id="find"]', 'maxlength'), $this->attr($x, '//input[@id="find"]', 'value')]);
        self::assertSame(
            [
                'Name, A to Z', 'Name, Z to A', 'Room, A to Z', 'Room, Z to A', 'Category, in list order', 'Category, in reverse list order',
                'Purchased, oldest first', 'Purchased, newest first', 'Value, lowest first', 'Value, highest first',
                'Condition, lowest first', 'Condition, highest first', 'Under warranty, not ticked first', 'Under warranty, ticked first',
                'Notes, A to Z', 'Notes, Z to A', 'Newest added first', 'Oldest added first', 'Recently changed first',
            ],
            $this->texts($x, '//select[@id="sort"]/option'),
        );
        self::assertSame(['Name, A to Z'], $this->texts($x, '//select[@id="sort"]/option[@selected]'));
        self::assertSame($this->f['Name'] . '.asc', $this->attr($x, '//select[@id="sort"]/option[@selected]', 'value'));
        self::assertSame(['Apply'], $this->texts($x, '//form[@class="list-controls"]/button[@type="submit"]'));
        self::assertSame($this->t('/filter'), $this->attr($x, '//form[@class="list-controls"]/a[@class="button"]', 'href'));
        self::assertSame('Filter', HtmlAssertions::text($html, '//form[@class="list-controls"]/a'));

        // Table (item 6).
        self::assertSame('Items, 1 to 3 of 3 shown', HtmlAssertions::text($html, '//table[@class="data-table data-table-plain"]/caption[@class="visually-hidden"]'));
        self::assertSame(['Name (A to Z)', 'Room', 'Category', 'Purchased', 'Value', 'Condition', 'Receipt'], $this->texts($x, '//thead/tr/th[@scope="col"]'));
        self::assertSame('ascending', $this->attr($x, '//thead/tr/th[1]', 'aria-sort'));
        self::assertSame(1, $x->query('//thead//th[@aria-sort]')->length);
        self::assertSame(['Value'], $this->texts($x, '//thead/tr/th[@class="num"]'));
        self::assertSame(['Cordless drill', 'Dutch oven', 'Stand mixer'], $this->texts($x, '//tbody/tr/th[@scope="row"]/a[@class="cell-title"]'));
        self::assertSame($this->t('/records/' . $this->r['Cordless drill']), $this->attr($x, '//tbody/tr[1]/th/a', 'href'));
        self::assertSame(['Kitchen', 'Appliance', '14 Mar 2025', '$329.00', '5 of 5', 'None'], $this->texts($x, '//tbody/tr[3]/td'));
        self::assertSame(['None', 'Tool', '2 Nov 2023', '$149.00', '', '1 file'], $this->texts($x, '//tbody/tr[1]/td'));
        self::assertSame('/projects/' . $this->project . '/tables/' . $this->rooms . '/records/' . $this->r['Kitchen'], $this->attr($x, '//tbody/tr[3]/td[1]/a', 'href'));
        self::assertSame(['None', 'None', 'None', 'None'], $this->texts($x, '//tbody//td/span[@class="cell-none"]'));
        self::assertSame('num', $this->attr($x, '//tbody/tr[3]/td[4]', 'class'));

        // Pager (item 7).
        self::assertSame('Pages of records', $this->attr($x, '//nav[@class="pager"]', 'aria-label'));
        self::assertSame('Showing 1 to 3 of 3', HtmlAssertions::text($html, '//nav[@class="pager"]/span[@class="pager-status"]'));
        self::assertSame(['Previous', 'Next'], $this->texts($x, '//nav[@class="pager"]//span[@class="button pager-link"][@aria-disabled="true"]'));
        self::assertSame(0, $x->query('//nav[@class="pager"]//a')->length);

        self::assertSame(0, $x->query('//div[contains(@class, "filter-bar")] | //div[@class="empty-state"] | //*[@id="find-error"]')->length);
        $this->assertOrder($x, ['//div[@class="page-head"]', '//form[@class="list-controls"]', '//div[@class="table-wrap"]', '//nav[@class="pager"]']);
    }

    public function testStateIsCarriedInEveryLink(): void
    {
        $sort = $this->f['Value'] . '.desc';
        $html = $this->page(self::MEMBER, $this->t('?q=%20mixer%20&sort=' . $sort . '&page=1&other=x'), true);
        $x = $this->xpath($html);
        $state = '?q=mixer&sort=' . rawurlencode($sort);

        self::assertSame('1 record matches out of 3, sorted by Value, highest first', HtmlAssertions::text($html, '//p[@class="lead"]'));
        self::assertSame('mixer', $this->attr($x, '//input[@id="find"]', 'value'));
        self::assertSame(['Value, highest first'], $this->texts($x, '//select[@id="sort"]/option[@selected]'));
        self::assertSame($this->t('/records/new' . $state), $this->attr($x, '//div[@class="page-actions"]/a', 'href'));
        self::assertSame($this->t('/filter' . $state), $this->attr($x, '//form[@class="list-controls"]/a', 'href'));
        self::assertSame($this->t('/records/' . $this->r['Stand mixer'] . $state), $this->attr($x, '//tbody/tr/th/a', 'href'));
        self::assertSame('Value (highest first)', HtmlAssertions::text($html, '//thead/tr/th[@aria-sort="descending"]'));
        self::assertSame('Name', HtmlAssertions::text($html, '//thead/tr/th[1]'));
        self::assertSame(0, $x->query('//form[@class="list-controls"]/input[@type="hidden"]')->length, 'q and sort are controls; page is never carried.');
    }

    public function testSortsByAddedAndChangedUseTheShortWordsInTheLead(): void
    {
        $html = $this->page(self::ADMIN, $this->t('?sort=added.desc'));
        $x = $this->xpath($html);
        self::assertSame('3 records, sorted by newest added first', HtmlAssertions::text($html, '//p[@class="lead"]'));
        self::assertSame(['Stand mixer', 'Dutch oven', 'Cordless drill'], array_reverse($this->texts($x, '//tbody/tr/th/a')));
        self::assertSame(0, $x->query('//thead//th[@aria-sort]')->length);

        $html = $this->page(self::ADMIN, $this->t('?sort=nonsense'));
        self::assertSame('3 records, sorted by Name, A to Z', HtmlAssertions::text($html, '//p[@class="lead"]'), 'An unknown sort is the default.');
    }

    public function testPaging(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->core->insertRecord($this->project, $this->items, [$this->f['Name'] => sprintf('Example Item %02d', $i)]);
        }
        $html = $this->page(self::ADMIN, $this->t('?q=Example'));
        $x = $this->xpath($html);
        self::assertSame('Showing 1 to 50 of 52', HtmlAssertions::text($html, '//span[@class="pager-status"]'));
        self::assertSame('Items, 1 to 50 of 52 shown', HtmlAssertions::text($html, '//caption'));
        self::assertSame(['Previous'], $this->texts($x, '//nav[@class="pager"]//span[@aria-disabled="true"]'));
        self::assertSame($this->t('?page=2&q=Example'), $this->attr($x, '//nav[@class="pager"]//a[@rel="next"]', 'href'));
        self::assertSame(50, $x->query('//tbody/tr')->length);

        $html = $this->page(self::ADMIN, $this->t('?q=Example&page=99'));
        $x = $this->xpath($html);
        self::assertSame('Showing 51 to 52 of 52', HtmlAssertions::text($html, '//span[@class="pager-status"]'));
        self::assertSame(['Next'], $this->texts($x, '//nav[@class="pager"]//span[@aria-disabled="true"]'));
        self::assertSame($this->t('?page=1&q=Example'), $this->attr($x, '//nav[@class="pager"]//a[@rel="prev"]', 'href'));
        self::assertSame($this->t('/records/' . ($this->r['Cordless drill'] + 52) . '?q=Example&page=2'), $this->attr($x, '//tbody/tr[last()]/th/a', 'href'));
    }

    // Filter bar (item 4).

    public function testActiveFilterBarAndConditions(): void
    {
        $untitled = $this->core->insertRecord($this->project, $this->rooms);
        $this->ops->records()->setLinks($this->admin(), $this->project, $this->items, (string) $this->r['Dutch oven'], $this->f['Room'], [$untitled]);
        $cases = [
            ['filter' => $this->f['Category'], 'op' => 'is', 'v' => 'Appliance', 'Category is Appliance', 1],
            ['filter' => $this->f['Category'], 'op' => 'empty', 'Category is empty', 0],
            ['filter' => $this->f['Under warranty'], 'op' => 'is', 'v' => '1', 'Under warranty is ticked', 1],
            ['filter' => $this->f['Under warranty'], 'op' => 'is', 'v' => '0', 'Under warranty is not ticked', 2],
            ['filter' => $this->f['Room'], 'op' => 'links-to', 'v' => (string) $this->r['Kitchen'], 'Room is Kitchen', 1],
            ['filter' => $this->f['Room'], 'op' => 'links-to', 'v' => (string) $untitled, 'Room is Room ' . $untitled, 1],
            ['filter' => $this->f['Room'], 'op' => 'empty', 'Room is empty', 1],
            ['filter' => $this->f['Purchased'], 'op' => 'between', 'v' => '2023-01-01', 'v2' => '2025-12-31', 'Purchased is from 1 Jan 2023 to 31 Dec 2025', 2],
            ['filter' => $this->f['Purchased'], 'op' => 'between', 'v' => '2023-01-01', 'Purchased is on or after 1 Jan 2023', 2],
            ['filter' => $this->f['Purchased'], 'op' => 'between', 'v2' => '2023-01-01', 'Purchased is on or before 1 Jan 2023', 1],
        ];
        foreach ($cases as $case) {
            $count = array_pop($case);
            $text = array_pop($case);
            $query = ['q' => 'e'] + $case;
            $html = $this->page(self::ADMIN, $this->t('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
            $x = $this->xpath($html);
            self::assertSame('Showing only records where ' . $text . '.', HtmlAssertions::text($html, '//div[@class="notice filter-bar"]/p'), $text);
            self::assertSame($this->t('?q=e'), $this->attr($x, '//div[@class="notice filter-bar"]/a', 'href'), $text);
            self::assertSame('Remove the filter', HtmlAssertions::text($html, '//div[@class="notice filter-bar"]/a'));
            $hidden = [];
            foreach ($x->query('//form[@class="list-controls"]/input[@type="hidden"]') as $input) {
                $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            self::assertSame(array_diff_key($case, ['q' => true]), $hidden, $text);
            self::assertSame($count, $x->query('//tbody/tr')->length, $text);
            self::assertStringStartsWith($count . ' record', HtmlAssertions::text($html, '//p[@class="lead"]'));
            $this->assertOrder($x, ['//form[@class="list-controls"]', '//div[@class="notice filter-bar"]']);
        }
    }

    public function testMarkupInFilterValuesAndPickListIsEscaped(): void
    {
        $label = 'Oak "dark" <b>&amp;</b>';
        $finish = $this->addField('Finish', 'choice', ['choices' => [$label, 'Plain']]);
        $this->add(['Name' => 'Example shelf', 'Finish' => $label]);
        $room = 'Den <i>"x"</i> & co';
        $roomId = $this->core->addRecord($this->project, $this->rooms, [$this->f['Room name'] => $room]);

        // The relation pick list on filter step 2 (option labels are record titles).
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Room']));
        self::assertStringNotContainsString('<i>', $html);
        $x = $this->xpath($html);
        self::assertSame($room, HtmlAssertions::text($html, '//select[@id="filter-value"]/option[@value="' . $roomId . '"]'));

        // The active filter's value in the list controls' hidden inputs.
        $html = $this->page(self::ADMIN, $this->t('?' . http_build_query(['filter' => $finish, 'op' => 'is', 'v' => $label], '', '&', PHP_QUERY_RFC3986)));
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('"dark"', $html);
        $x = $this->xpath($html);
        self::assertSame($label, $this->attr($x, '//form[@class="list-controls"]/input[@type="hidden"][@name="v"]', 'value'));
        self::assertSame(['Example shelf'], $this->texts($x, '//tbody/tr/th/a'));
    }

    public function testFilterProblemTextIsEscaped(): void
    {
        // Problem texts are fixed strings today; a strings file whose text
        // holds markup checks that the list escapes the text at output.
        $temp = TempDir::create();
        try {
            $root = $temp->path();
            self::assertTrue(symlink(Fixtures::appRoot() . '/templates', $root . '/templates'));
            self::assertTrue(mkdir($root . '/strings'));
            $real = var_export(Fixtures::appRoot() . '/strings/en.php', true);
            file_put_contents($root . '/strings/en.php', "<?php\nreturn ['problem.filter.invalid' => 'Use <b>\"this\"</b> & not that.'] + (require {$real});\n");
            $kernel = new Kernel(Fixtures::config(false), $root, Fixtures::applicationRoutes(), $this->services);
            $response = Fixtures::handle($kernel, Fixtures::fullRequest('GET', $this->t('?filter=nosuchfield&op=is&v=x'), [], ['tdb_session' => self::ADMIN]))[0];
            self::assertSame(200, $response->status);
            self::assertStringNotContainsString('<b>', $response->body);
            self::assertStringContainsString('Use &lt;b&gt;&quot;this&quot;&lt;/b&gt; &amp; not that.', $response->body);
            $x = $this->xpath($response->body);
            self::assertSame(['The filter could not be used, so every record is shown.', 'Use <b>"this"</b> & not that.'], $this->texts($x, '//div[@class="notice notice-warn"]/p'));
        } finally {
            $temp->remove();
        }
    }

    public function testAFilterTheCoreRefusedIsReportedAndIgnored(): void
    {
        $cases = [
            'filter=nosuchfield&op=is&v=x' => 'That filter cannot be used with this field.',
            'filter=' . $this->f['Name'] . '&op=is&v=x' => 'That filter cannot be used with this field.',
            'filter=' . $this->f['Purchased'] . '&op=between&v=2025-01-01&v2=2024-01-01' => 'The start of the range must be on or before the end.',
            'filter=' . $this->f['Purchased'] . '&op=between' => 'Choose a value for the filter.',
            'filter=' . $this->f['Category'] . '&op=is&v=Nonsense' => 'Choose a value for the filter.',
        ];
        foreach ($cases as $query => $problem) {
            $html = $this->page(self::ADMIN, $this->t('?' . $query));
            $x = $this->xpath($html);
            self::assertSame(['The filter could not be used, so every record is shown.', $problem], $this->texts($x, '//div[@class="notice notice-warn"]/p'), $query);
            self::assertSame(3, $x->query('//tbody/tr')->length, 'Unfiltered.');
            self::assertSame(0, $x->query('//div[contains(@class, "filter-bar")]')->length);
            self::assertSame(0, $x->query('//form[@class="list-controls"]/input[@type="hidden"]')->length);
            self::assertSame('3 records, sorted by Name, A to Z', HtmlAssertions::text($html, '//p[@class="lead"]'));
        }
    }

    // Refused search (item 5).

    public function testARefusedSearchIsShownAtTheFindBox(): void
    {
        $long = str_repeat('a', 201);
        $html = $this->page(self::MEMBER, $this->t('?q=' . $long), true);
        $x = $this->xpath($html);
        self::assertSame($long, $this->attr($x, '//input[@id="find"]', 'value'));
        self::assertSame('find-error', $this->attr($x, '//input[@id="find"]', 'aria-describedby'));
        self::assertSame('true', $this->attr($x, '//input[@id="find"]', 'aria-invalid'));
        self::assertSame('Use at most 200 characters.', HtmlAssertions::text($html, '//p[@class="error-text"][@id="find-error"]'));
        self::assertSame(1, $x->query('//input[@id="find"]/following-sibling::p[@id="find-error"]')->length);
        self::assertSame(3, $x->query('//tbody/tr')->length, 'Unsearched.');
        self::assertSame('3 records, sorted by Name, A to Z', HtmlAssertions::text($html, '//p[@class="lead"]'));
        self::assertSame($this->t('/records/' . $this->r['Cordless drill']), $this->attr($x, '//tbody/tr[1]/th/a', 'href'), 'The refused text is not carried.');

        $html = $this->page(self::MEMBER, $this->t('?q=' . rawurlencode("bad\x07text")), true);
        self::assertSame('Remove the special characters, such as tabs or invisible characters.', HtmlAssertions::text($html, '//p[@id="find-error"]'));
    }

    // Empty states (section 2.1).

    public function testNoRecordsYet(): void
    {
        foreach ([$this->r['Stand mixer'], $this->r['Dutch oven'], $this->r['Cordless drill']] as $id) {
            $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $id);
        }
        $html = $this->page(self::ADMIN, $this->t('?q=mixer'));
        $x = $this->xpath($html);
        self::assertSame(['No records yet', 'Select Add item to add the first one.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame(0, $x->query('//div[@class="empty-state"]/a')->length);
        self::assertSame(1, $x->query('//div[@class="page-actions"]/a')->length);
        self::assertSame(0, $x->query('//form[@class="list-controls"] | //table | //nav[@class="pager"]')->length);
        self::assertSame('3', HtmlAssertions::text($html, '//div[@class="sidebar-item"]/span[@class="sidebar-count"]'), 'Trash count.');
        self::assertSame('Items 0', HtmlAssertions::text($html, '//nav[@class="sidebar"]//a[@aria-current="page"]'));
    }

    public function testTableWithoutFields(): void
    {
        $notes = $this->ops->schema()->createTable($this->admin(), $this->project, 'Notes', 'note', false)->key;
        $this->share();

        $html = $this->page(self::ADMIN, $this->p('/tables/' . $notes));
        $x = $this->xpath($html);
        self::assertSame(['This table has no fields yet', 'Add fields to say what each record holds.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame('Add a field', HtmlAssertions::text($html, '//div[@class="empty-state"]/a'));
        self::assertSame($this->p('/tables/' . $notes . '/fields/new'), $this->attr($x, '//div[@class="empty-state"]/a', 'href'));
        self::assertSame(0, $x->query('//div[@class="page-actions"] | //form[@class="list-controls"] | //table | //nav[@class="pager"]')->length);

        $html = $this->page(self::MEMBER, $this->p('/tables/' . $notes));
        $x = $this->xpath($html);
        self::assertSame(['This table has no fields yet', 'An Admin needs to add fields before records can be added.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame(0, $x->query('//div[@class="empty-state"]/a | //div[@class="page-actions"]')->length);
    }

    public function testNothingMatches(): void
    {
        $sort = 'sort=added.desc';
        $filter = 'filter=' . $this->f['Category'] . '&op=is&v=Cookware';
        $cases = [
            '?q=zzz&' . $sort => 'No records match “zzz”.',
            '?' . $filter . '&q=mixer&' . $sort => 'No records match “mixer” with this filter.',
            '?filter=' . $this->f['Under warranty'] . '&op=is&v=1&q=oven&' . $sort => 'No records match “oven” with this filter.',
            '?filter=' . $this->f['Category'] . '&op=empty&' . $sort => 'No records match the filter.',
        ];
        foreach ($cases as $query => $body) {
            $html = $this->page(self::ADMIN, $this->t($query));
            $x = $this->xpath($html);
            self::assertSame(['Nothing matches', $body], $this->texts($x, '//div[@class="empty-state"]/p'), $query);
            self::assertSame('Show every record', HtmlAssertions::text($html, '//div[@class="empty-state"]/a'));
            self::assertSame($this->t('?sort=added.desc'), $this->attr($x, '//div[@class="empty-state"]/a', 'href'));
            self::assertSame(1, $x->query('//form[@class="list-controls"]')->length, 'Controls stay.');
            self::assertSame(0, $x->query('//table | //nav[@class="pager"]')->length);
            self::assertStringStartsWith('0 records match out of 3', HtmlAssertions::text($html, '//p[@class="lead"]'));
        }
    }

    public function testTableWithoutATitleField(): void
    {
        $notes = $this->ops->schema()->createTable($this->admin(), $this->project, 'Notes', 'note', false)->key;
        $body = $this->ops->schema()->addField($this->admin(), $this->project, $notes, 'Body', 'text', [], false, '')->key;
        $id = $this->core->addRecord($this->project, $notes, [$body => 'Example note']);

        $html = $this->page(self::ADMIN, $this->p('/tables/' . $notes));
        $x = $this->xpath($html);
        self::assertSame(['Note', 'Body'], $this->texts($x, '//thead/tr/th'));
        self::assertSame(['Note ' . $id], $this->texts($x, '//tbody/tr/th/a'));
        self::assertSame(['Example note'], $this->texts($x, '//tbody/tr/td'));
        self::assertSame('1 record, sorted by oldest added first', HtmlAssertions::text($html, '//p[@class="lead"]'));
    }

    public function testUntitledRecordsAndLinksReadItemAndId(): void
    {
        $id = $this->add(['Category' => 'Tool']);
        $room = $this->core->insertRecord($this->project, $this->rooms);
        $this->ops->records()->setLinks($this->admin(), $this->project, $this->items, (string) $id, $this->f['Room'], [$room]);

        $html = $this->page(self::ADMIN, $this->t(''));
        $x = $this->xpath($html);
        self::assertSame('Item ' . $id, HtmlAssertions::text($html, '//tbody/tr[last()]/th/a'));
        self::assertSame('Room ' . $room, HtmlAssertions::text($html, '//tbody/tr[last()]/td[1]/a'));
    }

    public function testStoredTextWithMarkupIsEscaped(): void
    {
        $evil = '<script>alert("x")</script> & {name}';
        $this->ops->schema()->updateTable($this->admin(), $this->project, $this->items, $evil, 'item', $this->f['Name']);
        $field = $this->addField('<b>Bold</b>', 'text');
        $this->add(['Name' => $evil, '<b>Bold</b>' => '<img src=x onerror=alert(1)>']);

        $html = $this->page(self::ADMIN, $this->t('?q=' . rawurlencode('<script>')));
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<b>Bold', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; {name}', $html);
        $x = $this->xpath($html);
        self::assertSame($evil, HtmlAssertions::text($html, '//main//h1'));
        self::assertSame($evil, HtmlAssertions::text($html, '//tbody/tr/th/a'));
        self::assertSame('<script>', $this->attr($x, '//input[@id="find"]', 'value'));
        self::assertContains('<b>Bold</b>, A to Z', $this->texts($x, '//select[@id="sort"]/option'));
        self::assertSame($field, $this->f['<b>Bold</b>']);
    }

    public function testAccess(): void
    {
        self::assertSame(404, $this->request(self::MEMBER, $this->t(''))->status, 'Not shared: not found.');
        self::assertSame(404, $this->request(self::MEMBER, $this->t('/filter'))->status);
        self::assertSame(404, $this->request(self::ADMIN, $this->p('/tables/aaaaaaaaaaaa'))->status);
        self::assertSame(404, $this->request(self::ADMIN, $this->p('/tables/aaaaaaaaaaaa/filter'))->status);
        self::assertSame(404, $this->request(self::ADMIN, '/projects/aaaaaaaaaaaa/tables/' . $this->items)->status);
        $this->share();
        $html = $this->page(self::MEMBER, $this->t(''));
        self::assertSame('Add item', HtmlAssertions::text($html, '//div[@class="page-actions"]/a'));
    }

    public function testProjectOpenRedirectsToTheFirstTable(): void
    {
        $response = $this->request(self::ADMIN, $this->p(''));
        self::assertSame(303, $response->status);
        self::assertSame($this->t(''), $response->header('Location'));
    }

    // Filter page (section 3).

    public function testFilterPageWithoutFilterableFields(): void
    {
        $notes = $this->ops->schema()->createTable($this->admin(), $this->project, 'Notes', 'note', true)->key;
        $html = $this->page(self::ADMIN, $this->p('/tables/' . $notes . '/filter?q=x'));
        $x = $this->xpath($html);
        self::assertSame('Filter Notes - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Notes', 'Filter'], $this->crumbs($x));
        self::assertSame('Filter Notes', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('Show only the records that match one condition.', HtmlAssertions::text($html, '//main/div/p[@class="lead"]'));
        self::assertSame('Filters work on choice, checkbox, link, date, and date-and-time fields. This table has none yet.', HtmlAssertions::text($html, '//p[@class="notice"]'));
        self::assertSame('Back to the list', HtmlAssertions::text($html, '//main/a'));
        self::assertSame($this->p('/tables/' . $notes . '?q=x'), $this->attr($x, '//main/a', 'href'));
        self::assertSame(0, $x->query('//main//form')->length);
    }

    public function testFilterStepOne(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/filter?q=mixer&sort=added.desc&filter=' . $this->f['Category'] . '&op=is&v=Tool&page=2'));
        $x = $this->xpath($html);
        self::assertSame(1, $x->query('//main[@id="main"][@class="page"]')->length, 'No sidebar.');
        self::assertSame(0, $x->query('//nav[@class="sidebar"]')->length);
        self::assertSame(1, $x->query('//form[@class="top-search"]')->length);
        self::assertSame(['Home Inventory', 'Items', 'Filter'], $this->crumbs($x));
        self::assertSame('/projects/' . $this->project, $this->attr($x, '//nav[@class="breadcrumb"]/a[1]', 'href'));
        $state = '?q=mixer&sort=added.desc&filter=' . $this->f['Category'] . '&op=is&v=Tool';
        self::assertSame($this->t($state), $this->attr($x, '//nav[@class="breadcrumb"]/a[2]', 'href'), 'The breadcrumb carries the list state.');

        $form = '//form[@class="form form-card form-wide"]';
        self::assertSame(['get', $this->t('/filter')], [$this->attr($x, $form, 'method'), $this->attr($x, $form, 'action')]);
        $hidden = [];
        foreach ($x->query($form . '/input[@type="hidden"]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertSame(['q' => 'mixer', 'sort' => 'added.desc', 'filter' => $this->f['Category'], 'op' => 'is', 'v' => 'Tool'], $hidden);
        self::assertSame('Field', HtmlAssertions::text($html, $form . '//label[@for="filter-field"]'));
        self::assertSame('field', $this->attr($x, '//select[@id="filter-field"]', 'name'));
        self::assertSame(['Room (Link to another table)', 'Category (Choice)', 'Purchased (Date)', 'Under warranty (Checkbox)'], $this->texts($x, '//select[@id="filter-field"]/option'));
        self::assertSame(['Category (Choice)'], $this->texts($x, '//select[@id="filter-field"]/option[@selected]'));
        self::assertSame(['Next'], $this->texts($x, $form . '//button[@class="button button-large button-primary"]'));
        self::assertSame($this->t($state), $this->attr($x, $form . '//a[@class="form-cancel"]', 'href'), 'Cancel carries the list state.');
    }

    public function testFilterStepTwoChoice(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Category'] . '&q=e'));
        $x = $this->xpath($html);
        $form = '//form[@class="form form-card form-wide"]';
        self::assertSame(['get', $this->t('')], [$this->attr($x, $form, 'method'), $this->attr($x, $form, 'action')]);
        self::assertSame(['q' => 'e', 'filter' => $this->f['Category']], $this->hidden($x, $form));
        self::assertSame('Show records where Category', HtmlAssertions::text($html, $form . '/fieldset/legend[@class="legend-plain"]'));
        self::assertSame(['is', 'Choice', 'is empty'], $this->texts($x, $form . '/fieldset//label'));
        self::assertSame(['is', 'empty'], $this->values($x, $form . '//input[@name="op"]'));
        self::assertSame(['is'], $this->values($x, $form . '//input[@name="op"][@checked]'));
        self::assertSame('filter-op-is', $this->attr($x, $form . '//input[@value="is"]', 'id'));
        self::assertSame(['Appliance', 'Cookware', 'Tool'], $this->texts($x, '//select[@id="filter-value"][@name="v"]/option'));
        self::assertSame(['Show matching records'], $this->texts($x, $form . '//button'));
        self::assertSame($this->t('?q=e'), $this->attr($x, $form . '//a[@class="form-cancel"]', 'href'));
        self::assertSame('Choose another field', HtmlAssertions::text($html, '//main/a[last()]'));
        self::assertSame($this->t('/filter?q=e'), $this->attr($x, '//main/a[last()]', 'href'));

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Category'] . '&filter=' . $this->f['Category'] . '&op=is&v=Cookware'));
        $x = $this->xpath($html);
        self::assertSame(['Cookware'], $this->texts($x, '//select[@id="filter-value"]/option[@selected]'));
        self::assertSame(['is'], $this->values($x, '//input[@name="op"][@checked]'));

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Category'] . '&filter=' . $this->f['Category'] . '&op=empty'));
        self::assertSame(['empty'], $this->values($this->xpath($html), '//input[@name="op"][@checked]'));
    }

    public function testFilterStepTwoCheckbox(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Under warranty']));
        $x = $this->xpath($html);
        $form = '//form[@class="form form-card form-wide"]';
        self::assertSame(['filter' => $this->f['Under warranty'], 'op' => 'is'], $this->hidden($x, $form));
        self::assertSame(['is ticked', 'is not ticked'], $this->texts($x, $form . '/fieldset/label[@class="check"]'));
        self::assertSame(['1', '0'], $this->values($x, $form . '//input[@type="radio"][@name="v"]'));
        self::assertSame(['1'], $this->values($x, $form . '//input[@name="v"][@checked]'));

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Under warranty'] . '&filter=' . $this->f['Under warranty'] . '&op=is&v=0'));
        self::assertSame(['0'], $this->values($this->xpath($html), '//input[@name="v"][@checked]'));
    }

    public function testFilterStepTwoRelation(): void
    {
        $untitled = $this->core->insertRecord($this->project, $this->rooms);
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Room']));
        $x = $this->xpath($html);
        $form = '//form[@class="form form-card form-wide"]';
        self::assertSame(['links to', 'Record', 'has no link'], $this->texts($x, $form . '/fieldset//label'));
        self::assertSame(['links-to', 'empty'], $this->values($x, $form . '//input[@name="op"]'));
        self::assertSame(['links-to'], $this->values($x, $form . '//input[@name="op"][@checked]'));
        self::assertSame(['Kitchen', 'Room ' . $untitled], $this->texts($x, '//select[@id="filter-value"]/option'));
        self::assertSame([(string) $this->r['Kitchen'], (string) $untitled], $this->values($x, '//select[@id="filter-value"]/option'));
        self::assertSame(0, $x->query('//p[@class="help"]')->length);

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Room'] . '&filter=' . $this->f['Room'] . '&op=links-to&v=' . $untitled));
        self::assertSame([(string) $untitled], $this->values($this->xpath($html), '//select[@id="filter-value"]/option[@selected]'));
    }

    public function testFilterStepTwoRelationListsAtMost500(): void
    {
        for ($i = 0; $i < 501; $i++) {
            $this->core->insertRecord($this->project, $this->rooms, [$this->f['Room name'] => sprintf('Example Room %03d', $i)]);
        }
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Room']));
        $x = $this->xpath($html);
        self::assertSame(500, $x->query('//select[@id="filter-value"]/option')->length);
        self::assertSame('Only the first 500 are listed.', HtmlAssertions::text($html, '//select[@id="filter-value"]/following-sibling::p[@class="help"]'));
    }

    public function testFilterStepTwoDates(): void
    {
        $when = $this->addField('Delivered', 'date_time');
        foreach ([$this->f['Purchased'], $when] as $field) {
            $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $field));
            $x = $this->xpath($html);
            $form = '//form[@class="form form-card form-wide"]';
            self::assertSame(['is in this range', 'From', 'To', 'is empty'], $this->texts($x, $form . '/fieldset//label'));
            self::assertSame(['between'], $this->values($x, $form . '//input[@name="op"][@checked]'));
            self::assertSame(['date', 'v', 'input input-short'], [$this->attr($x, '//input[@id="filter-from"]', 'type'), $this->attr($x, '//input[@id="filter-from"]', 'name'), $this->attr($x, '//input[@id="filter-from"]', 'class')]);
            self::assertSame(['date', 'v2'], [$this->attr($x, '//input[@id="filter-to"]', 'type'), $this->attr($x, '//input[@id="filter-to"]', 'name')]);
            self::assertSame('Leave one empty for an open range.', HtmlAssertions::text($html, $form . '//p[@class="help"]'));
        }

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $when . '&filter=' . $when . '&op=between&v=2024-01-01&v2=2024-02-01'));
        $x = $this->xpath($html);
        self::assertSame(['2024-01-01', '2024-02-01'], [$this->attr($x, '//input[@id="filter-from"]', 'value'), $this->attr($x, '//input[@id="filter-to"]', 'value')]);

        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Category'] . '&filter=' . $this->f['Purchased'] . '&op=between&v=2024-01-01'));
        self::assertSame([], $this->values($this->xpath($html), '//select[@id="filter-value"]/option[@selected]'), 'An active filter on another field preselects nothing.');
    }

    public function testFilterFieldThatIsNotFilterableShowsStepOne(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/filter?field=' . $this->f['Name']));
        self::assertSame(1, $this->xpath($html)->query('//select[@id="filter-field"]')->length);
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

    private function share(): void
    {
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    /** @param array<string, mixed> $options */
    private function addField(string $name, string $type, array $options = []): string
    {
        $key = $this->ops->schema()->addField($this->admin(), $this->project, $this->items, $name, $type, $options, false, '')->key;
        $this->f[$name] = $key;

        return $key;
    }

    /** @param array<string, string> $text field name => form text */
    private function add(array $text): int
    {
        $input = [];
        foreach ($text as $name => $value) {
            $input[$this->f[$name]] = $value;
        }

        return $this->core->addRecord($this->project, $this->items, $input);
    }

    /** Attaches one synthetic file row (no file on disk) to the Receipt field of a record. */
    private function attach(int $recordId): void
    {
        $db = $this->core->projectDb($this->project);
        $db->run('INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, 1, ?, ?, ?)', [str_repeat('a', 64), 'application/pdf', 'example.pdf', '2026-11-01T00:00:00Z']);
        $db->run('INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, 1)', [$this->f['Receipt'], $recordId, $db->lastInsertId()]);
    }

    private function p(string $path): string
    {
        return '/projects/' . $this->project . $path;
    }

    private function t(string $path): string
    {
        return $this->p('/tables/' . $this->items . $path);
    }

    private function request(string $token, string $uri): Response
    {
        return Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => $token]))[0];
    }

    private function page(string $token, string $uri, bool $share = false): string
    {
        if ($share) {
            $this->share();
        }
        $response = $this->request($token, $uri);
        self::assertSame(200, $response->status, $uri);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);

        return $response->body;
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $x->query($query)->item(0);

        return $node instanceof \DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
    }

    /** @return array<string, string> */
    private function hidden(DOMXPath $x, string $form): array
    {
        $hidden = [];
        foreach ($x->query($form . '/input[@type="hidden"]') as $input) {
            \assert($input instanceof \DOMElement);
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $hidden;
    }

    /** @return list<string> */
    private function values(DOMXPath $x, string $query): array
    {
        $values = [];
        foreach ($x->query($query) as $node) {
            \assert($node instanceof \DOMElement);
            $values[] = $node->getAttribute('value');
        }

        return $values;
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

    /** @param list<string> $queries elements expected in this document order */
    private function assertOrder(DOMXPath $x, array $queries): void
    {
        $positions = [];
        $all = iterator_to_array($x->query('//*'));
        foreach ($queries as $query) {
            $node = $x->query($query)->item(0);
            self::assertNotNull($node, $query);
            $positions[] = array_search($node, $all, true);
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Reading order.');
    }
}
