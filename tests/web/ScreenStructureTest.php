<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FieldController;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';

/**
 * T-SCR-1 (M1 spec part 2 section 9) and T-SCR-2 (M2 spec part 2
 * section 12, testEveryM2ScreenInEveryState): every GET route of the real route
 * table, in each state its design document under docs/design/m1/ lists
 * that a GET can reach, served through the kernel with the real
 * CoreServices over a temporary installation built through the screens.
 * Each page is well-formed, has no inline code, passes the shell
 * assertions and assertForm, and has the expected title and `h1` text.
 * States reached only by a POST (validation errors, step 2 of the
 * installer) are covered by each screen package's own tests.
 *
 * Installer step 1 with a failing check (installer.md section 6) is reached
 * through the real services too: the RequirementEnvironment seam of
 * Installer::requirements() is not reachable through CoreServices (the
 * controller passes none), so the test configures data_dir under a folder
 * that does not exist, which fails the real "A place for the data folder"
 * check while every other check runs against this server.
 * Synthetic data only.
 */
#[CoversNothing]
final class ScreenStructureTest extends TestCase
{
    private const PASSWORD = 'example admin passphrase 1';
    private const MEMBER_TEMPORARY = 'temporary words 0101';
    private const MEMBER_PASSWORD = 'example member passphrase 2';

    /** The type labels of strings/en/types.php, in core order (schema-editor.md 7.2: "New field: {type}"). */
    private const TYPE_LABELS = [
        'text' => 'Text',
        'long_text' => 'Long text',
        'number' => 'Number',
        'currency' => 'Currency',
        'date' => 'Date',
        'time' => 'Time',
        'date_time' => 'Date and time',
        'duration' => 'Duration',
        'checkbox' => 'Checkbox',
        'choice' => 'Choice',
        'rating' => 'Rating',
        'url' => 'Web address',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'attachment' => 'File or image',
        'relation' => 'Link to another table',
        'auto_number' => 'Auto-number',
    ];

    private TempDir $temp;
    private Kernel $kernel;

    /** @var list<string> Every GET route pattern a case has visited. */
    private array $visited = [];

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-screens-');
        $config = Fixtures::config(false) + ['data_dir' => $this->temp->path()];
        $services = new CoreServices(
            $config,
            Fixtures::appRoot(),
            new FixedClock('2026-11-03T10:00:00Z'),
            new SeededRandom('tiffindb-screen-structure-seed'),
            new PasswordHasher(['bcrypt' => 4]),
        );
        $this->kernel = new Kernel($config, Fixtures::appRoot(), null, $services);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testInstallerStepOneWithAFailingCheck(): void
    {
        $missing = $this->temp->path('missing/data');
        $config = Fixtures::config(false) + ['data_dir' => $missing];
        $kernel = new Kernel($config, Fixtures::appRoot(), null, new CoreServices(
            $config,
            Fixtures::appRoot(),
            new FixedClock('2026-11-03T10:00:00Z'),
            new SeededRandom('tiffindb-screen-structure-seed'),
            new PasswordHasher(['bcrypt' => 4]),
        ));
        $client = new EndToEndClient($kernel);

        $this->screen($client, '/setup', 'Set up TiffinDB', 'Set up TiffinDB');
        $html = $client->page();
        self::assertSame(
            'Needs fixing',
            HtmlAssertions::text($html, '//main//tbody/tr[th[normalize-space(.)="A place for the data folder"]]/td[1]'),
        );
        self::assertSame(0, $client->xpath()->query('//form[@action="/setup/data-folder"]')->length, 'No way on while a check fails.');
        self::assertSame(['Check again'], $client->texts('//main//a[@href="/setup"]'));
        self::assertStringStartsWith('Fix the checks marked Needs fixing', HtmlAssertions::text($html, '//main//*[@role="alert"]'));
        self::assertDirectoryDoesNotExist($this->temp->path('missing'), 'The check writes nothing.');
    }

    public function testEveryScreenInEveryState(): void
    {
        $admin = new EndToEndClient($this->kernel);

        // Installer (installer.md section 6): step 1 passing, then step 3.
        $this->screen($admin, '/setup', 'Set up TiffinDB', 'Set up TiffinDB');
        self::assertSame(303, $admin->submit('/setup/data-folder')->status);
        $this->screen($admin, '/setup', 'Set up TiffinDB', 'Set up TiffinDB');
        $code = trim((string) file_get_contents($this->temp->path('setup-code.txt')));
        self::assertSame(303, $admin->submit('/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::PASSWORD,
            'repeat' => self::PASSWORD,
        ])->status);
        $this->visit('/setup', 404, $admin);

        // Project list (sign-in-and-projects.md 3.2): after setup, then empty.
        $this->screen($admin, '/projects', 'Your projects', 'Your projects', 'TiffinDB is ready. Select New project to make your first project.');
        $this->screen($admin, '/projects', 'Your projects', 'Your projects');
        self::assertSame('There are no projects yet. Select New project to make the first one.', HtmlAssertions::text($admin->page(), '//main//p[@class="notice"]'));

        // Account, people, and new project with nothing else yet.
        $this->screen($admin, '/account/password', 'Change your password', 'Change your password');
        $this->screen($admin, '/admin/people', 'People', 'People');
        $this->screen($admin, '/admin/people/new', 'Add a person', 'Add a person');
        $adminKey = $this->personKey($admin, 'Example Admin');
        $this->screen($admin, '/admin/people/' . $adminKey, 'Example Admin', 'Example Admin');
        $this->screen($admin, '/projects/new', 'New project', 'New project');

        // A project without tables, and sharing with no Members.
        $recipes = $this->createProject($admin, 'Recipes', '');
        $this->screen($admin, $recipes . '/tables', 'Recipes', 'Recipes');
        self::assertSame('No tables yet', HtmlAssertions::text($admin->page(), '//main//p[@class="lead"]'));
        $this->screen($admin, $recipes . '/sharing', 'Share Recipes', 'Share Recipes');
        $this->screen($admin, $recipes . '/settings', 'Project settings', 'Project settings');

        // A Member with a temporary password and no projects.
        $admin->get('/admin/people/new');
        $added = $admin->submit('/admin/people', [
            'display_name' => 'Example Member',
            'username' => 'member',
            'role' => 'member',
            'temporary_password' => self::MEMBER_TEMPORARY,
        ]);
        self::assertSame(303, $added->status, $added->body);
        $memberKey = $this->personKey($admin, 'Example Member');
        $member = new EndToEndClient($this->kernel);
        $this->screen($member, '/sign-in', 'Sign in', 'Sign in');
        self::assertSame(303, $member->submit('/sign-in', ['username' => 'member', 'password' => self::MEMBER_TEMPORARY])->status);
        $this->screen(
            $member,
            '/account/password',
            'Change your password',
            'Change your password',
            'Before you go on, choose your own password. The one you were given was only for signing in the first time.',
        );
        self::assertSame(0, $member->xpath()->query('//main//a[normalize-space(.)="Cancel"]')->length, 'No Cancel when forced.');
        self::assertSame(303, $member->submit('/account/password', [
            'current' => self::MEMBER_TEMPORARY,
            'password' => self::MEMBER_PASSWORD,
            'repeat' => self::MEMBER_PASSWORD,
        ])->status);
        $this->screen($member, '/projects', 'Your projects', 'Your projects', 'Your password has been changed.');
        $this->screen($member, '/projects', 'Your projects', 'Your projects');
        self::assertSame(
            'No projects have been shared with you yet. Ask the person who looks after this TiffinDB to share one with you.',
            HtmlAssertions::text($member->page(), '//main//p[@class="notice"]'),
        );
        $this->screen($member, '/account/password', 'Change your password', 'Change your password');

        // Reading and display settings (docs/design/m2/settings.md): standard, then saved (the reset button uses formaction; SettingsScreenTest covers it).
        $this->screen($member, '/settings', 'Reading and display', 'Reading and display');
        self::assertSame(303, $member->submit('/settings', [
            'theme' => 'hc-dark',
            'accent' => 'plum',
            'typeface' => 'atkinson',
            'text_size' => 'largest',
            'line_spacing' => 'very-roomy',
        ])->status);
        $this->screen($member, '/settings', 'Reading and display', 'Reading and display', 'Your display settings have been saved.');
        self::assertSame('t-hcd f-atk s-largest l-very-roomy', HtmlAssertions::rootClass($member->page()));

        // Three projects, as in the mock-up; Home Inventory has every kind of field.
        $home = $this->createProject($admin, 'Home Inventory', 'Items');
        $garden = $this->createProject($admin, 'Garden Log', 'Plants');
        $admin->get($home . '/tables/new');
        self::assertSame(200, $admin->follow($admin->submit($home . '/tables', ['name' => 'Rooms', 'item_name' => 'room']))->status);
        $items = $this->tablePath($admin, $home, 'Items');
        $rooms = $this->tablePath($admin, $home, 'Rooms');
        $this->addField($admin, $items, 'relation', ['name' => 'Room', 'opt_target' => '']);
        $this->addField($admin, $items, 'choice', ['name' => 'Condition', 'opt_choices' => "Good\nWorn"]);
        $this->addField($admin, $items, 'currency', ['name' => 'Price', 'opt_code' => 'USD']);
        $this->addField($admin, $items, 'text', ['name' => 'Serial number']);
        $this->addField($admin, $items, 'number', ['name' => 'Quantity']);
        $this->addField($admin, $items, 'date', ['name' => 'Bought on']);

        // Sharing: Home Inventory and Recipes with the Member.
        $this->screen($admin, $home . '/sharing', 'Share Home Inventory', 'Share Home Inventory');
        self::assertSame(303, $admin->submit($home . '/sharing', ['members' => [$memberKey]])->status);
        $this->screen($admin, '/projects', 'Your projects', 'Your projects', 'Sharing for Home Inventory has been saved.');
        $admin->get($recipes . '/sharing');
        self::assertSame(303, $admin->submit($recipes . '/sharing', ['members' => [$memberKey]])->status);
        $this->screen($admin, '/projects', 'Your projects', 'Your projects', 'Sharing for Recipes has been saved.');
        self::assertSame(['Garden Log', 'Home Inventory', 'Recipes'], $admin->texts('//main//li[contains(@class, "project-card")]//h2'));

        // Member: projects, tables (with and without tables), no editing.
        $this->screen($member, '/projects', 'Your projects', 'Your projects');
        self::assertSame(['Home Inventory', 'Recipes'], $member->texts('//main//li[contains(@class, "project-card")]//h2'));
        $this->screen($member, $home . '/tables', 'Home Inventory', 'Home Inventory');
        $this->screen($member, $recipes . '/tables', 'Recipes', 'Recipes');
        $this->visit($items . '/fields', 403, $member);
        $this->visit($home . '/settings', 403, $member);
        $this->visit($home . '/sharing', 403, $member);
        $this->visit('/admin/people', 403, $member);
        $this->visit('/projects/new', 403, $member);

        // Schema editor (schema-editor.md sections 2 to 10).
        $this->visit($home, 303, $admin);
        $this->screen($admin, $home . '/tables', 'Home Inventory', 'Home Inventory');
        $this->screen($admin, $home . '/tables/new', 'New table', 'New table');
        $this->screen($admin, $items . '/fields', 'Items', 'Items');
        $this->screen($admin, $items, 'Items, Home Inventory', 'Items');
        $this->screen($admin, $items . '/filter', 'Filter Items', 'Filter Items');
        $this->screen($admin, $home . '/search?q=x', 'Search results, Home Inventory', 'Search results');
        $this->screen($admin, $rooms . '/fields', 'Rooms', 'Rooms');
        $this->screen($admin, $rooms . '/delete', 'Delete the table Rooms?', 'Delete the table Rooms?');
        $this->screen($admin, $items . '/fields/new', 'Add a field to Items', 'Add a field to Items');
        $this->screen($admin, $items . '/fields/new?type=unknown', 'Error: Add a field to Items', 'Add a field to Items', null, 422);
        self::assertSame('Choose what the field will hold.', HtmlAssertions::text($admin->page(), '//main//*[@class="error-text"]'));
        self::assertSame(array_keys(FieldController::KINDS), array_keys(self::TYPE_LABELS), 'Every type has a details page.');
        foreach (self::TYPE_LABELS as $type => $label) {
            $this->screen($admin, $items . '/fields/new?type=' . $type, "New field: {$label}", "New field: {$label}");
        }
        foreach (['Name', 'Room', 'Condition', 'Price', 'Serial number', 'Quantity', 'Bought on'] as $name) {
            $field = $this->fieldPath($admin, $items, $name);
            $this->screen($admin, $field, "Change the field {$name}", "Change the field {$name}");
            $this->screen($admin, $field . '/delete', "Delete the field {$name}?", "Delete the field {$name}?");
        }
        $serial = $this->fieldPath($admin, $items, 'Serial number');
        $this->screen($admin, $serial . '/type', 'Change the type of Serial number', 'Change the type of Serial number');
        $this->visit($this->fieldPath($admin, $items, 'Room') . '/type', 303, $admin);

        // Record screens (record.md sections 2 to 6), as the Member.
        $nameKey = substr($this->fieldPath($admin, $items, 'Name'), strlen($items . '/fields/'));
        $this->screen($member, $items . '/records/new', 'New item', 'New item');
        $added = $member->submit($items . '/records', ['f_' . $nameKey => 'Stand mixer']);
        self::assertSame(303, $added->status);
        $record = (string) parse_url((string) $added->header('Location'), PHP_URL_PATH);
        $this->screen($member, $record, 'Stand mixer', 'Stand mixer', 'The item has been added.');
        self::assertSame(303, $member->submit($record . '/trash')->status);
        $this->screen($member, $record, 'Stand mixer', 'This item is in the trash', 'Stand mixer has been moved to the trash. Undo');
        self::assertSame(303, $member->submit($record . '/restore')->status);
        $this->screen($member, $record, 'Stand mixer', 'Stand mixer', 'Stand mixer has been restored.');

        // People (people-and-sharing.md sections 2 to 5).
        $this->screen($admin, '/admin/people', 'People', 'People');
        $this->screen($admin, '/admin/people/new', 'Add a person', 'Add a person');
        $this->screen($admin, '/admin/people/' . $memberKey, 'Example Member', 'Example Member');
        $this->screen($admin, '/admin/people/' . $adminKey, 'Example Admin', 'Example Admin');
        $this->screen($admin, '/admin/people/' . $memberKey . '/deactivate', 'Stop Example Member from signing in?', 'Stop Example Member from signing in?');
        self::assertSame(303, $admin->submit('/admin/people/' . $memberKey . '/deactivate')->status);
        $this->screen($admin, '/admin/people/' . $memberKey, 'Example Member', 'Example Member', 'Example Member can no longer sign in.');
        $this->screen($admin, '/admin/people', 'People', 'People');

        // A project whose file is missing (sign-in-and-projects.md 3.1.3.4),
        // and an unknown project.
        $file = $this->temp->path('projects/' . substr($garden, strlen('/projects/')) . '/project.tiffin');
        self::assertFileExists($file);
        self::assertTrue(unlink($file));
        $this->screen($admin, '/projects', 'Your projects', 'Your projects');
        self::assertSame(
            "TiffinDB cannot open this project's file. The person who looks after this TiffinDB can check the data folder.",
            HtmlAssertions::text($admin->page(), '//main//li[.//h2[normalize-space(.)="Garden Log"]]//p[contains(@class, "notice-warn")]'),
        );
        self::assertSame(0, $admin->xpath()->query('//main//li[.//h2[normalize-space(.)="Garden Log"]]//h2/a')->length, 'No link to an unavailable project.');
        $this->visit($garden . '/tables', 503, $admin);
        $this->visit('/projects/aaaaaaaaaaaa/tables', 404, $admin);

        // Signing out, and the signed-out sign-in page.
        self::assertSame(303, $admin->submit('/sign-out')->status);
        $this->screen($admin, '/sign-in?signed-out=1', 'Sign in', 'Sign in', 'You have signed out.');
        $this->visit('/', 303, $admin);
        $this->visit('/projects', 303, $admin);

        // Every GET route was visited. Routes of controllers that do not
        // exist yet (M2 spec part 2 section 2: WP6 to WP8) are counted once
        // their controller lands.
        $patterns = [];
        foreach (Fixtures::applicationRoutes() as $route) {
            if (is_array($route) && $route[0] === 'GET' && class_exists($route[2][0])) {
                $patterns[] = (string) $route[1];
            }
        }
        sort($patterns);
        $visited = array_values(array_unique($this->visited));
        sort($visited);
        self::assertSame($patterns, $visited, 'Every GET route is visited.');
    }

    /**
     * T-SCR-2 (M2 spec part 2 section 12): every GET route M2 added, in each
     * state its design document under docs/design/m2/ lists that a GET can
     * reach, over the M2 synthetic data set (M2Fixture) with the real
     * CoreServices, as the Admin and as the Member. States reached only by
     * a POST response (422, the 409 conflict page) are covered by
     * KeyboardStructureTest and EndToEndTest.
     */
    public function testEveryM2ScreenInEveryState(): void
    {
        $core = new CoreFixture();
        try {
            $core->clock->set('2026-11-03T10:00:00Z');
            $ops = $core->operations();
            $m2 = M2Fixture::build($core, $ops);
            $actor = $core->actor('admin');
            $loans = $ops->schema()->createTable($actor, $m2->project, 'Loans', 'loan', true)->key;
            $blank = $ops->schema()->createTable($actor, $m2->project, 'Notes', 'note', false)->key;
            $config = Fixtures::config(false) + ['data_dir' => $core->temp->path('data'), 'timezone' => 'UTC'];
            $kernel = new Kernel($config, Fixtures::appRoot(), null, new CoreServices($config, Fixtures::appRoot(), $core->clock, new SeededRandom('tiffindb-screen-structure-m2-seed'), $core->hasher));
            $admin = $this->signIn($kernel, 'admin');
            $member = $this->signIn($kernel, 'member');

            $project = $m2->projectPath();
            $items = $m2->tablePath($m2->items);
            $f = $m2->itemFields;

            // /projects/{p} opens the first table.
            self::assertSame($items, $this->visit($project, 303, $member)->header('Location'));

            // List view (list-and-search.md section 2), Admin with the mock-up data, then each state.
            foreach ([$admin, $member] as $client) {
                $this->screen($client, $items, 'Items, Home Inventory', 'Items');
                self::assertSame('48 records, sorted by Name, A to Z', HtmlAssertions::text($client->page(), '//main//div[@class="page-head"]//p[@class="lead"]'));
            }
            self::assertCount(48, $admin->texts('//main//table/tbody/tr'));
            $this->screen($admin, $items . '?sort=' . $f['Purchased'] . '.desc', 'Items, Home Inventory', 'Items');
            self::assertSame('48 records, sorted by Purchased, newest first', HtmlAssertions::text($admin->page(), '//main//div[@class="page-head"]//p[@class="lead"]'));
            $this->screen($member, $items . '?q=machine', 'Items, Home Inventory', 'Items');
            self::assertSame('3 records match out of 48, sorted by Name, A to Z', HtmlAssertions::text($member->page(), '//main//div[@class="page-head"]//p[@class="lead"]'));
            $this->screen($member, $items . '?filter=' . $f['Under warranty'] . '&op=is&v=1', 'Items, Home Inventory', 'Items');
            self::assertSame('Showing only records where Under warranty is ticked. Remove the filter', HtmlAssertions::text($member->page(), '//main//div[contains(@class, "filter-bar")]'));
            self::assertCount(12, $member->texts('//main//table/tbody/tr'));
            $this->screen($member, $items . '?filter=' . $f['Purchased'] . '&op=between&v=&v2=', 'Items, Home Inventory', 'Items');
            self::assertStringStartsWith('The filter could not be used, so every record is shown.', HtmlAssertions::text($member->page(), '//main//div[contains(@class, "notice-warn")]'));
            $this->screen($member, $items . '?q=' . str_repeat('x', 201), 'Items, Home Inventory', 'Items');
            self::assertSame(1, $member->xpath()->query('//main//p[@id="find-error"][@class="error-text"]')->length);
            $this->screen($member, $items . '?q=zzzz', 'Items, Home Inventory', 'Items');
            self::assertSame('Nothing matches', HtmlAssertions::text($member->page(), '//main//p[@class="empty-state-title"]'));
            $this->screen($member, $items . '?q=zzzz&filter=' . $f['Under warranty'] . '&op=is&v=1', 'Items, Home Inventory', 'Items');
            $this->screen($member, $m2->tablePath($loans), 'Loans, Home Inventory', 'Loans');
            self::assertSame('No records yet', HtmlAssertions::text($member->page(), '//main//p[@class="empty-state-title"]'));
            $this->screen($admin, $m2->tablePath($blank), 'Notes, Home Inventory', 'Notes');
            self::assertSame(['Add a field'], $admin->texts('//main//div[@class="empty-state"]//a'));
            $this->screen($member, $m2->tablePath($blank), 'Notes, Home Inventory', 'Notes');
            self::assertSame(0, $member->xpath()->query('//main//div[@class="empty-state"]//a')->length);

            // Filter (section 3): no filterable field, step 1, step 2 for each filterable type in the data.
            $this->screen($member, $m2->tablePath($loans) . '/filter', 'Filter Loans', 'Filter Loans');
            $this->screen($member, $items . '/filter', 'Filter Items', 'Filter Items');
            foreach (['Category', 'Under warranty', 'Room', 'Purchased'] as $name) {
                $this->screen($member, $items . '/filter?field=' . $f[$name], 'Filter Items', 'Filter Items');
                self::assertSame('Show records where ' . $name, HtmlAssertions::text($member->page(), '//main//form//legend'));
            }
            $this->screen($member, $items . '/filter?field=' . $f['Notes'], 'Filter Items', 'Filter Items');
            self::assertSame(1, $member->xpath()->query('//main//select[@name="field"]')->length, 'A field that cannot filter gives step 1.');

            // Search results (section 4).
            $this->screen($member, $project . '/search?q=machine', 'Search results, Home Inventory', 'Search results');
            self::assertSame(['Items: 3 matches', 'Rooms: 2 matches', 'Warranties: 3 matches'], $member->texts('//main//section[@class="result-group"]/h2'));
            $this->screen($member, $project . '/search?q=e', 'Search results, Home Inventory', 'Search results');
            self::assertNotSame([], $member->texts('//main//a[@class="result-more"]'), 'A "Show all" link.');
            $this->screen($member, $project . '/search?q=zzzz', 'Search results, Home Inventory', 'Search results');
            self::assertSame('Nothing found', HtmlAssertions::text($member->page(), '//main//p[@class="empty-state-title"]'));
            $this->screen($member, $project . '/search', 'Search results, Home Inventory', 'Search results');
            $this->screen($member, $project . '/search?q=' . str_repeat('x', 201), 'Search results, Home Inventory', 'Search results');
            self::assertSame(1, $member->xpath()->query('//main//div[contains(@class, "notice-warn")]')->length);

            // Record screens (record.md sections 2 to 6), as the Member.
            $mixer = $m2->itemPath('Stand mixer');
            $this->screen($member, $mixer, 'Stand mixer', 'Stand mixer');
            self::assertSame('Item 9 of 48. Last saved today at 10:00.', HtmlAssertions::text($member->page(), '//div[@class="page-head"]//p[@class="lead"]'));
            self::assertSame(
                'Added 14 Mar 2025 by Example Admin. Last changed today by Example Member. Records in the trash can be restored.',
                HtmlAssertions::text($member->page(), '//main//p[@class="record-meta"]'),
            );
            $this->screen($member, $mixer . '?q=mixer', 'Stand mixer', 'Stand mixer');
            self::assertSame('Item 1 of 1. Last saved today at 10:00.', HtmlAssertions::text($member->page(), '//div[@class="page-head"]//p[@class="lead"]'));
            $this->screen($member, $m2->itemPath('Bookshelf, oak'), 'Bookshelf, oak', 'Bookshelf, oak');
            $this->screen($member, $items . '/records/new', 'New item', 'New item');
            $this->screen($member, $items . '/records/new?q=mixer', 'New item', 'New item');
            $this->screen($member, $m2->itemPath('Old kettle'), 'Old kettle', 'This item is in the trash');
            $member->get($mixer);
            self::assertSame(303, $member->submit($mixer)->status);
            $this->screen($member, $mixer, 'Stand mixer', 'Stand mixer', 'Your changes have been saved.');
            self::assertSame(303, $member->submit($mixer . '/trash')->status);
            $this->screen($member, $items, 'Items, Home Inventory', 'Items', 'Stand mixer has been moved to the trash. Undo');
            $this->screen($member, $mixer, 'Stand mixer', 'This item is in the trash');
            self::assertSame(303, $member->submit($mixer . '/restore')->status);
            $this->screen($member, $mixer, 'Stand mixer', 'Stand mixer', 'Stand mixer has been restored.');
            $this->visit($items . '/records/0', 404, $member);
            $this->visit($items . '/records/999', 404, $member);

            // Display settings (settings.md): standard, saved, back to standard.
            $this->screen($member, '/settings', 'Reading and display', 'Reading and display');
            self::assertSame(303, $member->submit('/settings', ['theme' => 'dark', 'accent' => 'indigo'])->status);
            $this->screen($member, '/settings', 'Reading and display', 'Reading and display', 'Your display settings have been saved.');
            self::assertSame('t-dark a-indigo', HtmlAssertions::rootClass($member->page()));
            self::assertSame(303, $member->post('/settings/reset', ['_csrf' => (string) $member->xpath()->query('//form[@id="settings-form"]/input[@name="_csrf"]/@value')->item(0)?->nodeValue])->status);
            $this->screen($member, '/settings', 'Reading and display', 'Reading and display', 'Your display settings are back to standard.');
            $this->screen($admin, '/settings', 'Reading and display', 'Reading and display');
        } finally {
            $core->remove();
        }

        $m2Patterns = [
            '/projects/{project}',
            '/projects/{project}/search',
            '/projects/{project}/tables/{table}',
            '/projects/{project}/tables/{table}/filter',
            '/projects/{project}/tables/{table}/records/new',
            '/projects/{project}/tables/{table}/records/{record}',
            '/settings',
        ];
        $visited = array_values(array_unique($this->visited));
        foreach ($m2Patterns as $pattern) {
            self::assertContains($pattern, $visited, "{$pattern} is visited.");
        }
    }

    // Helpers.

    private function signIn(Kernel $kernel, string $username): EndToEndClient
    {
        $client = new EndToEndClient($kernel);
        $client->get('/sign-in');
        $signedIn = $client->submit('/sign-in', ['username' => $username, 'password' => CoreFixture::PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);

        return $client;
    }

    /**
     * GETs a screen and asserts its structure, title, `h1`, and the notice
     * at the top of main (the flash notice, or the forced-password notice),
     * or its absence.
     */
    private function screen(EndToEndClient $client, string $uri, string $title, string $h1, ?string $notice = null, int $status = 200): void
    {
        $response = $this->visit($uri, $status, $client);
        $html = $response->body;
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
        self::assertSame($title . ' - TiffinDB', HtmlAssertions::text($html, '//title'), "Title of {$uri}");
        self::assertSame($h1, HtmlAssertions::text($html, '//h1'), "h1 of {$uri}");
        // The top notice is the first element of main after the breadcrumb, or inside it.
        $flash = $client->xpath()->query('(//main/*[not(self::nav)])[1]/descendant-or-self::*[@role="status"]');
        if ($notice === null) {
            self::assertSame(0, $flash->length, "No flash notice on {$uri}");
        } else {
            self::assertSame(1, $flash->length, "A flash notice on {$uri}");
            self::assertSame($notice, trim((string) preg_replace('/\s+/u', ' ', (string) $flash->item(0)?->textContent)), "Flash notice on {$uri}");
        }
    }

    private function visit(string $uri, int $status, EndToEndClient $client): Response
    {
        $response = $client->get($uri);
        self::assertSame($status, $response->status, "GET {$uri}");
        if ($status !== 303) {
            HtmlAssertions::assertWellFormed($response->body);
            HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        }
        $path = (string) parse_url('http://tiffindb.example' . $uri, PHP_URL_PATH);
        foreach (Fixtures::applicationRoutes() as $route) {
            if (is_array($route) && $route[0] === 'GET' && preg_match(self::routeRegex((string) $route[1]), $path) === 1) {
                $this->visited[] = (string) $route[1];
                break;
            }
        }

        return $response;
    }

    private static function routeRegex(string $pattern): string
    {
        return '#^' . preg_replace('#\\\\\{[a-z]+\\\\\}#', '[^/]+', preg_quote($pattern, '#')) . '\z#';
    }

    /** Creates a project through the screens; returns its path. */
    private function createProject(EndToEndClient $admin, string $name, string $firstTable): string
    {
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => $name, 'first_table' => $firstTable]);
        self::assertSame(303, $made->status, $made->body);
        self::assertSame(200, $admin->follow($made)->status);

        return substr((string) $made->header('Location'), 0, -strlen('/tables'));
    }

    /** The path of a table (`/projects/{p}/tables/{t}`). */
    private function tablePath(EndToEndClient $admin, string $project, string $name): string
    {
        self::assertSame(200, $admin->get($project . '/tables')->status);
        $href = (string) $admin->xpath()->query('//main//tbody/tr/th/a[normalize-space(.)="' . $name . '"]/@href')->item(0)?->nodeValue;
        self::assertStringEndsWith('/fields', $href, "Table {$name}");

        return substr($href, 0, -strlen('/fields'));
    }

    private function fieldPath(EndToEndClient $admin, string $table, string $name): string
    {
        self::assertSame(200, $admin->get($table . '/fields')->status);
        $href = (string) $admin->xpath()->query('//main//tbody/tr/th/a[normalize-space(.)="' . $name . '"]/@href')->item(0)?->nodeValue;
        self::assertNotSame('', $href, "Field {$name}");

        return $href;
    }

    private function personKey(EndToEndClient $admin, string $name): string
    {
        self::assertSame(200, $admin->get('/admin/people')->status);
        $href = (string) $admin->xpath()->query('//main//a[normalize-space(.)="' . $name . '"]/@href')->item(0)?->nodeValue;
        self::assertStringStartsWith('/admin/people/', $href, "Person {$name}");

        return substr($href, strlen('/admin/people/'));
    }

    /** The value of the option labelled $label in the last page's select $name. */
    private function option(EndToEndClient $client, string $name, string $label): string
    {
        return (string) $client->xpath()->query('//select[@name="' . $name . '"]/option[normalize-space(.)="' . $label . '"]/@value')->item(0)?->nodeValue;
    }

    /** @param array<string, string> $values */
    private function addField(EndToEndClient $admin, string $table, string $type, array $values): void
    {
        self::assertSame(200, $admin->get($table . '/fields/new?type=' . $type)->status);
        if (isset($values['opt_target']) && $values['opt_target'] === '') {
            $values['opt_target'] = $this->option($admin, 'opt_target', 'Rooms');
        }
        $added = $admin->submit($table . '/fields', $values);
        self::assertSame(303, $added->status, $added->body);
        self::assertSame(200, $admin->follow($added)->status);
    }
}
