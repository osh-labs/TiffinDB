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
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Context;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\Services;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-E2E-1 to T-E2E-4 (M1 spec part 2 section 9): the application driven
 * through the kernel with the real route table, templates, strings, and the
 * real CoreServices (a FixedClock, a SeededRandom, and a bcrypt cost 4
 * PasswordHasher) over an empty temporary data folder. DOCUMENT_ROOT is the
 * repository's public/ folder, so the data folder is outside it and no
 * exposure probe runs: the kernel gets the CoreServices through
 * NoProbeServices, which delegates every call but hands out a probe that
 * only counts its requests, and each test asserts that the count stays 0,
 * so no socket is opened. Forms are read from the served pages
 * and submitted with their own fields, as a browser would. After each test,
 * every file of the repository (except vendor/, .git, .claude/, and
 * .phpunit.cache/) has the same size, modification time, and hash as
 * before. Synthetic data only.
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
            Fixtures::appRoot(),
            $this->clock,
            new SeededRandom('tiffindb-end-to-end-seed'),
            new PasswordHasher(['bcrypt' => 4]),
        );
        $this->services = new NoProbeServices($services);
        $this->kernel = new Kernel($config, Fixtures::appRoot(), null, $this->services);
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
        self::assertStringNotContainsString('/settings', $tables->body);

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

    // Helpers.

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

    /** Top-level entries the tree check skips: dependencies, git metadata, agent files, PHPUnit's cache. */
    private const TREE_EXCLUDED = ['vendor', '.git', '.claude', '.phpunit.cache'];

    /**
     * Size, modification time, and SHA-256 of every file and directory in
     * the repository except TREE_EXCLUDED, to show that a run writes
     * nothing there.
     *
     * @return array<string, string>
     */
    private static function tree(): array
    {
        $root = Fixtures::appRoot();
        $entries = [];
        $directory = new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file, string $path, RecursiveDirectoryIterator $iterator): bool
                => $iterator->getSubPath() !== '' || !in_array($file->getFilename(), self::TREE_EXCLUDED, true),
        );
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

    private string $page = '';

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
        $xpath = $this->xpath();
        $forms = $xpath->query('//form[@method="post"][@action="' . $action . '"]');
        Assert::assertGreaterThan(0, $forms->length, "The page has a form posting to {$action}.");
        $form = $forms->item(0);
        Assert::assertInstanceOf(DOMElement::class, $form);

        $fields = [];
        foreach ($xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $control) {
            /** @var DOMElement $control */
            $name = $control->getAttribute('name');
            $list = str_ends_with($name, '[]');
            $key = $list ? substr($name, 0, -2) : $name;
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
            Assert::assertArrayHasKey($name, $fields, "The form posting to {$action} has a field {$name}.");
            $fields[$name] = $value;
        }

        return $this->post($action, $fields);
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

    /** @param array<string, string|list<string>> $post */
    private function send(string $method, string $uri, array $post): Response
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
            'DOCUMENT_ROOT' => Fixtures::appRoot() . '/public',
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'tiffindb.example',
            'SERVER_PORT' => '80',
        ];
        if ($method === 'POST') {
            $server['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        }
        $request = Request::fromGlobals($server, $query, $post, $this->cookies);
        [$response, $log] = Fixtures::handle($this->kernel, $request);
        Assert::assertSame('', $log, "No log output for {$method} {$uri}.");
        Assert::assertNotSame(500, $response->status, "{$method} {$uri} does not fail.");

        foreach ($response->cookies as $cookie) {
            if ($cookie->maxAge === 0) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }
        if ($response->status !== 303) {
            $this->page = $response->body;
        }

        return $response;
    }
}
