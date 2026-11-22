<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FormSourceController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\UploadLimits;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/../core/forms/corpus/Corpus.php';

/**
 * T-WEB-77 (M5-WP6): hostile requests to the form source, download, and
 * upload routes, through the kernel with the real templates and core.
 *
 * - Every refused corpus case posted as source text (Check and Save) is a
 *   422 page that is well-formed, holds no inline code, keeps the text in
 *   the text area (escaped; the parsed value is the posted text whenever
 *   e() has nothing to replace), shows no part of it elsewhere, and
 *   stores nothing.
 * - Hostile uploads (UTF-16, a PNG, an HTML page with a script, one byte
 *   over the cap, an empty file, a sparse file far over the cap, and a
 *   client name with `../` and U+202E) store nothing when refused, and
 *   never show the file's text or name.
 * - Array-shaped `text`, `do`, and `base` never fail a page.
 * - A Member (403) and an ungranted person (404) on every route of this
 *   package; a table key of another project, an unknown project, and
 *   malformed keys (404); a body over post_max_size (413). Nothing is
 *   stored by any of them. Synthetic data only.
 */
#[CoversClass(FormSourceController::class)]
final class FormHostileRequestsTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const OUTSIDER = 'tokenCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $otherProject;
    private string $otherTable;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->core->addPerson('outsider', 'Example Outsider', Role::Member);
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->uploadLimits = new UploadLimits(2 * 1048576, 8 * 1048576);
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->services->sessions[self::OUTSIDER] = $this->signedIn('outsider');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($admin, $this->project)[0]->table->key;
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);
        $this->otherProject = $this->ops->projects()->create($admin, 'Garden', 'Plants')->key;
        $this->otherTable = $this->ops->schema()->tables($admin, $this->otherProject)[0]->table->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testEveryRefusedCorpusCaseIsA422ThatKeepsTheTextAndStoresNothing(): void
    {
        $before = $this->state();
        $base = hash('sha256', $this->storedHtml());
        $count = 0;
        foreach (Corpus::all() as $id => $case) {
            if ($case['verdict'] !== 'refuse') {
                continue;
            }
            $text = $case['text'];
            self::assertIsString($text);
            foreach ($count % 4 === 0 ? ['check', 'save'] : ['check'] as $do) {
                $response = $this->post(self::ADMIN, $this->t() . '/form/source', ['text' => $text, 'base' => $base, 'do' => $do]);
                self::assertSame(422, $response->status, $id);
                $x = $this->assertPage($response->body);
                self::assertSame(1, $x->query('//div[@class="error-summary"]//li')->length, $id);
                self::assertSame(1, $x->query('//textarea[@id="source"][@aria-invalid="true"]')->length, $id);
                self::assertSame(0, $x->query('//body//script | //body//style | //iframe | //object')->length, $id);
                $value = $this->areaValue($x);
                if (self::escapesUnchanged($text)) {
                    self::assertSame(self::lineFeeds($text), $value, $id . ': the parsed text area gives the posted text back.');
                }
                // The marker is only ever in the text area.
                $outside = substr_count($response->body, Corpus::MARKER) - substr_count($value, Corpus::MARKER);
                self::assertSame(0, $outside, $id . ': no part of the refused text outside the text area.');
            }
            $count++;
        }
        self::assertGreaterThan(100, $count, 'The corpus was read.');
        self::assertSame($before, $this->state(), 'No refused text was stored.');
    }

    public function testHostileUploadsStoreNothingAndNeverShowTheFile(): void
    {
        $before = $this->state();
        $script = "<!DOCTYPE html>\n<html><head><script>alert('" . Corpus::MARKER . "')</script></head><body></body></html>";
        $sparse = $this->core->upload('');
        $handle = fopen($sparse, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, 64 * 1048576);
        fclose($handle);
        $cases = [
            'UTF-16' => [$this->core->upload("\xFF\xFE" . mb_convert_encoding('<div data-tiffin-form="1"><p>' . Corpus::MARKER . '</p></div>', 'UTF-16LE', 'UTF-8')), 'Line 1: The text is not in UTF-8. Save the file as UTF-8 and try again.'],
            'a PNG' => [$this->core->upload(FileFixture::png()), 'Line 1: The text is not in UTF-8. Save the file as UTF-8 and try again.'],
            'an HTML page with a script' => [$this->core->upload($script), 'Line 1: Forms cannot hold DOCTYPE, ENTITY, or CDATA sections. Remove that part.'],
            'one byte over' => [$this->core->upload('<div data-tiffin-form="1"><p>' . str_repeat('a', 262145 - 39) . '</p></div>'), 'The form is larger than 262,144 bytes.'],
            'empty' => [$this->core->upload(''), 'That file is empty. Choose a form file.'],
            'far over, sparse' => [$sparse, 'The form is larger than 262,144 bytes.'],
        ];
        self::assertSame(262145, (int) filesize((string) $cases['one byte over'][0]));
        foreach ($cases as $label => [$path, $problem]) {
            $name = '../../' . Corpus::MARKER . "\u{202E}lmth.html";
            $response = $this->upload(self::ADMIN, new UploadedFile('file', UploadedFile::baseName($name), (int) filesize($path), UPLOAD_ERR_OK, $path));
            self::assertSame(422, $response->status, $label);
            $x = $this->assertPage($response->body);
            self::assertSame([$problem], $this->texts($x, '//div[@class="error-summary"]//li'), $label);
            self::assertStringNotContainsString(Corpus::MARKER, $response->body, $label . ': neither the text nor the name is shown.');
            self::assertStringNotContainsString('lmth', $response->body, $label);
        }
        self::assertSame($before, $this->state(), 'No refused upload was stored.');

        // An accepted file under a hostile name: stored; the name is in no notice, row, or audit entry.
        $good = $this->core->upload("<div data-tiffin-form=\"1\">\n  <p>Good</p>\n</div>\n");
        $response = $this->upload(self::ADMIN, new UploadedFile('file', '..' . "\u{202E}" . Corpus::MARKER . '.html', 50, UPLOAD_ERR_OK, $good));
        self::assertSame(303, $response->status);
        self::assertSame(['key' => 'notice.form_uploaded', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame("<div data-tiffin-form=\"1\">\n  <p>Good</p>\n</div>\n", $this->storedHtml());
        self::assertStringNotContainsString(Corpus::MARKER, json_encode($this->core->systemRows(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function testArrayShapedInputNeverFailsAPage(): void
    {
        $before = $this->state();
        $base = hash('sha256', $this->storedHtml());
        $canonical = "<div data-tiffin-form=\"1\">\n  <p>Changed</p>\n</div>\n";
        $cases = [
            'text array' => [['text' => [$canonical], 'base' => $base, 'do' => 'save'], 422, 'A form is one div element with data-tiffin-form="1", with everything inside it.'],
            'text map' => [['text' => ['a' => ['b' => 'c']], 'base' => $base, 'do' => 'check'], 422, 'A form is one div element with data-tiffin-form="1", with everything inside it.'],
            'do array' => [['text' => $canonical, 'base' => $base, 'do' => ['save']], 200, null],
            'base array' => [['text' => $canonical, 'base' => [$base], 'do' => 'save'], 422, 'Someone saved this form after you opened it. Save again to replace their version with yours.'],
            'everything missing' => [[], 422, 'A form is one div element with data-tiffin-form="1", with everything inside it.'],
        ];
        foreach ($cases as $label => [$post, $status, $problem]) {
            $response = $this->post(self::ADMIN, $this->t() . '/form/source', $post);
            self::assertSame($status, $response->status, $label);
            $x = $this->assertPage($response->body);
            self::assertSame($problem === null ? [] : [$problem], $this->texts($x, '//div[@class="error-summary"]//li'), $label);
        }
        self::assertSame($before, $this->state());
    }

    public function testAMemberAndAnUngrantedPersonAreRefusedEveryRoute(): void
    {
        $before = $this->state();
        foreach ($this->routes($this->t()) as [$method, $uri, $post, $file]) {
            foreach ([[self::MEMBER, 403], [self::OUTSIDER, 404]] as [$who, $status]) {
                $response = $file
                    ? $this->upload($who, new UploadedFile('file', 'form.html', 40, UPLOAD_ERR_OK, $this->core->upload('<div data-tiffin-form="1"><p>x</p></div>')), $uri)
                    : $this->request($who, $method, $uri, $post);
                self::assertSame($status, $response->status, $method . ' ' . $uri);
                self::assertNull($response->header('Content-Disposition'), 'No download for ' . $uri);
                self::assertStringNotContainsString('data-tiffin-form', $response->body, $uri);
            }
        }
        self::assertSame($before, $this->state());
    }

    public function testForeignAndMalformedKeysAreNotFound(): void
    {
        $before = $this->state();
        $prefixes = [
            'another project\'s table' => '/projects/' . $this->project . '/tables/' . $this->otherTable,
            'a table under another project' => '/projects/' . $this->otherProject . '/tables/' . $this->items,
            'unknown project' => '/projects/zzzzzzzzzzzz/tables/' . $this->items,
            'malformed table' => '/projects/' . $this->project . '/tables/' . 'x%27%3E%3Cscript%3E',
            'dot dot' => '/projects/' . $this->project . '/tables/..',
            'upper case' => '/projects/' . $this->project . '/tables/' . strtoupper($this->items),
            'long' => '/projects/' . $this->project . '/tables/' . str_repeat('a', 300),
        ];
        foreach ($prefixes as $label => $prefix) {
            foreach ($this->routes($prefix) as [$method, $uri, $post, $file]) {
                $response = $file
                    ? $this->upload(self::ADMIN, new UploadedFile('file', 'form.html', 40, UPLOAD_ERR_OK, $this->core->upload('<div data-tiffin-form="1"><p>x</p></div>')), $uri)
                    : $this->request(self::ADMIN, $method, $uri, $post);
                self::assertSame(404, $response->status, $label . ': ' . $method . ' ' . $uri);
                self::assertNull($response->header('Content-Disposition'));
            }
        }
        self::assertSame($before, $this->state());
    }

    public function testABodyOverPostMaxSizeIsRefusedBeforeTheController(): void
    {
        $before = $this->state();
        $post = ['text' => "<div data-tiffin-form=\"1\"><p>x</p></div>", 'base' => hash('sha256', $this->storedHtml()), 'do' => 'save', '_csrf' => FakeServices::SESSION_CSRF];
        $r = Fixtures::fullRequest('POST', $this->t() . '/form/source', $post, ['tdb_session' => self::ADMIN], ['CONTENT_LENGTH' => (string) (9 * 1048576)]);
        self::assertSame(413, Fixtures::handle($this->kernel, $r)[0]->status);
        self::assertSame($before, $this->state());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The routes of this package under a table path: method, address, POST
     * body, and whether it is the multipart upload.
     *
     * @return list<array{string, string, array<string, string>, bool}>
     */
    private function routes(string $table): array
    {
        $save = ['text' => "<div data-tiffin-form=\"1\"><p>x</p></div>", 'base' => hash('sha256', $this->storedHtml()), 'do' => 'save'];

        return [
            ['GET', $table . '/form/source', [], false],
            ['POST', $table . '/form/source', $save, false],
            ['GET', $table . '/form/download', [], false],
            ['GET', $table . '/form/upload', [], false],
            ['POST', $table . '/form/upload', [], true],
        ];
    }

    /** True when e() leaves every character of $text as it is (valid UTF-8, no character it replaces). */
    private static function escapesUnchanged(string $text): bool
    {
        return preg_match('//u', $text) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\x{FDD0}-\x{FDEF}]|[\x{FFFE}\x{FFFF}]/u', $text) !== 1
            && preg_match('/[\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{10FFFE}\x{10FFFF}]/u', $text) !== 1;
    }

    /** Line breaks as an XML or HTML parser reads them: CR LF and lone CR as LF. */
    private static function lineFeeds(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** The stored forms of both projects and the audit log: what no refused request may change. */
    private function state(): array
    {
        return [
            $this->core->projectDb($this->project)->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'),
            $this->core->projectDb($this->otherProject)->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'),
            $this->core->systemRows(),
        ];
    }

    private function storedHtml(): string
    {
        return (string) $this->core->projectDb($this->project)->value('SELECT "html" FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]);
    }

    private function t(): string
    {
        return '/projects/' . $this->project . '/tables/' . $this->items;
    }

    private function areaValue(DOMXPath $x): string
    {
        $node = $x->query('//textarea[@id="source"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $node);
        self::assertStringStartsWith("\n", $node->textContent);

        return substr($node->textContent, 1);
    }

    private function assertPage(string $html): DOMXPath
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);

        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
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

    /** @param array<string, mixed> $post */
    private function post(string $token, string $uri, array $post): Response
    {
        return $this->request($token, 'POST', $uri, $post);
    }

    /** @param array<string, mixed> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

    private function upload(string $token, UploadedFile $file, ?string $uri = null): Response
    {
        $r = Fixtures::fullRequest('POST', $uri ?? $this->t() . '/form/upload', ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => $token]);
        $request = new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, ['file' => $file], $r->contentLength,
        );

        return Fixtures::handle($this->kernel, $request)[0];
    }

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
}
