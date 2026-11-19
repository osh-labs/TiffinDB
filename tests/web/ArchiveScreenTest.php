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
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\ArchiveController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadedFile;
use ZipArchive;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-45 (M3-WP10): importing a project archive
 * (docs/design/m3/archive.md) through the kernel with the real templates,
 * strings, and core operations. Uploads are supplied through the Request
 * test seam (a file left where PHP would leave it). Synthetic data only.
 */
#[CoversClass(ArchiveController::class)]
final class ArchiveScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const OTHER_ADMIN = 'other-admin-session-token-00000000000000000000000000000000000000';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private M2Fixture $m2;
    private string $archive;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->m2 = M2Fixture::build($this->core, $this->ops);
        $this->core->addPerson('admin2', 'Second Admin', Role::Admin);
        $admin = $this->core->actor('admin');
        $file = $this->ops->exports()->archive($admin, $this->m2->project);
        $this->archive = (string) file_get_contents($file->path);
        $this->ops->exports()->finish($admin, $this->m2->project, $file->jobKey);

        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->services->sessions[self::OTHER_ADMIN] = $this->signedIn('admin2');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testForm(): void
    {
        $html = $this->page(self::ADMIN, '/projects/import');
        $x = $this->xpath($html);

        self::assertSame('Import a project archive - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Import a project archive', HtmlAssertions::text($html, '//h1'));
        self::assertSame(1, $x->query('//main[@class="page page-io"]')->length);
        self::assertSame(['Projects', 'Import a project archive'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        $form = '//form[@method="post"][@enctype="multipart/form-data"][@action="/projects/import"]';
        self::assertSame(1, $x->query($form)->length);
        self::assertSame('.zip', $x->query($form . '//input[@type="file"][@name="file"]/@accept')->item(0)?->nodeValue);
        self::assertSame('help-file', $x->query('//input[@id="f-file"]/@aria-describedby')->item(0)?->nodeValue);
        self::assertStringStartsWith('A .zip file made by TiffinDB, up to ', HtmlAssertions::text($html, '//p[@id="help-file"]'));
        self::assertSame('Name for the new project (optional)', HtmlAssertions::text($html, '//label[@for="f-name"]'));
        self::assertSame('80', $x->query('//input[@id="f-name"]/@maxlength')->item(0)?->nodeValue);
        self::assertSame(['Import'], $this->texts($x, '//div[@class="io-actions"]/button'));
        self::assertSame('/projects', $x->query('//div[@class="io-actions"]/a/@href')->item(0)?->nodeValue);
        self::assertStringContainsString('section 13.4', $html);
    }

    public function testImportWithANameRedirectsWithTheNotice(): void
    {
        // A project without attachments rebuilds without any change.
        $admin = $this->core->actor('admin');
        $recipes = $this->ops->projects()->create($admin, 'Recipes', 'Dishes')->key;
        $file = $this->ops->exports()->archive($admin, $recipes);
        $zip = (string) file_get_contents($file->path);
        $this->ops->exports()->finish($admin, $recipes, $file->jobKey);

        $response = $this->upload(self::ADMIN, '/projects/import', $zip, 'recipes.tiffindb.zip', ['name' => 'Restored <i>Recipes</i>']);
        self::assertSame(303, $response->status);
        $key = $this->keyOf('Restored <i>Recipes</i>');
        self::assertSame('/projects/' . $key . '/tables', $response->header('Location'));
        self::assertSame(['key' => 'notice.archive_imported', 'params' => ['name' => 'Restored <i>Recipes</i>']], $this->services->flash[self::ADMIN] ?? null);

        $html = $this->page(self::ADMIN, '/projects/' . $key . '/tables');
        self::assertSame('The project Restored <i>Recipes</i> has been made from the archive.', HtmlAssertions::text($html, '//div[@class="notice"]/p'));
        self::assertStringNotContainsString('<i>Recipes', $html);
        // Nobody else was given the new project.
        self::assertSame(404, $this->request(self::MEMBER, 'GET', '/projects/' . $key . '/tables')->status);
    }

    public function testNameTakenThenRenamed(): void
    {
        $response = $this->upload(self::ADMIN, '/projects/import', $this->archive, '<b>home</b>.zip', []);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('Another project already has this name.', HtmlAssertions::text($response->body, '//p[@id="err-name"]'));
        // The core does not return the archive's stored name, so the field shows what was posted.
        self::assertSame('', $x->query('//input[@id="f-name"]/@value')->item(0)?->nodeValue);
        self::assertSame(0, $x->query('//input[@type="file"]')->length);
        self::assertSame(0, $x->query('//form[@enctype]')->length);
        self::assertSame('The archive <b>home</b>.zip has been checked.', HtmlAssertions::text($response->body, '//main//form/p[1]'));
        self::assertStringNotContainsString('<b>home', $response->body);
        $action = (string) $x->query('//main//form/@action')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('#^/projects/import/[A-Za-z0-9]+\z#', $action);
        self::assertStringEndsWith('#f-name', (string) $x->query('//div[@class="error-summary"]//a/@href')->item(0)?->nodeValue);

        // Another Admin, a Member, and a forged key cannot use the kept archive.
        self::assertSame(404, $this->request(self::OTHER_ADMIN, 'POST', $action, ['name' => 'Taken over'])->status);
        self::assertSame(403, $this->request(self::MEMBER, 'POST', $action, ['name' => 'Taken over'])->status);
        self::assertSame(404, $this->request(self::ADMIN, 'POST', '/projects/import/zzzzzzzzzzzz', ['name' => 'Forged'])->status);
        self::assertContains($this->request(self::ADMIN, 'GET', $action)->status, [404, 405]);

        // Still taken: the same page again.
        $again = $this->request(self::ADMIN, 'POST', $action, ['name' => 'Home Inventory', 'file_name' => "x\u{0007}.zip"]);
        self::assertSame(422, $again->status);
        self::assertSame('The archive x.zip has been checked.', HtmlAssertions::text($again->body, '//main//form/p[1]'));

        $done = $this->request(self::ADMIN, 'POST', $action, ['name' => 'Second Inventory']);
        self::assertSame(303, $done->status);
        self::assertSame('/projects/' . $this->keyOf('Second Inventory') . '/tables', $done->header('Location'));
        // The job is gone once used.
        self::assertSame(404, $this->request(self::ADMIN, 'POST', $action, ['name' => 'Third Inventory'])->status);
    }

    public function testHiddenCharactersAreRemovedFromTheShownFileName(): void
    {
        $response = $this->upload(self::ADMIN, '/projects/import', $this->archive, "a\u{202E}piz.exe", []);
        self::assertSame(422, $response->status);
        self::assertSame('The archive apiz.exe has been checked.', HtmlAssertions::text($response->body, '//main//form/p[1]'));
        $action = (string) $this->xpath($response->body)->query('//main//form/@action')->item(0)?->nodeValue;

        // A forged hidden file_name with bidi, zero-width, byte order mark, soft hyphen, separators, and a tag character.
        $forged = "ok\u{202E}name\u{200B}\u{FEFF}\u{00AD}\u{2028}\u{2029}\u{2066}\u{E0041}.zip";
        $again = $this->request(self::ADMIN, 'POST', $action, ['name' => 'Home Inventory', 'file_name' => $forged]);
        self::assertSame(422, $again->status);
        self::assertSame('The archive okname.zip has been checked.', HtmlAssertions::text($again->body, '//main//form/p[1]'));
        self::assertSame('okname.zip', $this->xpath($again->body)->query('//input[@name="file_name"]/@value')->item(0)?->nodeValue);
        foreach (["\u{202E}", "\u{200B}", "\u{FEFF}", "\u{00AD}", "\u{2028}", "\u{2029}", "\u{2066}", "\u{E0041}"] as $hidden) {
            self::assertStringNotContainsString($hidden, $again->body);
        }
        // The joiners stay, as the core keeps them in client names.
        $joined = $this->request(self::ADMIN, 'POST', $action, ['name' => 'Home Inventory', 'file_name' => "a\u{200D}b.zip"]);
        self::assertSame("The archive a\u{200D}b.zip has been checked.", HtmlAssertions::text($joined->body, '//main//form/p[1]'));
    }

    public function testChangesAreListedInTheNotice(): void
    {
        // The synthetic data set's attachments have no files on disk: the rebuild reports them missing.
        $response = $this->upload(self::ADMIN, '/projects/import', $this->archive, 'a.zip', ['name' => 'Without Files']);
        self::assertSame(303, $response->status);
        $flash = $this->services->flash[self::ADMIN] ?? null;
        self::assertSame('notice.archive_imported_changes', $flash['key'] ?? null);
        self::assertMatchesRegularExpression('/^\d+ files? missing$/', (string) ($flash['params']['list'] ?? ''));

        $html = $this->page(self::ADMIN, (string) $response->header('Location'));
        self::assertMatchesRegularExpression(
            "/^The project Without Files has been made from the archive. Some things were changed to fit TiffinDB's rules: \\d+ files? missing\\.$/",
            HtmlAssertions::text($html, '//div[@class="notice"]/p'),
        );
    }

    public function testUploadProblems(): void
    {
        $path = $this->core->upload($this->archive);
        foreach (
            [
                'no file' => [new UploadedFile('file', '', 0, UPLOAD_ERR_NO_FILE, null), 'Choose a file.'],
                'too large' => [new UploadedFile('file', 'a.zip', 0, UPLOAD_ERR_INI_SIZE, null), 'That file is larger than '],
                'partial' => [new UploadedFile('file', 'a.zip', 10, UPLOAD_ERR_PARTIAL, null), 'The file did not arrive completely. Try again.'],
                'other field' => [new UploadedFile('other', 'a.zip', strlen($this->archive), UPLOAD_ERR_OK, $path), 'Choose a file.'],
            ] as $case => [$file, $text]
        ) {
            $response = $this->handleUpload(self::ADMIN, '/projects/import', [$file->field => $file], ['name' => '"><i>x</i>']);
            self::assertSame(422, $response->status, $case);
            $this->assertScreen($response->body);
            self::assertStringStartsWith($text, HtmlAssertions::text($response->body, '//p[@id="err-file"]'), $case);
            self::assertSame('true', $this->xpath($response->body)->query('//input[@id="f-file"]/@aria-invalid')->item(0)?->nodeValue, $case);
            self::assertSame('"><i>x</i>', $this->xpath($response->body)->query('//input[@id="f-name"]/@value')->item(0)?->nodeValue, $case);
            self::assertStringNotContainsString('<i>x</i>', $response->body, $case);
        }

        // A urlencoded POST (the wrong content type) carries no file.
        $plain = $this->request(self::ADMIN, 'POST', '/projects/import', ['name' => 'x', 'file' => 'not a file']);
        self::assertSame(422, $plain->status);
        self::assertSame('Choose a file.', HtmlAssertions::text($plain->body, '//p[@id="err-file"]'));
        self::assertSame(1, count($this->ops->projects()->listFor($this->core->actor('admin'))));
    }

    public function testEachArchiveProblemIsShown(): void
    {
        $manifest = static function (ZipArchive $z): string {
            return (string) $z->getFromName('tiffindb-archive.json');
        };
        $cases = [
            'not a zip' => 'This is not a zip file at all.',
            'entry_name' => $this->rewrite($this->archive, static fn (ZipArchive $z) => $z->addFromString('evil.php', '<?php')),
            'missing_entry' => $this->rewrite($this->archive, static fn (ZipArchive $z) => $z->deleteName('project.tiffin')),
            'manifest' => $this->rewrite($this->archive, static function (ZipArchive $z) use ($manifest): void {
                $z->addFromString('tiffindb-archive.json', str_replace('"project_size":', '"project_size": 1, "x":', $manifest($z)));
            }),
            'not_sqlite' => $this->rewrite($this->archive, static fn (ZipArchive $z) => $z->addFromString('project.tiffin', 'not a database')),
        ];
        $archiveTexts = array_filter(require Fixtures::appRoot() . '/strings/en/problems.php', static fn (string $k): bool => str_starts_with($k, 'problem.archive.'), ARRAY_FILTER_USE_KEY);
        $seen = [];
        foreach ($cases as $case => $zip) {
            $response = $this->upload(self::ADMIN, '/projects/import', $zip, 'x.zip', ['name' => 'Refused']);
            self::assertSame(422, $response->status, $case);
            $this->assertScreen($response->body);
            $text = HtmlAssertions::text($response->body, '//p[@id="err-file"]');
            self::assertContains($text, $archiveTexts, $case);
            self::assertStringEndsWith('#f-file', (string) $this->xpath($response->body)->query('//div[@class="error-summary"]//a/@href')->item(0)?->nodeValue, $case);
            $seen[$text] = true;
        }
        self::assertGreaterThanOrEqual(3, count($seen), 'Different refusals show different texts.');
        self::assertSame(1, count($this->ops->projects()->listFor($this->core->actor('admin'))), 'Nothing was registered.');
    }

    public function testMembersAreRefused(): void
    {
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->m2->project, [$this->core->person('member')->key]);
        self::assertSame(403, $this->request(self::MEMBER, 'GET', '/projects/import')->status);
        self::assertSame(403, $this->upload(self::MEMBER, '/projects/import', $this->archive, 'a.zip', ['name' => 'Member copy'])->status);
        $bad = $this->handleUpload(self::MEMBER, '/projects/import', ['file' => new UploadedFile('file', '', 0, UPLOAD_ERR_NO_FILE, null)], []);
        self::assertSame(403, $bad->status, 'Refused before the upload is looked at.');
        self::assertSame(403, $this->request(self::MEMBER, 'POST', '/projects/import/zzzzzzzzzzzz', ['name' => 'x'])->status);
        self::assertSame(1, count($this->ops->projects()->listFor($this->core->actor('admin'))));
    }

    public function testCsrfIsRequired(): void
    {
        $request = $this->uploadRequest(self::ADMIN, '/projects/import', ['file' => $this->file($this->archive, 'a.zip')], ['name' => 'No token'], false);
        self::assertSame(403, Fixtures::handle($this->kernel, $request)[0]->status);
        self::assertSame(1, count($this->ops->projects()->listFor($this->core->actor('admin'))));
    }

    // Helpers.

    /** A copy of $zip changed by $change. */
    private function rewrite(string $zip, \Closure $change): string
    {
        $path = $this->core->temp->path('rewrite-' . bin2hex(random_bytes(4)) . '.zip');
        file_put_contents($path, $zip);
        $z = new ZipArchive();
        self::assertTrue($z->open($path));
        $change($z);
        $z->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function keyOf(string $name): string
    {
        foreach ($this->ops->projects()->listFor($this->core->actor('admin')) as $summary) {
            if ($summary->project->name === $name) {
                return $summary->project->key;
            }
        }
        self::fail("No project {$name}.");
    }

    private function file(string $bytes, string $clientName): UploadedFile
    {
        return new UploadedFile('file', $clientName, strlen($bytes), UPLOAD_ERR_OK, $this->core->upload($bytes));
    }

    /** @param array<string, string> $post */
    private function upload(string $token, string $uri, string $bytes, string $clientName, array $post): Response
    {
        return $this->handleUpload($token, $uri, ['file' => $this->file($bytes, $clientName)], $post);
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $post
     */
    private function handleUpload(string $token, string $uri, array $files, array $post): Response
    {
        return Fixtures::handle($this->kernel, $this->uploadRequest($token, $uri, $files, $post, true))[0];
    }

    /**
     * A multipart POST as fromGlobals() would build it, with $files set
     * through the constructor (the test seam: is_uploaded_file() is only
     * consulted in fromGlobals).
     *
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $post
     */
    private function uploadRequest(string $token, string $uri, array $files, array $post, bool $csrf): Request
    {
        if ($csrf) {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }
        $r = Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => $token]);

        return new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, $files, $r->contentLength,
        );
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

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
