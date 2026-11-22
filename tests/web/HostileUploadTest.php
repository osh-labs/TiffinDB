<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';
require_once __DIR__ . '/AttachmentAccessTest.php';

/**
 * T-WEB-70 (M4 spec part 2 section 11.1; session plan section 7): a
 * hostile-upload corpus through the upload route (`POST .../files/{field}`)
 * with the real CoreServices over the M4 data set, as a granted Member
 * signed in through the form. Each upload reaches the kernel as PHP would
 * hand it over: an entry of PHP's upload array read by
 * UploadedFile::fromEntry (which keeps only the part of the client's name
 * after the last `/` or `\`), with the PHP limits raised in the services
 * seam so the core cap is what counts. One case goes past the parser with
 * separators left in the name, to show the core cleans them as well.
 *
 * Stored cases: exactly one new row with the cleaned name, the type from
 * the leading bytes, and the size; the file at `attachments/<2>/<64>` and
 * nowhere else under the data folder; served only as specified (the four
 * image types inline with their type, everything else
 * application/octet-stream as an attachment), always with the sandbox
 * policy and nosniff. Refused cases: 422 with the problem in the error
 * summary, no row, no link, no stored file. Every case: nothing left in
 * staging. The cap cases use sparse files, so the test stays small on
 * disk. Synthetic data only.
 */
#[CoversNothing]
final class HostileUploadTest extends TestCase
{
    private const CAP = 64 * 1048576;

    private CoreFixture $core;
    private Operations $ops;
    private M4Fixture $fx;
    private LimitsServices $services;
    private Kernel $kernel;
    private EndToEndClient $member;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set('2026-11-03T10:00:00Z');
        $this->ops = $this->core->operations();
        $this->fx = M4Fixture::build($this->core, $this->ops);
        $config = Fixtures::config(false) + ['data_dir' => $this->core->temp->path('data'), 'timezone' => 'UTC'];
        $this->services = new LimitsServices(new CoreServices($config, Fixtures::appRoot(), $this->core->clock, new SeededRandom('tiffindb-hostile-upload-seed'), $this->core->hasher));
        $this->services->limits = new UploadLimits(null, null);
        $this->kernel = new Kernel($config, Fixtures::appRoot(), null, $this->services);
        $this->member = new EndToEndClient($this->kernel);
        $this->member->get('/sign-in');
        self::assertSame(303, $this->member->submit('/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD])->status);
    }

    protected function tearDown(): void
    {
        $this->services->close();
        $this->core->remove();
    }

    /**
     * @return iterable<string, array{string, string|int, string, array{0: string, 1: string, 2: bool}|string}>
     *     field (Receipt or Photo), the bytes (or a size for a sparse file), the client's name, and either
     *     [stored name, stored type, inline] or the problem key
     */
    public static function corpus(): iterable
    {
        $html = "<!DOCTYPE html>\n<html><body><script>alert(document.cookie)</script></body></html>\n";
        $svg = M4Fixture::SVG;
        $trick = "\x89PNG\r\n\x1A\n<html><script>alert(1)</script></html>";
        $pdf = FileFixture::pdf();

        yield 'HTML' => ['Receipt', $html, 'page.html', ['page.html', 'application/octet-stream', false]];
        yield 'HTML named as an image' => ['Receipt', $html, 'photo.png', ['photo.png', 'application/octet-stream', false]];
        yield 'SVG with a script' => ['Receipt', $svg, 'drawing.svg', ['drawing.svg', 'application/octet-stream', false]];
        yield 'SVG into an images-only field' => ['Photo', $svg, 'drawing.svg', 'problem.attachment.not_image'];
        // Typed by its leading bytes (review M4-sec-wp1-wp2 finding 5): shown as an image, never run (nosniff, sandbox).
        yield 'PNG signature followed by HTML' => ['Receipt', $trick, 'trick.html', ['trick.html', 'image/png', true]];
        yield 'a script file' => ['Receipt', "alert(1);\n", 'run.js', ['run.js', 'application/octet-stream', false]];
        yield 'parent folders in the name' => ['Receipt', $pdf, '../../etc/passwd', ['passwd', 'application/pdf', false]];
        yield 'a slash in the name' => ['Receipt', $pdf, 'receipts/stand-mixer.pdf', ['stand-mixer.pdf', 'application/pdf', false]];
        yield 'a back-slash in the name' => ['Receipt', $pdf, 'C:\\receipts\\stand-mixer.pdf', ['stand-mixer.pdf', 'application/pdf', false]];
        yield 'only separators' => ['Receipt', $pdf, '../', ['file', 'application/pdf', false]];
        yield 'control characters' => ['Receipt', $pdf, "rec\x01ei\x7Fpt\t\r\n.pdf", ['receipt.pdf', 'application/pdf', false]];
        yield 'right-to-left override' => ['Receipt', $pdf, "receipt\u{202E}fdp.exe", ['receiptfdp.exe', 'application/pdf', false]];
        yield 'zero-width and byte-order marks' => ['Receipt', $pdf, "\u{FEFF}re\u{200B}ceipt.pdf", ['receipt.pdf', 'application/pdf', false]];
        yield 'invalid UTF-8' => ['Receipt', $pdf, "receipt\xC3\x28.pdf", ['file', 'application/pdf', false]];
        yield 'a 1,000-character name' => ['Receipt', $pdf, str_repeat('n', 996) . '.pdf', [str_repeat('n', 255), 'application/pdf', false]];
        yield 'a zero-byte file' => ['Receipt', '', 'empty.pdf', 'problem.upload.empty'];
        yield 'a file at the core cap' => ['Receipt', self::CAP, 'large.bin', ['large.bin', 'application/octet-stream', false]];
        yield 'one byte over the core cap' => ['Receipt', self::CAP + 1, 'larger.bin', 'problem.upload.too_large'];
    }

    /**
     * @param string|int $bytes
     * @param array{0: string, 1: string, 2: bool}|string $expected
     */
    #[DataProvider('corpus')]
    public function testHostileUploadsThroughTheRoute(string $field, string|int $bytes, string $clientName, array|string $expected): void
    {
        $route = $this->fx->m2->itemPath('Toaster') . '/files/' . $this->fx->m2->itemFields[$field];
        [$path, $size, $sha] = $this->source($bytes);
        $file = UploadedFile::fromEntry('file', ['name' => $clientName, 'tmp_name' => $path, 'size' => $size, 'error' => UPLOAD_ERR_OK], static fn (string $p): bool => true);
        self::assertInstanceOf(UploadedFile::class, $file);
        $this->assertUpload($route, $file, $size, $sha, $expected, $bytes);
    }

    /** Separators that reach the core (a client name not cut by the parser) are replaced, never used as a path. */
    public function testSeparatorsPastTheParserAreCleanedByTheCore(): void
    {
        $route = $this->fx->m2->itemPath('Toaster') . '/files/' . $this->fx->m2->itemFields['Receipt'];
        $pdf = FileFixture::pdf();
        [$path, $size, $sha] = $this->source($pdf);
        $file = new UploadedFile('file', '..\\../x/y.pdf', $size, UPLOAD_ERR_OK, $path);
        $this->assertUpload($route, $file, $size, $sha, ['.._.._x_y.pdf', 'application/pdf', false], $pdf);
    }

    /**
     * @param array{0: string, 1: string, 2: bool}|string $expected
     */
    private function assertUpload(string $route, UploadedFile $file, int $size, string $sha, array|string $expected, string|int $bytes): void
    {
        $db = $this->core->projectDb($this->fx->m2->project);
        $before = $this->storage();
        $last = (int) $db->value('SELECT MAX("id") FROM "_tiffin_attachments"');

        $this->member->get($route . '/new');
        $csrf = (string) $this->member->xpath()->query('//form[@enctype="multipart/form-data"]//input[@name="_csrf"]/@value')->item(0)?->nodeValue;
        [$response, $log] = $this->post($route, ['_csrf' => $csrf], ['file' => $file]);
        self::assertSame([], $this->staging(), 'Nothing is left in staging.');

        if (is_string($expected)) {
            self::assertSame(422, $response->status, $response->body);
            self::assertSame('', $log);
            $text = (string) (require Fixtures::appRoot() . '/strings/en/problems.php')[$expected];
            $summary = HtmlAssertions::text($response->body, '//div[@class="error-summary"]');
            self::assertStringContainsString(explode('{', $text)[0], $summary);
            self::assertSame($before, $this->storage(), 'No row, no link, no file.');

            return;
        }

        [$name, $type, $inline] = $expected;
        self::assertSame(303, $response->status, $response->body);
        self::assertSame('', $log);
        $rows = $db->all('SELECT * FROM "_tiffin_attachments" WHERE "id" > ?', [$last]);
        self::assertCount(1, $rows, 'One new row.');
        $row = $rows[0];
        self::assertSame([$name, $type, $size, $sha], [$row['original_name'], $row['media_type'], (int) $row['size'], $row['sha256']]);
        $stored = $this->core->projectFolder($this->fx->m2->project) . '/attachments/' . substr($sha, 0, 2) . '/' . $sha;
        self::assertFileExists($stored);
        self::assertSame($size, filesize($stored));
        self::assertSame($this->otherFiles($before['tree']), $this->otherFiles($this->tree()), 'No file appears anywhere else under the data folder.');

        $id = (int) $row['id'];
        foreach (['', '?download=1', '?download=0'] as $query) {
            [$served] = $this->get($this->fx->m2->projectPath() . '/files/' . $id . $query);
            $asInline = $inline && $query !== '?download=1';
            self::assertSame(200, $served->status);
            self::assertTrue($served->isSandboxed());
            self::assertSame($inline ? $type : 'application/octet-stream', $served->header('Content-Type'));
            self::assertSame(Response::contentDisposition($name, $asInline), $served->header('Content-Disposition'));
            self::assertSame((string) $size, $served->header('Content-Length'));
            self::assertSame(SecurityHeaders::FILE_CSP, $served->header('Content-Security-Policy'));
            self::assertSame('nosniff', $served->header('X-Content-Type-Options'));
            if (is_string($bytes)) {
                self::assertSame($bytes, $served->body());
            }
        }
        if ($size > 1048576) {
            unlink($stored);
        }
    }

    /**
     * The upload's temporary file: the bytes, or a sparse file of that size.
     *
     * @return array{0: string, 1: int, 2: string} path, size, SHA-256
     */
    private function source(string|int $bytes): array
    {
        if (is_string($bytes)) {
            $path = $this->core->upload($bytes);

            return [$path, strlen($bytes), hash('sha256', $bytes)];
        }
        $path = (string) tempnam($this->core->temp->path(), 'sparse-');
        $handle = fopen($path, 'r+');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, $bytes));
        fclose($handle);

        return [$path, $bytes, (string) hash_file('sha256', $path)];
    }

    /** @return array<string, mixed> rows, links, and the tree of the data folder */
    private function storage(): array
    {
        $db = $this->core->projectDb($this->fx->m2->project);

        return [
            'rows' => $db->all('SELECT * FROM "_tiffin_attachments" ORDER BY "id"'),
            'links' => $db->all('SELECT * FROM "_tiffin_attachment_links" ORDER BY "field_key", "record_id", "attachment_id"'),
            'tree' => $this->tree(),
        ];
    }

    /** @return list<string> every file under the data folder with its size, except the databases and their journals */
    private function tree(): array
    {
        $root = $this->core->data->path();
        $entries = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('/\.(tiffin|sqlite)(-wal|-shm|-journal)?\z/', $relative) === 1) {
                continue;
            }
            $entries[] = $relative . ' ' . $file->getSize();
        }
        sort($entries);

        return $entries;
    }

    /**
     * @param list<string> $tree
     * @return list<string> the entries outside the attachments folders
     */
    private function otherFiles(array $tree): array
    {
        return array_values(array_filter($tree, static fn (string $entry): bool => preg_match('#^projects/[a-z2-7]{12}/attachments/[0-9a-f]{2}/[0-9a-f]{64} #', $entry) !== 1));
    }

    /** @return list<string> the entries of the staging folder's job folders */
    private function staging(): array
    {
        $root = $this->core->data->path() . '/staging';
        if (!is_dir($root)) {
            return [];
        }
        $entries = [];
        foreach ((array) scandir($root) as $kind) {
            if (!is_string($kind) || $kind === '.' || $kind === '..' || !is_dir($root . '/' . $kind)) {
                continue;
            }
            foreach ((array) scandir($root . '/' . $kind) as $job) {
                if (is_string($job) && $job !== '.' && $job !== '..') {
                    $entries[] = $kind . '/' . $job;
                }
            }
        }

        return $entries;
    }

    /** @return array{0: Response, 1: string} */
    private function get(string $uri): array
    {
        return $this->request('GET', $uri, [], []);
    }

    /**
     * @param array<string, string> $post
     * @param array<string, UploadedFile> $files
     * @return array{0: Response, 1: string}
     */
    private function post(string $uri, array $post, array $files): array
    {
        return $this->request('POST', $uri, $post, $files);
    }

    /**
     * @param array<string, string> $post
     * @param array<string, UploadedFile> $files
     * @return array{0: Response, 1: string}
     */
    private function request(string $method, string $uri, array $post, array $files): array
    {
        $query = [];
        $queryString = parse_url('http://tiffindb.example' . $uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        $server = [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => Fixtures::appRoot() . '/public', 'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_HOST' => 'tiffindb.example', 'SERVER_PORT' => '80',
        ];
        if ($method === 'POST') {
            $server['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        }
        $r = Request::fromGlobals($server, $query, $post, $this->member->cookies);
        $request = new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, $files, $r->contentLength,
        );

        return Fixtures::handle($this->kernel, $request);
    }
}
