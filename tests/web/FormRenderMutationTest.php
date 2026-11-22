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
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Core\Forms\FormMutationTest;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\FormFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/../core/forms/FormMutationTest.php';

/**
 * T-SAN-4 (M5 spec section 11.6): the mutations of T-SAN-3 that the parser
 * accepts, with the same seed and the same documents (the mutation itself
 * is FormMutationTest's own, called through a closure bound to it, so the
 * two sets cannot drift apart), each stored as the Items form and rendered
 * through the kernel on the record screen with the real templates. The
 * form part of every page (`form#record-form`) holds only elements that
 * the record-form partials and the partials they include can write
 * (collected from the template files, PHP comments left out), no
 * attribute starting `on`, no `style` attribute, no `script`, `style`,
 * `iframe`, `object`, `embed`, `svg`, or `math` element, and every
 * `a href` inside it starts `http://` or `https://`. Synthetic data only.
 */
#[CoversNothing]
final class FormRenderMutationTest extends TestCase
{
    /** FormMutationTest's seed and count (T-SAN-3). */
    private const SEED = '20261124';
    private const MUTATIONS = 2000;
    private const DOCUMENTS = 50;

    private const FORBIDDEN = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];

    /** The partials the record form is written with (M5 spec part 2 section 6.1). */
    private const ROOTS = ['record-form/blocks', 'record-form/block', 'record-form/cell', 'record-form/section', 'record-form/field', 'record-form/static', 'form-inline'];

    private CoreFixture $core;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private int $id;

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
        $name = $ops->schema()->design($actor, $this->project, $this->items)->fields[0]->field->key;
        $this->id = $ops->records()->create($actor, $this->project, $this->items, [$name => 'Example item']);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testAcceptedMutationsRenderOnlyTheRecordFormElements(): void
    {
        $allowed = self::partialElements();
        foreach (self::FORBIDDEN as $element) {
            self::assertNotContains($element, $allowed, "The partials never write {$element}.");
        }
        self::assertContains('a', $allowed);
        self::assertContains('fieldset', $allowed);

        $accepted = self::acceptedMutations();
        self::assertGreaterThan(100, count($accepted), 'A useful number of accepted mutations.');

        $links = 0;
        $seen = [];
        foreach ($accepted as $n => $text) {
            $this->storeForm($text);
            $html = $this->render();
            $where = 'Mutation ' . $n;
            $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
            $forms = $x->query('//form[@id="record-form"]');
            self::assertSame(1, $forms->length, $where);
            $form = $forms->item(0);
            self::assertInstanceOf(DOMElement::class, $form);

            foreach ($x->query('.//*', $form) as $element) {
                /** @var DOMElement $element */
                $name = $element->localName;
                $seen[$name] = true;
                self::assertContains($name, $allowed, "{$where}: <{$name}> is an element of the record-form partials.");
                self::assertNotContains($name, self::FORBIDDEN, $where);
                foreach ($element->attributes as $attribute) {
                    self::assertDoesNotMatchRegularExpression('/^on/i', $attribute->nodeName, "{$where}: no event attribute.");
                    self::assertNotSame('style', strtolower($attribute->nodeName), "{$where}: no style attribute.");
                }
            }
            foreach ($x->query('.//a[@href]', $form) as $a) {
                /** @var DOMElement $a */
                self::assertMatchesRegularExpression('~\Ahttps?://~i', $a->getAttribute('href'), "{$where}: a form link is http or https.");
                self::assertSame('noopener noreferrer', $a->getAttribute('rel'), $where);
                self::assertFalse($a->hasAttribute('target'), $where);
                $links++;
            }
            // The rest of the page (the layout's own marks are SVG): no inline code anywhere.
            self::assertSame(0, $x->query('//@style | //*[@target] | //style | //script[not(@src)] | //iframe | //object | //embed')->length, $where);
        }
        self::assertGreaterThan(0, $links, 'Some accepted mutations hold links that reach the page.');
        self::assertArrayHasKey('h2', $seen, 'Headings reach the page.');
        self::assertArrayHasKey('p', $seen, 'Paragraphs reach the page.');
    }

    /**
     * The accepted texts of T-SAN-3, by mutation number.
     *
     * @return array<int, string>
     */
    private static function acceptedMutations(): array
    {
        $test = new FormMutationTest('testMutationsAreRefusedOrSafe');
        $random = new SeededRandom(self::SEED);
        $mutate = \Closure::bind(function (string $text) use ($random): array {
            $this->random = $random;

            return $this->mutate($text);
        }, $test, FormMutationTest::class);
        $fixture = new FormFixture();
        $parser = new FormParser(FieldTypes::standard());
        $serializer = new FormSerializer();
        $documents = [];
        for ($i = 0; $i < self::DOCUMENTS; $i++) {
            $documents[] = $serializer->serialize($fixture->document());
        }
        $accepted = [];
        for ($n = 0; $n < self::MUTATIONS; $n++) {
            [, $text] = $mutate($documents[$n % self::DOCUMENTS]);
            try {
                $parser->parse($text);
            } catch (FormRejected) {
                continue;
            }
            $accepted[$n] = $text;
        }

        return $accepted;
    }

    /**
     * The element names the record-form partials, and every partial they
     * include, can write: tags in the templates' HTML and PHP strings, PHP
     * comments left out.
     *
     * @return list<string>
     */
    private static function partialElements(): array
    {
        $root = Fixtures::appRoot() . '/templates/partials/';
        $queue = self::ROOTS;
        $done = [];
        $elements = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($done[$name])) {
                continue;
            }
            $done[$name] = true;
            $source = (string) file_get_contents($root . $name . '.php');
            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            preg_match_all('~<([a-z][a-z0-9]*)\b~', $code, $tags);
            foreach ($tags[1] as $tag) {
                $elements[$tag] = true;
            }
            preg_match_all("~partial\\('([a-z0-9/-]+)'~", $code, $included);
            foreach ($included[1] as $partial) {
                $queue[] = $partial;
            }
        }
        unset($elements['php']);
        $list = array_keys($elements);
        sort($list);

        return $list;
    }

    private function storeForm(string $html): void
    {
        $db = $this->core->projectDb($this->project);
        $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "table_key" = ? AND "is_default" = 1', [$html, $this->items]);
    }

    private function render(): string
    {
        $uri = '/projects/' . $this->project . '/tables/' . $this->items . '/records/' . $this->id;
        $response = Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => FakeServices::TOKEN]))[0];
        self::assertSame(200, $response->status, $uri);

        return $response->body;
    }
}
