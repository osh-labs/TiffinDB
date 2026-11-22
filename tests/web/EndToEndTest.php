<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\Services;
use TiffinDB\Web\UploadLimits;
use TiffinDB\Web\UploadedFile;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-E2E-1 to T-E2E-4 (M1 spec part 2 section 9), T-E2E-5 to T-E2E-7
 * (M2 spec part 2 section 12), and T-E2E-8 to T-E2E-10 (M3 spec part 2
 * section 10.1: import, a failed import, and the project archive, with
 * files uploaded through the Request test seam and downloads read from
 * the response): the application driven
 * through the kernel with the real route table, templates, strings, and the
 * real CoreServices (a FixedClock, a SeededRandom, and a bcrypt cost 4
 * PasswordHasher) over an empty temporary data folder. The application
 * root is a private copy of the repository's public/, templates/,
 * strings/, and config/app.php (appRoot(), made once per process), so
 * other processes that change the working tree while the suite runs (an
 * editor, git, a second test run) cannot fail the tree check below (M4-X:
 * the one-off full-suite failure hunt). DOCUMENT_ROOT is the copy's
 * public/ folder, so the data folder is outside it and no
 * exposure probe runs: the kernel gets the CoreServices through
 * NoProbeServices, which delegates every call but hands out a probe that
 * only counts its requests, and each test asserts that the count stays 0,
 * so no socket is opened. Forms are read from the served pages
 * and submitted with their own fields, as a browser would. After each test,
 * every file and folder of that application root has the same size,
 * modification time, and hash as before, and nothing was added: the
 * application writes nothing outside its data folder. Synthetic data only.
 */
#[CoversNothing]
final class EndToEndTest extends TestCase
{
    public const ADMIN_PASSWORD = 'example admin passphrase 1';
    public const MEMBER_TEMPORARY = 'temporary words 0101';
    public const MEMBER_PASSWORD = 'example member passphrase 2';

    private TempDir $temp;
    private FixedClock $clock;
    private Kernel $kernel;
    private NoProbeServices $services;

    /** @var array<string, string> Relative path => size, modification time, and hash of every repository file. */
    private array $treeBefore;

    protected function setUp(): void
    {
        $this->treeBefore = self::tree();
        $this->temp = TempDir::create('tiffindb-e2e-');
        $this->clock = new FixedClock('2026-11-03T10:00:00Z');
        $config = Fixtures::config(false) + ['data_dir' => $this->temp->path()];
        $services = new CoreServices(
            $config,
            self::appRoot(),
            $this->clock,
            new SeededRandom('tiffindb-end-to-end-seed'),
            new PasswordHasher(['bcrypt' => 4]),
        );
        $this->services = new NoProbeServices($services);
        $this->kernel = new Kernel($config, self::appRoot(), null, $this->services);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
        self::assertSame(0, $this->services->probeRequests, 'The exposure probe is never asked for a file.');
        self::assertSame($this->treeBefore, self::tree(), 'Nothing is written outside the temporary folder.');
    }

    // T-E2E-1: Admin can create a project and a table.

    public function testAdminCanCreateAProjectAndATable(): void
    {
        $admin = $this->client();

        $home = $admin->get('/');
        self::assertSame(303, $home->status);
        self::assertSame('/setup', $home->header('Location'));

        $check = $admin->follow($home);
        self::assertSame(200, $check->status);
        self::assertSame('Set up TiffinDB - TiffinDB', HtmlAssertions::text($check->body, '//title'));
        $checks = $admin->texts('//main//table/tbody/tr/th');
        self::assertContains('PHP version', $checks, 'Step 1 lists checks.');
        self::assertContains('SQLite database support', $checks);
        self::assertContains('A place for the data folder', $checks);
        self::assertStringContainsString('Make the data folder', $check->body);
        self::assertSame([], array_values(array_diff((array) scandir($this->temp->path()), ['.', '..'])), 'Step 1 writes nothing.');

        $folder = $admin->submit('/setup/data-folder');
        self::assertSame(303, $folder->status);
        self::assertSame('/setup', $folder->header('Location'));

        $step3 = $admin->follow($folder);
        self::assertSame(200, $step3->status);
        $code = trim((string) file_get_contents($this->temp->path('setup-code.txt')));
        self::assertNotSame('', $code);

        $created = $admin->submit('/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::ADMIN_PASSWORD,
            'repeat' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(303, $created->status);
        self::assertSame('/projects', $created->header('Location'));
        self::assertNotNull($admin->cookie(Context::SESSION_COOKIE), 'The first Admin is signed in.');
        self::assertNull($admin->cookie(Context::CSRF_COOKIE), 'The anonymous CSRF cookie is deleted.');
        self::assertFileDoesNotExist($this->temp->path('setup-code.txt'));

        $projects = $admin->follow($created);
        self::assertSame(200, $projects->status);
        self::assertSame('Your projects', HtmlAssertions::text($projects->body, '//h1'));
        self::assertSame(
            'TiffinDB is ready. Select New project to make your first project.',
            HtmlAssertions::text($projects->body, '//main//*[@role="status"]'),
            'The setup notice survives the new session (first-Admin flash).',
        );
        self::assertStringEndsWith('Example Admin', HtmlAssertions::text($projects->body, '//header//a[@href="/account/password"]'));
        $again = $admin->get('/projects');
        self::assertSame(0, $admin->xpath()->query('//main//*[@role="status"]')->length, 'The notice is shown once.');
        self::assertSame(200, $again->status);

        // Create the project with its first table.
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => 'Home Inventory', 'first_table' => 'Items']);
        self::assertSame(303, $made->status);
        $tables = $admin->follow($made);
        self::assertSame(200, $tables->status);
        $tablesPath = (string) $made->header('Location');
        self::assertMatchesRegularExpression('#^/projects/[^/]+/tables\z#', $tablesPath);
        $projectPath = substr($tablesPath, 0, -strlen('/tables'));
        self::assertSame('Home Inventory', HtmlAssertions::text($tables->body, '//h1'));
        self::assertSame(
            'Home Inventory has been created, with the table Items.',
            HtmlAssertions::text($tables->body, '//main//*[@role="status"]'),
        );

        // Add the table Rooms.
        $admin->get($projectPath . '/tables/new');
        $rooms = $admin->submit($projectPath . '/tables', ['name' => 'Rooms', 'item_name' => 'room']);
        self::assertSame(303, $rooms->status);
        $roomsFields = $admin->follow($rooms);
        self::assertSame(200, $roomsFields->status);
        self::assertSame('The table Rooms has been created.', HtmlAssertions::text($roomsFields->body, '//main//*[@role="status"]'));

        // The tables page lists both tables.
        $list = $admin->get($tablesPath);
        self::assertSame(200, $list->status);
        self::assertSame(['Items', 'Rooms'], $admin->texts('//main//tbody/tr/th'));
        $itemsPath = (string) $admin->xpath()->query('//main//tbody/tr[th[normalize-space(.)="Items"]]/th/a/@href')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#^/projects/[^/]+/tables/[^/]+/fields\z#', $itemsPath);

        // Add the relation field Room, from Items to Rooms.
        $chooser = $admin->get($itemsPath . '/new');
        self::assertSame(200, $chooser->status);
        $details = $admin->get($itemsPath . '/new?type=relation');
        self::assertSame(200, $details->status);
        $target = (string) $admin->xpath()->query('//select[@name="opt_target"]/option[normalize-space(.)="Rooms"]/@value')->item(0)?->nodeValue;
        self::assertNotSame('', $target, 'Rooms is offered as the target.');
        $field = $admin->submit($itemsPath, ['name' => 'Room', 'opt_target' => $target, 'opt_this_max' => 'one', 'opt_other_max' => 'many']);
        self::assertSame(303, $field->status, $field->body);
        self::assertSame($itemsPath, $field->header('Location'));

        // The fields page lists Name and Room.
        $fields = $admin->follow($field);
        self::assertSame(200, $fields->status);
        self::assertSame('The field Room has been added.', HtmlAssertions::text($fields->body, '//main//*[@role="status"]'));
        self::assertSame(['Name', 'Room'], $admin->texts('//main//tbody/tr/th/a'));
        self::assertSame(['Name (shown as the title)', 'Room'], $admin->texts('//main//tbody/tr/th'));

        foreach ([$check, $step3, $projects, $tables, $roomsFields, $list, $chooser, $details, $fields] as $page) {
            self::assertScreen($page->body);
        }
    }

    // T-E2E-2: a Member sees only the shared project and cannot change it.

    public function testMemberSeesOnlyTheSharedProjectAndCannotEditIt(): void
    {
        $admin = $this->install();
        [$projectPath, $itemsPath] = $this->homeInventory($admin);
        $admin->get('/projects/new');
        $admin->follow($admin->submit('/projects', ['name' => 'Recipes', 'first_table' => 'Recipes']));

        // Add the Member, sharing nothing yet.
        $admin->get('/admin/people/new');
        $added = $admin->submit('/admin/people', [
            'display_name' => 'Example Member',
            'username' => 'member',
            'role' => 'member',
            'temporary_password' => self::MEMBER_TEMPORARY,
            'projects' => [],
        ]);
        self::assertSame(303, $added->status, $added->body);
        $person = $admin->follow($added);
        self::assertSame(200, $person->status);
        self::assertStringContainsString('Example Member has been added.', HtmlAssertions::text($person->body, '//main//*[@role="status"]'));

        // Share Home Inventory with them.
        $sharing = $admin->get($projectPath . '/sharing');
        self::assertSame(200, $sharing->status);
        $memberKey = (string) $admin->xpath()->query('//input[@name="members[]"]/@value')->item(0)?->nodeValue;
        self::assertNotSame('', $memberKey);
        $shared = $admin->submit($projectPath . '/sharing', ['members' => [$memberKey]]);
        self::assertSame(303, $shared->status);
        $after = $admin->follow($shared);
        self::assertSame('Sharing for Home Inventory has been saved.', HtmlAssertions::text($after->body, '//main//*[@role="status"]'));

        // The Member signs in and must change the temporary password.
        $member = $this->client();
        $member->get('/sign-in');
        $signedIn = $member->submit('/sign-in', ['username' => 'member', 'password' => self::MEMBER_TEMPORARY]);
        self::assertSame(303, $signedIn->status);
        self::assertSame('/account/password', $signedIn->header('Location'));
        $forced = $member->follow($signedIn);
        self::assertSame(200, $forced->status);
        self::assertStringContainsString('Before you go on, choose your own password.', $forced->body);
        $redirected = $member->get('/projects');
        self::assertSame(303, $redirected->status, 'Every other page leads to the password page.');
        self::assertSame('/account/password', $redirected->header('Location'));

        $member->get('/account/password');
        $oldCookie = (string) $member->cookie(Context::SESSION_COOKIE);
        $changed = $member->submit('/account/password', [
            'current' => self::MEMBER_TEMPORARY,
            'password' => self::MEMBER_PASSWORD,
            'repeat' => self::MEMBER_PASSWORD,
        ]);
        self::assertSame(303, $changed->status, $changed->body);
        self::assertSame('/projects', $changed->header('Location'));
        self::assertNotSame($oldCookie, $member->cookie(Context::SESSION_COOKIE), 'The session cookie is replaced.');

        // Only the shared project.
        $projects = $member->follow($changed);
        self::assertSame(200, $projects->status);
        self::assertSame('Your password has been changed.', HtmlAssertions::text($projects->body, '//main//*[@role="status"]'));
        self::assertSame(['Home Inventory'], $member->texts('//main//li[contains(@class, "project-card")]//h2'));
        self::assertStringNotContainsString('Recipes', $projects->body);
        self::assertStringNotContainsString('/projects/new', $projects->body);
        self::assertStringNotContainsString('/admin/people', $projects->body);

        // The tables page opens, without editing controls.
        $tables = $member->get($projectPath . '/tables');
        self::assertSame(200, $tables->status);
        self::assertScreen($tables->body);
        self::assertSame(['Items', 'Rooms'], $member->texts('//main//tbody/tr/th'));
        self::assertSame(0, $member->xpath()->query('//main//form | //main//div[@class="page-actions"] | //main//tbody//a')->length, 'No editing controls.');
        self::assertStringNotContainsString('/fields', $tables->body);
        self::assertStringNotContainsString('/sharing', $tables->body);
        self::assertStringNotContainsString($projectPath . '/settings', $tables->body);

        // The fields editor refuses the Member: a POST that passes the CSRF
        // check (the Member's own session token, read from the page) reaches
        // the controller, and the core refuses it.
        $token = (string) $member->xpath()->query('//form[@action="/sign-out"]/input[@name="_csrf"]/@value')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $token, 'The Member\'s CSRF token is on the page.');
        $refused = $member->post($itemsPath, ['_csrf' => $token, 'type' => 'text', 'name' => 'Colour']);
        self::assertSame(403, $refused->status);
        self::assertSame('You cannot do that here - TiffinDB', HtmlAssertions::text($refused->body, '//title'), 'The authorization 403, not the CSRF one.');
        $noCsrf = $member->post($itemsPath, ['type' => 'text', 'name' => 'Colour']);
        self::assertSame(403, $noCsrf->status, 'A POST without _csrf is refused too.');
        self::assertSame('This form has expired - TiffinDB', HtmlAssertions::text($noCsrf->body, '//title'), 'The CSRF 403.');
        self::assertSame(['Name', 'Room'], $this->fieldNames($admin, $itemsPath), 'Nothing was added.');
        self::assertSame(403, $member->get($itemsPath)->status);
        self::assertSame(403, $member->get('/admin/people')->status, 'People is not open to Members.');
    }

    // T-E2E-3: signing out ends the session; a POST without _csrf is refused.

    public function testSignOutEndsTheSessionAndCsrfIsRequired(): void
    {
        $admin = $this->install();
        [$projectPath] = $this->homeInventory($admin);

        $admin->get('/projects/new');
        $refused = $admin->post('/projects', ['name' => 'Garden Log', 'first_table' => '']);
        self::assertSame(403, $refused->status, 'A POST without _csrf is refused.');
        self::assertStringContainsString('<h1', $refused->body);
        $wrong = $admin->post('/projects', ['_csrf' => str_repeat('0', 64), 'name' => 'Garden Log']);
        self::assertSame(403, $wrong->status, 'A wrong _csrf is refused.');
        $admin->get('/projects');
        self::assertSame(['Home Inventory'], $admin->texts('//main//li[contains(@class, "project-card")]//h2'), 'Nothing was created.');

        $oldCookie = (string) $admin->cookie(Context::SESSION_COOKIE);
        self::assertNotSame('', $oldCookie);
        $out = $admin->submit('/sign-out');
        self::assertSame(303, $out->status);
        self::assertSame('/sign-in?signed-out=1', $out->header('Location'));
        self::assertNull($admin->cookie(Context::SESSION_COOKIE), 'The session cookie is deleted.');
        $signIn = $admin->follow($out);
        self::assertSame(200, $signIn->status);
        self::assertSame('You have signed out.', HtmlAssertions::text($signIn->body, '//main//*[@role="status"]'));

        // The old cookie no longer works, and is deleted again.
        $stale = $this->client();
        $stale->cookies[Context::SESSION_COOKIE] = $oldCookie;
        $page = $stale->get('/projects');
        self::assertSame(303, $page->status);
        self::assertSame('/sign-in', $page->header('Location'));
        self::assertSame(0, $page->cookie(Context::SESSION_COOKIE)?->maxAge, 'The stale cookie is deleted.');
        $stale->cookies[Context::SESSION_COOKIE] = $oldCookie;
        self::assertSame(303, $stale->get($projectPath . '/tables')->status);
        $stale->cookies[Context::SESSION_COOKIE] = $oldCookie;
        $post = $stale->post('/sign-out', []);
        self::assertSame(303, $post->status);
        self::assertSame('/sign-in', $post->header('Location'));
    }

    // T-E2E-4: five wrong passwords, then a wait.

    public function testFiveWrongPasswordsMakeThePersonWait(): void
    {
        $this->install();
        $guest = $this->client();
        $guest->get('/sign-in');

        $statuses = [];
        for ($try = 1; $try <= LoginThrottle::USER_LIMIT; $try++) {
            $statuses[] = $guest->submit('/sign-in', ['username' => 'admin', 'password' => 'not the password ' . $try])->status;
            self::assertNull($guest->cookie(Context::SESSION_COOKIE));
            $this->clock->advance(1);
        }
        self::assertSame([422, 422, 422, 422, 422], $statuses, 'Each wrong password is refused as wrong.');
        self::assertSame('That username and password do not match. Check them and try again.', HtmlAssertions::text($guest->page(), '//main//*[@role="alert"]'));

        $throttled = $guest->submit('/sign-in', ['username' => 'admin', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(429, $throttled->status, 'The right password does not sign in while the wait lasts.');
        self::assertNull($guest->cookie(Context::SESSION_COOKIE));
        $wait = (int) $throttled->header('Retry-After');
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(LoginThrottle::FIRST_WAIT, $wait);
        $message = HtmlAssertions::text($throttled->body, '//main//*[@role="alert"]');
        self::assertMatchesRegularExpression('/Too many tries\. Wait \d+ seconds?, then try again\./', $message);
        self::assertSame('admin', (string) $guest->xpath()->query('//input[@name="username"]/@value')->item(0)?->nodeValue, 'The username is kept.');
        self::assertSame('', (string) $guest->xpath()->query('//input[@name="password"]/@value')->item(0)?->nodeValue);
        self::assertScreen($throttled->body);

        // Still refused one second before the wait is over.
        $this->clock->advance($wait - 1);
        self::assertSame(429, $guest->submit('/sign-in', ['username' => 'admin', 'password' => self::ADMIN_PASSWORD])->status);
        self::assertNull($guest->cookie(Context::SESSION_COOKIE));

        // Once the clock passes the wait, the right password signs in.
        $this->clock->advance(LoginThrottle::MAX_WAIT);
        $signedIn = $guest->submit('/sign-in', ['username' => 'admin', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);
        self::assertSame('/projects', $signedIn->header('Location'));
        self::assertNotNull($guest->cookie(Context::SESSION_COOKIE));
        self::assertSame(200, $guest->follow($signedIn)->status);
    }

    // T-E2E-5 (M2): a Member can add, find, and edit records.

    public function testMemberAddsFindsAndEditsARecord(): void
    {
        $admin = $this->install();
        [$projectPath, $fieldsPath] = $this->homeInventory($admin);
        $this->addField($admin, $fieldsPath, 'choice', ['name' => 'Category', 'opt_choices' => "Appliance\nCookware\nTool"]);
        $this->addField($admin, $fieldsPath, 'date', ['name' => 'Purchased']);
        $this->addField($admin, $fieldsPath, 'currency', ['name' => 'Value', 'opt_code' => 'USD']);
        $this->addField($admin, $fieldsPath, 'checkbox', ['name' => 'Under warranty']);
        $member = $this->member($admin, $projectPath);
        $listPath = substr($fieldsPath, 0, -strlen('/fields'));

        // The project opens on its first table, empty.
        $open = $member->get($projectPath);
        self::assertSame(303, $open->status);
        self::assertSame($listPath, $open->header('Location'));
        $empty = $member->follow($open);
        self::assertSame(200, $empty->status);
        self::assertSame('Items', HtmlAssertions::text($empty->body, '//main//h1'));
        self::assertSame('No records yet', HtmlAssertions::text($empty->body, '//main//p[@class="empty-state-title"]'));
        self::assertScreen($empty->body);

        // Add two records through the new-record form.
        $new = $member->get($listPath . '/records/new');
        self::assertSame(200, $new->status);
        self::assertScreen($new->body);
        self::assertSame('New item', HtmlAssertions::text($new->body, '//h1'));
        $fields = $this->controlNames($member, ['Name', 'Category', 'Purchased', 'Value (US dollars)', 'Under warranty']);
        $added = $member->submit($listPath . '/records', [
            $fields['Name'] => 'Stand mixer',
            $fields['Category'] => 'Appliance',
            $fields['Purchased'] => '2025-03-14',
            $fields['Value (US dollars)'] => '329.00',
            $fields['Under warranty'] => '1',
        ]);
        self::assertSame(303, $added->status, $added->body);
        $recordPath = (string) $added->header('Location');
        self::assertMatchesRegularExpression('#^' . preg_quote($listPath, '#') . '/records/1\z#', $recordPath);
        $record = $member->follow($added);
        self::assertSame(200, $record->status);
        self::assertScreen($record->body);
        self::assertSame('The item has been added.', HtmlAssertions::text($record->body, '//main//*[@role="status"]'));
        self::assertSame('Stand mixer', HtmlAssertions::text($record->body, '//h1'));
        self::assertSame('Item 1 of 1. Last saved today at 10:00.', HtmlAssertions::text($record->body, '//div[@class="page-head"]//p[@class="lead"]'));
        $member->get($listPath . '/records/new');
        self::assertSame(303, $member->submit($listPath . '/records', [$fields['Name'] => 'Dutch oven', $fields['Category'] => 'Cookware'])->status);

        // Find it with q.
        $found = $member->get($listPath . '?q=mixer');
        self::assertSame(200, $found->status);
        self::assertScreen($found->body);
        self::assertSame('1 record matches out of 2, sorted by Name, A to Z', HtmlAssertions::text($found->body, '//main//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Stand mixer'], $member->texts('//main//table/tbody/tr/th'));
        $link = (string) $member->xpath()->query('//main//table/tbody/tr/th/a/@href')->item(0)?->nodeValue;
        self::assertSame($recordPath . '?q=mixer', $link, 'The title link carries the search.');

        // Open it and edit it with its version.
        $opened = $member->get($link);
        self::assertSame(200, $opened->status);
        self::assertScreen($opened->body);
        self::assertSame('Item 1 of 1. Last saved today at 10:00.', HtmlAssertions::text($opened->body, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', (string) $member->xpath()->query('//form[@id="record-form"]/input[@name="version"]/@value')->item(0)?->nodeValue);
        $saved = $member->submit($link, [$fields['Value (US dollars)'] => '299.00']);
        self::assertSame(303, $saved->status, $saved->body);
        self::assertSame($link, $saved->header('Location'), 'Back to the record with the list state.');
        $after = $member->follow($saved);
        self::assertSame('Your changes have been saved.', HtmlAssertions::text($after->body, '//main//*[@role="status"]'));
        self::assertSame('299.00', (string) $member->xpath()->query('//input[@name="' . $fields['Value (US dollars)'] . '"]/@value')->item(0)?->nodeValue);
        self::assertSame('Stand mixer', (string) $member->xpath()->query('//input[@name="' . $fields['Name'] . '"]/@value')->item(0)?->nodeValue, 'Fields not changed are kept.');

        // The list shows the change.
        $list = $member->get($listPath);
        self::assertSame(200, $list->status);
        self::assertScreen($list->body);
        self::assertSame('2 records, sorted by Name, A to Z', HtmlAssertions::text($list->body, '//main//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Dutch oven', 'Stand mixer'], $member->texts('//main//table/tbody/tr/th'));
        self::assertSame(['Name (A to Z)', 'Room', 'Category', 'Purchased', 'Value', 'Under warranty'], $member->texts('//main//table/thead/tr/th'));
        self::assertSame(['None', 'Appliance', '14 Mar 2025', '$299.00', 'Yes'], $member->texts('//main//table/tbody/tr[2]/td'));
    }

    // T-E2E-6 (M2): two sessions edit the same record.

    public function testSecondSaveOfTheSameRecordGetsTheConflictPage(): void
    {
        $admin = $this->install();
        [$projectPath, $fieldsPath] = $this->homeInventory($admin);
        $this->addField($admin, $fieldsPath, 'currency', ['name' => 'Value', 'opt_code' => 'USD']);
        $member = $this->member($admin, $projectPath);
        $listPath = substr($fieldsPath, 0, -strlen('/fields'));
        $member->get($listPath . '/records/new');
        $fields = $this->controlNames($member, ['Name', 'Value (US dollars)']);
        $added = $member->submit($listPath . '/records', [$fields['Name'] => 'Stand mixer', $fields['Value (US dollars)'] => '329.00']);
        self::assertSame(303, $added->status, $added->body);
        $recordPath = (string) $added->header('Location');

        // Both open the record; the Admin saves first.
        self::assertSame(200, $member->get($recordPath)->status);
        self::assertSame(200, $admin->get($recordPath)->status);
        self::assertSame(303, $admin->submit($recordPath, [$fields['Value (US dollars)'] => '250.00'])->status);

        $conflict = $member->submit($recordPath, [$fields['Value (US dollars)'] => '199.00']);
        self::assertSame(409, $conflict->status, $conflict->body);
        self::assertScreen($conflict->body);
        self::assertSame('This item was changed while you were editing it', HtmlAssertions::text($conflict->body, '//h1'));
        self::assertSame(
            'Example Admin saved a change today at 10:00, after you opened it. What you typed has not been saved yet.',
            HtmlAssertions::text($conflict->body, '//main/p[1] | //main/div/p[1]'),
        );
        self::assertSame(['Value', '$250.00', '199.00'], $member->texts('//table[contains(@class, "compare-table")]/tbody/tr/*'));
        self::assertSame([$recordPath], $member->texts('//main//a[normalize-space(.)="Keep the saved version"]/@href'));
        $x = $member->xpath();
        self::assertSame(
            (string) $x->query('//form[@action="/sign-out"]/input[@name="_csrf"]/@value')->item(0)?->nodeValue,
            (string) $x->query('//form[@action="' . $recordPath . '"]/input[@name="_csrf"]/@value')->item(0)?->nodeValue,
            'The re-post form carries the current session token.',
        );
        self::assertSame(['Save my version anyway'], $member->texts('//form[@action="' . $recordPath . '"]//button'));

        // "Save my version anyway" saves through the normal path.
        $overwrite = $member->submit($recordPath);
        self::assertSame(303, $overwrite->status, $overwrite->body);
        $after = $member->follow($overwrite);
        self::assertSame('Your changes have been saved.', HtmlAssertions::text($after->body, '//main//*[@role="status"]'));
        self::assertSame('199.00', (string) $member->xpath()->query('//input[@name="' . $fields['Value (US dollars)'] . '"]/@value')->item(0)?->nodeValue);
        $admin->get($recordPath);
        self::assertSame('199.00', (string) $admin->xpath()->query('//input[@name="' . $fields['Value (US dollars)'] . '"]/@value')->item(0)?->nodeValue, 'The Admin sees the saved version.');
    }

    // T-E2E-7 (M2): a version 1 project file is upgraded when first opened.

    public function testVersionOneProjectFileIsUpgradedThroughTheListRoute(): void
    {
        $admin = $this->install();
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => 'Home Inventory', 'first_table' => '']);
        self::assertSame(303, $made->status, $made->body);
        $projectPath = substr((string) $made->header('Location'), 0, -strlen('/tables'));
        $projectKey = substr($projectPath, strlen('/projects/'));
        $folder = $this->temp->path('projects/' . $projectKey);
        $file = $folder . '/project.tiffin';
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($file . $suffix)) {
                self::assertTrue(unlink($file . $suffix));
            }
        }
        self::buildVersionOne($file, $projectKey);
        self::assertSame(1, self::userVersion($file));
        self::assertSame([], self::snapshots($folder));

        $list = $admin->get($projectPath . '/tables/' . self::V1_ITEMS);
        self::assertSame(200, $list->status, $list->body);
        self::assertScreen($list->body);
        self::assertSame('Items', HtmlAssertions::text($list->body, '//main//h1'));
        self::assertSame('2 records, sorted by Name, A to Z', HtmlAssertions::text($list->body, '//main//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Mower', 'Toaster'], $admin->texts('//main//table/tbody/tr/th'));
        self::assertSame(['$329.00', '$29.99'], $admin->texts('//main//table/tbody/tr/td'));

        self::assertSame(4, self::userVersion($file), 'The file is at version 4.');
        $snapshots = self::snapshots($folder);
        self::assertCount(1, $snapshots, 'One format-upgrade snapshot.');
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z-format-upgrade-[a-z2-7]{4}\.tiffin\z/', $snapshots[0]);
        self::assertSame(1, self::userVersion($folder . '/snapshots/' . $snapshots[0]), 'The snapshot holds the version 1 file.');

        // A second open takes no snapshot.
        self::assertSame(200, $admin->get($projectPath . '/tables/' . self::V1_ITEMS)->status);
        self::assertCount(1, self::snapshots($folder));
    }

    // Helpers.

    /** The table key of the version 1 file's Items table (docs/formats/tiffin.md section 5). */
    private const V1_ITEMS = 'h3kq7vn2xbma';

    /**
     * Writes a version 1 project file from the first SQL block of
     * docs/formats/tiffin.md section 4 and the user-table statements of
     * section 5, with the document's synthetic keys: Items with Name (the
     * title) and Value (currency USD), two live records and one trashed.
     */
    // T-E2E-8 (M3): import a seeded CSV into a new table through the routes, then export it.

    public function testAdminImportsACsvIntoANewTableAndExportsTheSameBytes(): void
    {
        $admin = $this->install();
        [$projectPath] = $this->homeInventory($admin);
        $csv = (new IoFixture())->canonicalCsv(60, ['Kitchen', 'Garage', 'Study']);

        $report = $this->importCsv($admin, $projectPath, $csv, 'inventory.csv');
        self::assertSame('60 rows were imported into inventory.', HtmlAssertions::text($report->body, '//main//div[@class="notice"][@role="status"]/p'));
        self::assertContains('Records added 60', $admin->texts('//dl[@class="facts"]/div'));
        self::assertContains('Linked records added 3', $admin->texts('//dl[@class="facts"]/div'));
        self::assertContains('3 records were added to Rooms.', $admin->texts('//main/p'));
        $tablePath = (string) $admin->xpath()->query('//main//a[normalize-space(.)="Open the table"]/@href')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#^' . preg_quote($projectPath, '#') . '/tables/[a-z2-7]{12}\z#', $tablePath);

        // The records are in the list.
        $list = $admin->get($tablePath);
        self::assertSame(200, $list->status);
        self::assertScreen($list->body);
        self::assertSame('inventory', HtmlAssertions::text($list->body, '//h1'));
        self::assertStringStartsWith('60 records', HtmlAssertions::text($list->body, '//main//div[@class="page-head"]//p[@class="lead"]'));
        $names = array_map(static fn (array $row): string => $row[0], (new IoFixture())->canonicalRows(60, ['Kitchen', 'Garage', 'Study']));
        foreach ($admin->texts('//main//table/tbody/tr/th') as $title) {
            self::assertContains($title, $names);
        }

        // Export the table as CSV: the same bytes come back (T-RT-1 through the web).
        $export = $admin->get($projectPath . '/export');
        self::assertSame(200, $export->status);
        self::assertScreen($export->body);
        $tableKey = substr($tablePath, strrpos($tablePath, '/') + 1);
        $download = $admin->submit($projectPath . '/export', ['format' => 'export/csv', 'scope' => 'table', 'table' => $tableKey]);
        self::assertSame(200, $download->status, $download->body);
        self::assertTrue($download->isDownload());
        self::assertSame('text/csv; charset=utf-8', $download->header('Content-Type'));
        self::assertStringContainsString('filename="inventory.csv"', (string) $download->header('Content-Disposition'));
        self::assertSame($csv, $download->body(), 'CSV to project to CSV through the routes is byte-identical.');
        $admin->cleanUp();
    }

    // T-E2E-9 (M3): a commit that fails leaves the project file as it was.

    public function testAFailedImportLeavesTheProjectUnchanged(): void
    {
        $admin = $this->install();
        [$projectPath] = $this->homeInventory($admin);
        $csv = (new IoFixture())->canonicalCsv(40, ['Kitchen', 'Garage']);
        $jobPath = $this->reviewCsv($admin, $projectPath, $csv, 'inventory.csv');
        self::assertSame(303, $admin->submit($jobPath, ['action' => 'import'])->status);
        $this->continueToSave($admin, $jobPath);

        // The failure is injected into the project file (ImportOperations::commit
        // has no core seam into the committer): a trigger that raises an error
        // when the commit writes the import log row, after every other step.
        $file = $this->temp->path('projects/' . substr($projectPath, strlen('/projects/')) . '/project.tiffin');
        $db = Sqlite::open($file);
        $db->run('CREATE TRIGGER "injected_failure" AFTER INSERT ON "_tiffin_import_log" BEGIN SELECT RAISE(ABORT, \'injected failure\'); END');
        $db->close();
        $before = self::projectChecksum($file);

        // The failed save answers 303 to the job's address, which shows the
        // failure page (the commit error is logged, so the log check is off
        // for this one request).
        $admin->allowFailure = true;
        $saved = $admin->submit($jobPath . '/continue', ['step' => 'save']);
        $admin->allowFailure = false;
        self::assertSame(303, $saved->status, $saved->body);
        self::assertSame($jobPath, $saved->header('Location'));
        self::assertSame($before, self::projectChecksum($file), 'The project file holds exactly what it held before.');

        $failed = $admin->follow($saved);
        self::assertSame(200, $failed->status);
        self::assertScreen($failed->body);
        self::assertSame('The import did not finish - TiffinDB', HtmlAssertions::text($failed->body, '//title'));
        self::assertSame(['Nothing was saved. The project is exactly as it was before.', 'Saving the rows went wrong.'], $admin->texts('//main/p'));
        self::assertSame(['Start again'], $admin->texts('//main//form[@action="' . $jobPath . '/cancel"]//button'));
        $again = $admin->submit($jobPath . '/cancel');
        self::assertSame(303, $again->status);
        self::assertSame($projectPath . '/import', $again->header('Location'), 'Start again leads to step 1.');
        self::assertSame(200, $admin->follow($again)->status);
        $tables = $admin->get($projectPath . '/tables');
        self::assertSame(['Items', 'Rooms'], $admin->texts('//main//tbody/tr/th'), 'No table was added.');
        self::assertSame(200, $tables->status);
        $admin->cleanUp();
    }

    // T-E2E-10 (M3): archive export and import through the routes.

    public function testArchiveExportAndImportThroughTheRoutes(): void
    {
        $admin = $this->install();
        [$projectPath] = $this->homeInventory($admin);
        $member = $this->member($admin, $projectPath);
        $this->importCsv($admin, $projectPath, (new IoFixture())->canonicalCsv(45, ['Kitchen', 'Garage']), 'inventory.csv');
        $tablePath = (string) $admin->xpath()->query('//main//a[normalize-space(.)="Open the table"]/@href')->item(0)?->nodeValue;
        $admin->get($tablePath);
        $titles = $admin->texts('//main//table/tbody/tr/th');
        self::assertCount(45, $titles);

        $admin->get($projectPath . '/export');
        $archive = $admin->submit($projectPath . '/export/archive');
        self::assertSame(200, $archive->status, $archive->body);
        self::assertSame('application/zip', $archive->header('Content-Type'));
        self::assertStringContainsString('filename="home-inventory.tiffindb.zip"', (string) $archive->header('Content-Disposition'));
        $zip = $this->temp->path('home-inventory.tiffindb.zip');
        file_put_contents($zip, $archive->body());

        $form = $admin->get('/projects/import');
        self::assertSame(200, $form->status);
        self::assertScreen($form->body);
        $imported = $admin->upload('/projects/import', 'file', $zip, 'home-inventory.tiffindb.zip', ['name' => 'Home Inventory copy']);
        self::assertSame(303, $imported->status, $imported->body);
        $copyPath = (string) $imported->header('Location');
        self::assertMatchesRegularExpression('#^/projects/[a-z2-7]{12}#', $copyPath);
        self::assertStringStartsNotWith($projectPath, $copyPath);
        $landing = $admin->follow($imported);
        while ($landing->status === 303) {
            $landing = $admin->follow($landing);
        }
        self::assertSame(200, $landing->status);
        self::assertStringContainsString('Home Inventory copy', HtmlAssertions::text($landing->body, '//main//*[@role="status"]'));

        // The copy lists the same records.
        $copyKey = substr($copyPath, strlen('/projects/'), 12);
        $copyTable = '/projects/' . $copyKey . substr($tablePath, strlen($projectPath));
        self::assertSame(200, $admin->get($copyTable)->status);
        self::assertSame($titles, $admin->texts('//main//table/tbody/tr/th'), 'The same records, in the same order.');
        $admin->get('/projects');
        self::assertSame(['Home Inventory', 'Home Inventory copy'], $admin->texts('//main//li[contains(@class, "project-card")]//h2'));

        // The Member granted on the original does not see the copy.
        $member->get('/projects');
        self::assertSame(['Home Inventory'], $member->texts('//main//li[contains(@class, "project-card")]//h2'));
        self::assertSame(404, $member->get($copyTable)->status);
        self::assertSame(404, $member->get('/projects/' . $copyKey . '/tables')->status);
        $admin->cleanUp();
    }

    // T-E2E-11 (M4): relations and files through the routes.

    public function testMemberLinksUnlinksUploadsOpensAndRemovesThroughTheRoutes(): void
    {
        $admin = $this->install();
        [$projectPath, $fieldsPath, $items, $rooms, $warranties] = $this->homeInventoryM4($admin);
        $member = $this->member($admin, $projectPath);
        $kitchen = $this->addRecord($member, $rooms, 'Kitchen');
        $garage = $this->addRecord($member, $rooms, 'Garage');
        $this->addRecord($member, $warranties, 'Stand mixer, manufacturer warranty');

        // A new record shows the notes instead of the controls (M4 D-38).
        $member->get($items . '/records/new');
        self::assertSame(['You can choose one once this record is saved.', 'You can add files once this record is saved.', 'You can add links once this record is saved.'], array_values(array_filter(
            $member->texts('//form[@id="record-form"]//p[@class="help"]'),
            static fn (string $text): bool => str_starts_with($text, 'You can'),
        )));
        $mixer = $this->addRecord($member, $items, 'Stand mixer');
        $page = $member->get($mixer);
        self::assertScreen($page->body);
        self::assertSame(['No room chosen', 'Choose a record'], $member->texts('//div[@class="link-control"]/*'));

        // Choose a room through the picker, with a find.
        $picker = $this->href($member, '//div[@class="link-control"]/a[normalize-space(.)="Choose a record"]');
        self::assertMatchesRegularExpression('#^' . preg_quote($mixer, '#') . '/links/[a-z2-7]{12}\z#', $picker);
        $member->get($picker);
        self::assertScreen($member->page());
        self::assertSame('Room for Stand mixer', HtmlAssertions::text($member->page(), '//h1'));
        self::assertSame(['Garage', 'Kitchen'], $member->texts('//section[@aria-labelledby="pk-list"]//span[@class="picker-title"]'));
        $found = $member->get($picker . '?q=gar');
        self::assertSame(200, $found->status);
        self::assertSame(['Garage'], $member->texts('//section[@aria-labelledby="pk-list"]//span[@class="picker-title"]'));
        $chosen = $this->press($member, 'Choose Garage');
        self::assertSame(303, $chosen->status, $chosen->body);
        self::assertSame($picker . '?q=gar', $chosen->header('Location'), 'Back to the picker with the find kept.');
        $member->follow($chosen);
        self::assertSame('Garage is now chosen.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame(['Garage'], $member->texts('//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]'));
        $member->get($this->href($member, '//div[@class="form-actions"]/a[normalize-space(.)="Back to Stand mixer"]'));
        self::assertSame(['Garage', 'Choose a different room'], $member->texts('//div[@class="link-control"]/*'));
        self::assertSame($garage, $this->href($member, '//div[@class="link-control"]/a[@class="link-chosen"]'));
        self::assertNotSame($kitchen, $garage);

        // Link a warranty, then add a new one from the picker, then unlink the first.
        $warrantyPicker = $this->href($member, '//div[@class="control-actions"]/a[normalize-space(.)="Link a record"]');
        $member->get($warrantyPicker);
        self::assertSame(303, $this->press($member, 'Link Stand mixer, manufacturer warranty')->status);
        $member->get($warrantyPicker);
        self::assertSame(['Stand mixer, manufacturer warranty'], $member->texts('//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]'));
        $member->get($this->href($member, '//div[@class="form-actions"]/a[normalize-space(.)="Add a new warranty and link it"]'));
        self::assertScreen($member->page());
        self::assertSame('New warranty', HtmlAssertions::text($member->page(), '//h1'));
        $action = (string) $member->xpath()->query('//form[@id="record-form"]/@action')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('/^[a-z2-7]{12}-\d+\z/', (string) $member->xpath()->query('//form[@id="record-form"]/input[@name="from"]/@value')->item(0)?->nodeValue, 'The origin record and field.');
        $names = $this->controlNames($member, ['Name']);
        $made = $member->submit($action, [$names['Name'] => 'Extended cover']);
        self::assertSame(303, $made->status, $made->body);
        self::assertSame($warrantyPicker, $made->header('Location'), 'Back to the picker.');
        $member->follow($made);
        self::assertSame('Extended cover has been added and linked.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame(['Stand mixer, manufacturer warranty', 'Extended cover'], $member->texts('//section[@aria-labelledby="pk-now"]//span[@class="picker-title"]'));
        $member->get($mixer);
        self::assertSame(['Stand mixer, manufacturer warranty', 'Extended cover'], $member->texts('//ul[@class="link-list"]//div[@class="linked-main"]/a'));
        $unlinked = $this->press($member, 'Unlink Stand mixer, manufacturer warranty');
        self::assertSame(303, $unlinked->status, $unlinked->body);
        self::assertSame($mixer, $unlinked->header('Location'));
        $member->follow($unlinked);
        self::assertSame('Stand mixer, manufacturer warranty is no longer linked.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame(['Extended cover'], $member->texts('//ul[@class="link-list"]//div[@class="linked-main"]/a'));

        // Upload a photo; a PDF is refused by the images-only field.
        $member->get($this->href($member, '//div[@class="control-actions"]/a[normalize-space(.)="Add an image"]'));
        self::assertScreen($member->page());
        $upload = (string) $member->xpath()->query('//form[@enctype="multipart/form-data"]/@action')->item(0)?->nodeValue;
        $pdf = $this->temp->path('receipt.pdf');
        file_put_contents($pdf, FileFixture::pdf());
        $refused = $member->upload($upload, 'file', $pdf, 'receipt.pdf');
        self::assertSame(422, $refused->status);
        self::assertSame('This field takes images only: PNG, JPEG, GIF, or WebP.', HtmlAssertions::text($refused->body, '//main//p[@class="error-text"]'));
        $bytes = FileFixture::png(8, 6, 'e2e-stand-mixer');
        $png = $this->temp->path('stand-mixer.png');
        file_put_contents($png, $bytes);
        $added = $member->upload($upload, 'file', $png, 'stand-mixer.png');
        self::assertSame(303, $added->status, $added->body);
        self::assertSame($mixer, $added->header('Location'));
        $member->follow($added);
        self::assertSame('stand-mixer.png has been added.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        $src = (string) $member->xpath()->query('//li[contains(@class, "file-row")]/img/@src')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#^' . preg_quote($projectPath, '#') . '/files/\d+\z#', $src);
        $served = $member->get($src);
        self::assertSame(200, $served->status);
        self::assertSame('image/png', $served->header('Content-Type'));
        self::assertSame($bytes, $served->body());
        $sha = hash('sha256', $bytes);
        $stored = $this->temp->path('projects/' . substr($projectPath, strlen('/projects/')) . '/attachments/' . substr($sha, 0, 2) . '/' . $sha);
        self::assertFileExists($stored);

        // Open the file page, then remove the file through its confirmation.
        $member->get($this->href($member, '//a[@aria-label="Open stand-mixer.png"]'));
        self::assertScreen($member->page());
        self::assertSame('stand-mixer.png', HtmlAssertions::text($member->page(), '//h1'));
        self::assertSame($src, (string) $member->xpath()->query('//img[@class="file-image-large"]/@src')->item(0)?->nodeValue);
        $member->get($this->href($member, '//main//a[normalize-space(.)="Remove"]'));
        self::assertScreen($member->page());
        $removeAction = (string) $member->xpath()->query('//main//form[@method="post"]/@action')->item(0)?->nodeValue;
        $removed = $member->submit($removeAction);
        self::assertSame(303, $removed->status, $removed->body);
        $member->follow($removed);
        self::assertSame('stand-mixer.png has been removed.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame(0, $member->xpath()->query('//li[contains(@class, "file-row")]')->length);
        self::assertFileDoesNotExist($stored, 'The file nothing links to is deleted.');
        self::assertSame(404, $member->get($src)->status);
        $member->cleanUp();
    }

    // T-E2E-12 (M4): collections through the routes, kept by the archive.

    public function testCollectionsAreMadeViewedEditedDeletedAndKeptByTheArchive(): void
    {
        $admin = $this->install();
        [$projectPath, $fieldsPath, $items, $rooms] = $this->homeInventoryM4($admin);
        $this->addField($admin, $fieldsPath, 'currency', ['name' => 'Value', 'opt_code' => 'USD']);
        $member = $this->member($admin, $projectPath);
        $kitchen = $this->addRecord($member, $rooms, 'Kitchen');
        $this->addRecord($member, $rooms, 'Garage');
        $values = ['Stand mixer' => '329.00', 'Dutch oven' => '95.00', 'Cordless drill' => '149.00'];
        foreach ($values as $name => $value) {
            $record = $this->addRecord($member, $items, $name, ['Value (US dollars)' => $value]);
            if ($name !== 'Cordless drill') {
                $member->get($record . '/links/' . $this->fieldKey($admin, $fieldsPath, 'Room'));
                self::assertSame(303, $this->press($member, 'Choose Kitchen')->status);
            }
        }

        // New collection from the sidebar: name, a Room rule (field first, then the record), preview, save.
        $member->get($items);
        $new = $this->href($member, '//nav[contains(@class, "sidebar")]//a[normalize-space(.)="New collection"]');
        self::assertSame($items . '/collections/new', $new);
        $member->get($new);
        self::assertScreen($member->page());
        self::assertSame('New collection', HtmlAssertions::text($member->page(), '//h1'));
        $form = (string) $member->xpath()->query('//form[@id="collection-form"]/@action')->item(0)?->nodeValue;
        $room = $this->fieldKey($admin, $fieldsPath, 'Room');
        $value = $this->fieldKey($admin, $fieldsPath, 'Value');
        $shown = $member->submit($form, ['name' => 'Kitchen things', 'rules[1][field]' => $room, 'action' => 'preview']);
        self::assertSame(200, $shown->status, $shown->body);
        self::assertScreen($shown->body);
        $added = $member->submit($form, ['rules[1][test]' => 'links-to', 'rules[1][record]' => substr($kitchen, strrpos($kitchen, '/') + 1), 'action' => 'add']);
        self::assertSame(200, $added->status);
        self::assertSame(2, $member->xpath()->query('//fieldset[contains(@class, "rule-row")]')->length, 'Add a rule gives a second row.');
        $member->submit($form, ['rules[2][field]' => $value, 'action' => 'preview']);
        $preview = $member->submit($form, ['rules[2][test]' => 'more', 'rules[2][value]' => '100', 'action' => 'preview']);
        self::assertSame(200, $preview->status);
        self::assertSame(['Stand mixer'], $member->texts('//main//table/tbody/tr/th'), 'Preview: in the kitchen and worth more than 100.');
        $removedRule = $member->submit($form, ['action' => 'remove-2']);
        self::assertSame(200, $removedRule->status);
        self::assertSame(1, $member->xpath()->query('//fieldset[contains(@class, "rule-row")]')->length);
        $saved = $member->submit($form, ['action' => 'save']);
        self::assertSame(303, $saved->status, $saved->body);
        $view = (string) $saved->header('Location');
        self::assertMatchesRegularExpression('#^' . preg_quote($projectPath, '#') . '/collections/[a-z2-7]{12}\z#', $view);
        $member->follow($saved);
        self::assertScreen($member->page());
        self::assertSame('The collection has been saved.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame('Kitchen things', HtmlAssertions::text($member->page(), '//h1'));
        self::assertSame(['Dutch oven', 'Stand mixer'], $member->texts('//main//table/tbody/tr/th'));
        self::assertContains('Kitchen things 2', $member->texts('//nav[contains(@class, "sidebar")]//a'));

        // Edit: rename it.
        $member->get($view . '/edit');
        self::assertScreen($member->page());
        $renamed = $member->submit($view . '/edit', ['name' => 'In the kitchen', 'action' => 'save']);
        self::assertSame(303, $renamed->status, $renamed->body);
        self::assertSame($view, $renamed->header('Location'));
        $member->follow($renamed);
        self::assertSame('In the kitchen', HtmlAssertions::text($member->page(), '//h1'));

        // The archive keeps it: same rules, same records in the copy.
        $admin->get($projectPath . '/export');
        $archive = $admin->submit($projectPath . '/export/archive');
        self::assertSame(200, $archive->status, $archive->body);
        $zip = $this->temp->path('home.tiffindb.zip');
        file_put_contents($zip, $archive->body());
        $admin->get('/projects/import');
        $imported = $admin->upload('/projects/import', 'file', $zip, 'home.tiffindb.zip', ['name' => 'Home Inventory copy']);
        self::assertSame(303, $imported->status, $imported->body);
        $copyKey = substr((string) $imported->header('Location'), strlen('/projects/'), 12);
        $copyView = '/projects/' . $copyKey . substr($view, strlen($projectPath));
        self::assertSame(200, $admin->get($copyView)->status);
        self::assertScreen($admin->page());
        self::assertSame('In the kitchen', HtmlAssertions::text($admin->page(), '//h1'));
        self::assertSame(['Dutch oven', 'Stand mixer'], $admin->texts('//main//table/tbody/tr/th'));
        $admin->get($copyView . '/edit');
        self::assertSame([$room], array_values(array_filter($admin->texts('//select[@name="rules[1][field]"]/option[@selected]/@value'))));

        // Delete it through the confirmation.
        $member->get($view . '/delete');
        self::assertScreen($member->page());
        self::assertSame('Delete In the kitchen?', HtmlAssertions::text($member->page(), '//h1'));
        $deleted = $member->submit($view . '/delete');
        self::assertSame(303, $deleted->status, $deleted->body);
        self::assertSame($items, $deleted->header('Location'));
        $member->follow($deleted);
        self::assertSame('In the kitchen has been deleted.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame(404, $member->get($view)->status);
        self::assertSame(200, $admin->get($copyView)->status, 'The copy keeps its own.');
        $admin->cleanUp();
        $member->cleanUp();
    }

    // T-E2E-13 (M4): the trash screen with files.

    public function testTrashRestoreAndEmptyWithFilesThroughTheRoutes(): void
    {
        $admin = $this->install();
        [$projectPath, $fieldsPath, $items] = $this->homeInventoryM4($admin);
        $this->addField($admin, $fieldsPath, 'attachment', ['name' => 'Receipt']);
        $member = $this->member($admin, $projectPath);
        $mixer = $this->addRecord($member, $items, 'Stand mixer');
        $oven = $this->addRecord($member, $items, 'Dutch oven');
        $receipt = $this->fieldKey($admin, $fieldsPath, 'Receipt');
        $own = FileFixture::pdf() . "% stand mixer only\n";
        $shared = FileFixture::pdf() . "% shared\n";
        foreach ([[$mixer, $own, 'receipt.pdf'], [$mixer, $shared, 'guarantee.pdf'], [$oven, $shared, 'guarantee-copy.pdf']] as [$record, $bytes, $name]) {
            $member->get($record . '/files/' . $receipt . '/new');
            $path = $this->temp->path('upload-' . $name);
            file_put_contents($path, $bytes);
            self::assertSame(303, $member->upload($record . '/files/' . $receipt, 'file', $path, $name)->status);
        }
        $folder = $this->temp->path('projects/' . substr($projectPath, strlen('/projects/')) . '/attachments');
        $stored = static fn (string $bytes): string => $folder . '/' . substr(hash('sha256', $bytes), 0, 2) . '/' . hash('sha256', $bytes);
        self::assertFileExists($stored($own));
        self::assertFileExists($stored($shared));

        // Trash, then restore from the trash screen (reached from the sidebar).
        $member->get($mixer);
        self::assertSame(303, $member->submit($mixer . '/trash')->status);
        $member->get($items);
        $trash = $this->href($member, '//nav[contains(@class, "sidebar")]//a[contains(normalize-space(.), "In the trash")]');
        self::assertSame($projectPath . '/trash', $trash);
        $member->get($trash);
        self::assertScreen($member->page());
        self::assertSame(['Stand mixer Moved to the trash today by Example Member.'], $member->texts('//main//span[@class="trash-title"]'));
        self::assertSame(0, $member->xpath()->query('//main//a[normalize-space(.)="Empty the trash"]')->length, 'Members never see Empty the trash.');
        $restored = $this->press($member, 'Restore Stand mixer');
        self::assertSame(303, $restored->status, $restored->body);
        $member->follow($restored);
        self::assertSame('Stand mixer has been restored.', HtmlAssertions::text($member->page(), '//main//*[@role="status"]'));
        self::assertSame('The trash is empty.', HtmlAssertions::text($member->page(), '//main//p[@class="empty-state-title"]'));
        self::assertSame(403, $member->get($trash . '/empty')->status);

        // Trash again, empty as the Admin through the confirmation.
        $member->get($mixer);
        self::assertSame(303, $member->submit($mixer . '/trash')->status);
        $admin->get($trash);
        $admin->get($this->href($admin, '//main//a[normalize-space(.)="Empty the trash"]'));
        self::assertScreen($admin->page());
        self::assertSame('Empty the trash?', HtmlAssertions::text($admin->page(), '//h1'));
        self::assertContains('1 file that only these records hold will be deleted.', $admin->texts('//main//p'));
        $emptied = $admin->submit($trash . '/empty');
        self::assertSame(303, $emptied->status, $emptied->body);
        $admin->follow($emptied);
        self::assertSame('The trash has been emptied: 1 record and 1 file deleted.', HtmlAssertions::text($admin->page(), '//main//*[@role="status"]'));
        self::assertFileDoesNotExist($stored($own), 'The file only the emptied record held is gone.');
        self::assertFileExists($stored($shared), 'The file a live record also links stays.');
        self::assertSame(404, $admin->get($mixer)->status);
        $admin->get($oven);
        $open = $this->href($admin, '//a[@aria-label="Open guarantee-copy.pdf"]');
        $admin->get($open);
        $download = $admin->get($this->href($admin, '//main//a[normalize-space(.)="Download"]') . '?download=1');
        self::assertSame(200, $download->status);
        self::assertSame($shared, $download->body());
        $member->cleanUp();
    }

    // T-E2E-14 (M4): a version 3 project folder through the routes.

    public function testVersionThreeProjectFolderIsUpgradedAndItsCollectionsWork(): void
    {
        $admin = $this->install();
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => 'Home Inventory', 'first_table' => '']);
        self::assertSame(303, $made->status, $made->body);
        $projectPath = substr((string) $made->header('Location'), 0, -strlen('/tables'));
        $projectKey = substr($projectPath, strlen('/projects/'));
        $folder = $this->temp->path('projects/' . $projectKey);
        $file = $folder . '/project.tiffin';
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($file . $suffix)) {
                self::assertTrue(unlink($file . $suffix));
            }
        }
        $rows = M4Fixture::buildVersionThree($file, $projectKey, Fixtures::appRoot());
        self::assertSame(3, self::userVersion($file));
        self::assertSame([], self::snapshots($folder));

        $items = $projectPath . '/tables/' . M4Fixture::V3_ITEMS;
        $list = $admin->get($items);
        self::assertSame(200, $list->status, $list->body);
        self::assertScreen($list->body);
        self::assertSame(['Mower', 'Toaster'], $admin->texts('//main//table/tbody/tr/th'));
        self::assertSame(4, self::userVersion($file), 'Upgraded to version 4.');
        $snapshots = self::snapshots($folder);
        self::assertCount(1, $snapshots, 'After one format-upgrade snapshot.');
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z-format-upgrade-[a-z2-7]{4}\.tiffin\z/', $snapshots[0]);
        self::assertSame(3, self::userVersion($folder . '/snapshots/' . $snapshots[0]));

        // Its files are served; the trashed record's is not.
        $served = $admin->get($projectPath . '/files/' . $rows['toaster.png']['id']);
        self::assertSame(200, $served->status);
        self::assertSame($rows['toaster.png']['bytes'], $served->body());
        self::assertSame(404, $admin->get($projectPath . '/files/' . $rows['kettle.gif']['id'])->status);

        // The collections: none yet; make one through the editor and view it.
        self::assertSame(0, $admin->xpath()->query('//nav[contains(@class, "sidebar")]//h2[normalize-space(.)="Collections"]')->length);
        $admin->get($items);
        $admin->get($this->href($admin, '//nav[contains(@class, "sidebar")]//a[normalize-space(.)="New collection"]'));
        self::assertScreen($admin->page());
        $form = (string) $admin->xpath()->query('//form[@id="collection-form"]/@action')->item(0)?->nodeValue;
        $admin->submit($form, ['name' => 'With a photo', 'rules[1][field]' => M4Fixture::V3_FIELDS['Name'], 'action' => 'preview']);
        $saved = $admin->submit($form, ['rules[1][test]' => 'contains', 'rules[1][value]' => 'o', 'action' => 'save']);
        self::assertSame(303, $saved->status, $saved->body);
        $view = $admin->follow($saved);
        self::assertSame(200, $view->status);
        self::assertScreen($view->body);
        self::assertSame(['Mower', 'Toaster'], $admin->texts('//main//table/tbody/tr/th'));
        self::assertContains('With a photo 2', $admin->texts('//nav[contains(@class, "sidebar")]//a'));
        self::assertCount(1, self::snapshots($folder), 'No second snapshot.');
    }

    /**
     * Home Inventory (homeInventory) plus the table Warranties, the many
     * relation Warranties on Items, and an images-only attachment field
     * Photo.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} project, Items fields, Items, Rooms, Warranties paths
     */
    private function homeInventoryM4(EndToEndClient $admin): array
    {
        [$projectPath, $fieldsPath] = $this->homeInventory($admin);
        $admin->get($projectPath . '/tables/new');
        self::assertSame(303, $admin->submit($projectPath . '/tables', ['name' => 'Warranties', 'item_name' => 'warranty'])->status);
        $admin->get($fieldsPath . '/new?type=relation');
        $target = (string) $admin->xpath()->query('//select[@name="opt_target"]/option[normalize-space(.)="Warranties"]/@value')->item(0)?->nodeValue;
        $relation = $admin->submit($fieldsPath, ['name' => 'Warranties', 'opt_target' => $target, 'opt_this_max' => 'many', 'opt_other_max' => 'one']);
        self::assertSame(303, $relation->status, $relation->body);
        $this->addField($admin, $fieldsPath, 'attachment', ['name' => 'Photo', 'opt_images_only' => '1']);
        $admin->get($projectPath . '/tables');
        $table = fn (string $name): string => substr((string) $admin->xpath()->query('//main//tbody/tr[th[normalize-space(.)="' . $name . '"]]/th/a/@href')->item(0)?->nodeValue, 0, -strlen('/fields'));

        return [$projectPath, $fieldsPath, $table('Items'), $table('Rooms'), $table('Warranties')];
    }

    /**
     * Adds a record through the new-record form; returns its path.
     *
     * @param array<string, string> $more label => value
     */
    private function addRecord(EndToEndClient $client, string $table, string $name, array $more = []): string
    {
        self::assertSame(200, $client->get($table . '/records/new')->status);
        $names = $this->controlNames($client, ['Name', ...array_keys($more)]);
        $values = [$names['Name'] => $name];
        foreach ($more as $label => $value) {
            $values[$names[$label]] = $value;
        }
        $added = $client->submit($table . '/records', $values);
        self::assertSame(303, $added->status, $added->body);

        return (string) parse_url((string) $added->header('Location'), PHP_URL_PATH);
    }

    /** The key of the field named $name, from the fields page. */
    private function fieldKey(EndToEndClient $admin, string $fieldsPath, string $name): string
    {
        self::assertSame(200, $admin->get($fieldsPath)->status);
        $href = (string) $admin->xpath()->query('//main//tbody/tr/th/a[normalize-space(.)="' . $name . '"]/@href')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#/fields/[a-z2-7]{12}\z#', $href, "Field {$name}");

        return substr($href, -12);
    }

    /** The href of the one element $query finds on the last page. */
    private function href(EndToEndClient $client, string $query): string
    {
        $nodes = $client->xpath()->query($query . '/@href');
        self::assertSame(1, $nodes->length, $query);

        return (string) $nodes->item(0)?->nodeValue;
    }

    /**
     * Presses the submit button whose accessible name (aria-label, else
     * its text) is $name on the last page: posts its form (the enclosing
     * one, or the one its `form` attribute names) with the form's own
     * fields and the button's name and value, as a browser would.
     */
    private function press(EndToEndClient $client, string $name): Response
    {
        $x = $client->xpath();
        $buttons = $x->query('//button[@aria-label="' . $name . '" or (not(@aria-label) and normalize-space(.)="' . $name . '")]');
        self::assertSame(1, $buttons->length, "One button named {$name}.");
        $button = $buttons->item(0);
        self::assertInstanceOf(DOMElement::class, $button);
        $formId = $button->getAttribute('form');
        $form = $formId !== '' ? $x->query('//form[@id="' . $formId . '"]')->item(0) : $x->query('ancestor::form[1]', $button)->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $post = [];
        foreach ($x->query('.//input[@type="hidden"][@name]', $form) as $input) {
            /** @var DOMElement $input */
            $post[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        if ($button->getAttribute('name') !== '') {
            $post[$button->getAttribute('name')] = $button->getAttribute('value');
        }

        return $client->post($form->getAttribute('action'), $post);
    }

    /** Uploads $csv on step 1 of $projectPath's import; returns the job path (the review page is the last page). */
    private function reviewCsv(EndToEndClient $admin, string $projectPath, string $csv, string $name): string
    {
        $start = $admin->get($projectPath . '/import');
        self::assertSame(200, $start->status);
        self::assertScreen($start->body);
        $path = $this->temp->path('upload-' . $name);
        file_put_contents($path, $csv);
        $uploaded = $admin->upload($projectPath . '/import', 'file', $path, $name);
        self::assertSame(303, $uploaded->status, $uploaded->body);
        $jobPath = (string) $uploaded->header('Location');
        self::assertMatchesRegularExpression('#^' . preg_quote($projectPath, '#') . '/import/[A-Za-z0-9_-]+\z#', $jobPath);
        $review = $admin->follow($uploaded);
        self::assertSame(200, $review->status);
        self::assertScreen($review->body);
        self::assertSame('Check the fields - TiffinDB', HtmlAssertions::text($review->body, '//title'));

        return $jobPath;
    }

    /** Presses Continue on the progress page until "Save the rows" appears. */
    private function continueToSave(EndToEndClient $admin, string $jobPath): void
    {
        for ($i = 0; $i < 20; $i++) {
            $page = $admin->get($jobPath);
            self::assertSame(200, $page->status);
            self::assertScreen($page->body);
            if ($admin->xpath()->query('//form[@action="' . $jobPath . '/continue"]//input[@name="step"][@value="save"]')->length === 1) {
                self::assertSame(['Save the rows'], $admin->texts('//form[@action="' . $jobPath . '/continue"]//button'));

                return;
            }
            self::assertSame(303, $admin->submit($jobPath . '/continue')->status);
        }
        self::fail('The import never became ready to save.');
    }

    /** The whole import of $csv into a new table with the review's defaults; returns the report page. */
    private function importCsv(EndToEndClient $admin, string $projectPath, string $csv, string $name): Response
    {
        $jobPath = $this->reviewCsv($admin, $projectPath, $csv, $name);
        $accepted = $admin->submit($jobPath, ['action' => 'import']);
        self::assertSame(303, $accepted->status, $accepted->body);
        self::assertSame($jobPath, $accepted->header('Location'));
        $this->continueToSave($admin, $jobPath);
        $saved = $admin->submit($jobPath . '/continue', ['step' => 'save']);
        self::assertSame(303, $saved->status, $saved->body);
        self::assertSame($jobPath . '/report', $saved->header('Location'));
        $report = $admin->follow($saved);
        self::assertSame(200, $report->status);
        self::assertScreen($report->body);
        self::assertSame('Import report - TiffinDB', HtmlAssertions::text($report->body, '//title'));

        return $report;
    }

    /** SHA-256 of every table's rows and the schema of a project file, read with plain SQL. */
    private static function projectChecksum(string $file): string
    {
        $db = Sqlite::openUntrusted($file);
        try {
            $content = [$db->all('SELECT type, name, sql FROM sqlite_master ORDER BY type, name')];
            foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
                $rows = $db->all('SELECT * FROM "' . str_replace('"', '""', (string) $table['name']) . '"');
                sort($rows);
                $content[(string) $table['name']] = $rows;
            }
        } finally {
            $db->close();
        }

        return hash('sha256', serialize($content));
    }

    private static function buildVersionOne(string $file, string $projectKey): void
    {
        $time = '2026-11-03T09:00:00Z';
        $doc = (string) file_get_contents(Fixtures::appRoot() . '/docs/formats/tiffin.md');
        $block = static function (string $from, string $to) use ($doc): string {
            $start = strpos($doc, $from);
            $end = strpos($doc, $to, (int) $start);
            Assert::assertIsInt($start);
            Assert::assertIsInt($end);
            Assert::assertSame(1, preg_match('/```sql\n(.*?)```/s', substr($doc, $start, $end - $start), $match));

            return $match[1];
        };
        $statements = static fn (string $sql): array => array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
        $ddl = $statements($block('## 4. Metadata tables', '## 5. User tables'));
        $userDdl = $statements($block('## 5. User tables', '## 6. Field types'));
        Assert::assertCount(10, $ddl);
        Assert::assertCount(2, $userDdl);

        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA application_id = 1413891138');
        $pdo->exec('PRAGMA user_version = 1');
        $pdo->beginTransaction();
        foreach ([...$ddl, ...$userDdl] as $statement) {
            $pdo->exec($statement);
        }
        $insert = static function (string $sql, array $params) use ($pdo): void {
            $pdo->prepare($sql)->execute($params);
        };
        $meta = ['format' => 'tiffindb-project', 'format_version' => '1', 'project_key' => $projectKey, 'project_name' => 'Home Inventory',
            'created_at' => $time, 'created_by' => 'm2kq7vn3xbpa', 'created_with' => '0.1.0-dev', 'updated_at' => $time, 'updated_by' => 'm2kq7vn3xbpa'];
        foreach ($meta as $key => $value) {
            $insert('INSERT INTO "_tiffin_meta" ("key", "value") VALUES (?, ?)', [$key, $value]);
        }
        $pdo->exec('ALTER TABLE "t_h3kq7vn2xbma" ADD COLUMN "f_m4pz7k2qsw3n" TEXT');
        $pdo->exec('ALTER TABLE "t_h3kq7vn2xbma" ADD COLUMN "f_b7xk2mq4nz3p" INTEGER');
        $insert('INSERT INTO "_tiffin_tables" VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [self::V1_ITEMS, 't_' . self::V1_ITEMS, 'Items', 'item', 1, 'm4pz7k2qsw3n', $time, $time]);
        foreach ([['m4pz7k2qsw3n', 'Name', 'text', '{"max_length":500}', 1], ['b7xk2mq4nz3p', 'Value', 'currency', '{"code":"USD","minor_digits":2}', 2]] as [$key, $name, $type, $options, $position]) {
            $insert(
                'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "position", "created_at", "updated_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, self::V1_ITEMS, 'f_' . $key, $name, $type, $options, $position, $time, $time],
            );
        }
        foreach ([['Toaster', 2999, null], ['Mower', 32900, null], ['Old kettle', null, $time]] as [$name, $value, $trashed]) {
            $insert('INSERT INTO "t_h3kq7vn2xbma" ("_created_at", "_updated_at", "_trashed_at", "f_m4pz7k2qsw3n", "f_b7xk2mq4nz3p") VALUES (?, ?, ?, ?, ?)', [$time, $time, $trashed, $name, $value]);
        }
        $pdo->commit();
        $pdo = null;
    }

    private static function userVersion(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file);
        $version = (int) $pdo->query('PRAGMA user_version')?->fetchColumn();
        $pdo = null;

        return $version;
    }

    /** @return list<string> */
    private static function snapshots(string $folder): array
    {
        if (!is_dir($folder . '/snapshots')) {
            return [];
        }

        return array_values(array_filter((array) scandir($folder . '/snapshots'), static fn ($name): bool => is_string($name) && str_ends_with($name, '.tiffin')));
    }

    /** @param array<string, string> $values */
    private function addField(EndToEndClient $admin, string $fieldsPath, string $type, array $values): void
    {
        self::assertSame(200, $admin->get($fieldsPath . '/new?type=' . $type)->status);
        $added = $admin->submit($fieldsPath, $values);
        self::assertSame(303, $added->status, $added->body);
    }

    /**
     * Adds "Example Member", shares the project with them, and returns
     * their client, signed in with their own password.
     */
    private function member(EndToEndClient $admin, string $projectPath): EndToEndClient
    {
        $admin->get('/admin/people/new');
        $added = $admin->submit('/admin/people', [
            'display_name' => 'Example Member',
            'username' => 'member',
            'role' => 'member',
            'temporary_password' => self::MEMBER_TEMPORARY,
        ]);
        self::assertSame(303, $added->status, $added->body);
        $admin->get($projectPath . '/sharing');
        $memberKey = (string) $admin->xpath()->query('//input[@name="members[]"]/@value')->item(0)?->nodeValue;
        self::assertSame(303, $admin->submit($projectPath . '/sharing', ['members' => [$memberKey]])->status);

        $member = $this->client();
        $member->get('/sign-in');
        self::assertSame(303, $member->submit('/sign-in', ['username' => 'member', 'password' => self::MEMBER_TEMPORARY])->status);
        $member->get('/account/password');
        self::assertSame(303, $member->submit('/account/password', [
            'current' => self::MEMBER_TEMPORARY,
            'password' => self::MEMBER_PASSWORD,
            'repeat' => self::MEMBER_PASSWORD,
        ])->status);

        return $member;
    }

    /**
     * The `name` of the control each label names on the last page.
     *
     * @param list<string> $labels
     * @return array<string, string>
     */
    private function controlNames(EndToEndClient $client, array $labels): array
    {
        $names = [];
        $x = $client->xpath();
        foreach ($labels as $label) {
            $for = (string) $x->query('//label[normalize-space(.)="' . $label . '"]/@for')->item(0)?->nodeValue;
            $query = $for !== '' ? '//*[@id="' . $for . '"]/@name' : '//label[normalize-space(.)="' . $label . '"]//input/@name';
            $names[$label] = (string) $x->query($query)->item(0)?->nodeValue;
            self::assertMatchesRegularExpression('/^f_[a-z2-7]{12}\z/', $names[$label], "The control labelled {$label}.");
        }

        return $names;
    }

    private function client(): EndToEndClient
    {
        return new EndToEndClient($this->kernel);
    }

    /** Installs through the setup screens; returns the signed-in first Admin. */
    private function install(): EndToEndClient
    {
        $admin = $this->client();
        $admin->get('/setup');
        self::assertSame(303, $admin->submit('/setup/data-folder')->status);
        $admin->get('/setup');
        $code = trim((string) file_get_contents($this->temp->path('setup-code.txt')));
        $created = $admin->submit('/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => self::ADMIN_PASSWORD,
            'repeat' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(303, $created->status, $created->body);
        $admin->follow($created);

        return $admin;
    }

    /**
     * Creates Home Inventory with Items, Rooms, and the field Room.
     *
     * @return array{0: string, 1: string} The project path and the Items fields path.
     */
    private function homeInventory(EndToEndClient $admin): array
    {
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => 'Home Inventory', 'first_table' => 'Items']);
        self::assertSame(303, $made->status, $made->body);
        $projectPath = substr((string) $made->header('Location'), 0, -strlen('/tables'));
        $admin->get($projectPath . '/tables/new');
        self::assertSame(303, $admin->submit($projectPath . '/tables', ['name' => 'Rooms', 'item_name' => 'room'])->status);
        $admin->get($projectPath . '/tables');
        $itemsPath = (string) $admin->xpath()->query('//main//tbody/tr[th[normalize-space(.)="Items"]]/th/a/@href')->item(0)?->nodeValue;
        $admin->get($itemsPath . '/new?type=relation');
        $target = (string) $admin->xpath()->query('//select[@name="opt_target"]/option[normalize-space(.)="Rooms"]/@value')->item(0)?->nodeValue;
        $field = $admin->submit($itemsPath, ['name' => 'Room', 'opt_target' => $target, 'opt_this_max' => 'one', 'opt_other_max' => 'many']);
        self::assertSame(303, $field->status, $field->body);

        return [$projectPath, $itemsPath];
    }

    /** @return list<string> */
    private function fieldNames(EndToEndClient $admin, string $itemsPath): array
    {
        self::assertSame(200, $admin->get($itemsPath)->status);

        return $admin->texts('//main//tbody/tr/th/a');
    }

    private static function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
    }

    /** What the application root copy holds: the folders and file the kernel and the installer read below the root. */
    private const APP_COPY = ['public', 'templates', 'strings', 'config/app.php'];

    private static ?TempDir $appCopy = null;

    /**
     * The application root of these tests: a private copy of APP_COPY from
     * the repository, made once per process and removed at its end. The
     * kernel, CoreServices, and the installer resolve every path they
     * write from this root, so a write outside the data folder lands here
     * and tree() sees it; changes other processes make to the working tree
     * meanwhile do not.
     */
    public static function appRoot(): string
    {
        if (self::$appCopy === null) {
            $copy = TempDir::create('tiffindb-e2e-app-');
            foreach (self::APP_COPY as $relative) {
                self::copyTree(Fixtures::appRoot() . '/' . $relative, $copy->path($relative));
            }
            self::$appCopy = $copy;
            register_shutdown_function(static fn () => $copy->remove());
        }

        return self::$appCopy->path();
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0700, true);
        }
        if (is_file($from)) {
            if (!copy($from, $to)) {
                throw new \RuntimeException('Cannot copy ' . $from);
            }

            return;
        }
        mkdir($to, 0700);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            /** @var SplFileInfo $file */
            $target = $to . '/' . substr($file->getPathname(), strlen($from) + 1);
            if ($file->isDir()) {
                mkdir($target, 0700);
            } elseif (!copy($file->getPathname(), $target)) {
                throw new \RuntimeException('Cannot copy ' . $file->getPathname());
            }
        }
    }

    /**
     * Size, modification time, and SHA-256 of every file and directory of
     * the application root (appRoot()), to show that a run writes nothing
     * there.
     *
     * @return array<string, string>
     */
    private static function tree(): array
    {
        $root = self::appRoot();
        $entries = [];
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST) as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $entries[$relative] = $file->isDir()
                ? 'directory'
                : $file->getSize() . ' ' . $file->getMTime() . ' ' . hash_file('sha256', $file->getPathname());
        }
        ksort($entries);

        return $entries;
    }
}

/**
 * The real CoreServices behind the Services interface, with every call
 * delegated, except probe(): the setup controller asks for a probe on every
 * data-folder POST, so this one is handed out, and it only counts the
 * requests it is asked to make (and answers none) instead of opening a
 * socket. The end-to-end tests assert that the count stays 0.
 */
final class NoProbeServices implements Services
{
    public int $probeRequests = 0;

    public function __construct(private readonly CoreServices $inner)
    {
    }

    public function installState(): InstallState
    {
        return $this->inner->installState();
    }

    public function installer(): Installer
    {
        return $this->inner->installer();
    }

    public function operations(): Operations
    {
        return $this->inner->operations();
    }

    public function probe(): HttpProbe
    {
        $count = function (): void {
            $this->probeRequests++;
        };

        return new class ($count) implements HttpProbe {
            /** @param \Closure(): void $count */
            public function __construct(private readonly \Closure $count)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                ($this->count)();

                return null;
            }
        };
    }

    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
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

    public function now(): DateTimeImmutable
    {
        return $this->inner->now();
    }

    public function uploadLimits(): UploadLimits
    {
        return $this->inner->uploadLimits();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}

/**
 * A minimal browser over the kernel: keeps cookies, remembers the last
 * page, follows a redirect on request, and submits a page's form with the
 * fields the form itself carries (hidden fields, checked boxes, selected
 * options) overridden by the values given.
 */
final class EndToEndClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** Whether a response may write to the PHP log and be a 500 (a failure the test injects on purpose). */
    public bool $allowFailure = false;

    private string $page = '';

    /** @var list<string> */
    private array $uploads = [];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function get(string $uri): Response
    {
        return $this->send('GET', $uri, []);
    }

    /** @param array<string, string|list<string>> $post */
    public function post(string $uri, array $post): Response
    {
        return $this->send('POST', $uri, $post);
    }

    /** GET of a redirect's target. */
    public function follow(Response $redirect): Response
    {
        Assert::assertSame(303, $redirect->status, 'A redirect to follow.');
        $location = $redirect->header('Location');
        Assert::assertIsString($location);

        return $this->get($location);
    }

    /**
     * Submits the last page's POST form whose action is $action.
     *
     * @param array<string, string|list<string>> $values Field name (without `[]`) => value.
     */
    public function submit(string $action, array $values = []): Response
    {
        return $this->post($action, $this->formFields($action, $values));
    }

    /**
     * Submits the last page's multipart POST form whose action is $action
     * with the file at $path as the upload of field $field, named
     * $clientName, as PHP hands an accepted upload to the application
     * (the Request test seam: fromGlobals only builds files that pass
     * is_uploaded_file()). The file is copied first, so the core can move it.
     *
     * @param array<string, string|list<string>> $values
     */
    public function upload(string $action, string $field, string $path, string $clientName, array $values = []): Response
    {
        Assert::assertSame(1, $this->xpath()->query('//form[@method="post"][@action="' . $action . '"][@enctype="multipart/form-data"]//input[@type="file"][@name="' . $field . '"]')->length, "A multipart form posting to {$action} with the file input {$field}.");
        $copy = (string) tempnam(sys_get_temp_dir(), 'tiffindb-upload-');
        Assert::assertTrue(copy($path, $copy));
        $this->uploads[] = $copy;

        return $this->send('POST', $action, $this->formFields($action, $values), [$field => new UploadedFile($field, $clientName, (int) filesize($copy), UPLOAD_ERR_OK, $copy)]);
    }

    /** Removes the upload copies the core did not move away. */
    public function cleanUp(): void
    {
        foreach ($this->uploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->uploads = [];
    }

    /**
     * The fields a browser sends for the last page's POST form posting to
     * $action, overridden by $values; names with brackets (`include[0]`,
     * `setting[export/csv][record_ids]`) become nested arrays, as PHP builds
     * them.
     *
     * @param array<string, string|list<string>> $values
     * @return array<string, mixed>
     */
    private function formFields(string $action, array $values): array
    {
        $xpath = $this->xpath();
        $forms = $xpath->query('//form[@method="post"][@action="' . $action . '"]');
        Assert::assertGreaterThan(0, $forms->length, "The page has a form posting to {$action}.");
        $form = $forms->item(0);
        Assert::assertInstanceOf(DOMElement::class, $form);

        $fields = [];
        $present = [];
        $id = $form->getAttribute('id');
        foreach ($xpath->query('//button[@name][@form="' . $id . '"] | .//button[@name]', $form) as $button) {
            /** @var DOMElement $button */
            // A named submit button sends its value only when pressed: pass it in $values.
            $present[$button->getAttribute('name')] = true;
        }
        foreach ($xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $control) {
            /** @var DOMElement $control */
            $name = $control->getAttribute('name');
            $list = str_ends_with($name, '[]');
            $key = $list ? substr($name, 0, -2) : $name;
            $present[$key] = true;
            $value = self::controlValue($control);
            if ($value === null) {
                if ($list) {
                    $fields[$key] ??= [];
                }
                continue;
            }
            if ($list) {
                $existing = $fields[$key] ?? [];
                $fields[$key] = array_merge(is_array($existing) ? $existing : [], [$value]);
            } else {
                $fields[$key] = $value;
            }
        }
        foreach ($values as $name => $value) {
            // A control that sends nothing as served (an unticked box) can still be set.
            Assert::assertArrayHasKey($name, $present, "The form posting to {$action} has a field {$name}.");
            $fields[$name] = $value;
        }
        foreach ($fields as $name => $value) {
            if (str_contains($name, '[')) {
                unset($fields[$name]);
                parse_str(http_build_query([$name => $value]), $nested);
                $fields = array_replace_recursive($fields, $nested);
            }
        }

        return $fields;
    }

    /** The body of the last page that was not a redirect. */
    public function page(): string
    {
        return $this->page;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function xpath(): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($this->page));
    }

    /** @return list<string> Normalised text of each node $query finds in the last page. */
    public function texts(string $query): array
    {
        $texts = [];
        foreach ($this->xpath()->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }

    /** The value a browser would send for a control, or null for none. */
    private static function controlValue(DOMElement $control): ?string
    {
        if ($control->nodeName === 'select') {
            $first = null;
            foreach ($control->getElementsByTagName('option') as $option) {
                $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
                $first ??= $value;
                if ($option->hasAttribute('selected')) {
                    return $value;
                }
            }

            return $first;
        }
        if ($control->nodeName === 'textarea') {
            return $control->textContent;
        }
        $type = strtolower($control->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
            return null;
        }
        if ($type === 'checkbox' || $type === 'radio') {
            return $control->hasAttribute('checked') ? ($control->hasAttribute('value') ? $control->getAttribute('value') : 'on') : null;
        }

        return $control->getAttribute('value');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, UploadedFile> $files
     */
    private function send(string $method, string $uri, array $post, array $files = []): Response
    {
        $query = [];
        $queryString = parse_url('http://tiffindb.example' . $uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => EndToEndTest::appRoot() . '/public',
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'tiffindb.example',
            'SERVER_PORT' => '80',
        ];
        if ($method === 'POST') {
            $server['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        }
        $request = Request::fromGlobals($server, $query, $post, $this->cookies);
        if ($files !== []) {
            $request = new Request(
                $request->method, $request->path, $request->basePath, $request->query, $request->malformed, $request->post,
                $request->cookies, $request->secure, $request->clientAddress, $request->secFetchSite, $request->host,
                $request->serverAddress, $request->serverPort, $request->documentRoot, $request->origin, $files, $request->contentLength,
            );
        }
        [$response, $log] = Fixtures::handle($this->kernel, $request);
        if (!$this->allowFailure) {
            Assert::assertSame('', $log, "No log output for {$method} {$uri}.");
            Assert::assertNotSame(500, $response->status, "{$method} {$uri} does not fail.");
        }

        foreach ($response->cookies as $cookie) {
            if ($cookie->maxAge === 0) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }
        if ($response->status !== 303 && !$response->isDownload()) {
            $this->page = $response->body;
        }

        return $response;
    }
}
