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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;
use TiffinDB\Tests\Support\CoreFixture;
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
 * The form source view, download, and upload (docs/design/m5/source.md;
 * M5 spec part 2 sections 4.2 and 6.6) through the kernel with the real
 * templates, strings, and core operations over a CoreFixture instance.
 *
 * T-WEB-73: download headers (200, application/octet-stream, attachment
 * with the cleaned name, no-store, nosniff, the sandboxed policy) and the
 * body (stored, refused stored, and generated text); HEAD without body.
 * T-WEB-75 (source and upload): every POST route of this package refuses
 * a missing or wrong CSRF token and a cross-origin Origin (403) and
 * stores nothing.
 * T-WEB-79: the source states, Check and Save, "Line" and "Near line",
 * corpus D20 and a closing textarea tag staying inside the text area, the
 * text area value read back from the page and posted with CR LF line
 * breaks giving the stored text again (T35, T15, an edited form), and the
 * upload states and notices. Synthetic data only.
 */
#[CoversClass(FormSourceController::class)]
final class FormSourceScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $name;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->uploadLimits = new UploadLimits(2 * 1048576, 8 * 1048576);
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $admin = $this->admin();
        $schema = $this->ops->schema();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->name = $schema->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $schema->addField($admin, $this->project, $this->items, 'Serial', 'text', [], true, '');
        $this->ops->projects()->setSharing($admin, $this->project, [$this->core->person('member')->key]);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ------------------------------------------------------------------ source view

    public function testTheSourceViewOfAStoredForm(): void
    {
        $stored = $this->storedHtml();
        $html = $this->page($this->t() . '/form/source');
        $x = $this->xpath($html);

        self::assertSame('Form source for Items - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@id="main"][@class="page page-form"]')->length);
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Form', 'Source'], $this->crumbs($x));
        self::assertSame(['/projects', '/projects/' . $this->project . '/tables', $this->t() . '/fields', $this->t() . '/form'], $this->texts($x, '//nav[@class="breadcrumb"]/a/@href'));
        self::assertSame('Form source for Items', $this->text($x, '//div[@class="page-head"]//h1'));
        self::assertStringStartsWith('The form as text, in TiffinDB\'s form format', $this->text($x, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame('Source', $this->text($x, '//nav[@class="form-tabs"]//a[@aria-current="page"]'));
        self::assertSame($this->t() . '/form/download', $this->attr($x, '//nav[@class="form-tabs"]//a[.="Download the form"]', 'href'));
        self::assertSame(0, $x->query('//p[contains(@class, "notice")] | //div[contains(@class, "notice")]')->length, 'A stored form has no state note.');

        $form = $this->one($x, '//form[@id="form-source"]');
        self::assertSame(['post', $this->t() . '/form/source'], [$form->getAttribute('method'), $form->getAttribute('action')]);
        self::assertFalse($form->hasAttribute('enctype'));
        self::assertSame(hash('sha256', $stored), $this->attr($x, '//form[@id="form-source"]/input[@name="base"]', 'value'));
        self::assertSame('Form source', $this->text($x, '//label[@for="source"]'));
        self::assertSame('Up to 256 KB. Element and attribute names are lower case. Links must start with http:// or https://.', $this->text($x, '//p[@id="source-help"]'));
        $area = $this->one($x, '//textarea[@id="source"]');
        self::assertSame(
            ['input source-input', 'text', '24', 'false', 'off', 'off', 'off', 'ltr', 'source-help', null],
            [$area->getAttribute('class'), $area->getAttribute('name'), $area->getAttribute('rows'), $area->getAttribute('spellcheck'), $area->getAttribute('autocapitalize'), $area->getAttribute('autocomplete'), $area->getAttribute('wrap'), $area->getAttribute('dir'), $area->getAttribute('aria-describedby'), $area->hasAttribute('aria-invalid') ? 'x' : null],
        );
        self::assertSame($stored, $this->areaValue($x));
        self::assertStringContainsString('<textarea class="input source-input" id="source" name="text" rows="24" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" dir="ltr" aria-describedby="source-help">' . "\n" . '&lt;div data-tiffin-form=&quot;1&quot;&gt;', $html);

        self::assertSame(['save', 'check'], $this->texts($x, '//form[@id="form-source"]//button[@type="submit"][@name="do"]/@value'));
        self::assertSame(['Save', 'Check'], $this->texts($x, '//form[@id="form-source"]//button'));
        self::assertSame('button button-primary button-large', $this->attr($x, '//button[@value="save"]', 'class'));
        self::assertSame([$this->t() . '/form'], $this->texts($x, '//form[@id="form-source"]//div[@class="form-actions"]/a[@class="button button-large"]/@href'));
        self::assertSame(0, $x->query('//section[contains(@class, "form-report")]')->length, 'No report before a check.');
    }

    public function testWithoutAStoredFormTheGeneratedTextIsShown(): void
    {
        $generated = $this->storedHtml();
        $this->core->projectDb($this->project)->run('DELETE FROM "_tiffin_forms" WHERE "table_key" = ?', [$this->items]);
        $x = $this->xpath($this->page($this->t() . '/form/source'));
        self::assertSame('This is the standard form, made from the table\'s fields. It is stored the first time you save.', $this->text($x, '//p[@class="notice notice-muted"]'));
        self::assertSame($generated, $this->areaValue($x));
        self::assertSame(hash('sha256', ''), $this->attr($x, '//input[@name="base"]', 'value'), 'The save token of no stored form.');
    }

    public function testARefusedStoredTextIsShownExactlyWithItsProblem(): void
    {
        $refused = "<div data-tiffin-form=\"1\">\n<p>Example</p>\n<p onclick=\"alert(1)\">x</p>\n</div>\n";
        $this->setHtml($refused);
        $html = $this->page($this->t() . '/form/source');
        $x = $this->xpath($html);
        self::assertSame('The saved text has a problem', $this->text($x, '//div[@class="notice notice-warn"]/p[@class="notice-title"]'));
        self::assertSame([
            'The saved text has a problem',
            'Line 3: The element p cannot have the attribute onclick, or is missing one it needs.',
            'Records use the standard layout until it is fixed. Fix the text below and save.',
        ], $this->texts($x, '//div[@class="notice notice-warn"]/p'));
        self::assertSame($refused, $this->areaValue($x));
        self::assertSame(0, $x->query('//*[@onclick]')->length);
        self::assertSame(hash('sha256', $refused), $this->attr($x, '//input[@name="base"]', 'value'));

        // It can be fixed and saved from here.
        $fixed = "<div data-tiffin-form=\"1\">\n  <p>Example</p>\n</div>\n";
        $response = $this->post('/form/source', ['text' => $fixed, 'base' => hash('sha256', $refused), 'do' => 'save']);
        self::assertSame(303, $response->status);
        self::assertSame($fixed, $this->storedHtml());
        self::assertSame(0, $this->xpath($this->page($this->t() . '/form/source'))->query('//div[contains(@class, "notice-warn")]')->length);
    }

    public function testCheckReportsAndStoresNothing(): void
    {
        $before = $this->storedHtml();
        $text = "<div data-tiffin-form=\"1\">\n<!-- a comment -->\n<div data-tiffin-field=\"" . Corpus::KEY2 . "\"></div>\n<p><a href=\"https://example.com/help\"></a></p>\n</div>";
        $response = $this->post('/form/source', ['text' => $text, 'base' => hash('sha256', $before), 'do' => 'check']);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('No problems found.', $this->text($x, '//div[@class="notice"][@role="status"]/p'));
        $report = '//section[@class="panel form-report"]';
        self::assertSame([
            '2 fields are not on this form:',
            'Required fields that the form does not show are not required when records are saved through it.',
            '1 field element names a field that is not in Items. They show nothing.',
            '1 link has no text, so it is not shown.',
        ], $this->texts($x, $report . '/p'));
        self::assertSame(['Name', 'Serial Required'], $this->texts($x, $report . '/ul[@class="form-report-list"]/li'));
        self::assertSame($text, $this->areaValue($x), 'The text as typed, comment included.');
        self::assertSame($before, $this->storedHtml());

        // An unknown do is a check; so is none.
        foreach (['', 'delete', 'SAVE'] as $do) {
            $again = $this->post('/form/source', ['text' => $text, 'base' => hash('sha256', $before), 'do' => $do]);
            self::assertSame(200, $again->status, $do);
            self::assertSame($before, $this->storedHtml());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'attribute: Line' => [
            "<div data-tiffin-form=\"1\">\n<p>Fine</p>\n<p onclick=\"alert(1)\">x</p>\n</div>",
            'Line 3: The element p cannot have the attribute onclick, or is missing one it needs.',
        ];
        yield 'text: Near line' => [
            "<div data-tiffin-form=\"1\">\n<p>a</p>\nloose text\n</div>",
            'Near line 1: Text must be inside a heading, a paragraph, or a legend.',
        ];
        yield 'empty: no line' => ['', 'A form is one div element with data-tiffin-form="1", with everything inside it.'];
        yield 'element' => [
            "<div data-tiffin-form=\"1\">\n\n<script>alert(1)</script>\n</div>",
            'Line 3: Forms cannot hold the element script.',
        ];
        yield 'href' => [
            "<div data-tiffin-form=\"1\">\n<p><a href=\"javascript:alert(1)\">x</a></p>\n</div>",
            'Line 2: Links must be full web addresses that start with http:// or https://, with no spaces.',
        ];
        yield 'too large' => [
            "<div data-tiffin-form=\"1\">\n<p>" . str_repeat('a', 262144) . "</p>\n</div>",
            'The form is larger than 262,144 bytes.',
        ];
    }

    #[DataProvider('refusals')]
    public function testARefusedTextShowsTheProblemWithItsLineAndKeepsTheText(string $text, string $problem): void
    {
        $before = $this->storedHtml();
        foreach (['check', 'save'] as $do) {
            $response = $this->post('/form/source', ['text' => $text, 'base' => hash('sha256', $before), 'do' => $do]);
            self::assertSame(422, $response->status, $do);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame([$problem], $this->texts($x, '//div[@class="error-summary"]//li'));
            self::assertSame(['#source'], $this->texts($x, '//div[@class="error-summary"]//li/a/@href'));
            self::assertSame($problem, $this->text($x, '//p[@class="error-text"][@id="source-error"]'));
            self::assertSame(['true', 'source-help source-error'], [$this->attr($x, '//textarea', 'aria-invalid'), $this->attr($x, '//textarea', 'aria-describedby')]);
            self::assertSame($text, $this->areaValue($x));
            self::assertSame(0, $x->query('//div[@role="status"] | //section[contains(@class, "form-report")]')->length);
            self::assertSame($before, $this->storedHtml(), 'Nothing stored.');
        }
    }

    public function testSavingAndItsThreeNotices(): void
    {
        $base = hash('sha256', $this->storedHtml());
        $canonical = "<div data-tiffin-form=\"1\">\n  <p>About this item</p>\n</div>\n";

        $saved = $this->post('/form/source', ['text' => $canonical, 'base' => $base, 'do' => 'save']);
        self::assertSame(303, $saved->status);
        self::assertSame($this->t() . '/form/source', $saved->header('Location'));
        self::assertSame(['key' => 'notice.form_source_saved', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame($canonical, $this->storedHtml());
        $x = $this->xpath($this->page($this->t() . '/form/source'));
        self::assertSame('The form for Items has been saved.', $this->text($x, '//div[@class="notice"]'));
        self::assertSame($canonical, $this->areaValue($x));

        // The same text again: nothing changed.
        $this->post('/form/source', ['text' => $canonical, 'base' => hash('sha256', $canonical), 'do' => 'save']);
        self::assertSame(['key' => 'notice.form_source_unchanged', 'params' => []], $this->services->flash[self::ADMIN]);
        self::assertSame('Nothing had changed, so the form was not saved again.', $this->text($this->xpath($this->page($this->t() . '/form/source')), '//div[@class="notice"]'));

        // A comment and other spacing: stored in TiffinDB's layout.
        $this->post('/form/source', ['text' => "<div data-tiffin-form=\"1\"><!-- note --><p>Other</p></div>", 'base' => hash('sha256', $canonical), 'do' => 'save']);
        self::assertSame(['key' => 'notice.form_source_rewritten', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame("<div data-tiffin-form=\"1\">\n  <p>Other</p>\n</div>\n", $this->storedHtml());
        self::assertSame(
            'The form for Items has been saved. TiffinDB rewrote the text in its own layout: comments and spacing were not kept.',
            $this->text($this->xpath($this->page($this->t() . '/form/source')), '//div[@class="notice"]'),
        );
    }

    public function testASaveAfterSomeoneElseSavedAsksAgainAndThenReplaces(): void
    {
        $old = hash('sha256', $this->storedHtml());
        $this->ops->forms()->source($this->admin(), $this->project, $this->items, ['text' => "<div data-tiffin-form=\"1\"><p>Theirs</p></div>", 'base' => $old, 'do' => 'save']);
        $theirs = $this->storedHtml();
        $mine = "<div data-tiffin-form=\"1\">\n  <p>Mine</p>\n</div>\n";

        $response = $this->post('/form/source', ['text' => $mine, 'base' => $old, 'do' => 'save']);
        self::assertSame(422, $response->status);
        $x = $this->xpath($response->body);
        self::assertSame(['Someone saved this form after you opened it. Save again to replace their version with yours.'], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame(0, $x->query('//div[@class="error-summary"]//a')->length, 'A form-wide problem links to nothing.');
        self::assertSame(0, $x->query('//textarea[@aria-invalid]')->length);
        self::assertSame($mine, $this->areaValue($x));
        self::assertSame($theirs, $this->storedHtml());
        $current = (string) $this->attr($x, '//input[@name="base"]', 'value');
        self::assertSame(hash('sha256', $theirs), $current);

        self::assertSame(303, $this->post('/form/source', ['text' => $mine, 'base' => $current, 'do' => 'save'])->status);
        self::assertSame($mine, $this->storedHtml());
    }

    public function testAClosingTextareaTagStaysInsideTheTextArea(): void
    {
        $base = hash('sha256', $this->storedHtml());
        $d20 = Corpus::all()['D20']['text'];
        self::assertIsString($d20);
        $hostile = "<div data-tiffin-form=\"1\">\n</textarea><script>alert(1)</script><textarea>\n&lt;/textarea&gt;</div>";
        foreach ([[$d20, 200], [$hostile, 422], ["</textarea>\n<p>x</p>", 422]] as [$text, $status]) {
            $response = $this->post('/form/source', ['text' => $text, 'base' => $base, 'do' => 'check']);
            self::assertSame($status, $response->status);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame(1, $x->query('//textarea')->length);
            self::assertSame(0, $x->query('//body//script')->length);
            self::assertSame($text, $this->areaValue($x), 'The parsed page gives the posted text back.');
            self::assertStringNotContainsString('</textarea><script>', $response->body);
        }
        // Saved, D20 comes back from the stored text the same way.
        $this->post('/form/source', ['text' => $d20, 'base' => $base, 'do' => 'save']);
        $x = $this->xpath($this->page($this->t() . '/form/source'));
        self::assertSame($this->storedHtml(), $this->areaValue($x));
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;', $this->storedHtml());
    }

    /** @return iterable<string, array{string}> */
    public static function roundTrips(): iterable
    {
        yield 'T35' => ['T35'];
        yield 'T15' => ['T15'];
        yield 'edited form' => [''];
    }

    #[DataProvider('roundTrips')]
    public function testTheTextAreaValuePostedWithCrLfGivesTheStoredTextAgain(string $case): void
    {
        if ($case === '') {
            $text = "<div data-tiffin-form=\"1\">\n<fieldset><legend>Main \u{00E9}</legend><div data-tiffin-field=\"" . $this->name . "\" data-tiffin-span=\"6\" data-tiffin-label=\"What it &quot;is&quot;\"></div></fieldset>\n"
                . "<p>Tab\there &amp; <strong>bold</strong> <a href=\"https://example.com/a?b=1&amp;c=2\">link</a></p><hr/>\n</div>";
        } else {
            $text = Corpus::all()[$case]['text'];
            self::assertIsString($text);
        }
        $saved = $this->post('/form/source', ['text' => $text, 'base' => hash('sha256', $this->storedHtml()), 'do' => 'save']);
        self::assertSame(303, $saved->status);
        $stored = $this->storedHtml();

        $x = $this->xpath($this->page($this->t() . '/form/source'));
        $value = $this->areaValue($x);
        self::assertSame($stored, $value);
        $base = (string) $this->attr($x, '//input[@name="base"]', 'value');

        $crlf = str_replace("\n", "\r\n", $value);
        $again = $this->post('/form/source', ['text' => $crlf, 'base' => $base, 'do' => 'save']);
        self::assertSame(303, $again->status);
        self::assertSame(['key' => 'notice.form_source_unchanged', 'params' => []], $this->services->flash[self::ADMIN], 'Posted back as a browser sends it, nothing changed.');
        self::assertSame($stored, $this->storedHtml());
    }

    // ------------------------------------------------------------------ download

    public function testTheDownloadIsTheStoredTextAsAnAttachment(): void
    {
        $stored = $this->storedHtml();
        $response = $this->request(self::ADMIN, 'GET', $this->t() . '/form/download');
        self::assertSame(200, $response->status);
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertSame('attachment; filename="Items form.html"; filename*=UTF-8\'\'Items%20form.html', $response->header('Content-Disposition'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->header('Content-Security-Policy'));
        self::assertSame((string) strlen($stored), $response->header('Content-Length'));
        self::assertSame($stored, $response->body);
        self::assertTrue($response->isDownload());

        // A refused stored text exactly, and the generated text when there is no row.
        $refused = "<div data-tiffin-form=\"1\">\n<script>alert(1)</script>\n</div>\n\x00";
        $this->setHtml($refused);
        $body = $this->request(self::ADMIN, 'GET', $this->t() . '/form/download');
        self::assertSame([$refused, 'application/octet-stream'], [$body->body, $body->header('Content-Type')]);
        $this->core->projectDb($this->project)->run('DELETE FROM "_tiffin_forms" WHERE "table_key" = ?', [$this->items]);
        self::assertSame($stored, $this->request(self::ADMIN, 'GET', $this->t() . '/form/download')->body);
        self::assertSame(1, (int) $this->core->projectDb($this->project)->value('SELECT 1 + COUNT(*) FROM "_tiffin_forms"'), 'A download writes nothing.');
    }

    public function testHeadSendsTheHeadersWithoutTheBody(): void
    {
        $response = $this->request(self::ADMIN, 'HEAD', $this->t() . '/form/download');
        self::assertSame(200, $response->status);
        $steps = [];
        ob_start();
        try {
            $response->send(true, static function (string $step, string|int $value) use (&$steps): void {
                $steps[] = [$step, $value];
            });
        } finally {
            $out = (string) ob_get_clean();
        }
        self::assertSame('', $out);
        self::assertContains(['header', 'Content-Type: application/octet-stream'], $steps);
        self::assertContains(['header', 'X-Content-Type-Options: nosniff'], $steps);
    }

    /** @return iterable<string, array{string, string}> */
    public static function hostileNames(): iterable
    {
        yield 'quotes, slash, semicolon' => ['Say "hi"; a/b', 'attachment; filename="Say _hi__ a_b form.html"; filename*=UTF-8\'\'Say%20%22hi%22%3B%20a%2Fb%20form.html'];
        yield 'accents' => ["Caf\u{00E9}", 'attachment; filename="Caf__ form.html"; filename*=UTF-8\'\'Caf%C3%A9%20form.html'];
    }

    #[DataProvider('hostileNames')]
    public function testHostileTableNamesAreCleanedInTheFileName(string $name, string $disposition): void
    {
        $this->ops->schema()->updateTable($this->admin(), $this->project, $this->items, $name, 'item', null);
        $response = $this->request(self::ADMIN, 'GET', $this->t() . '/form/download');
        self::assertSame(200, $response->status);
        self::assertSame($disposition, $response->header('Content-Disposition'));
    }

    public function testBidirectionalAndLongNamesAreCleanedInTheFileName(): void
    {
        // Table names cannot hold such characters (the core cleans them), so the cleaning is checked on the name the core returns.
        self::assertSame(
            'attachment; filename="Itemslmth form.html"; filename*=UTF-8\'\'Itemslmth%20form.html',
            Response::contentDisposition("Items\u{202E}lmth\u{2066} form.html"),
        );
        $this->ops->schema()->updateTable($this->admin(), $this->project, $this->items, str_repeat('Long name ', 6), 'item', null);
        $header = (string) $this->request(self::ADMIN, 'GET', $this->t() . '/form/download')->header('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="Long name Long name', $header);
        self::assertLessThan(500, strlen($header));
    }

    // ------------------------------------------------------------------ upload

    public function testTheUploadPage(): void
    {
        $html = $this->page($this->t() . '/form/upload');
        $x = $this->xpath($html);
        self::assertSame('Upload a form file for Items - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//main[@class="page page-form page-form-narrow"]')->length);
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Form', 'Upload'], $this->crumbs($x));
        self::assertSame('If TiffinDB can read the file, it replaces the form for Items at once. Download the current form first if you may want it back.', $this->text($x, '//p[@class="lead"]'));
        self::assertSame('Upload a form file', $this->text($x, '//nav[@class="form-tabs"]//a[@aria-current="page"]'));
        $form = $this->one($x, '//form[@id="form-upload"]');
        self::assertSame(['post', $this->t() . '/form/upload', 'multipart/form-data'], [$form->getAttribute('method'), $form->getAttribute('action'), $form->getAttribute('enctype')]);
        self::assertSame('Form file', $this->text($x, '//label[@for="file"]'));
        self::assertSame('One file of at most 256 KB, in TiffinDB\'s form format, saved as UTF-8.', $this->text($x, '//p[@id="file-help"]'));
        $input = $this->one($x, '//input[@id="file"]');
        self::assertSame(['file', 'file', '.html,.htm,.txt', 'file-help'], [$input->getAttribute('type'), $input->getAttribute('name'), $input->getAttribute('accept'), $input->getAttribute('aria-describedby')]);
        self::assertSame(['Upload and use it'], $this->texts($x, '//form[@id="form-upload"]//button[contains(@class, "button-primary")]'));
        self::assertSame($this->t() . '/form', $this->attr($x, '//form[@id="form-upload"]//div[@class="form-actions"]/a', 'href'));
        self::assertSame(0, $x->query('//p[.="Nothing was changed."]')->length);
    }

    public function testUploadingAFileAndBothNotices(): void
    {
        $canonical = "<div data-tiffin-form=\"1\">\n  <p>Uploaded</p>\n</div>\n";
        $response = $this->upload($canonical, 'items form.html');
        self::assertSame(303, $response->status);
        self::assertSame($this->t() . '/form/source', $response->header('Location'));
        self::assertSame(['key' => 'notice.form_uploaded', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame($canonical, $this->storedHtml());
        $x = $this->xpath($this->page($this->t() . '/form/source'));
        self::assertSame('The file is now the form for Items.', $this->text($x, '//div[@class="notice"]'));
        self::assertSame($canonical, $this->areaValue($x));

        $this->upload("\u{FEFF}<div data-tiffin-form=\"1\"><!-- c --><p>Again</p></div>", 'x.txt');
        self::assertSame(['key' => 'notice.form_uploaded_rewritten', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertSame("<div data-tiffin-form=\"1\">\n  <p>Again</p>\n</div>\n", $this->storedHtml());
        self::assertSame(
            'The file is now the form for Items. TiffinDB rewrote it in its own layout: comments and spacing were not kept.',
            $this->text($this->xpath($this->page($this->t() . '/form/source')), '//div[@class="notice"]'),
        );
    }

    public function testARefusedFileShowsItsLineAndNotItsText(): void
    {
        $before = $this->storedHtml();
        $text = "<div data-tiffin-form=\"1\">\n<p>" . Corpus::MARKER . "</p>\n<p onclick=\"x\">y</p>\n</div>";
        $response = $this->upload($text, 'hostile.html');
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        $line = 'Line 3: The element p cannot have the attribute onclick, or is missing one it needs.';
        self::assertSame([$line], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame(['#file'], $this->texts($x, '//div[@class="error-summary"]//li/a/@href'));
        self::assertSame($line, $this->text($x, '//p[@id="file-error"]'));
        self::assertSame(['true', 'file-help file-error'], [$this->attr($x, '//input[@id="file"]', 'aria-invalid'), $this->attr($x, '//input[@id="file"]', 'aria-describedby')]);
        self::assertSame('Nothing was changed.', $this->text($x, '//main/p[.="Nothing was changed."]'));
        self::assertStringNotContainsString(Corpus::MARKER, $response->body);
        self::assertStringNotContainsString('hostile.html', $response->body);
        self::assertSame($before, $this->storedHtml());
    }

    /** @return iterable<string, array{string, string}> */
    public static function uploadProblems(): iterable
    {
        yield 'empty' => ['', 'That file is empty. Choose a form file.'];
        yield 'over 256 KB' => [str_repeat('a', 262145), 'The form is larger than 262,144 bytes.'];
        yield 'UTF-16' => [mb_convert_encoding('<div data-tiffin-form="1"></div>', 'UTF-16LE', 'UTF-8'), 'Line 1: The text holds a NUL character, which forms cannot hold.'];
        yield 'Latin-1' => ["<div data-tiffin-form=\"1\">\n<p>Caf\xE9</p>\n</div>", 'Line 2: The text is not in UTF-8. Save the file as UTF-8 and try again.'];
    }

    #[DataProvider('uploadProblems')]
    public function testCoreProblemsAre422AtTheFileFieldAndStoreNothing(string $bytes, string $text): void
    {
        $before = $this->storedHtml();
        $response = $this->upload($bytes, 'form.html');
        self::assertSame(422, $response->status);
        $x = $this->xpath($response->body);
        self::assertSame([$text], $this->texts($x, '//div[@class="error-summary"]//li'));
        self::assertSame('Nothing was changed.', $this->text($x, '//main/p[.="Nothing was changed."]'));
        self::assertSame($before, $this->storedHtml());
    }

    /** @return iterable<string, array{int, string}> */
    public static function uploadErrors(): iterable
    {
        yield 'no file' => [UPLOAD_ERR_NO_FILE, 'Choose a file.'];
        yield 'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'That file is larger than 256 KB, the largest this installation accepts.'];
        yield 'partial' => [UPLOAD_ERR_PARTIAL, 'The file did not arrive completely. Try again.'];
    }

    #[DataProvider('uploadErrors')]
    public function testUploadErrorsAre422BeforeTheCore(int $error, string $text): void
    {
        $before = $this->storedHtml();
        $file = new UploadedFile('file', 'form.html', 10, $error, $this->core->upload('<div data-tiffin-form="1"></div>'));
        $response = $this->send($this->t() . '/form/upload', ['file' => $file], true);
        self::assertSame(422, $response->status);
        self::assertSame([$text], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//li'));
        self::assertSame($before, $this->storedHtml());

        // No file at all, and a file under another name.
        self::assertSame(422, $this->send($this->t() . '/form/upload', [], true)->status);
        $other = new UploadedFile('other', 'form.html', 10, UPLOAD_ERR_OK, $this->core->upload('<div data-tiffin-form="1"></div>'));
        self::assertSame(422, $this->send($this->t() . '/form/upload', ['other' => $other], true)->status);
        self::assertSame($before, $this->storedHtml());
    }

    // ------------------------------------------------------------------ CSRF and origin (T-WEB-75)

    public function testEveryPostRouteRefusesAMissingOrWrongTokenAndAForeignOrigin(): void
    {
        $before = $this->storedHtml();
        $text = "<div data-tiffin-form=\"1\">\n  <p>Changed</p>\n</div>\n";
        $file = fn (): UploadedFile => new UploadedFile('file', 'form.html', strlen($text), UPLOAD_ERR_OK, $this->core->upload($text));
        $source = ['text' => $text, 'base' => hash('sha256', $before), 'do' => 'save'];

        foreach ([null, '', str_repeat('b', 64), ['x']] as $token) {
            $post = $token === null ? $source : $source + ['_csrf' => $token];
            $r = Fixtures::fullRequest('POST', $this->t() . '/form/source', $post, ['tdb_session' => self::ADMIN]);
            self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status);
            $upload = $this->uploadRequest($this->t() . '/form/upload', ['file' => $file()], $token === null ? [] : ['_csrf' => $token], []);
            self::assertSame(403, Fixtures::handle($this->kernel, $upload)[0]->status);
        }
        // The right token from a foreign origin.
        foreach (['https://evil.example', 'null'] as $origin) {
            $server = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => $origin];
            $r = Fixtures::fullRequest('POST', $this->t() . '/form/source', $source + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $server);
            self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status, $origin);
            $upload = $this->uploadRequest($this->t() . '/form/upload', ['file' => $file()], ['_csrf' => FakeServices::SESSION_CSRF], $server);
            self::assertSame(403, Fixtures::handle($this->kernel, $upload)[0]->status, $origin);
        }
        $site = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
        $r = Fixtures::fullRequest('POST', $this->t() . '/form/source', $source + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $site);
        self::assertSame(403, Fixtures::handle($this->kernel, $r)[0]->status);
        self::assertSame($before, $this->storedHtml(), 'No refused POST stored anything.');

        // The same requests with the token and the own origin work.
        $own = ['HTTP_HOST' => 'tiffindb.example', 'HTTP_ORIGIN' => 'http://tiffindb.example'];
        $upload = $this->uploadRequest($this->t() . '/form/upload', ['file' => $file()], ['_csrf' => FakeServices::SESSION_CSRF], $own);
        self::assertSame(303, Fixtures::handle($this->kernel, $upload)[0]->status);
        self::assertSame($text, $this->storedHtml());
    }

    // ------------------------------------------------------------------ helpers

    private function storedHtml(): string
    {
        return (string) $this->core->projectDb($this->project)->value('SELECT "html" FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]);
    }

    private function setHtml(string $html): void
    {
        $this->core->projectDb($this->project)->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "is_default" = 1 AND "table_key" = ?', [$html, $this->items]);
    }

    /**
     * The text area's value as an HTML parser gives it: the element's text
     * with the one line feed after the start tag dropped.
     */
    private function areaValue(DOMXPath $x): string
    {
        $value = $this->one($x, '//textarea[@id="source"]')->textContent;
        self::assertStringStartsWith("\n", $value, 'The template writes a line feed first.');

        return substr($value, 1);
    }

    /** The table's path. */
    private function t(): string
    {
        return '/projects/' . $this->project . '/tables/' . $this->items;
    }

    /** @param array<string, string> $post */
    private function post(string $suffix, array $post): Response
    {
        return $this->request(self::ADMIN, 'POST', $this->t() . $suffix, $post);
    }

    private function upload(string $bytes, string $clientName): Response
    {
        return $this->send($this->t() . '/form/upload', ['file' => new UploadedFile('file', $clientName, strlen($bytes), UPLOAD_ERR_OK, $this->core->upload($bytes))], true);
    }

    /** @param array<string, UploadedFile> $files */
    private function send(string $uri, array $files, bool $csrf): Response
    {
        return Fixtures::handle($this->kernel, $this->uploadRequest($uri, $files, $csrf ? ['_csrf' => FakeServices::SESSION_CSRF] : [], []))[0];
    }

    /**
     * A multipart POST as fromGlobals() would build it, with $files set
     * through the constructor.
     *
     * @param array<string, UploadedFile> $files
     * @param array<string, mixed>        $post
     * @param array<string, string>       $server
     */
    private function uploadRequest(string $uri, array $files, array $post, array $server): Request
    {
        $r = Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => self::ADMIN], $server);

        return new Request(
            $r->method, $r->path, $r->basePath, $r->query, $r->malformed, $r->post, $r->cookies, $r->secure, $r->clientAddress,
            $r->secFetchSite, $r->host, $r->serverAddress, $r->serverPort, $r->documentRoot, $r->origin, $files, $r->contentLength,
        );
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

    /** @param array<string, mixed> $post */
    private function request(string $token, string $method, string $uri, array $post = []): Response
    {
        if ($method === 'POST') {
            $post['_csrf'] = FakeServices::SESSION_CSRF;
        }

        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, $post, ['tdb_session' => $token]))[0];
    }

    private function page(string $uri): string
    {
        $response = $this->request(self::ADMIN, 'GET', $uri);
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

    private function one(DOMXPath $x, string $query): DOMElement
    {
        $node = $x->query($query)->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $this->one($x, $query);

        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    private function text(DOMXPath $x, string $query): string
    {
        $texts = $this->texts($x, $query);
        self::assertNotSame([], $texts, $query);

        return $texts[0];
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
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
}
