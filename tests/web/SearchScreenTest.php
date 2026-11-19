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
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\SearchController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-29 (M2-WP6): search results across the tables of a project
 * (docs/design/m2/list-and-search.md section 4), through the kernel with
 * the real templates and strings and real core operations over a
 * CoreFixture instance. Synthetic data only.
 */
#[CoversClass(SearchController::class)]
final class SearchScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $rooms;
    private string $notes;
    private string $itemName;
    private string $itemNotes;
    private string $roomName;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $schema = $this->ops->schema();
        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->itemName = $schema->design($admin, $this->project, $this->items)->fields[0]->field->key;
        $this->itemNotes = $schema->addField($admin, $this->project, $this->items, 'Notes', 'long_text', [], false, '')->key;
        $this->rooms = $schema->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
        $this->roomName = $schema->design($admin, $this->project, $this->rooms)->fields[0]->field->key;
        $this->notes = $schema->createTable($admin, $this->project, 'Warranties', 'warranty', true)->key;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testResultsInSeveralTables(): void
    {
        $mixer = $this->core->addRecord($this->project, $this->items, [$this->itemName => 'Stand mixer', $this->itemNotes => "Kept in the kitchen\ncupboard"]);
        $this->core->addRecord($this->project, $this->items, [$this->itemName => 'Floor lamp']);
        $kitchen = $this->core->addRecord($this->project, $this->rooms, [$this->roomName => 'Kitchen']);
        $this->share();

        $html = $this->page(self::MEMBER, $this->p('/search?q=%20kitchen%20'));
        $x = $this->xpath($html);

        self::assertSame('Search results, Home Inventory - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//div[@class="app-columns"]/nav[@class="sidebar"]/following-sibling::main[@class="page page-list"]')->length);
        self::assertSame(0, $x->query('//nav[@class="sidebar"]//*[@aria-current]')->length, 'No table is current.');
        self::assertSame(' kitchen ', $this->attr($x, '//input[@id="top-search"]', 'value'), 'The top search box holds the search text.');
        self::assertSame('Search results', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('2 records match “kitchen”', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));

        self::assertSame(['Items: 1 match', 'Rooms: 1 match'], $this->texts($x, '//section[@class="result-group"]/h2[@class="section-title"]'));
        foreach ($x->query('//section[@class="result-group"]') as $section) {
            \assert($section instanceof \DOMElement);
            $id = $section->getAttribute('aria-labelledby');
            self::assertSame(1, $x->query('//h2[@id="' . $id . '"]', $section)->length);
        }
        self::assertSame('group-' . $this->items, $this->attr($x, '//section[1]', 'aria-labelledby'));
        self::assertSame(['Stand mixer', 'Kitchen'], $this->texts($x, '//ol[@class="result-list"]/li[@class="result"]/a[@class="result-title"]'));
        self::assertSame($this->p('/tables/' . $this->items . '/records/' . $mixer . '?q=kitchen'), $this->attr($x, '//section[1]//a[@class="result-title"]', 'href'));
        self::assertSame($this->p('/tables/' . $this->rooms . '/records/' . $kitchen . '?q=kitchen'), $this->attr($x, '//section[2]//a[@class="result-title"]', 'href'));
        self::assertSame(['Notes: Kept in the kitchen cupboard'], $this->texts($x, '//p[@class="result-match"]'), 'Only the title matched in Rooms.');
        self::assertSame(0, $x->query('//a[@class="result-more"] | //div[@class="empty-state"] | //div[contains(@class, "notice-warn")]')->length);
    }

    public function testShowAllWhenATableHasMoreThanTenMatches(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->core->addRecord($this->project, $this->items, [$this->itemName => sprintf('Example lamp %02d', $i)]);
        }
        $html = $this->page(self::ADMIN, $this->p('/search?q=lamp'));
        $x = $this->xpath($html);
        self::assertSame('12 records match “lamp”', HtmlAssertions::text($html, '//p[@class="lead"]'));
        self::assertSame(['Items: 12 matches'], $this->texts($x, '//h2[@class="section-title"]'));
        self::assertSame(10, $x->query('//li[@class="result"]')->length);
        self::assertSame('Show all 12 in Items', HtmlAssertions::text($html, '//section/a[@class="result-more"]'));
        self::assertSame($this->p('/tables/' . $this->items . '?q=lamp'), $this->attr($x, '//a[@class="result-more"]', 'href'));
        self::assertSame(1, $x->query('//ol[@class="result-list"]/following-sibling::a[@class="result-more"]')->length);
    }

    public function testUntitledHitsReadItemAndId(): void
    {
        $id = $this->core->addRecord($this->project, $this->items, [$this->itemNotes => 'Example spare key']);
        $html = $this->page(self::ADMIN, $this->p('/search?q=spare'));
        $x = $this->xpath($html);
        self::assertSame(['Item ' . $id], $this->texts($x, '//a[@class="result-title"]'));
        self::assertSame(['Notes: Example spare key'], $this->texts($x, '//p[@class="result-match"]'));
    }

    public function testLongValuesAreCutAroundTheMatch(): void
    {
        $long = str_repeat('Example filler words ', 6) . 'the needle sits here ' . str_repeat('and more filler text ', 6);
        $this->core->addRecord($this->project, $this->items, [$this->itemName => 'Box', $this->itemNotes => $long]);
        $html = $this->page(self::ADMIN, $this->p('/search?q=NEEDLE'));
        $match = HtmlAssertions::text($html, '//p[@class="result-match"]');
        self::assertStringStartsWith('Notes: …', $match);
        self::assertStringEndsWith('…', $match);
        self::assertStringContainsString('needle', $match);
        self::assertSame(120, mb_strlen(substr($match, strlen('Notes: '))));
    }

    public function testExcerpt(): void
    {
        self::assertSame('one two', SearchController::excerpt("one\ntwo", 'two'));
        self::assertSame('a b c', SearchController::excerpt("a\r\nb\rc", 'b'));
        $short = str_repeat('x', 120);
        self::assertSame($short, SearchController::excerpt($short, 'x'));

        // Match near the start: no cut at the start, cut at the end.
        $text = 'match ' . str_repeat('y', 200);
        $excerpt = SearchController::excerpt($text, 'MATCH');
        self::assertSame(120, mb_strlen($excerpt));
        self::assertStringStartsWith('match y', $excerpt);
        self::assertStringEndsWith("y\u{2026}", $excerpt);

        // Match in the middle: 40 characters before it, both ends cut.
        $text = str_repeat('a', 100) . 'match' . str_repeat('b', 100);
        $excerpt = SearchController::excerpt($text, 'match');
        self::assertSame("\u{2026}" . str_repeat('a', 40) . 'match' . str_repeat('b', 73) . "\u{2026}", $excerpt);
        self::assertSame(120, mb_strlen($excerpt));

        // Match near the end: cut at the start only.
        $text = str_repeat('a', 200) . 'match';
        self::assertSame("\u{2026}" . str_repeat('a', 40) . 'match', SearchController::excerpt($text, 'match'));

        // Multibyte text counts characters, not bytes.
        $text = str_repeat('é', 100) . 'match' . str_repeat('ü', 100);
        $excerpt = SearchController::excerpt($text, 'match');
        self::assertSame("\u{2026}" . str_repeat('é', 40) . 'match' . str_repeat('ü', 73) . "\u{2026}", $excerpt);
    }

    public function testNoSearchText(): void
    {
        foreach (['/search', '/search?q=', '/search?q=%20%20', '/search?q[]=x'] as $uri) {
            $html = $this->page(self::ADMIN, $this->p($uri));
            $x = $this->xpath($html);
            self::assertSame('Type a word in the search box at the top of the page to look through every table in Home Inventory.', HtmlAssertions::text($html, '//p[@class="lead"]'), $uri);
            self::assertSame(0, $x->query('//section | //div[@class="empty-state"] | //div[contains(@class, "notice-warn")]')->length, $uri);
        }
        self::assertSame(null, $this->attr($this->xpath($this->page(self::ADMIN, $this->p('/search'))), '//input[@id="top-search"]', 'value'));
    }

    public function testNothingFound(): void
    {
        $this->core->addRecord($this->project, $this->items, [$this->itemName => 'Stand mixer']);
        $html = $this->page(self::ADMIN, $this->p('/search?q=zzz'));
        $x = $this->xpath($html);
        self::assertSame(['Nothing found', 'No records in Home Inventory match “zzz”. Check the spelling, or try a shorter word.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame(0, $x->query('//section | //p[@class="lead"] | //div[@class="empty-state"]/a')->length);
    }

    public function testARefusedSearchText(): void
    {
        $this->core->addRecord($this->project, $this->items, [$this->itemName => str_repeat('a', 210)]);
        $html = $this->page(self::ADMIN, $this->p('/search?q=' . str_repeat('a', 201)));
        $x = $this->xpath($html);
        self::assertSame('Use at most 200 characters.', HtmlAssertions::text($html, '//div[@class="notice notice-warn"]/p'));
        self::assertSame(0, $x->query('//section | //div[@class="empty-state"] | //p[@class="lead"]')->length);

        $html = $this->page(self::ADMIN, $this->p('/search?q=' . rawurlencode("a\x01")));
        self::assertSame('Remove the special characters, such as tabs or invisible characters.', HtmlAssertions::text($html, '//div[@class="notice notice-warn"]/p'));
    }

    public function testStoredTextWithMarkupIsEscaped(): void
    {
        $evil = '<script>alert("x")</script> & {q}';
        $this->ops->schema()->updateTable($this->admin(), $this->project, $this->items, '<i>Items</i>', 'item', $this->itemName);
        $this->core->addRecord($this->project, $this->items, [$this->itemName => $evil, $this->itemNotes => 'note <img src=x onerror=alert(1)> script']);
        $html = $this->page(self::ADMIN, $this->p('/search?q=' . rawurlencode('script')));
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<i>Items', $html);
        $x = $this->xpath($html);
        self::assertSame([$evil], $this->texts($x, '//a[@class="result-title"]'));
        self::assertSame(['<i>Items</i>: 1 match'], $this->texts($x, '//h2[@class="section-title"]'));
        self::assertSame(['Notes: note <img src=x onerror=alert(1)> script'], $this->texts($x, '//p[@class="result-match"]'));

        $html = $this->page(self::ADMIN, $this->p('/search?q=' . rawurlencode('"><b>')));
        self::assertStringNotContainsString('"><b>', $html);
        self::assertSame('"><b>', $this->attr($this->xpath($html), '//input[@id="top-search"]', 'value'));
    }

    public function testAccess(): void
    {
        self::assertSame(404, $this->request(self::MEMBER, $this->p('/search?q=x'))->status, 'Not shared: not found.');
        self::assertSame(404, $this->request(self::ADMIN, '/projects/aaaaaaaaaaaa/search?q=x')->status);
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

    private function share(): void
    {
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    private function p(string $path): string
    {
        return '/projects/' . $this->project . $path;
    }

    private function request(string $token, string $uri): Response
    {
        return Fixtures::handle($this->kernel, Fixtures::fullRequest('GET', $uri, [], ['tdb_session' => $token]))[0];
    }

    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, $uri);
        self::assertSame(200, $response->status, $uri);
        HtmlAssertions::assertShell($response->body, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($response->body, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($response->body);

        return $response->body;
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $x->query($query)->item(0);

        return $node instanceof \DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
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
