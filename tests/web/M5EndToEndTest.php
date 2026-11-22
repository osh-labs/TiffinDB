<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Tests\Support\AppearanceMatrix;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';

/**
 * T-E2E-15 and T-E2E-16 (M5 spec part 2 section 11.1), from an empty data
 * folder with the real CoreServices, through the routes only, as a browser
 * would post (the editor's hidden draft and the source text area are read
 * with an HTML parser, so line feeds are kept as a browser keeps them, and
 * sent with CR LF line breaks):
 *
 * - T-E2E-15: an Admin creates Home Inventory with Items and Rooms,
 *   arranges the Items form in the editor (hide, widths, a label, a new
 *   section, moves), adds a link in the source view, and saves; a Member
 *   opens a record: the hidden field is absent, the widths and the link
 *   render, and saving the record works with the hidden required field
 *   empty; the Member is refused every form route; a field the Admin adds
 *   later appears at the end of the first section, and the hidden field
 *   stays hidden.
 * - T-E2E-16: a hand-written form with comments saved in the source view,
 *   then project archive export and import into a new project: the copy's
 *   form is the same bytes; and AppearanceMatrix::rows() covers every
 *   required pair in at most 45 rows.
 * - The editor's output through every form route: editor save, source
 *   view, download, upload of the downloaded file, editor again, with the
 *   stored bytes (read with plain SQL) the same at every step.
 *
 * Synthetic data only.
 */
#[CoversNothing]
final class M5EndToEndTest extends TestCase
{
    private const LINK = 'https://example.com/manual';

    private TempDir $temp;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-m5-e2e-');
        $config = Fixtures::config(false) + ['data_dir' => $this->temp->path(), 'timezone' => 'UTC'];
        $services = new CoreServices(
            $config,
            EndToEndTest::appRoot(),
            new FixedClock('2026-11-03T10:00:00Z'),
            new SeededRandom('tiffindb-m5-end-to-end-seed'),
            new PasswordHasher(['bcrypt' => 4]),
        );
        $this->kernel = new Kernel($config, EndToEndTest::appRoot(), null, new NoProbeServices($services));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    // T-E2E-15: arrange, save, use as a Member, refuse the Member, add a field.

    public function testAdminArrangesTheFormAndAMemberUsesIt(): void
    {
        $admin = $this->install();
        [$project, $items] = $this->homeInventory($admin);
        $member = $this->member($admin, $project);
        $form = $items . '/form';

        // The editor shows the standard form.
        $this->page($admin, $form, 'Form for Items');
        self::assertSame(['Details', 'Name', 'Room', 'Brand', 'Serial number', 'Notes'], $admin->texts('//form[@id="form-editor"]/ol[@class="form-rows"]//li//span[@class="form-row-name"]'));

        // Hide Serial number.
        $this->change($admin, $form, 'hide-' . $this->path($admin, 'Serial number'));
        self::assertSame(['Serial number'], $admin->texts('//section[@aria-labelledby="not-on-form-title"]//span[@class="form-row-name"]'));
        self::assertSame(['Required. While the form does not show it, records can be saved without it.'], $admin->texts('//section[@aria-labelledby="not-on-form-title"]//li/p'));

        // Widths and a label, applied with Enter (do=apply).
        $name = $this->path($admin, 'Name');
        $brand = $this->path($admin, 'Brand');
        $this->change($admin, $form, 'apply', ['width[' . $name . ']' => 'two-thirds', 'label[' . $name . ']' => 'Item name', 'width[' . $brand . ']' => 'one-third']);
        self::assertSame('Item name', (string) $admin->xpath()->query('//input[@id="label-' . $name . '"]/@value')->item(0)?->nodeValue);
        self::assertSame(['two-thirds'], $admin->texts('//select[@id="width-' . $name . '"]/option[@selected]/@value'));
        self::assertSame(0, $admin->xpath()->query('//*[@autofocus]')->length, 'No autofocus after Apply.');

        // Move Brand up: focus stays on its Move up button in its new place.
        $this->change($admin, $form, 'up-' . $brand);
        self::assertSame(['Details', 'Name', 'Brand', 'Room', 'Notes'], $admin->texts('//form[@id="form-editor"]/ol[@class="form-rows"]//li//span[@class="form-row-name"]'));
        $brand = $this->path($admin, 'Brand');
        self::assertSame(['up-' . $brand], $admin->texts('//*[@autofocus]/@value'));

        // A new section, named, with Room moved into it.
        $this->change($admin, $form, 'add-section');
        $section = $this->path($admin, 'New section');
        self::assertSame(['legend-' . $section], $admin->texts('//*[@autofocus]/@id'), 'Focus on the new section\'s name box.');
        $this->change($admin, $form, 'apply', ['legend[' . $section . ']' => 'Where it is']);
        for ($i = 0; $i < 6 && !str_starts_with($this->path($admin, 'Room'), $this->path($admin, 'Where it is') . '-'); $i++) {
            $this->change($admin, $form, 'down-' . $this->path($admin, 'Room'));
            self::assertSame(['down-' . $this->path($admin, 'Room')], $admin->texts('//*[@autofocus]/@value'), 'Focus follows Room.');
        }
        self::assertSame(['Details', 'Name', 'Brand', 'Notes', 'Where it is', 'Room'], $admin->texts('//form[@id="form-editor"]/ol[@class="form-rows"]//li//span[@class="form-row-name"]'));
        self::assertSame('2-1', $this->path($admin, 'Room'));

        // Save.
        $saved = $admin->submit($form, ['do' => 'save', 'draft' => $this->draftOf($admin->page())]);
        self::assertSame(303, $saved->status, $saved->body);
        $admin->follow($saved);
        self::assertSame('The form for Items has been saved.', HtmlAssertions::text($admin->page(), '//main//*[@role="status"]'));

        // A link, through the source view.
        $this->page($admin, $form . '/source', 'Form source for Items');
        $text = $this->textareaOf($admin->page());
        $withLink = str_replace('</fieldset>', '  <p>Read <a href="' . self::LINK . '">the manual</a> first.</p>' . "\n" . '  </fieldset>', $text, $count);
        self::assertSame(2, $count);
        $withLink = (string) preg_replace('~  <p>Read .*?</p>\n  </fieldset>~s', '</fieldset>', $withLink, 1, $replaced);
        self::assertSame(1, $replaced, 'Only the second section gets the link.');
        $sourceSaved = $admin->submit($form . '/source', ['do' => 'save', 'text' => str_replace("\n", "\r\n", $withLink)]);
        self::assertSame(303, $sourceSaved->status, $sourceSaved->body);
        $admin->follow($sourceSaved);
        $stored = $this->download($admin, $items);
        self::assertStringContainsString('<a href="' . self::LINK . '">the manual</a>', $stored);

        // The Member opens a record: no Serial number, widths, the link.
        $record = $this->addRecord($member, $items, 'Stand mixer');
        $member->get($record);
        $x = $member->xpath();
        self::assertSame(0, $x->query('//label[normalize-space(.)="Serial number"]')->length, 'The hidden field is absent.');
        self::assertSame(['Item name'], $member->texts('//div[@class="cell span-8"]//label'));
        self::assertSame(['Brand'], $member->texts('//div[@class="cell span-4"]//label'));
        self::assertSame(['Where it is'], $member->texts('//form[@id="record-form"]/fieldset[2]/legend'));
        self::assertSame(['the manual'], $member->texts('//fieldset[2]//a[@class="form-link"]'));
        $link = $x->query('//a[@class="form-link"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertSame([self::LINK, 'noopener noreferrer', false], [$link->getAttribute('href'), $link->getAttribute('rel'), $link->hasAttribute('target')]);
        self::assertSame(0, $x->query('//@style')->length);
        self::assertSame(0, $member->xpath()->query('//main//a[contains(@href, "/form")]')->length, 'No editor link for a Member.');

        // Saving the record works with the hidden required field empty.
        $action = (string) $x->query('//form[@id="record-form"]/@action')->item(0)?->nodeValue;
        $brandControl = (string) $x->query('//div[@class="cell span-4"]//input/@name')->item(0)?->nodeValue;
        $updated = $member->submit($action, [$brandControl => 'Example brand']);
        self::assertSame(303, $updated->status, $updated->body);
        $member->follow($updated);
        self::assertSame(['Example brand'], $member->texts('//input[@name="' . $brandControl . '"]/@value'));

        // The Member is refused every form route.
        foreach (['/form', '/form/reset', '/form/source', '/form/download', '/form/upload'] as $route) {
            self::assertSame(403, $member->get($items . $route)->status, "GET {$route}");
        }
        $member->get($record);
        foreach (['/form', '/form/reset', '/form/source', '/form/upload'] as $route) {
            $csrf = (string) $member->xpath()->query('//input[@name="_csrf"]/@value')->item(0)?->nodeValue;
            self::assertSame(403, $member->post($items . $route, ['_csrf' => $csrf, 'do' => 'save', 'text' => $stored, 'draft' => $stored])->status, "POST {$route}");
        }
        self::assertSame($stored, $this->download($admin, $items), 'Nothing changed.');

        // A field added later appears at the end of the first section; the hidden one stays hidden.
        $this->addField($admin, $items, 'text', ['name' => 'Colour']);
        $this->page($admin, $form, 'Form for Items');
        self::assertSame(['Item name', 'Brand', 'Notes', 'Colour'], $this->labelsOfFirstSection($admin));
        self::assertSame(['Serial number'], $admin->texts('//section[@aria-labelledby="not-on-form-title"]//span[@class="form-row-name"]'));
        $member->get($record);
        self::assertSame(0, $member->xpath()->query('//label[normalize-space(.)="Serial number"]')->length);
        self::assertSame(1, $member->xpath()->query('//form[@id="record-form"]/fieldset[1]//label[normalize-space(.)="Colour"]')->length);
        $admin->cleanUp();
    }

    // T-E2E-16: a hand-written form survives archive export and import; the appearance matrix.

    public function testAHandWrittenFormSurvivesTheArchive(): void
    {
        $admin = $this->install();
        [$project, $items] = $this->homeInventory($admin);
        $this->page($admin, $items . '/form/source', 'Form source for Items');
        $generated = $this->textareaOf($admin->page());
        preg_match_all('~data-tiffin-field="([a-z2-7]{12})"~', $generated, $keys);
        [$nameKey, $roomKey, $brandKey, $serialKey, $notesKey] = $keys[1];
        $hand = "<!-- Items, by hand -->\r\n<div data-tiffin-form=\"1\">\r\n"
            . "  <fieldset data-tiffin-section=\"details\" ><legend> Details </legend>\r\n"
            . "<div data-tiffin-field=\"{$nameKey}\"   data-tiffin-span=\"8\" data-tiffin-label=\"Item name\"></div>\r\n"
            . "<!-- brand beside the name -->\r\n<div data-tiffin-field=\"{$brandKey}\" data-tiffin-span=\"4\"/>\r\n"
            . "\t<div data-tiffin-field=\"{$notesKey}\" data-tiffin-span=\"full\"></div>\r\n"
            . "<h2>Care</h2><p>See <a href=\"" . self::LINK . "\">the <em>manual</em></a>.</p>\r\n"
            . "  </fieldset>\r\n<fieldset><legend>Where it is</legend><div data-tiffin-field=\"{$roomKey}\"></div></fieldset>\r\n"
            . "</div>\r\n<!-- end -->\r\n";
        self::assertSame(200, $admin->submit($items . '/form/source', ['do' => 'check', 'text' => $hand])->status);
        $saved = $admin->submit($items . '/form/source', ['do' => 'save', 'text' => $hand]);
        self::assertSame(303, $saved->status, $saved->body);
        $admin->follow($saved);
        self::assertStringContainsString('TiffinDB rewrote', HtmlAssertions::text($admin->page(), '//main//*[@role="status"]'));
        $stored = $this->download($admin, $items);
        self::assertSame($stored, $this->storedHtml($project, $items));
        self::assertStringNotContainsString('<!--', $stored);
        self::assertStringNotContainsString($serialKey, $stored);

        // Archive export and import into a new project.
        $admin->get($project . '/export');
        $archive = $admin->submit($project . '/export/archive');
        self::assertSame(200, $archive->status, $archive->body);
        $zip = $this->temp->path('home-inventory.tiffindb.zip');
        file_put_contents($zip, $archive->body());
        $admin->get('/projects/import');
        $imported = $admin->upload('/projects/import', 'file', $zip, 'home-inventory.tiffindb.zip', ['name' => 'Home Inventory copy']);
        self::assertSame(303, $imported->status, $imported->body);
        $copy = substr((string) $imported->header('Location'), 0, strlen('/projects/') + 12);
        self::assertNotSame($project, $copy);
        $copyItems = $copy . substr($items, strlen($project));

        self::assertSame($stored, $this->download($admin, $copyItems), 'The copy\'s form is the same bytes.');
        self::assertSame($stored, $this->storedHtml($copy, $copyItems));
        $this->page($admin, $copyItems . '/form', 'Form for Items');
        self::assertSame($stored, $this->draftOf($admin->page()));
        self::assertSame(['Serial number'], $admin->texts('//section[@aria-labelledby="not-on-form-title"]//span[@class="form-row-name"]'));
        $admin->cleanUp();

        // The appearance matrix of V-30 (M5 spec part 2 section 10.1).
        $rows = AppearanceMatrix::rows();
        self::assertLessThanOrEqual(45, count($rows));
        self::assertCount(900, AppearanceMatrix::candidates());
        $uncovered = AppearanceMatrix::requiredPairs();
        foreach ($rows as $row) {
            self::assertSame(array_keys(AppearanceMatrix::VALUES), array_keys($row));
            foreach ($row as $setting => $value) {
                self::assertContains($value, AppearanceMatrix::VALUES[$setting]);
            }
            if (in_array($row['theme'], AppearanceMatrix::HIGH_CONTRAST, true)) {
                self::assertSame(AppearanceMatrix::DEFAULT_ACCENT, $row['accent'], 'High contrast takes the default accent.');
            }
            foreach (AppearanceMatrix::pairsOf($row) as $pair) {
                unset($uncovered[$pair]);
            }
        }
        self::assertSame([], $uncovered, 'Every required pair is covered.');
        self::assertCount(count($rows), array_unique(array_map('serialize', $rows)), 'No row twice.');
        foreach (AppearanceMatrix::EXTREMES as $extreme) {
            self::assertContains($extreme, $rows);
        }
        self::assertSame($rows, AppearanceMatrix::rows(), 'Deterministic.');
    }

    // The editor's output through every form route keeps the same bytes.

    public function testEditorSourceDownloadUploadThroughTheRoutesKeepTheBytes(): void
    {
        $admin = $this->install();
        [$project, $items] = $this->homeInventory($admin);
        $form = $items . '/form';

        $this->page($admin, $form, 'Form for Items');
        $this->change($admin, $form, 'hide-' . $this->path($admin, 'Brand'));
        $name = $this->path($admin, 'Name');
        $this->change($admin, $form, 'apply', ['width[' . $name . ']' => 'half', 'label[' . $name . ']' => 'Item "name" & \'title\'']);
        $this->change($admin, $form, 'add-section');
        $draft = $this->draftOf($admin->page());
        $saved = $admin->submit($form, ['do' => 'save', 'draft' => str_replace("\n", "\r\n", $draft)]);
        self::assertSame(303, $saved->status, $saved->body);
        $admin->follow($saved);
        $stored = $this->storedHtml($project, $items);
        self::assertSame($draft, $stored, 'The editor\'s draft is what is stored.');

        // Editor again, saved unchanged.
        $this->page($admin, $form, 'Form for Items');
        self::assertSame($stored, $this->draftOf($admin->page()));
        $again = $admin->submit($form, ['do' => 'save', 'draft' => str_replace("\n", "\r\n", $this->draftOf($admin->page()))]);
        self::assertSame(303, $again->status);
        $admin->follow($again);
        self::assertSame($stored, $this->storedHtml($project, $items), 'After the second editor save.');

        // Source view: the text area holds the bytes; saved as a browser sends it, nothing changes.
        $this->page($admin, $form . '/source', 'Form source for Items');
        self::assertSame($stored, $this->textareaOf($admin->page()));
        $sourceSaved = $admin->submit($form . '/source', ['do' => 'save', 'text' => str_replace("\n", "\r\n", $this->textareaOf($admin->page()))]);
        self::assertSame(303, $sourceSaved->status, $sourceSaved->body);
        $admin->follow($sourceSaved);
        self::assertSame($stored, $this->storedHtml($project, $items), 'After the source save.');

        // Download, then upload of that file.
        $file = $this->download($admin, $items);
        self::assertSame($stored, $file);
        $path = $this->temp->path('Items form.html');
        file_put_contents($path, $file);
        $this->page($admin, $form . '/upload', 'Upload a form file for Items');
        $uploaded = $admin->upload($form . '/upload', 'file', $path, 'Items form.html');
        self::assertSame(303, $uploaded->status, $uploaded->body);
        $admin->follow($uploaded);
        self::assertSame($stored, $this->storedHtml($project, $items), 'After the upload.');
        self::assertSame($stored, $this->textareaOf($admin->page()));

        // The editor again.
        $this->page($admin, $form, 'Form for Items');
        self::assertSame($stored, $this->draftOf($admin->page()));
        self::assertSame('Item "name" & \'title\'', (string) $admin->xpath()->query('//input[@id="label-' . $name . '"]/@value')->item(0)?->nodeValue);
        $admin->cleanUp();
    }

    // ------------------------------------------------------------------ helpers

    /** GETs $uri (200) and checks the page and its title. */
    private function page(EndToEndClient $client, string $uri, string $h1): void
    {
        $response = $client->get($uri);
        self::assertSame(200, $response->status, "GET {$uri}");
        self::assertScreen($response->body);
        self::assertSame($h1, HtmlAssertions::text($response->body, '//main//h1'));
    }

    /**
     * Posts an editor change as the page would (its fields, the draft as a
     * browser reads it, $values), expecting the editor page again.
     *
     * @param array<string, string> $values
     */
    private function change(EndToEndClient $client, string $form, string $do, array $values = []): void
    {
        $response = $client->submit($form, ['do' => $do, 'draft' => str_replace("\n", "\r\n", $this->draftOf($client->page()))] + $values);
        self::assertSame(200, $response->status, "do={$do}: " . $response->body);
        self::assertScreen($response->body);
        self::assertSame(0, $client->xpath()->query('//div[@class="error-summary"]')->length, "do={$do}: no problem.");
    }

    /** The path of the editor row named $name (a field's or a section's name). */
    private function path(EndToEndClient $client, string $name): string
    {
        $ids = $client->xpath()->query('//form[@id="form-editor"]//li[div[@class="form-row-head"]/span[@class="form-row-name"][normalize-space(.)="' . $name . '"]]/@id');
        self::assertSame(1, $ids->length, "One row named {$name}.");

        return substr((string) $ids->item(0)?->nodeValue, strlen('row-'));
    }

    /** @return list<string> each field row of the first section: its label, else its field's name, in order */
    private function labelsOfFirstSection(EndToEndClient $client): array
    {
        $labels = [];
        foreach ($client->xpath()->query('//form[@id="form-editor"]/ol/li[1]/ol/li//input[starts-with(@id, "label-")]') as $input) {
            /** @var DOMElement $input */
            $labels[] = $input->getAttribute('value') !== '' ? $input->getAttribute('value') : (string) $client->xpath()->query('//li[@id="row-' . substr($input->getAttribute('id'), 6) . '"]//span[@class="form-row-name"]')->item(0)?->textContent;
        }

        return $labels;
    }

    /** The editor's hidden draft as a browser reads it (an HTML parser keeps line feeds in attribute values). */
    private function draftOf(string $html): string
    {
        $node = self::htmlXpath($html)->query('//form[@id="form-editor"]/input[@name="draft"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node->getAttribute('value');
    }

    /** The source text area's value as a browser reads it (the line feed after the start tag is dropped). */
    private function textareaOf(string $html): string
    {
        $node = self::htmlXpath($html)->query('//textarea[@id="source"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        $value = (string) $node->textContent;

        // libxml keeps the line feed that follows the start tag; a browser drops it (HTML, "textarea").
        return str_starts_with($value, "\n") ? substr($value, 1) : $value;
    }

    private static function htmlXpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** The download's body (200, an attachment). */
    private function download(EndToEndClient $client, string $table): string
    {
        $response = $client->get($table . '/form/download');
        self::assertSame(200, $response->status);
        self::assertTrue($response->isDownload());
        self::assertSame('application/octet-stream', $response->header('Content-Type'));

        return $response->body();
    }

    /** The stored default form of a table, read with plain SQL from the project file. */
    private function storedHtml(string $projectPath, string $tablePath): string
    {
        $file = $this->temp->path('projects/' . substr($projectPath, strlen('/projects/')) . '/project.tiffin');
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare('SELECT "html" FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1');
        $statement->execute([substr($tablePath, -12)]);
        $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $rows);

        return (string) $rows[0];
    }

    /** Installs through the setup screens; returns the signed-in first Admin. */
    private function install(): EndToEndClient
    {
        $admin = new EndToEndClient($this->kernel);
        $admin->get('/setup');
        self::assertSame(303, $admin->submit('/setup/data-folder')->status);
        $admin->get('/setup');
        $code = trim((string) file_get_contents($this->temp->path('setup-code.txt')));
        $created = $admin->submit('/setup/admin', [
            'setup_code' => $code,
            'display_name' => 'Example Admin',
            'username' => 'admin',
            'password' => EndToEndTest::ADMIN_PASSWORD,
            'repeat' => EndToEndTest::ADMIN_PASSWORD,
        ]);
        self::assertSame(303, $created->status, $created->body);
        $admin->follow($created);

        return $admin;
    }

    /**
     * Home Inventory with Items (Name, Room to Rooms, Brand, Serial number
     * required, Notes long text) and Rooms.
     *
     * @return array{0: string, 1: string} the project path and the Items path
     */
    private function homeInventory(EndToEndClient $admin): array
    {
        $admin->get('/projects/new');
        $made = $admin->submit('/projects', ['name' => 'Home Inventory', 'first_table' => 'Items']);
        self::assertSame(303, $made->status, $made->body);
        $project = substr((string) $made->header('Location'), 0, -strlen('/tables'));
        $admin->get($project . '/tables/new');
        self::assertSame(303, $admin->submit($project . '/tables', ['name' => 'Rooms', 'item_name' => 'room'])->status);
        $admin->get($project . '/tables');
        $fields = (string) $admin->xpath()->query('//main//tbody/tr[th[normalize-space(.)="Items"]]/th/a/@href')->item(0)?->nodeValue;
        self::assertStringEndsWith('/fields', $fields);
        $items = substr($fields, 0, -strlen('/fields'));
        $admin->get($fields . '/new?type=relation');
        $target = (string) $admin->xpath()->query('//select[@name="opt_target"]/option[normalize-space(.)="Rooms"]/@value')->item(0)?->nodeValue;
        self::assertSame(303, $admin->submit($fields, ['name' => 'Room', 'opt_target' => $target, 'opt_this_max' => 'one', 'opt_other_max' => 'many'])->status);
        $this->addField($admin, $items, 'text', ['name' => 'Brand']);
        $this->addField($admin, $items, 'text', ['name' => 'Serial number', 'required' => '1']);
        $this->addField($admin, $items, 'long_text', ['name' => 'Notes']);
        $admin->get($fields);
        self::assertSame(1, $admin->xpath()->query('//main//a[normalize-space(.)="Edit the form"][@href="' . $items . '/form"]')->length, 'The Fields page leads to the editor.');

        return [$project, $items];
    }

    /** @param array<string, string> $values */
    private function addField(EndToEndClient $admin, string $items, string $type, array $values): void
    {
        self::assertSame(200, $admin->get($items . '/fields/new?type=' . $type)->status);
        $added = $admin->submit($items . '/fields', $values);
        self::assertSame(303, $added->status, $added->body);
    }

    /** Adds a record named $name through the new record form; returns its path. */
    private function addRecord(EndToEndClient $client, string $items, string $name): string
    {
        $records = $items . '/records';
        $client->get($records . '/new');
        $x = $client->xpath();
        $action = (string) $x->query('//form[@id="record-form"]/@action')->item(0)?->nodeValue;
        $control = (string) $x->query('//div[@class="cell span-8"]//input/@name')->item(0)?->nodeValue;
        self::assertMatchesRegularExpression('/^f_[a-z2-7]{12}\z/', $control);
        $created = $client->submit($action, [$control => $name]);
        self::assertSame(303, $created->status, $created->body);

        return (string) strtok((string) $created->header('Location'), '?');
    }

    /** Adds "Example Member", shares the project with them, and returns their client with their own password. */
    private function member(EndToEndClient $admin, string $project): EndToEndClient
    {
        $admin->get('/admin/people/new');
        $added = $admin->submit('/admin/people', [
            'display_name' => 'Example Member',
            'username' => 'member',
            'role' => 'member',
            'temporary_password' => EndToEndTest::MEMBER_TEMPORARY,
        ]);
        self::assertSame(303, $added->status, $added->body);
        $admin->get($project . '/sharing');
        $memberKey = (string) $admin->xpath()->query('//input[@name="members[]"]/@value')->item(0)?->nodeValue;
        self::assertSame(303, $admin->submit($project . '/sharing', ['members' => [$memberKey]])->status);

        $member = new EndToEndClient($this->kernel);
        $member->get('/sign-in');
        self::assertSame(303, $member->submit('/sign-in', ['username' => 'member', 'password' => EndToEndTest::MEMBER_TEMPORARY])->status);
        $member->get('/account/password');
        self::assertSame(303, $member->submit('/account/password', [
            'current' => EndToEndTest::MEMBER_TEMPORARY,
            'password' => EndToEndTest::MEMBER_PASSWORD,
            'repeat' => EndToEndTest::MEMBER_PASSWORD,
        ])->status);

        return $member;
    }

    private static function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode(self::withoutFormLinks($html), Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
    }

    /**
     * The page with each form link checked (the fixed markup of M5 spec
     * part 2 section 6.3) and its address replaced by `/`, so the shared
     * assertions, which allow no external address but the source link,
     * check everything else (as RecordScreenTest does).
     */
    private static function withoutFormLinks(string $html): string
    {
        $count = preg_match_all('#<a\b[^>]*\bclass="form-link"#', $html);
        $checked = preg_match_all('#<a class="form-link" href="https?://[\x21\x23-\x3B\x3D\x3F-\x7E]+" rel="noopener noreferrer">#', $html);
        self::assertSame($count, $checked, 'Every form link has exactly the fixed markup.');

        return (string) preg_replace('#<a class="form-link" href="https?://[^"]+" rel="noopener noreferrer">#', '<a class="form-link" href="/" rel="noopener noreferrer">', $html);
    }
}
