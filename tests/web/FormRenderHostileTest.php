<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * M5-R-WP3 review: hostile stored forms rendered through the kernel with
 * the real record-form partials. Every form text (headings, paragraphs,
 * link text, legends, labels of each control kind, alt text) reaches the
 * page escaped exactly once; links carry only class, href, and the fixed
 * rel; cells carry only literal span classes; no element has an event
 * attribute, a style attribute, or a target. Refused forms (bad link
 * addresses, extra link attributes, bad spans) never reach the page.
 * Synthetic data only.
 */
#[CoversNothing]
final class FormRenderHostileTest extends TestCase
{
    private CoreFixture $core;
    private Kernel $kernel;
    private string $project;
    private string $items;
    /** @var array<string, string> */
    private array $keys = [];
    private int $id;
    private int $attachment;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set(FakeServices::NOW);
        $ops = $this->core->operations();
        $services = new FakeServices();
        $services->operations = $ops;
        $actor = $this->core->actor('admin');
        $services->sessions[FakeServices::TOKEN] = new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
        $this->kernel = new Kernel(Fixtures::config(false) + ['timezone' => 'UTC'], Fixtures::appRoot(), Fixtures::applicationRoutes(), $services);
        $this->project = $ops->projects()->create($actor, 'Example Project', 'Items')->key;
        $this->items = $ops->schema()->tables($actor, $this->project)[0]->table->key;
        foreach ($ops->schema()->design($actor, $this->project, $this->items)->fields as $summary) {
            if ($summary->field->name === 'Name') {
                $this->keys['name'] = $summary->field->key;
            }
        }
        foreach (['text' => 'text', 'rating' => 'rating', 'check' => 'checkbox', 'long' => 'long_text', 'auto' => 'auto_number', 'file' => 'attachment'] as $k => $type) {
            $this->keys[$k] = $ops->schema()->addField($actor, $this->project, $this->items, 'Field ' . $k, $type, [], false, '')->key;
        }
        $this->id = $ops->records()->create($actor, $this->project, $this->items, [$this->keys['name'] => 'Drill']);
        $db = $this->core->projectDb($this->project);
        $db->run(
            'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
            [str_repeat('c', 64), 10, 'image/png', 'example.png', CoreFixture::START],
        );
        $this->attachment = (int) $db->lastInsertId();
        $db->run(
            'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, 1)',
            [$this->keys['file'], $this->id, $this->attachment],
        );
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testHostileFormTextIsEscapedOnceEverywhere(): void
    {
        $k = $this->keys;
        $this->storeForm(
            '<div data-tiffin-form="1">'
            . '<h2 data-tiffin-span="6">MARKER &lt;script&gt;alert(1)&lt;/script&gt; &quot;q&quot; \'a\' `b` &amp;amp; <em>&lt;img src=x onerror=alert(3)&gt;</em></h2>'
            . '<p data-tiffin-span="6">P <a href="https://example.com/a?x=1&amp;y=%22&amp;z=%3C#frag">&lt;/a&gt;&lt;script&gt;alert(9)&lt;/script&gt;</a> <strong>"&gt;&lt;b&gt;</strong><br/>end</p>'
            . '<fieldset data-tiffin-section="details" data-tiffin-span="12"><legend>L &lt;/legend&gt;&lt;script&gt;alert(4)&lt;/script&gt; onmouseover=alert(5)</legend>'
            . '<div data-tiffin-field="' . $k['text'] . '" data-tiffin-label="Lb &quot;&gt;&lt;script&gt;alert(6)&lt;/script&gt;" data-tiffin-span="7"></div>'
            . '<div data-tiffin-field="' . $k['rating'] . '" data-tiffin-label="R &lt;/legend&gt;&lt;i&gt;" data-tiffin-span="5"></div>'
            . '<div data-tiffin-field="' . $k['check'] . '" data-tiffin-label="C &lt;/label&gt;&lt;i&gt;"></div>'
            . '<div data-tiffin-field="' . $k['auto'] . '" data-tiffin-label="A &lt;/p&gt;&lt;i&gt;"></div>'
            . '<div data-tiffin-field="' . $k['file'] . '" data-tiffin-label="F &lt;/p&gt;&lt;i&gt;" data-tiffin-span="full"></div>'
            . '<div data-tiffin-field="' . $k['long'] . '" data-tiffin-label="N &lt;/textarea&gt;&lt;script&gt;alert(8)&lt;/script&gt;"></div>'
            . '<h3>h3 &lt;x&gt;</h3><hr/>'
            . '<img src="attachment:' . $this->attachment . '" alt="alt &quot; onerror=&quot;alert(7) &lt;x&gt;"/>'
            . '</fieldset>'
            . '<div data-tiffin-field="' . $k['name'] . '"></div>'
            . '</div>',
        );
        [$html, $x] = $this->render();

        $form = (string) $x->query('//form[@id="record-form"]')->item(0)?->textContent;
        foreach ([
            'MARKER <script>alert(1)</script> "q" \'a\' `b` &amp;', '<img src=x onerror=alert(3)>', '</a><script>alert(9)</script>', '"><b>',
            'L </legend><script>alert(4)</script> onmouseover=alert(5)', 'Lb "><script>alert(6)</script>', 'R </legend><i>', 'C </label><i>',
            'A </p><i>', 'F </p><i>', 'N </textarea><script>alert(8)</script>', 'h3 <x>',
        ] as $text) {
            self::assertStringContainsString($text, $form, 'Shown as text, escaped exactly once.');
        }
        foreach (['<script>', '<img src=x', '<i>', '<x>', '<b>', '</legend><', '</textarea><'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        $links = $x->query('//a[@class="form-link"]');
        self::assertSame(1, $links->length);
        $link = $links->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertSame(['class' => 'form-link', 'href' => 'https://example.com/a?x=1&y=%22&z=%3C#frag', 'rel' => 'noopener noreferrer'], self::attributes($link));
        self::assertSame(['alt " onerror="alert(7) <x>'], array_map(static fn (DOMElement $e): string => $e->getAttribute('alt'), iterator_to_array($x->query('//form[@id="record-form"]//img[@class="file-image" and not(ancestor::*[contains(@class, "file-row")])]'))));
        $cells = array_map(static fn (DOMElement $e): string => $e->getAttribute('class'), iterator_to_array($x->query('//div[contains(concat(" ", @class, " "), " cell ")]')));
        self::assertSame(['cell span-6', 'cell span-6', 'cell span-12', 'cell span-7', 'cell span-5', 'cell', 'cell', 'cell', 'cell', 'cell', 'cell', 'cell', 'cell'], $cells);
        self::assertSame(0, $x->query('//*[@style or @target or @onerror or @onmouseover or @onclick or @onload]')->length);
        self::assertSame(0, $x->query('//style | //script[not(@src)]')->length);
    }

    /** @return array<string, array{string}> */
    public static function refusedBodies(): array
    {
        return [
            'javascript' => ['<p><a href="javascript:alert(1)">x</a></p>'],
            'mixed case' => ['<p><a href="JaVaScRiPt:alert(1)">x</a></p>'],
            'character reference' => ['<p><a href="javascript&#58;alert(1)">x</a></p>'],
            'data' => ['<p><a href="data:text/html,x">x</a></p>'],
            'quote' => ['<p><a href="https://example.com/&quot;onmouseover=alert(1)">x</a></p>'],
            'angle' => ['<p><a href="https://example.com/&lt;x&gt;">x</a></p>'],
            'backtick' => ['<p><a href="https://example.com/`x">x</a></p>'],
            'backslash' => ['<p><a href="https://example.com/\\x">x</a></p>'],
            'tab' => ['<p><a href="https://example.com/a&#9;b">x</a></p>'],
            'protocol relative' => ['<p><a href="//example.com/">x</a></p>'],
            'target' => ['<p><a href="https://example.com/" target="_blank">x</a></p>'],
            'rel' => ['<p><a href="https://example.com/" rel="opener">x</a></p>'],
            'onclick' => ['<p><a href="https://example.com/" onclick="alert(1)">x</a></p>'],
            'style' => ['<p style="color: red">x</p>'],
            'class' => ['<p><a href="https://example.com/" class="evil">x</a></p>'],
            'span 13' => ['<p data-tiffin-span="13">x</p>'],
            'span 0' => ['<p data-tiffin-span="0">x</p>'],
            'span with attribute' => ['<p data-tiffin-span="6 onload=alert(1)">x</p>'],
            'span 06' => ['<p data-tiffin-span="06">x</p>'],
            'script' => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('refusedBodies')]
    public function testRefusedFormsNeverReachThePage(string $body): void
    {
        $this->storeForm('<div data-tiffin-form="1"><h2>MARKER</h2>' . $body . '<div data-tiffin-field="' . $this->keys['name'] . '"></div></div>');
        [$html, $x] = $this->render();

        self::assertStringNotContainsString('MARKER', $html, 'The stored form is refused: the generated layout is shown.');
        self::assertSame(0, $x->query('//a[@class="form-link"] | //*[@style or @target or @onclick or @onload or @onmouseover]')->length);
        self::assertStringNotContainsString('javascript', strtolower($html));
        self::assertSame(1, $x->query('//input[@id="f-' . $this->keys['name'] . '"]')->length);
    }

    private function storeForm(string $html): void
    {
        $db = $this->core->projectDb($this->project);
        $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "table_key" = ? AND "is_default" = 1', [$html, $this->items]);
        self::assertSame(1, (int) $db->value('SELECT count(*) FROM "_tiffin_forms" WHERE "table_key" = ? AND "html" = ?', [$this->items, $html]));
    }

    /** @return array{string, DOMXPath} */
    private function render(): array
    {
        $uri = '/projects/' . $this->project . '/tables/' . $this->items . '/records/' . $this->id;
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => FakeServices::TOKEN]))[0];
        self::assertSame(200, $response->status);
        $csp = (string) ($response->headers['Content-Security-Policy'] ?? '');
        self::assertStringNotContainsString('unsafe', $csp);

        return [$response->body, new DOMXPath(HtmlAssertions::assertWellFormed($response->body))];
    }

    /** @return array<string, string> */
    private static function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->nodeName] = (string) $attribute->nodeValue;
        }
        ksort($attributes);

        return $attributes;
    }
}
