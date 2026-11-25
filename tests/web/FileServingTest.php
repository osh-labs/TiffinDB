<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FileFixture;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FileController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;
use TiffinDB\Web\SecurityHeaders;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-53 (M4-WP6): the serving route `GET /projects/{p}/files/{a}`
 * through the kernel with real core operations and a real attachment
 * store (ADR 0018 decision 5; M4 spec part 2 section 2): the four image
 * types inline with their own type (an attachment with `download=1`),
 * every other file as application/octet-stream and an attachment, the
 * exact headers (Content-Length, nosniff, the sandboxed policy), the body
 * bytes, HEAD without a body, hostile download names, and every refusal
 * as the plain 404 page. Synthetic data only.
 */
#[CoversClass(FileController::class)]
final class FileServingTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $files;
    private string $name;
    private int $mixer;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $this->ops->schema()->tables($admin, $this->project)[0]->table->key;
        $this->name = $this->ops->schema()->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $this->files = $this->ops->schema()->addField($admin, $this->project, $this->items, 'Files', 'attachment', [], false, '')->key;
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);
        $this->mixer = $this->ops->records()->create($admin, $this->project, $this->items, [$this->name => 'Stand mixer']);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    /** @return iterable<string, array{string, string}> */
    public static function images(): iterable
    {
        yield 'PNG' => [FileFixture::png(2, 2), 'image/png'];
        yield 'JPEG' => [FileFixture::jpeg(), 'image/jpeg'];
        yield 'GIF' => [FileFixture::gif(), 'image/gif'];
        yield 'WebP' => [FileFixture::webp(), 'image/webp'];
    }

    #[DataProvider('images')]
    public function testTheFourImageTypesAreSentInlineWithTheirOwnType(string $bytes, string $type): void
    {
        $id = $this->attach($bytes, 'stand-mixer.img');
        $response = $this->get(self::MEMBER, $this->serve($id));
        $this->assertFile($response, $bytes, $type, 'inline; filename="stand-mixer.img"; filename*=UTF-8\'\'stand-mixer.img');

        $download = $this->get(self::MEMBER, $this->serve($id) . '?download=1');
        $this->assertFile($download, $bytes, $type, 'attachment; filename="stand-mixer.img"; filename*=UTF-8\'\'stand-mixer.img');

        // `download` must be exactly 1; anything else is inline.
        foreach (['0', 'yes', 'true', '1 ', ''] as $value) {
            self::assertStringStartsWith('inline; ', (string) $this->get(self::MEMBER, $this->serve($id) . '?download=' . rawurlencode($value))->header('Content-Disposition'), $value);
        }
        self::assertStringStartsWith('inline; ', (string) $this->get(self::MEMBER, $this->serve($id) . '?download[]=1')->header('Content-Disposition'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherFiles(): iterable
    {
        yield 'PDF' => [FileFixture::pdf()];
        yield 'HTML' => ['<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'];
        yield 'SVG with script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'plain text' => ['Stand mixer, bought 2025.'];
        yield 'bytes' => [FileFixture::bytes(3000)];
    }

    #[DataProvider('otherFiles')]
    public function testEveryOtherFileIsAnOctetStreamAttachment(string $bytes): void
    {
        $id = $this->attach($bytes, 'receipt.pdf');
        foreach (['', '?download=1'] as $query) {
            $this->assertFile($this->get(self::MEMBER, $this->serve($id) . $query), $bytes, 'application/octet-stream', 'attachment; filename="receipt.pdf"; filename*=UTF-8\'\'receipt.pdf');
        }
    }

    public function testAnImageSignatureFollowedByMarkupIsAnImageUnderNosniffAndTheSandbox(): void
    {
        // Intended (review M4-sec-wp1-wp2 finding 5): typed by its leading bytes, never run.
        $bytes = "\x89PNG\r\n\x1A\n<html><script>alert(1)</script></html>";
        $id = $this->attach($bytes, 'trick.html');
        $this->assertFile($this->get(self::MEMBER, $this->serve($id)), $bytes, 'image/png', 'inline; filename="trick.html"; filename*=UTF-8\'\'trick.html');
    }

    public function testHeadSendsTheHeadersOnly(): void
    {
        $bytes = FileFixture::png(3, 3);
        $id = $this->attach($bytes, 'stand-mixer.png');
        $head = $this->handle(self::MEMBER, 'HEAD', $this->serve($id));
        $get = $this->get(self::MEMBER, $this->serve($id));
        self::assertSame(200, $head->status);
        self::assertSame($get->headers, $head->headers);

        $steps = [];
        $head->send(true, static function (string $step, string|int $value) use (&$steps): void {
            $steps[] = $step;
        });
        self::assertNotContains('chunk', $steps, 'No body for HEAD.');
        self::assertContains('header', $steps);

        $body = '';
        $get->send(false, static function (string $step, string|int $value) use (&$body): void {
            if ($step === 'chunk') {
                $body .= (string) $value;
            }
        });
        self::assertSame($bytes, $body, 'GET streams the stored bytes.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'quotes' => ['say "hi".pdf', 'attachment; filename="say _hi_.pdf"; filename*=UTF-8\'\'say%20%22hi%22.pdf'];
        yield 'semicolons' => ['a; filename=evil.exe', 'attachment; filename="a_ filename=evil.exe"; filename*=UTF-8\'\'a%3B%20filename%3Devil.exe'];
        yield 'non-ASCII' => ['Küche – Möbel.pdf', 'attachment; filename="K__che ___ M__bel.pdf"; filename*=UTF-8\'\'K%C3%BCche%20%E2%80%93%20M%C3%B6bel.pdf'];
        yield 'bidi override' => ["receipt\u{202E}fdp.exe", 'attachment; filename="receiptfdp.exe"; filename*=UTF-8\'\'receiptfdp.exe'];
        yield 'isolates and zero width' => ["a\u{2066}b\u{200B}c\u{FEFF}.pdf", 'attachment; filename="abc.pdf"; filename*=UTF-8\'\'abc.pdf'];
        // The core's cleaning (Attachments::cleanName) drops the line break and turns the back-slash into _.
        yield 'line break and back-slash' => ["a\r\nX-Evil: 1\\b.pdf", 'attachment; filename="aX-Evil: 1_b.pdf"; filename*=UTF-8\'\'aX-Evil%3A%201_b.pdf'];
    }

    #[DataProvider('names')]
    public function testDownloadNamesAreSafe(string $stored, string $disposition): void
    {
        // The name is written by SQL, as a file edited by another tool could hold it.
        $id = $this->attach(FileFixture::pdf(), 'x.pdf');
        $this->core->projectDb($this->project)->run('UPDATE "_tiffin_attachments" SET "original_name" = ? WHERE "id" = ?', [$stored, $id]);
        $response = $this->get(self::MEMBER, $this->serve($id));
        self::assertSame(200, $response->status);
        $header = (string) $response->header('Content-Disposition');
        self::assertSame($disposition, $header);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $header, 'Printable ASCII only.');
    }

    public function testALongStoredNameGivesABoundedHeader(): void
    {
        $id = $this->attach(FileFixture::pdf(), 'x.pdf');
        $this->core->projectDb($this->project)->run('UPDATE "_tiffin_attachments" SET "original_name" = ? WHERE "id" = ?', [str_repeat("\u{1F4E6}", 5000) . '.pdf', $id]);
        $header = (string) $this->get(self::MEMBER, $this->serve($id))->header('Content-Disposition');
        // 255 code points at most (P181), keeping the extension (P187, M7 CF7-3):
        // 251 emoji and `.pdf` are 1,004 `_` and 3,012 percent-encoded characters.
        self::assertLessThan(4200, strlen($header));
        self::assertSame(251, substr_count($header, '%F0%9F%93%A6'));
        self::assertStringEndsWith('.pdf', $header);
    }

    // Refusals: the plain 404 page (ADR 0018 decision 5).

    public function testAMissingStoredFileIsNotFoundAndLogged(): void
    {
        $id = $this->attach(FileFixture::pdf(), 'receipt.pdf');
        $hash = (string) $this->core->projectDb($this->project)->value('SELECT "sha256" FROM "_tiffin_attachments" WHERE "id" = ?', [$id]);
        unlink($this->core->projectFolder($this->project) . '/attachments/' . substr($hash, 0, 2) . '/' . $hash);

        [$response, $log] = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $this->serve($id), [], ['tdb_session' => self::MEMBER]));
        $this->assertNotFound($response);
        self::assertStringContainsString('TiffinDB: an attachment file is missing; attachment=' . $id, $log);
        self::assertStringNotContainsString('receipt.pdf', $log, 'No file name in the log.');
    }

    public function testUngrantedOtherProjectTrashedAndUnknownAreNotFound(): void
    {
        $id = $this->attach(FileFixture::png(), 'stand-mixer.png');
        $this->ops->projects()->setSharing($this->admin(), $this->project, []);
        $this->assertNotFound($this->get(self::MEMBER, $this->serve($id)));
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);

        // An attachment id of this project asked for through another project.
        $other = $this->ops->projects()->create($this->admin(), 'Garden Log', 'Plants')->key;
        $this->ops->projects()->setSharing($this->admin(), $other, [$this->core->person('member')->key]);
        $this->assertNotFound($this->get(self::MEMBER, '/projects/' . $other . '/files/' . $id));

        foreach (['0', '01', 'abc', '-1', '999', str_repeat('9', 20), $id . '.png'] as $bad) {
            $this->assertNotFound($this->get(self::MEMBER, '/projects/' . $this->project . '/files/' . $bad), $bad);
        }

        // Linked only from a record in the trash.
        $this->ops->records()->trash($this->admin(), $this->project, $this->items, (string) $this->mixer);
        $this->assertNotFound($this->get(self::MEMBER, $this->serve($id)));
        $this->assertNotFound($this->get(self::ADMIN, $this->serve($id)));
        $this->ops->records()->restore($this->admin(), $this->project, $this->items, (string) $this->mixer);
        self::assertSame(200, $this->get(self::ADMIN, $this->serve($id))->status);

        // Its field deleted.
        $this->ops->schema()->deleteField($this->admin(), $this->project, $this->items, $this->files);
        $this->assertNotFound($this->get(self::ADMIN, $this->serve($id)));
    }

    public function testASignedOutRequestGoesToSignIn(): void
    {
        $id = $this->attach(FileFixture::png(), 'stand-mixer.png');
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $this->serve($id)))[0];
        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/sign-in', (string) $response->header('Location'));
        self::assertFalse($response->isSandboxed());
    }

    public function testPostIsNotAllowed(): void
    {
        $id = $this->attach(FileFixture::png(), 'stand-mixer.png');
        $response = $this->handle(self::MEMBER, 'POST', $this->serve($id), ['_csrf' => FakeServices::SESSION_CSRF]);
        self::assertSame(405, $response->status);
    }

    // Helpers.

    private function assertFile(Response $response, string $bytes, string $type, string $disposition): void
    {
        self::assertSame(200, $response->status);
        self::assertTrue($response->isSandboxed());
        self::assertSame($type, $response->header('Content-Type'), 'The type exactly, no charset.');
        self::assertSame((string) strlen($bytes), $response->header('Content-Length'));
        self::assertSame($disposition, $response->header('Content-Disposition'));
        self::assertSame(SecurityHeaders::FILE_CSP, $response->header('Content-Security-Policy'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->header('Content-Security-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertNull($response->header('Set-Cookie'));
        self::assertSame($bytes, $response->body(), 'The body is the stored bytes.');
    }

    private function assertNotFound(Response $response, string $what = ''): void
    {
        self::assertSame(404, $response->status, $what);
        self::assertFalse($response->isSandboxed(), $what);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'), $what);
        self::assertNull($response->header('Content-Disposition'), $what);
        self::assertStringContainsString('<h1>', $response->body(), $what);
    }

    /** Adds a file to the mixer's Files field through the core; returns its id. */
    private function attach(string $bytes, string $name): int
    {
        return $this->ops->attachments()->add($this->admin(), $this->project, $this->items, (string) $this->mixer, $this->files, $this->core->upload($bytes), $name, null)->id;
    }

    private function serve(int $id): string
    {
        return '/projects/' . $this->project . '/files/' . $id;
    }

    private function get(string $token, string $uri): Response
    {
        return $this->handle($token, 'GET', $uri);
    }

    /** @param array<string, string> $post */
    private function handle(string $token, string $method, string $uri, array $post = []): Response
    {
        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
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
