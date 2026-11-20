<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Controller\ExportController;
use TiffinDB\Web\Format;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\View;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-44 (M3-WP10): the export page and its downloads
 * (docs/design/m3/export.md), through the kernel with the real templates,
 * strings, shipped plugins, and core operations over a CoreFixture with
 * the M2 "Home Inventory" data set. Each download is read back with the
 * core's own import pipeline (CSV, Excel workbook), json_decode (JSON),
 * or the archive import (project archive). Synthetic data only.
 */
#[CoversClass(ExportController::class)]
final class ExportScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const STRANGER = 'stranger-session-token-0000000000000000000000000000000000000000';
    private const OTHER_ADMIN = 'other-admin-session-token-00000000000000000000000000000000000000';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private M2Fixture $m2;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->m2 = M2Fixture::build($this->core, $this->ops);
        $this->core->addPerson('admin2', 'Second Admin', Role::Admin);
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->services->sessions[self::STRANGER] = $this->signedIn('member2');
        $this->services->sessions[self::OTHER_ADMIN] = $this->signedIn('admin2');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    private function path(string $more = ''): string
    {
        return '/projects/' . $this->m2->project . '/export' . $more;
    }

    // The page.

    public function testFormDefaults(): void
    {
        $html = $this->page(self::ADMIN, $this->path());
        $x = $this->xpath($html);

        self::assertSame('Export - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Export', HtmlAssertions::text($html, '//h1'));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-io"]')->length);
        self::assertSame(['Home Inventory', 'Export'], $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]'));
        self::assertSame('/projects/' . $this->m2->project . '/tables', $x->query('//nav[@class="breadcrumb"]/a/@href')->item(0)?->nodeValue);

        // The archive panel and its own POST form.
        self::assertSame('The whole project, for TiffinDB', HtmlAssertions::text($html, '//section[@aria-labelledby="x-archive"]/h2'));
        self::assertSame($this->path('/archive'), $x->query('//section[@aria-labelledby="x-archive"]//form/@action')->item(0)?->nodeValue);
        self::assertSame('Download the project archive', HtmlAssertions::text($html, '//section[@aria-labelledby="x-archive"]//button'));

        // Formats: CSV, Excel workbook, JSON, the first checked; the spreadsheet hint (P135).
        self::assertSame(['Records, for other programs', 'Which records', 'Options'], $this->texts($x, '//form[@id="export-form"]/fieldset/legend'));
        self::assertSame(['export/csv', 'export/xlsx', 'export/json'], $this->values($x, '//input[@name="format"]/@value'));
        self::assertSame(['export/csv'], $this->values($x, '//input[@name="format"][@checked]/@value'));
        self::assertSame('f-format', $x->query('//input[@name="format"]/@id')->item(0)?->nodeValue);
        self::assertSame(
            ['CSV one table per file', 'Excel workbook one table, or every table as separate sheets', 'JSON the whole project in one file'],
            $this->texts($x, '//input[@name="format"]/following-sibling::span'),
        );
        self::assertSame(
            'Opening the file in a spreadsheet program? Choose the Excel workbook: its text is never treated as a formula.',
            HtmlAssertions::text($html, '//form[@id="export-form"]/fieldset[1]/p[@class="help"]'),
        );

        // Scope: one table (the first) by default, with record counts; every table.
        self::assertSame(['table'], $this->values($x, '//input[@name="scope"][@checked]/@value'));
        self::assertSame('Table to export', $x->query('//select[@name="table"]/@aria-label')->item(0)?->nodeValue);
        self::assertSame($this->m2->items, $x->query('//select[@name="table"]/option[@selected]/@value')->item(0)?->nodeValue);
        self::assertSame(3, $x->query('//select[@name="table"]/option')->length);
        self::assertStringStartsWith('Items (', HtmlAssertions::text($html, '//select[@name="table"]/option[1]'));
        self::assertStringEndsWith(' records)', HtmlAssertions::text($html, '//select[@name="table"]/option[1]'));
        self::assertSame('Records in the trash are not included.', HtmlAssertions::text($html, '//form[@id="export-form"]/fieldset[2]/p[@class="help"]'));

        // Options: CSV's two settings, both off; the spreadsheet-safe help names tab and carriage return.
        self::assertSame(['CSV', 'Excel workbook'], $this->texts($x, '//div[@class="field-group"]/p[@class="label"]'));
        self::assertSame(['setting[export/csv][record_ids]', 'setting[export/csv][spreadsheet_safe]'], $this->values($x, '//div[@class="field-group"][1]//input[@type="checkbox"]/@name'));
        self::assertSame(0, $x->query('//input[@type="checkbox"][@checked]')->length);
        self::assertStringContainsString('a tab, or a carriage return', $html);

        self::assertSame('Download', HtmlAssertions::text($html, '//div[@class="io-actions"]/button'));
        self::assertSame('The file is made now and downloaded straight away. It is not kept on the server.', HtmlAssertions::text($html, '//p[@class="io-note"]'));
    }

    /**
     * T-CF-7 (CF-5): the archive panel shows "About {size}." when the page
     * is given the project size, rendered from the template with the real
     * strings and Format::fileSize; without it there is no size line.
     */
    public function testTheArchivePanelShowsTheProjectSizeWhenGiven(): void
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        $strings = new Strings(RepoFiles::path('strings/en.php'));
        $format = new Format($strings, new DateTimeZone('UTC'), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T10:00:00Z'));
        $view = (new View(RepoFiles::path('templates'), $strings, new UrlGenerator('', '0.0.0-test'), Appearance::default(), $config))
            ->withCsrfToken(str_repeat('a', 64))->withFormat($format);
        $data = [
            'title' => 'Export', 'layout' => 'io', 'breadcrumb' => [], 'problems' => [], 'errors' => [],
            'projectKey' => 'abcdefghijkl', 'formats' => [], 'spreadsheetHint' => false, 'hasSettings' => false,
            'tables' => [], 'scope' => 'project', 'table' => '',
        ];
        $size = '//section[@aria-labelledby="x-archive"]/p[@class="help"]';

        $html = $view->page('export/form', $data + ['projectSize' => $format->fileSize(3 * 1048576 + 200000)])->body;
        HtmlAssertions::assertWellFormed($html);
        self::assertSame(['About 3.2 MB.'], $this->texts($this->xpath($html), $size));
        $html = $view->page('export/form', $data + ['projectSize' => '<b>1 KB</b>'])->body;
        self::assertSame(['About <b>1 KB</b>.'], $this->texts($this->xpath($html), $size), 'The size is escaped.');
        self::assertSame(0, $this->xpath($view->page('export/form', $data)->body)->query($size)->length);
    }

    public function testDisabledPluginsAreNotOffered(): void
    {
        $admin = $this->core->actor('admin');
        $this->ops->plugins()->disable($admin, 'export', 'xlsx');
        $this->ops->plugins()->disable($admin, 'export', 'csv');

        $x = $this->xpath($this->page(self::ADMIN, $this->path()));
        self::assertSame(['export/json'], $this->values($x, '//input[@name="format"]/@value'));
        self::assertSame(0, $x->query('//div[@class="field-group"]')->length);
        self::assertSame(0, $x->query('//form[@id="export-form"]/fieldset[1]/p[@class="help"]')->length, 'No spreadsheet hint without both formats.');

        $this->ops->plugins()->disable($admin, 'export', 'json');
        $html = $this->page(self::ADMIN, $this->path());
        self::assertSame('No export format is turned on. An Admin can turn formats on under Plugins.', HtmlAssertions::text($html, '//p[@class="notice"]'));
        self::assertSame(0, $this->xpath($html)->query('//form[@id="export-form"]')->length);

        // A disabled format posted anyway is refused.
        $response = $this->post(self::ADMIN, $this->path(), ['format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items]);
        self::assertSame(422, $response->status);
        self::assertFalse($response->isDownload());
    }

    public function testADisabledFormatPostedWhileOthersAreOnGives422WithNoFile(): void
    {
        $this->ops->plugins()->disable($this->core->actor('admin'), 'export', 'csv');
        foreach (
            [
                'disabled csv' => ['format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items, 'setting' => ['export/csv' => ['record_ids' => '1']]],
                'disabled csv, project scope' => ['format' => 'export/csv', 'scope' => 'project'],
                'import plugin as export' => ['format' => 'import/csv', 'scope' => 'table', 'table' => $this->m2->items],
            ] as $case => $post
        ) {
            $response = $this->post(self::ADMIN, $this->path(), $post);
            self::assertSame(422, $response->status, $case);
            self::assertFalse($response->isDownload(), $case);
            self::assertNull($response->header('Content-Disposition'), $case);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame('Choose one of the options shown.', HtmlAssertions::text($response->body, '//p[@id="err-format"]'), $case);
            self::assertStringEndsWith('#f-format', (string) $x->query('//div[@class="error-summary"]//a/@href')->item(0)?->nodeValue, $case);
            self::assertSame(['export/xlsx', 'export/json'], $this->values($x, '//input[@name="format"]/@value'), $case);
            self::assertSame(0, $this->exportFiles(), $case);
            self::assertSame([], glob($this->core->temp->path('data') . '/staging/exports/*') ?: [], $case);
        }
    }

    // Downloads.

    public function testCsvDownloadOfOneTable(): void
    {
        $response = $this->post(self::ADMIN, $this->path(), ['format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items]);
        self::assertSame(200, $response->status);
        self::assertTrue($response->isDownload());
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('attachment; filename="items.csv"; filename*=UTF-8\'\'items.csv', $response->header('Content-Disposition'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $body = $response->body();
        self::assertSame((string) strlen($body), $response->header('Content-Length'));

        [$headings, $rows] = $this->readBack($body, 'items.csv', 'import/csv');
        self::assertSame('Name', $headings[0]);
        self::assertSame(count($this->m2->itemIds), $rows, 'Every live item, none from the trash.');

        // The file is removed by the core once sent.
        self::assertSame(1, $this->exportFiles());
        $this->send($response);
        self::assertSame(0, $this->exportFiles());
    }

    public function testCsvSettingsApplyOnlyWhenChosen(): void
    {
        $name = $this->m2->itemFields['Name'];
        $this->core->addRecord($this->m2->project, $this->m2->items, [$name => '=SUM(A1:A2)']);

        $plain = $this->post(self::ADMIN, $this->path(), ['format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items])->body();
        self::assertStringContainsString("\n=SUM(A1:A2),", str_replace("\r\n", "\n", $plain), 'Spreadsheet-safe is off by default (P135).');

        $safe = $this->post(self::ADMIN, $this->path(), [
            'format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items,
            'setting' => ['export/csv' => ['spreadsheet_safe' => '1', 'record_ids' => '1'], 'export/xlsx' => ['record_ids' => 'x']],
        ])->body();
        self::assertStringContainsString("'=SUM(A1:A2)", $safe);
        [$headings] = $this->readBack($safe, 'items.csv', 'import/csv');
        self::assertCount(count($this->readBack($plain, 'items.csv', 'import/csv')[0]) + 1, $headings, 'The record number column is added.');
    }

    public function testXlsxDownloadOfOneTableAndOfTheProject(): void
    {
        $response = $this->post(self::ADMIN, $this->path(), ['format' => 'export/xlsx', 'scope' => 'table', 'table' => $this->m2->rooms]);
        self::assertSame(200, $response->status);
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->header('Content-Type'));
        self::assertSame('attachment; filename="rooms.xlsx"; filename*=UTF-8\'\'rooms.xlsx', $response->header('Content-Disposition'));
        [$headings, $rows] = $this->readBack($response->body(), 'rooms.xlsx', 'import/xlsx');
        self::assertSame('Name', $headings[0]);
        self::assertSame(count(M2Fixture::ROOMS), $rows);
        $this->send($response);

        $project = $this->post(self::ADMIN, $this->path(), ['format' => 'export/xlsx', 'scope' => 'project', 'table' => $this->m2->rooms]);
        self::assertSame(200, $project->status);
        self::assertSame('attachment; filename="home-inventory.xlsx"; filename*=UTF-8\'\'home-inventory.xlsx', $project->header('Content-Disposition'));
        $this->send($project);
        self::assertSame(0, $this->exportFiles());
    }

    public function testJsonDownloadAppliesTheFormatsOnlyScope(): void
    {
        // JSON offers the project scope only: a posted table scope is ignored (export.md section 2).
        $response = $this->post(self::ADMIN, $this->path(), ['format' => 'export/json', 'scope' => 'table', 'table' => $this->m2->items]);
        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('attachment; filename="home-inventory.json"; filename*=UTF-8\'\'home-inventory.json', $response->header('Content-Disposition'));
        $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringContainsString('"Rooms"', $response->body());
        $this->send($response);
    }

    public function testArchiveDownloadImportsBack(): void
    {
        $response = $this->post(self::ADMIN, $this->path('/archive'), []);
        self::assertSame(200, $response->status);
        self::assertSame('application/zip', $response->header('Content-Type'));
        self::assertSame('attachment; filename="home-inventory.tiffindb.zip"; filename*=UTF-8\'\'home-inventory.tiffindb.zip', $response->header('Content-Disposition'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        $zip = $response->body();
        $this->send($response);
        self::assertSame(0, $this->exportFiles());

        $result = $this->ops->archives()->import($this->core->actor('admin'), $this->core->upload($zip), 'home-inventory.tiffindb.zip', 'Copy of Home Inventory');
        self::assertSame('Copy of Home Inventory', $result->project->name);
        self::assertSame(3, $result->report->tables);
    }

    public function testHostileProjectNameNeverReachesTheHeaderRaw(): void
    {
        $admin = $this->core->actor('admin');
        $key = $this->ops->projects()->create($admin, 'Größe "Q"; <b>x</b>/\\y', 'Items')->key;
        $html = $this->page(self::ADMIN, '/projects/' . $key . '/export');
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertSame('Größe "Q"; <b>x</b>/\\y', HtmlAssertions::text($html, '//nav[@class="breadcrumb"]/a'));

        $response = $this->post(self::ADMIN, '/projects/' . $key . '/export/archive', []);
        self::assertSame(200, $response->status);
        $disposition = (string) $response->header('Content-Disposition');
        self::assertMatchesRegularExpression('/^attachment; filename="[\x20-\x21\x23-\x2E\x30-\x3A\x3C-\x5B\x5D-\x7E]+"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+\z/', $disposition);
        self::assertStringContainsString('gr%C3%B6%C3%9Fe', $disposition);
        $this->send($response);
    }

    // Refusals.

    public function testTamperedValuesGive422(): void
    {
        foreach (
            [
                'unknown format' => [['format' => 'export/nope', 'scope' => 'table', 'table' => $this->m2->items], 'f-format'],
                'markup format' => [['format' => '"><script>x</script>', 'scope' => 'project'], 'f-format'],
                'unknown scope' => [['format' => 'export/csv', 'scope' => 'everything', 'table' => $this->m2->items], 'f-scope'],
                'array scope' => [['format' => 'export/xlsx', 'scope' => ['table'], 'table' => $this->m2->items], 'f-scope'],
                'unknown table' => [['format' => 'export/csv', 'scope' => 'table', 'table' => 'zzzzzzzzzzzz'], 'f-table'],
                // CSV offers one table only: the server applies that scope, and no table was sent.
                'scope the format lacks' => [['format' => 'export/csv', 'scope' => 'project'], 'f-table'],
            ] as $case => [$post, $field]
        ) {
            $response = $this->post(self::ADMIN, $this->path(), $post);
            self::assertSame(422, $response->status, $case);
            self::assertFalse($response->isDownload(), $case);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            $links = $this->values($x, '//div[@class="error-summary"]//a/@href');
            self::assertNotSame([], $links, $case);
            self::assertStringEndsWith('#' . $field, $links[0], $case);
            self::assertSame(1, $x->query('//*[@id="' . $field . '"]')->length, $case);
            self::assertStringNotContainsString('<script>x', $response->body, $case);
        }
        self::assertSame(0, $this->exportFiles());
    }

    public function testThe422PageKeepsThePostedChoices(): void
    {
        $response = $this->post(self::ADMIN, $this->path(), [
            'format' => 'export/xlsx', 'scope' => 'table', 'table' => 'zzzzzzzzzzzz',
            'setting' => ['export/csv' => ['record_ids' => '1']],
        ]);
        self::assertSame(422, $response->status);
        $x = $this->xpath($response->body);
        self::assertSame(['export/xlsx'], $this->values($x, '//input[@name="format"][@checked]/@value'));
        self::assertSame(['setting[export/csv][record_ids]'], $this->values($x, '//input[@type="checkbox"][@checked]/@name'));
        self::assertSame('true', $x->query('//select[@name="table"]/@aria-invalid')->item(0)?->nodeValue);
    }

    public function testMembersAndStrangersAreRefusedOnEveryRoute(): void
    {
        $this->ops->projects()->setSharing($this->core->actor('admin'), $this->m2->project, [$this->core->person('member')->key]);
        foreach ([self::MEMBER => 403, self::STRANGER => 404] as $token => $status) {
            self::assertSame($status, $this->request($token, 'GET', $this->path())->status);
            $export = $this->post($token, $this->path(), ['format' => 'export/csv', 'scope' => 'table', 'table' => $this->m2->items]);
            self::assertSame($status, $export->status);
            self::assertFalse($export->isDownload());
            $archive = $this->post($token, $this->path('/archive'), []);
            self::assertSame($status, $archive->status);
            self::assertFalse($archive->isDownload());
        }
        self::assertSame(0, $this->exportFiles());
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/projects/zzzzzzzzzzzz/export')->status);
    }

    public function testPostRoutesRefuseGetAndMissingCsrf(): void
    {
        $get = $this->request(self::ADMIN, 'GET', $this->path('/archive'));
        self::assertContains($get->status, [404, 405]);
        self::assertFalse($get->isDownload());

        $noToken = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $this->path('/archive'), [], ['tdb_session' => self::ADMIN]))[0];
        self::assertSame(403, $noToken->status);
        self::assertFalse($noToken->isDownload());
        self::assertSame(0, $this->exportFiles());
    }

    public function testFinishIgnoresAnotherAdminsJob(): void
    {
        $response = $this->post(self::ADMIN, $this->path('/archive'), []);
        self::assertSame(1, $this->exportFiles());
        // Another Admin cannot remove (or learn about) the first Admin's job through the core.
        foreach (glob($this->core->temp->path('data') . '/staging/exports/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $this->ops->exports()->finish($this->core->actor('admin2'), $this->m2->project, basename($folder));
            $this->ops->exports()->finish($this->core->actor('admin2'), $this->m2->project, '../' . basename($folder));
        }
        self::assertSame(1, $this->exportFiles());
        $this->send($response);
        self::assertSame(0, $this->exportFiles());
    }

    // Helpers.

    /**
     * Reads an exported file back through the core's import pipeline.
     *
     * @return array{0: list<string>, 1: int} the column headings and the row count
     */
    private function readBack(string $bytes, string $name, string $plugin): array
    {
        $admin = $this->core->actor('admin');
        $imports = $this->ops->imports();
        $job = $imports->start($admin, $this->m2->project, $this->core->upload($bytes), $name, $plugin);
        for ($i = 0; $i < 100 && $imports->job($admin, $this->m2->project, $job)->state !== 'review'; $i++) {
            $imports->readChunk($admin, $this->m2->project, $job);
        }
        $review = $imports->review($admin, $this->m2->project, $job, null, []);
        $imports->cancel($admin, $this->m2->project, $job);

        return [array_map(static fn ($c): string => $c->heading, $review->columns), $review->rows];
    }

    /** Sends a download through the test seam, so its after-send callback runs. */
    private function send(Response $response): void
    {
        ob_start();
        try {
            $response->send(false, static function (): void {
            });
        } finally {
            ob_end_clean();
        }
    }

    /** Export files left in the staging area. */
    private function exportFiles(): int
    {
        $root = $this->core->temp->path('data') . '/staging/exports';
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/^(export\.[a-z]+|archive\.zip)$/', $file->getFilename()) === 1) {
                $count++;
            }
        }

        return $count;
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

    /** @return list<string> */
    private function values(DOMXPath $x, string $query): array
    {
        $values = [];
        foreach ($x->query($query) as $node) {
            $values[] = (string) $node->nodeValue;
        }

        return $values;
    }
}
