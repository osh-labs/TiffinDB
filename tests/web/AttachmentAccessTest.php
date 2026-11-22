<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;
use TiffinDB\Web\Services;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';

/**
 * T-WEB-60 to T-WEB-69 (M4 spec part 1 section 12.9; session plan section
 * 5.3): the request rows of the attachment access-control matrix, through
 * the kernel with the real route table and the real CoreServices over the
 * M4 data set (M4Fixture, real files on disk), signed in through the
 * sign-in form with real session cookies. One test per matrix row:
 *
 * - 60 signed out: 303 to /sign-in, no byte of the file;
 * - 61 a Member without a grant: 404, the same page as for an id that
 *   does not exist;
 * - 62 a granted Member, a file linked from a live record: 200 with the
 *   headers of ADR 0018 decision 5 exactly, the stored bytes;
 * - 63 an id that exists only in another project: 404;
 * - 64 linked only from a record in the trash: 404 (a row sharing its
 *   bytes with a live record is still served);
 * - 65 the row present and the file missing: 404 and one log line without
 *   the name;
 * - 66 an upload without the CSRF token (403), over post_max_size (413),
 *   and over the core cap (422): the staging and attachments folders and
 *   the rows unchanged;
 * - 67 HTML and SVG files (stored and uploaded): application/octet-stream
 *   as an attachment with the sandbox policy, whatever the query says;
 * - 68 a deactivated person with an old cookie: 303 to /sign-in;
 * - 69 the data folder through the front controller: 404 (Apache's own
 *   refusal is the maintainer's host check).
 *
 * Synthetic data only.
 */
#[CoversNothing]
final class AttachmentAccessTest extends TestCase
{
    private CoreFixture $core;
    private Operations $ops;
    private M4Fixture $fx;
    private LimitsServices $services;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set('2026-11-03T10:00:00Z');
        $this->ops = $this->core->operations();
        $this->fx = M4Fixture::build($this->core, $this->ops);
        $config = Fixtures::config(false) + ['data_dir' => $this->core->temp->path('data'), 'timezone' => 'UTC'];
        $this->services = new LimitsServices(new CoreServices($config, Fixtures::appRoot(), $this->core->clock, new SeededRandom('tiffindb-attachment-access-seed'), $this->core->hasher));
        $this->kernel = new Kernel($config, Fixtures::appRoot(), null, $this->services);
    }

    protected function tearDown(): void
    {
        $this->services->close();
        $this->core->remove();
        self::assertSame(0, $this->services->probeRequests, 'No exposure probe runs.');
    }

    // T-WEB-60

    public function testSignedOutRequestsGoToSignInWithNoBytes(): void
    {
        foreach ([...array_keys($this->fx->attachmentIds), null] as $name) {
            $uri = $name === null ? $this->fx->m2->projectPath() . '/files/99999' : $this->fx->servePath($name);
            foreach (['', '?download=1'] as $query) {
                [$response] = $this->send(null, 'GET', $uri . $query);
                self::assertSame(303, $response->status, $uri);
                self::assertStringStartsWith('/sign-in', (string) $response->header('Location'));
                self::assertFalse($response->isDownload(), 'No file body.');
                self::assertFalse($response->isSandboxed());
                if ($name !== null) {
                    self::assertStringNotContainsString($this->fx->files[$name], $response->body);
                }
            }
        }
    }

    // T-WEB-61

    public function testAMemberWithoutAGrantGetsTheSameNotFoundAsForNoSuchFile(): void
    {
        $stranger = $this->signIn('member2');
        $missing = $this->get($stranger, $this->fx->m2->projectPath() . '/files/99999');
        self::assertNotFound($missing);
        foreach (array_keys($this->fx->attachmentIds) as $name) {
            $response = $this->get($stranger, $this->fx->servePath($name));
            self::assertNotFound($response, $name);
            self::assertSame($missing->body, $response->body, "{$name}: the page does not tell an existing file from none.");
        }
    }

    // T-WEB-62

    public function testAGrantedMemberGetsEveryLiveFileWithTheExactHeaders(): void
    {
        $member = $this->signIn('member');
        foreach ($this->fx->attachmentIds as $name => $id) {
            $bytes = $this->fx->files[$name];
            $row = $this->core->projectDb($this->fx->m2->project)->one('SELECT "media_type" FROM "_tiffin_attachments" WHERE "id" = ?', [$id]);
            $stored = (string) ($row['media_type'] ?? '');
            $inline = in_array($stored, Response::INLINE_TYPES, true);
            $type = $inline ? $stored : 'application/octet-stream';
            $this->assertServed($this->get($member, $this->fx->servePath($name)), $bytes, $type, Response::contentDisposition($name, $inline), $name);
            $this->assertServed($this->get($member, $this->fx->servePath($name) . '?download=1'), $bytes, $type, Response::contentDisposition($name, false), $name);
        }
        // The exact values for one image and one PDF.
        $png = $this->get($member, $this->fx->servePath('stand-mixer.png'));
        self::assertSame(['image/png', 'inline; filename="stand-mixer.png"; filename*=UTF-8\'\'stand-mixer.png'], [$png->header('Content-Type'), $png->header('Content-Disposition')]);
        $pdf = $this->get($member, $this->fx->servePath('receipt-stand-mixer.pdf'));
        self::assertSame(['application/octet-stream', 'attachment; filename="receipt-stand-mixer.pdf"; filename*=UTF-8\'\'receipt-stand-mixer.pdf'], [$pdf->header('Content-Type'), $pdf->header('Content-Disposition')]);
        // The Admin too.
        $this->assertServed($this->get($this->signIn('admin'), $this->fx->servePath('robot-vacuum.gif')), $this->fx->files['robot-vacuum.gif'], 'image/gif', Response::contentDisposition('robot-vacuum.gif', true), 'Admin');
    }

    // T-WEB-63

    public function testAnIdOfAnotherProjectIsNotFound(): void
    {
        $admin = $this->core->actor('admin');
        $garden = $this->ops->projects()->create($admin, 'Garden Log', 'Plants')->key;
        $plants = $this->ops->schema()->tables($admin, $garden)[0]->table->key;
        $name = $this->ops->schema()->design($admin, $garden, $plants)->fields[0]->field->key;
        $photo = $this->ops->schema()->addField($admin, $garden, $plants, 'Photo', 'attachment', [], false, '')->key;
        $this->ops->projects()->setSharing($admin, $garden, [$this->core->person('member')->key]);
        $rose = $this->ops->records()->create($admin, $garden, $plants, [$name => 'Rose']);
        $db = $this->core->projectDb($garden);
        $db->write(static fn () => $db->run("INSERT INTO sqlite_sequence (name, seq) VALUES ('_tiffin_attachments', 1000)"));
        $bytes = FileFixture::png(2, 2, 'garden-rose');
        $id = $this->ops->attachments()->add($admin, $garden, $plants, (string) $rose, $photo, $this->core->upload($bytes), 'rose.png', null)->id;
        self::assertSame(1001, $id);
        self::assertNull($this->core->projectDb($this->fx->m2->project)->one('SELECT "id" FROM "_tiffin_attachments" WHERE "id" = ?', [$id]), 'The id exists only in the other project.');

        $member = $this->signIn('member');
        $this->assertServed($this->get($member, '/projects/' . $garden . '/files/' . $id), $bytes, 'image/png', Response::contentDisposition('rose.png', true), 'In its own project.');
        self::assertNotFound($this->get($member, $this->fx->m2->projectPath() . '/files/' . $id));
        // And an id of Home Inventory asked for through Garden Log.
        self::assertNotFound($this->get($member, '/projects/' . $garden . '/files/' . $this->fx->attachmentIds['stand-mixer.png']));
    }

    // T-WEB-64

    public function testAFileLinkedOnlyFromATrashedRecordIsNotFound(): void
    {
        $member = $this->signIn('member');
        $admin = $this->signIn('admin');
        $mixer = $this->fx->m2->itemPath('Stand mixer');
        $member->get($mixer);
        self::assertSame(303, $member->submit($mixer . '/trash')->status);

        foreach (['stand-mixer.png', 'receipt-stand-mixer.pdf'] as $name) {
            self::assertNotFound($this->get($member, $this->fx->servePath($name)), $name);
            self::assertNotFound($this->get($admin, $this->fx->servePath($name)), $name);
        }
        // The same bytes linked from a live record (Dutch oven) are still served there.
        $this->assertServed($this->get($member, $this->fx->servePath('receipt-copy.pdf')), $this->fx->files['receipt-copy.pdf'], 'application/octet-stream', Response::contentDisposition('receipt-copy.pdf', false), 'Shared bytes');

        $member->get($mixer);
        self::assertSame(303, $member->submit($mixer . '/restore')->status);
        self::assertSame(200, $this->get($member, $this->fx->servePath('stand-mixer.png'))->status, 'Served again once restored.');
    }

    // T-WEB-65

    public function testARowWhoseFileIsMissingIsNotFoundAndLoggedWithoutTheName(): void
    {
        $member = $this->signIn('member');
        $path = M4Fixture::storedPath($this->core, $this->fx->m2->project, $this->fx->files['robot-vacuum.gif']);
        self::assertTrue(unlink($path));
        [$response, $log] = $this->send($member, 'GET', $this->fx->servePath('robot-vacuum.gif'));
        self::assertNotFound($response);
        $lines = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => trim($line) !== ''));
        self::assertCount(1, $lines, 'One log line.');
        self::assertStringContainsString('attachment=' . $this->fx->attachmentIds['robot-vacuum.gif'], $lines[0]);
        self::assertStringNotContainsString('robot-vacuum', $log);
        self::assertStringNotContainsString($this->core->temp->path(), $log, 'No path in the log.');
    }

    // T-WEB-66

    public function testRefusedUploadsStoreNothing(): void
    {
        $member = $this->signIn('member');
        $receipt = $this->fx->m2->itemPath('Toaster') . '/files/' . $this->fx->m2->itemFields['Receipt'];
        $member->get($receipt . '/new');
        $csrf = (string) $member->xpath()->query('//form[@enctype="multipart/form-data"]//input[@name="_csrf"]/@value')->item(0)?->nodeValue;
        self::assertNotSame('', $csrf);
        $before = $this->storage();

        // Without the CSRF token.
        [$forbidden] = $this->send($member, 'POST', $receipt, [], ['file' => $this->upload(FileFixture::pdf(), 'receipt.pdf')]);
        self::assertSame(403, $forbidden->status);
        self::assertSame($before, $this->storage(), 'No CSRF token: nothing stored.');
        [$wrong] = $this->send($member, 'POST', $receipt, ['_csrf' => str_repeat('a', 64)], ['file' => $this->upload(FileFixture::pdf(), 'receipt.pdf')]);
        self::assertSame(403, $wrong->status);
        self::assertSame($before, $this->storage(), 'A wrong token: nothing stored.');

        // Over post_max_size (PHP would have dropped the body): the 413 page.
        $this->services->limits = new UploadLimits(1048576, 1048576);
        [$tooLarge] = $this->send($member, 'POST', $receipt, [], [], 2 * 1048576);
        self::assertSame(413, $tooLarge->status);
        self::assertSame($before, $this->storage(), 'Over post_max_size: nothing stored.');

        // Over the core cap of 64 MiB, with the PHP limits raised (a sparse file, so the test stays small on disk).
        $this->services->limits = new UploadLimits(null, null);
        $over = $this->sparse(64 * 1048576 + 1);
        [$refused] = $this->send($member, 'POST', $receipt, ['_csrf' => $csrf], ['file' => new UploadedFile('file', 'large.bin', 64 * 1048576 + 1, UPLOAD_ERR_OK, $over)]);
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('64.0 MB', $refused->body, 'The problem names the cap.');
        self::assertSame($before, $this->storage(), 'Over the core cap: nothing stored, nothing left in staging.');
        if (is_file($over)) {
            unlink($over);
        }
    }

    // T-WEB-67

    public function testActiveContentIsOnlyEverADownload(): void
    {
        $member = $this->signIn('member');
        $queries = ['', '?download=0', '?download=1', '?download=', '?download=true', '?inline=1', '?download[]=0', '?type=text/html'];
        foreach (['manual.html', 'diagram.svg'] as $name) {
            foreach ($queries as $query) {
                $this->assertServed($this->get($member, $this->fx->servePath($name) . $query), $this->fx->files[$name], 'application/octet-stream', Response::contentDisposition($name, false), $name . $query);
            }
        }

        // The same files uploaded through the route are stored the same way.
        $receipt = $this->fx->m2->itemPath('Toaster') . '/files/' . $this->fx->m2->itemFields['Receipt'];
        foreach (['page.html' => M4Fixture::HTML, 'picture.svg' => M4Fixture::SVG, 'run.js' => "alert(1);\n"] as $name => $bytes) {
            $member->get($receipt . '/new');
            $path = (string) tempnam($this->core->temp->path(), 'upload-');
            file_put_contents($path, $bytes);
            $added = $member->upload($receipt, 'file', $path, $name);
            self::assertSame(303, $added->status, $added->body);
            $id = (int) $this->core->projectDb($this->fx->m2->project)->value('SELECT MAX("id") FROM "_tiffin_attachments"');
            self::assertSame('application/octet-stream', $this->core->projectDb($this->fx->m2->project)->value('SELECT "media_type" FROM "_tiffin_attachments" WHERE "id" = ?', [$id]));
            foreach ($queries as $query) {
                $this->assertServed($this->get($member, $this->fx->m2->projectPath() . '/files/' . $id . $query), $bytes, 'application/octet-stream', Response::contentDisposition($name, false), $name . $query);
            }
            $member->cleanUp();
        }
    }

    // T-WEB-68

    public function testADeactivatedPersonWithAnOldCookieGoesToSignIn(): void
    {
        $member = $this->signIn('member');
        self::assertSame(200, $this->get($member, $this->fx->servePath('stand-mixer.png'))->status);
        $this->ops->people()->deactivate($this->core->actor('admin'), $this->core->person('member')->key);

        foreach (['stand-mixer.png', 'receipt-stand-mixer.pdf'] as $name) {
            [$response] = $this->send($member, 'GET', $this->fx->servePath($name));
            self::assertSame(303, $response->status, $name);
            self::assertStringStartsWith('/sign-in', (string) $response->header('Location'));
            self::assertFalse($response->isDownload());
        }
    }

    // T-WEB-69

    public function testTheDataFolderIsNotReachableThroughTheFrontController(): void
    {
        $member = $this->signIn('member');
        $sha = hash('sha256', $this->fx->files['stand-mixer.png']);
        $p = $this->fx->m2->project;
        $paths = [
            '/data/projects/' . $p . '/attachments/' . substr($sha, 0, 2) . '/' . $sha,
            '/data/projects/' . $p . '/project.tiffin',
            '/data/system.sqlite',
            '/projects/' . $p . '/attachments/' . substr($sha, 0, 2) . '/' . $sha,
            '/projects/' . $p . '/attachments/' . $sha,
            '/projects/' . $p . '/project.tiffin',
            '/projects/' . $p . '/files/' . substr($sha, 0, 2) . '/' . $sha,
            '/projects/' . $p . '/files/' . $sha,
        ];
        foreach ($paths as $path) {
            $response = $this->get($member, $path);
            self::assertNotFound($response, $path);
            self::assertStringNotContainsString($this->fx->files['stand-mixer.png'], $response->body);
        }
        foreach ($paths as $path) {
            [$response] = $this->send(null, 'GET', $path);
            self::assertContains($response->status, [303, 404], $path);
            self::assertFalse($response->isDownload(), $path);
        }
    }

    // Helpers.

    private function signIn(string $username): EndToEndClient
    {
        $client = new EndToEndClient($this->kernel);
        $client->get('/sign-in');
        $signedIn = $client->submit('/sign-in', ['username' => $username, 'password' => CoreFixture::PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);

        return $client;
    }

    private function get(EndToEndClient $client, string $uri): Response
    {
        [$response, $log] = $this->send($client, 'GET', $uri);
        self::assertSame('', $log, "No log output for {$uri}.");

        return $response;
    }

    /**
     * A request as the browser $client (null: no cookies) would send it,
     * with $files set through the Request constructor (the test seam).
     *
     * @param array<string, mixed> $post
     * @param array<string, UploadedFile> $files
     * @return array{0: Response, 1: string}
     */
    private function send(?EndToEndClient $client, string $method, string $uri, array $post = [], array $files = [], ?int $contentLength = null): array
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
        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = (string) $contentLength;
        }
        $r = Request::fromGlobals($server, $query, $post, $client?->cookies ?? []);
        $request = new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, $files, $r->contentLength,
        );

        return Fixtures::handle($this->kernel, $request);
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        return new UploadedFile('file', $name, strlen($bytes), UPLOAD_ERR_OK, $this->core->upload($bytes));
    }

    /** A sparse file of $size bytes in the fixture's folder. */
    private function sparse(int $size): string
    {
        $path = (string) tempnam($this->core->temp->path(), 'sparse-');
        $handle = fopen($path, 'r+');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, $size));
        fclose($handle);

        return $path;
    }

    /**
     * What an upload may change: the attachment rows and links, the stored
     * files, and the staging folder's entries.
     *
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $db = $this->core->projectDb($this->fx->m2->project);
        $files = [];
        $root = $this->core->projectFolder($this->fx->m2->project) . '/attachments';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $files[] = substr($file->getPathname(), strlen($root) + 1) . ' ' . $file->getSize();
        }
        sort($files);
        $staging = [];
        $stagingRoot = $this->core->data->path() . '/staging';
        if (is_dir($stagingRoot)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stagingRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                /** @var \SplFileInfo $entry */
                $staging[] = substr($entry->getPathname(), strlen($stagingRoot) + 1);
            }
        }
        sort($staging);

        return [
            'rows' => $db->all('SELECT * FROM "_tiffin_attachments" ORDER BY "id"'),
            'links' => $db->all('SELECT * FROM "_tiffin_attachment_links" ORDER BY "field_key", "record_id", "attachment_id"'),
            'files' => $files,
            'staging' => array_values(array_filter($staging, static fn (string $entry): bool => !in_array($entry, ['uploads', 'imports', 'exports'], true))),
        ];
    }

    private function assertServed(Response $response, string $bytes, string $type, string $disposition, string $what): void
    {
        self::assertSame(200, $response->status, $what);
        self::assertTrue($response->isSandboxed(), $what);
        self::assertSame($type, $response->header('Content-Type'), "{$what}: the type exactly, no charset.");
        self::assertSame((string) strlen($bytes), $response->header('Content-Length'), $what);
        self::assertSame($disposition, $response->header('Content-Disposition'), $what);
        self::assertSame(SecurityHeaders::FILE_CSP, $response->header('Content-Security-Policy'), $what);
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->header('Content-Security-Policy'), $what);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $what);
        self::assertSame('DENY', $response->header('X-Frame-Options'), $what);
        self::assertSame('no-store', $response->header('Cache-Control'), $what);
        self::assertNull($response->header('Set-Cookie'), $what);
        self::assertSame($bytes, $response->body(), "{$what}: the stored bytes.");
    }

    private static function assertNotFound(Response $response, string $what = ''): void
    {
        self::assertSame(404, $response->status, $what);
        self::assertFalse($response->isSandboxed(), $what);
        self::assertFalse($response->isDownload(), $what);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'), $what);
        self::assertNull($response->header('Content-Disposition'), $what);
    }
}

/**
 * The real CoreServices with upload limits a test can set (null: the
 * real ones) and a probe that only counts its requests (as
 * NoProbeServices), so no socket is opened.
 */
final class LimitsServices implements Services
{
    public ?UploadLimits $limits = null;

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
        return $this->limits ?? $this->inner->uploadLimits();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
