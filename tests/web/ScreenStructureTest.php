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
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Support\M4Fixture;
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
 * T-SCR-1 (M1 spec part 2 section 9), T-SCR-2 (M2 spec part 2
 * section 12, testEveryM2ScreenInEveryState), T-SCR-3 (M3 spec part 2
 * section 10.1: m3Screens and testPluginScreensWithAddedPlugins), and
 * T-SCR-4 (M4 spec part 2 section 11.1: m4Screens and
 * testEveryM4ScreenInEveryState): every GET route of the real route
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
    /** The M4 controllers (M4-WP6 to WP8); T-SCR-4 visits every GET route they handle. */
    private const M4_CONTROLLERS = [
        \TiffinDB\Web\Controller\LinkController::class,
        \TiffinDB\Web\Controller\FileController::class,
        \TiffinDB\Web\Controller\CollectionController::class,
        \TiffinDB\Web\Controller\TrashController::class,
    ];

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

        // M3 screens (T-SCR-3), on Home Inventory.
        $this->m3Screens($admin, $home, $items);

        // M4 screens (T-SCR-4), on Home Inventory.
        $this->m4Screens($admin, $home, $items, $record);

        // M5 editor screens (T-SCR-5, M5-WP5), on Home Inventory.
        $this->m5EditorScreens($admin, $items);

        // Signing out, and the signed-out sign-in page.
        self::assertSame(303, $admin->submit('/sign-out')->status);
        $this->screen($admin, '/sign-in?signed-out=1', 'Sign in', 'Sign in', 'You have signed out.');
        $this->visit('/', 303, $admin);
        $this->visit('/projects', 303, $admin);

        // Every GET route was visited, the M3 and M4 ones included (T-SCR-3,
        // T-SCR-4). Routes of controllers that do not exist yet are counted
        // once their controller lands.
        $patterns = [];
        foreach (Fixtures::applicationRoutes() as $route) {
            if (
                is_array($route)
                && $route[0] === 'GET'
                && class_exists($route[2][0])
            ) {
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

    /**
     * T-SCR-4 (M4 spec part 2 section 11.1): every GET route M4 added, in
     * each state its design document under docs/design/m4/ lists that a
     * GET can reach, over the M4 synthetic data set (M4Fixture, with real
     * files) plus 50 more rooms (so the Room picker has a second page) of
     * which 51 rooms are in the trash with "Laundry" (so the trash has a
     * second page for Rooms and a one-link picker shows the trashed-link
     * note), with the real CoreServices, as the Member and as the Admin.
     * The serving route is visited too (its bodies are files, not pages;
     * AttachmentAccessTest checks them). States reached only by a POST
     * (422 pages, the changed-trash confirmation) are covered by
     * KeyboardStructureTest and each screen package's tests.
     */
    public function testEveryM4ScreenInEveryState(): void
    {
        $core = new CoreFixture();
        try {
            $core->clock->set('2026-11-03T10:00:00Z');
            $ops = $core->operations();
            $fx = M4Fixture::build($core, $ops);
            $m2 = $fx->m2;
            $actor = $core->actor('admin');
            for ($n = 1; $n <= 50; $n++) {
                $id = $core->addRecord($m2->project, $m2->rooms, [$m2->roomFields['Name'] => sprintf('Store %02d', $n)]);
                $ops->records()->trash($actor, $m2->project, $m2->rooms, (string) $id);
            }
            $ops->records()->trash($actor, $m2->project, $m2->rooms, (string) $m2->roomIds['Laundry']);
            for ($n = 51; $n <= 100; $n++) {
                $core->addRecord($m2->project, $m2->rooms, [$m2->roomFields['Name'] => sprintf('Store %02d', $n)]);
            }
            $garden = $ops->projects()->create($actor, 'Garden Log', 'Plants')->key;
            $ops->projects()->setSharing($actor, $garden, [$core->person('member')->key]);
            $config = Fixtures::config(false) + ['data_dir' => $core->temp->path('data'), 'timezone' => 'UTC'];
            $kernel = new Kernel($config, Fixtures::appRoot(), null, new CoreServices($config, Fixtures::appRoot(), $core->clock, new SeededRandom('tiffindb-screen-structure-m4-seed'), $core->hasher));
            $admin = $this->signIn($kernel, 'admin');
            $member = $this->signIn($kernel, 'member');
            $f = $m2->itemFields;
            $project = $m2->projectPath();
            $items = $m2->tablePath($m2->items);
            $mixer = $m2->itemPath('Stand mixer');
            $id = static fn (string $name): int => $fx->attachmentIds[$name];

            // Record form controls (record-controls.md): links and files, none, other types, a new record.
            foreach ([$member, $admin] as $client) {
                $this->screen($client, $mixer, 'Stand mixer', 'Stand mixer');
                self::assertSame(['Kitchen', 'Choose a different room'], $client->texts('//div[@class="link-control"]/*'));
                self::assertSame(['Stand mixer, manufacturer warranty'], $client->texts('//ul[@class="link-list"]//div[@class="linked-main"]/a'));
                self::assertSame(1, $client->xpath()->query('//li[contains(@class, "file-row")]/img[@src="' . $fx->servePath('stand-mixer.png') . '"]')->length);
            }
            $this->screen($member, $m2->itemPath('Bookshelf, oak'), 'Bookshelf, oak', 'Bookshelf, oak');
            self::assertSame(['No files', 'No files', 'Link a record'], array_values(array_intersect($member->texts('//main//p[@class="static-value"] | //div[@class="control-actions"]/a'), ['No files', 'Link a record'])));
            $this->screen($member, $m2->itemPath('Sewing machine'), 'Sewing machine', 'Sewing machine');
            self::assertSame(2, $member->xpath()->query('//span[@class="file-name"][starts-with(normalize-space(.), "manual.html ") or starts-with(normalize-space(.), "diagram.svg ")]')->length);
            self::assertSame(0, $member->xpath()->query('//img[contains(@alt, "manual") or contains(@alt, "diagram")]')->length, 'Active content is never an image.');
            $this->screen($member, $items . '/records/new', 'New item', 'New item');
            self::assertSame(0, $member->xpath()->query('//div[@class="link-control"] | //div[@class="control-actions"]')->length, 'No controls on a new record.');

            // Picker (picker.md): one link, many links with taken candidates, find, no match, a refused find, page 2, a trashed link.
            $room = $mixer . '/links/' . $f['Room'];
            $this->screen($member, $room, 'Room for Stand mixer', 'Room for Stand mixer');
            self::assertSame(1, $member->xpath()->query('//main[@class="page page-picker"]')->length);
            self::assertSame(['Kitchen'], $member->texts('//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]'));
            self::assertGreaterThan(0, $member->xpath()->query('//main//a[contains(@href, "page=2")]')->length, 'Room has a second page.');
            $this->screen($member, $room . '?page=2', 'Room for Stand mixer', 'Room for Stand mixer');
            $this->screen($member, $room . '?q=gar', 'Room for Stand mixer', 'Room for Stand mixer');
            self::assertSame(['Garage'], $member->texts('//section[@aria-labelledby="pk-list"]//span[@class="picker-title"]'));
            $this->screen($member, $room . '?q=zzzz', 'Room for Stand mixer', 'Room for Stand mixer');
            self::assertSame('Nothing in Rooms matches "zzzz".', HtmlAssertions::text($member->page(), '//section[@aria-labelledby="pk-list"]/p[@class="static-value"]'));
            $this->screen($member, $room . '?q=' . str_repeat('x', 201), 'Room for Stand mixer', 'Room for Stand mixer');
            self::assertSame(1, $member->xpath()->query('//input[@name="q"][@aria-invalid="true"]')->length);
            $warranties = $mixer . '/links/' . $f['Warranties'];
            $this->screen($member, $warranties, 'Warranties for Stand mixer', 'Warranties for Stand mixer');
            self::assertGreaterThan(0, $member->xpath()->query('//span[@class="picker-taken"]')->length, 'Warranties linked to other items are shown as taken.');
            $laundryItem = (string) array_search('Laundry', $m2->itemRooms, true);
            self::assertNotSame('', $laundryItem);
            $this->screen($member, $m2->itemPath($laundryItem) . '/links/' . $f['Room'], 'Room for ' . $laundryItem, 'Room for ' . $laundryItem);
            self::assertSame(1, $member->xpath()->query('//section[@aria-labelledby="pk-now"]/p[contains(@class, "notice-warn")]')->length, 'The trashed-link note.');
            $this->visit($mixer . '/links/' . $f['Category'], 404, $member);

            // Upload (files.md section 2): any file, images only, replace.
            $this->screen($member, $mixer . '/files/' . $f['Receipt'] . '/new', 'Add a file to Stand mixer', 'Add a file to Stand mixer');
            $this->screen($member, $mixer . '/files/' . $f['Photo'] . '/new', 'Add a file to Stand mixer', 'Add a file to Stand mixer');
            $this->screen($member, $mixer . '/files/' . $f['Photo'] . '/new?replace=' . $id('stand-mixer.png'), 'Replace stand-mixer.png', 'Replace stand-mixer.png');
            $this->visit($mixer . '/files/' . $f['Photo'] . '/new?replace=' . $id('robot-vacuum.gif'), 404, $member);

            // File page (section 3): an image, a PDF, another type; the remove confirmation (section 4).
            $this->screen($member, $mixer . '/files/' . $f['Photo'] . '/' . $id('stand-mixer.png'), 'stand-mixer.png', 'stand-mixer.png');
            self::assertSame(1, $member->xpath()->query('//img[@class="file-image-large"]')->length);
            $this->screen($member, $mixer . '/files/' . $f['Receipt'] . '/' . $id('receipt-stand-mixer.pdf'), 'receipt-stand-mixer.pdf', 'receipt-stand-mixer.pdf');
            self::assertSame(0, $member->xpath()->query('//img')->length, 'A PDF is never shown in the page.');
            $sewing = $m2->itemPath('Sewing machine');
            $this->screen($member, $sewing . '/files/' . $f['Receipt'] . '/' . $id('manual.html'), 'manual.html', 'manual.html');
            $this->screen($member, $mixer . '/files/' . $f['Receipt'] . '/' . $id('receipt-stand-mixer.pdf') . '/remove', 'Remove receipt-stand-mixer.pdf?', 'Remove receipt-stand-mixer.pdf?');
            $this->visit($mixer . '/files/' . $f['Receipt'] . '/' . $id('manual.html'), 404, $member);

            // The serving route.
            foreach (['stand-mixer.png', 'receipt-stand-mixer.pdf', 'manual.html'] as $name) {
                $this->visitFile($fx->servePath($name), 200, $member);
            }
            $this->visitFile($project . '/files/99999', 404, $member);

            // Collection editor, view, and delete (collections.md), as the Member (who manages collections) and the Admin.
            foreach ([$member, $admin] as $client) {
                $this->screen($client, $items . '/collections/new', 'New collection', 'New collection');
                self::assertSame(1, $client->xpath()->query('//main[@class="page page-collection"]')->length);
            }
            $this->screen($member, $fx->collectionPath('Under warranty') . '/edit', 'Under warranty', 'Under warranty');
            self::assertSame(2, $member->xpath()->query('//fieldset[contains(@class, "rule-row")]')->length);
            $this->screen($member, $fx->collectionPath('Retired items') . '/edit', 'Retired items', 'Retired items');
            self::assertSame(1, $member->xpath()->query('//fieldset[contains(@class, "rule-broken")]')->length);
            $this->screen($member, $fx->collectionPath('Under warranty'), 'Under warranty, Home Inventory', 'Under warranty');
            self::assertSame(1, count(preg_grep('/^Under warranty \d+\z/', $member->texts('//nav[contains(@class, "sidebar")]//a[@aria-current="page"]')) ?: []), 'The sidebar marks the collection with its count.');
            $this->screen($member, $fx->collectionPath('Under warranty') . '?q=machine', 'Under warranty, Home Inventory', 'Under warranty');
            $this->screen($member, $fx->collectionPath('Under warranty') . '?q=zzzz', 'Under warranty, Home Inventory', 'Under warranty');
            $this->screen($member, $fx->collectionPath('Under warranty') . '?sort=' . $f['Value'] . '.asc', 'Under warranty, Home Inventory', 'Under warranty');
            $this->screen($member, $fx->collectionPath('Kitchen or study'), 'Kitchen or study, Home Inventory', 'Kitchen or study');
            $this->screen($member, $fx->collectionPath('Retired items'), 'Retired items, Home Inventory', 'Retired items');
            self::assertSame(1, $member->xpath()->query('//main//div[contains(@class, "notice-warn")]')->length, 'The broken-rule notice.');
            $this->screen($member, $fx->collectionPath('Under warranty') . '/delete', 'Delete Under warranty?', 'Delete Under warranty?');
            $this->visit($project . '/collections/aaaaaaaaaaaa', 404, $member);
            $this->visit('/projects/' . $garden . '/collections/' . $fx->collections['Under warranty'], 404, $member);

            // Trash (trash.md): every table, one table and its second page, an empty trash; the confirmation (Admin only).
            foreach ([$member, $admin] as $client) {
                $this->screen($client, $project . '/trash', 'Trash', 'Trash');
                self::assertSame(1, $client->xpath()->query('//main[@class="page page-trash"]')->length);
            }
            self::assertSame(0, $member->xpath()->query('//main//a[normalize-space(.)="Empty the trash"]')->length);
            self::assertSame(1, $admin->xpath()->query('//main//a[normalize-space(.)="Empty the trash"]')->length);
            $this->screen($member, $project . '/trash?table=' . $m2->items, 'Trash', 'Trash');
            $this->screen($member, $project . '/trash?table=' . $m2->rooms, 'Trash', 'Trash');
            $this->screen($member, $project . '/trash?table=' . $m2->rooms . '&page=2', 'Trash', 'Trash');
            self::assertSame(1, count($member->texts('//main//span[@class="trash-title"]')), '51 rooms: one on the second page.');
            $this->visit($project . '/trash?table=aaaaaaaaaaaa', 404, $member);
            $this->screen($member, '/projects/' . $garden . '/trash', 'Trash', 'Trash');
            self::assertSame('The trash is empty.', HtmlAssertions::text($member->page(), '//main//p[@class="empty-state-title"]'));
            $this->screen($admin, $project . '/trash/empty', 'Empty the trash?', 'Empty the trash?');
            self::assertSame(1, $admin->xpath()->query('//main//form//button[contains(@class, "button-danger")]')->length);
            $this->visit($project . '/trash/empty', 403, $member);

            // Project settings: the totals line.
            $this->screen($admin, $project . '/settings', 'Project settings', 'Project settings');
            self::assertStringContainsString('This project stores 50 files', HtmlAssertions::text($admin->page(), '//main'), '51 rows, 50 distinct files.');
        } finally {
            $core->remove();
        }

        $m4Patterns = [];
        foreach (Fixtures::applicationRoutes() as $route) {
            if (is_array($route) && $route[0] === 'GET' && in_array($route[2][0], self::M4_CONTROLLERS, true)) {
                $m4Patterns[] = (string) $route[1];
            }
        }
        self::assertCount(11, $m4Patterns, 'Eleven M4 GET routes.');
        $visited = array_values(array_unique($this->visited));
        foreach ($m4Patterns as $pattern) {
            self::assertContains($pattern, $visited, "{$pattern} is visited.");
        }
    }

    /**
     * The M4 screens inside testEveryScreenInEveryState, so that its
     * every-route check covers them (M4 spec part 2 section 12): on Home
     * Inventory as built through the screens, as the Admin, one visit of
     * each M4 GET route.
     */
    private function m4Screens(EndToEndClient $admin, string $home, string $items, string $record): void
    {
        $this->addField($admin, $items, 'attachment', ['name' => 'Photo', 'opt_images_only' => '1']);
        $room = substr($this->fieldPath($admin, $items, 'Room'), -12);
        $photo = substr($this->fieldPath($admin, $items, 'Photo'), -12);

        $this->screen($admin, $record . '/links/' . $room, 'Room for Stand mixer', 'Room for Stand mixer');
        $this->screen($admin, $record . '/files/' . $photo . '/new', 'Add a file to Stand mixer', 'Add a file to Stand mixer');
        $path = $this->temp->path('upload-photo.png');
        file_put_contents($path, FileFixture::png(4, 4, 'screen-structure'));
        $added = $admin->upload($record . '/files/' . $photo, 'file', $path, 'photo.png');
        self::assertSame(303, $added->status, $added->body);
        $this->screen($admin, $record, 'Stand mixer', 'Stand mixer', 'photo.png has been added.');
        $src = (string) $admin->xpath()->query('//li[contains(@class, "file-row")]/img/@src')->item(0)?->nodeValue;
        $this->visitFile($src, 200, $admin);
        $file = $record . '/files/' . $photo . '/' . substr($src, strrpos($src, '/') + 1);
        $this->screen($admin, $file, 'photo.png', 'photo.png');
        $this->screen($admin, $file . '/remove', 'Remove photo.png?', 'Remove photo.png?');

        $this->screen($admin, $items . '/collections/new', 'New collection', 'New collection');
        $form = (string) $admin->xpath()->query('//form[@id="collection-form"]/@action')->item(0)?->nodeValue;
        $name = substr($this->fieldPath($admin, $items, 'Name'), -12);
        $admin->get($items . '/collections/new');
        self::assertSame(200, $admin->submit($form, ['name' => 'Mixers', 'rules[1][field]' => $name, 'action' => 'preview'])->status);
        $saved = $admin->submit($form, ['rules[1][test]' => 'contains', 'rules[1][value]' => 'mixer', 'action' => 'save']);
        self::assertSame(303, $saved->status, $saved->body);
        $view = (string) $saved->header('Location');
        $this->screen($admin, $view, 'Mixers, Home Inventory', 'Mixers', 'The collection has been saved.');
        $this->screen($admin, $view . '/edit', 'Mixers', 'Mixers');
        $this->screen($admin, $view . '/delete', 'Delete Mixers?', 'Delete Mixers?');

        $admin->get($record);
        self::assertSame(303, $admin->submit($record . '/trash')->status);
        $this->screen($admin, $home . '/trash', 'Trash', 'Trash', 'Stand mixer has been moved to the trash. Undo');
        $this->screen($admin, $home . '/trash/empty', 'Empty the trash?', 'Empty the trash?');
        $admin->cleanUp();
    }

    /** M5-WP5: the form editor and the reset confirmation, with the main class of each layout. */
    private function m5EditorScreens(EndToEndClient $admin, string $items): void
    {
        $this->screen($admin, $items . '/form', 'Form for Items', 'Form for Items');
        self::assertSame('page page-form', (string) $admin->xpath()->query('//main/@class')->item(0)?->nodeValue);
        $this->screen($admin, $items . '/form/reset', 'Go back to the standard form?', 'Go back to the standard form?');
        self::assertSame('page page-form page-form-narrow', (string) $admin->xpath()->query('//main/@class')->item(0)?->nodeValue);
    }

    /** Visits a route that answers with a file (or a refusal page), counting the route; no page checks on a file. */
    private function visitFile(string $uri, int $status, EndToEndClient $client): Response
    {
        $response = $client->get($uri);
        self::assertSame($status, $response->status, "GET {$uri}");
        self::assertSame($status === 200, $response->isSandboxed(), "GET {$uri}");
        $path = (string) parse_url('http://tiffindb.example' . $uri, PHP_URL_PATH);
        foreach (Fixtures::applicationRoutes() as $route) {
            if (is_array($route) && $route[0] === 'GET' && preg_match(self::routeRegex((string) $route[1]), $path) === 1) {
                $this->visited[] = (string) $route[1];
                break;
            }
        }

        return $response;
    }

    /**
     * T-SCR-3 (M3 spec part 2 section 10.1): every M3 GET route in each
     * state its design document under docs/design/m3/ lists that a GET can
     * reach, and the pages a POST answers with (422 review problems, the
     * archive name taken), on a project with Items (with a Rooms link and a
     * Bought on date) and an empty Rooms. Files are uploaded through the
     * Request test seam (EndToEndClient::upload).
     */
    private function m3Screens(EndToEndClient $admin, string $home, string $items): void
    {
        $itemsKey = substr($items, strrpos($items, '/') + 1);
        $upload = function (string $name, string $contents): string {
            $path = $this->temp->path('upload-' . $name);
            file_put_contents($path, $contents);

            return $path;
        };

        // Step 1 (import.md 2).
        $this->screen($admin, $home . '/import', 'Import a file', 'Import a file');
        self::assertSame(1, $admin->xpath()->query('//form[@enctype="multipart/form-data"]//input[@type="file"][@name="file"]')->length);

        // Step 2, new table with a warning (the rooms are not in Rooms yet).
        $rows = (new IoFixture())->itemRows(12);
        $uploaded = $admin->upload($home . '/import', 'file', $upload('inventory.csv', IoFixture::csv(IoFixture::HEADINGS, $rows)), 'inventory.csv');
        self::assertSame(303, $uploaded->status, $uploaded->body);
        $job = (string) $uploaded->header('Location');
        $this->screen($admin, $job, 'Check the fields', 'Import a file');
        self::assertSame(1, $admin->xpath()->query('//section[contains(@class, "warning-panel")]')->length, 'With warnings.');
        self::assertSame(1, $admin->xpath()->query('//table[contains(@class, "mapping-table")]')->length);

        // Reading options (section 5).
        $this->screen($admin, $job . '/reading', 'How the file is read', 'How the file is read');

        // Step 2 for an existing table, then a 422 with every problem linked, then back to a new table.
        $admin->get($job);
        self::assertSame(303, $admin->submit($job, ['action' => 'recheck', 'target' => 'existing', 'table' => $itemsKey])->status);
        $this->screen($admin, $job, 'Check the fields', 'Import a file');
        self::assertSame('Goes into', HtmlAssertions::text($admin->page(), '//table[contains(@class, "mapping-table")]//thead/tr/th[3]'));
        // The existing-table page shows the new table's name empty, so it is typed again (reported).
        self::assertSame('', (string) $admin->xpath()->query('//input[@name="table_name"]/@value')->item(0)?->nodeValue);
        self::assertSame(303, $admin->submit($job, ['action' => 'recheck', 'target' => 'new', 'table_name' => 'inventory'])->status);
        $admin->get($job);
        $refused = $admin->submit($job, ['action' => 'import', 'table_name' => 'Items', 'name[0]' => '']);
        self::assertSame(422, $refused->status, $refused->body);
        $this->structure($refused->body, 'Error: Check the fields', 'Import a file', 'review 422');
        self::assertGreaterThanOrEqual(2, $admin->xpath()->query('//div[@class="error-summary"]//a')->length);

        // Step 3: preparing (with the script), then saving (without), then the report.
        $this->screen($admin, $job, 'Check the fields', 'Import a file');
        self::assertSame(303, $admin->submit($job, ['action' => 'import'])->status);
        $this->screen($admin, $job, 'Import a file', 'Import a file');
        self::assertSame(1, $admin->xpath()->query('//form[@data-auto-continue="1"]')->length, 'Preparing continues by itself with the script.');
        for ($i = 0; $i < 10 && $admin->xpath()->query('//input[@name="step"][@value="save"]')->length === 0; $i++) {
            self::assertSame(303, $admin->submit($job . '/continue')->status);
            $this->screen($admin, $job, 'Import a file', 'Import a file');
        }
        self::assertSame(0, $admin->xpath()->query('//script')->length, 'The saving page loads no script.');
        $saved = $admin->submit($job . '/continue', ['step' => 'save']);
        self::assertSame(303, $saved->status);
        $this->screen($admin, $job . '/report', 'Import report', 'Import a file', '12 rows were imported into inventory.');
        $this->screen($admin, $job . '/report', 'Import report', 'Import a file');

        // A report with values left empty: dates that cannot be read, into Items' Bought on.
        $dates = "Name,Bought on\r\nKettle,2025-03-14\r\nLadder,soon\r\nLamp,unknown\r\n";
        $admin->get($home . '/import');
        $second = $admin->upload($home . '/import', 'file', $upload('dates.csv', $dates), 'dates.csv');
        $job2 = (string) $second->header('Location');
        $admin->get($job2);
        self::assertSame(303, $admin->submit($job2, ['action' => 'recheck', 'target' => 'existing', 'table' => $itemsKey])->status);
        $admin->get($job2);
        self::assertSame(303, $admin->submit($job2, ['action' => 'import'])->status);
        $admin->get($job2);
        for ($i = 0; $i < 10 && $admin->xpath()->query('//input[@name="step"][@value="save"]')->length === 0; $i++) {
            $admin->submit($job2 . '/continue');
            $admin->get($job2);
        }
        self::assertSame(303, $admin->submit($job2 . '/continue', ['step' => 'save'])->status);
        $this->screen($admin, $job2 . '/report', 'Import report', 'Import a file', '3 rows were imported into Items.');
        self::assertSame(1, $admin->xpath()->query('//table[contains(@class, "report-table")]')->length, 'The values left empty are listed.');

        // Reading a large file in parts, and the failure page (the format turned off meanwhile).
        $admin->get($home . '/import');
        $large = $admin->upload($home . '/import', 'file', $upload('large.csv', IoFixture::csv(IoFixture::HEADINGS, (new IoFixture())->itemRows(2100))), 'large.csv');
        $job3 = (string) $large->header('Location');
        $this->screen($admin, $job3, 'Import a file', 'Import a file');
        self::assertStringStartsWith('Reading large.csv', HtmlAssertions::text($admin->page(), '//div[@class="progress"]/p[1]'));
        $admin->get('/admin/plugins/import/csv');
        self::assertSame(303, $admin->submit('/admin/plugins/import/csv/disable')->status);
        $this->screen($admin, '/admin/plugins/import/csv', 'CSV', 'CSV', 'CSV is turned off.');
        $this->visit('/admin/plugins/import/csv/enable', 303, $admin);
        $admin->get($job3);
        self::assertSame(303, $admin->submit($job3 . '/continue')->status);
        $this->screen($admin, $job3, 'The import did not finish', 'The import did not finish');
        $admin->get('/admin/plugins/import/csv');
        self::assertSame(303, $admin->submit('/admin/plugins/import/csv/enable')->status);
        $this->screen($admin, '/admin/plugins/import/csv', 'CSV', 'CSV', 'CSV is turned on.');

        // Export (export.md).
        $this->screen($admin, $home . '/export', 'Export', 'Export');

        // Plugins (plugins.md 2 and 3).
        $this->screen($admin, '/admin/plugins', 'Plugins', 'Plugins');
        foreach (['import/csv' => 'CSV', 'import/xlsx' => 'Excel workbook', 'export/csv' => 'CSV', 'export/json' => 'JSON', 'export/xlsx' => 'Excel workbook'] as $ref => $name) {
            $this->screen($admin, '/admin/plugins/' . $ref, $name, $name);
        }
        $this->visit('/admin/plugins/export/nothing', 404, $admin);

        // Import a project archive (archive.md 2 to 4): form, name taken (422), result.
        $admin->get($home . '/export');
        $archive = $admin->submit($home . '/export/archive');
        self::assertSame(200, $archive->status);
        $zip = $upload('home.tiffindb.zip', $archive->body());
        $this->screen($admin, '/projects/import', 'Import a project archive', 'Import a project archive');
        $taken = $admin->upload('/projects/import', 'file', $zip, 'home.tiffindb.zip', ['name' => 'Home Inventory']);
        self::assertSame(422, $taken->status, $taken->body);
        $this->structure($taken->body, 'Error: Import a project archive', 'Import a project archive', 'name taken');
        $nameForm = (string) $admin->xpath()->query('//main//form[@method="post"]/@action')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#^/projects/import/[A-Za-z0-9_-]+\z#', $nameForm);
        $made = $admin->submit($nameForm, ['name' => 'Home Inventory copy']);
        self::assertSame(303, $made->status, $made->body);
        $this->screen($admin, (string) $made->header('Location'), 'Home Inventory copy', 'Home Inventory copy', 'The project Home Inventory copy has been made from the archive.');
        $admin->cleanUp();
    }

    /**
     * The structure assertions of screen() for a page a POST answered with.
     */
    private function structure(string $html, string $title, string $h1, string $where): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
        self::assertSame($title . ' - TiffinDB', HtmlAssertions::text($html, '//title'), "Title of {$where}");
        self::assertSame($h1, HtmlAssertions::text($html, '//h1'), "h1 of {$where}");
    }

    /**
     * T-SCR-3, the plugin states that need plugins not shipped with
     * TiffinDB: the list with every status, the page of a disabled added
     * plugin, of one that cannot be used, and of a damaged one, and the
     * confirmation for turning on an added plugin. The real CoreServices
     * with the plugins directory of CoreFixture::pluginsRoot() (a copy of
     * plugins/ plus three synthetic plugins); only scanning happens here,
     * no plugin is loaded.
     */
    public function testPluginScreensWithAddedPlugins(): void
    {
        $core = new CoreFixture();
        try {
            $config = Fixtures::config(false) + ['data_dir' => $core->temp->path('data'), 'timezone' => 'UTC'];
            $services = new PluginsRootServices(new CoreServices($config, Fixtures::appRoot(), $core->clock, new SeededRandom('tiffindb-screen-structure-m3-seed'), $core->hasher), $core->pluginsRoot());
            $admin = $this->signIn(new Kernel($config, Fixtures::appRoot(), null, $services), 'admin');
            $this->screen($admin, '/admin/plugins', 'Plugins', 'Plugins');
            self::assertSame(['On', 'Off', 'Cannot be used', 'Damaged'], array_values(array_unique($admin->texts('//main//span[contains(@class, "plugin-status")]'))));
            $this->screen($admin, '/admin/plugins/import/example', 'Example format', 'Example format');
            $this->screen($admin, '/admin/plugins/import/example/enable', 'Turn on Example format?', 'Turn on Example format?');
            self::assertSame(['Turn it on'], $admin->texts('//main//form//button[contains(@class, "button-danger")]'));
            $this->screen($admin, '/admin/plugins/import/needs', 'Needs an extension', 'Needs an extension');
            $broken = HtmlAssertions::text($this->visit('/admin/plugins/export/broken', 200, $admin)->body, '//h1');
            $this->screen($admin, '/admin/plugins/export/broken', $broken, $broken);
            self::assertSame(1, $admin->xpath()->query('//main//p[contains(@class, "notice")]')->length, 'A damaged plugin says so.');
        } finally {
            $core->remove();
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

/**
 * The real CoreServices with another plugins directory: every call is
 * delegated, and the first Operations it hands out is pointed at $root
 * before any plugin is scanned (Operations::usePluginsRoot).
 */
final class PluginsRootServices implements \TiffinDB\Web\Services
{
    /** @var \WeakMap<\TiffinDB\Core\App\Operations, true> */
    private \WeakMap $rooted;

    public function __construct(private readonly CoreServices $inner, private readonly string $root)
    {
        $this->rooted = new \WeakMap();
    }

    public function installState(): \TiffinDB\Core\Install\InstallState
    {
        return $this->inner->installState();
    }

    public function installer(): \TiffinDB\Core\Install\Installer
    {
        return $this->inner->installer();
    }

    public function operations(): \TiffinDB\Core\App\Operations
    {
        $operations = $this->inner->operations();
        if (!isset($this->rooted[$operations])) {
            $operations->usePluginsRoot($this->root);
            $this->rooted[$operations] = true;
        }

        return $operations;
    }

    public function probe(): \TiffinDB\Core\Install\HttpProbe
    {
        return $this->inner->probe();
    }

    public function resume(#[\SensitiveParameter] string $token): ?\TiffinDB\Core\Auth\SignedIn
    {
        return $this->inner->resume($token);
    }

    public function endSession(#[\SensitiveParameter] string $token): void
    {
        $this->inner->endSession($token);
    }

    public function takeFlash(#[\SensitiveParameter] string $token): ?array
    {
        return $this->inner->takeFlash($token);
    }

    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void
    {
        $this->inner->setFlash($token, $key, $params);
    }

    public function userSettings(int $userId): array
    {
        return $this->inner->userSettings($userId);
    }

    public function newCsrfToken(): string
    {
        return $this->inner->newCsrfToken();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->inner->now();
    }

    public function uploadLimits(): \TiffinDB\Web\UploadLimits
    {
        return $this->inner->uploadLimits();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
