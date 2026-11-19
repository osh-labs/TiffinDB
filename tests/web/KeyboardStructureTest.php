<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';

/**
 * T-KBD-1 (M2 spec part 2 section 10.1): the keyboard structure of every M2
 * screen, rendered through the kernel with the real route table, templates,
 * strings, and the real CoreServices over a CoreFixture instance holding
 * the M2 synthetic data set (M2Fixture), signed in as the Member through
 * the sign-in form. The test adds two tables of its own: Loans (a Name
 * field and no records, for the empty list) and Samples (one field of
 * every type and one record, for the record form with every type and the
 * date-and-time filter).
 *
 * For each page: (1) no positive `tabindex`, and no `tabindex` except the
 * error summary's -1; (2) every control has an accessible name from a
 * `label` or `aria-label`, and every radio sits in a `fieldset` with a
 * `legend`; (3) interactive elements are native, and the disabled pager
 * and record navigation spans carry no `href` or `tabindex`; (4) the
 * focusable elements in document order carry the accessible names of the
 * reading order in docs/design/m2/ (list-and-search.md 2.2, 3, 4;
 * record.md 7; settings.md 4); (5) each form that changes data has exactly
 * one primary submit button, or is the trash, undo, or sign-out form, or
 * the conflict page's single destructive confirmation; the record form's
 * Save button has `form="record-form"` and the record form holds a one-line
 * text input; (6) every error message is referenced from its control by
 * `aria-describedby`, and every error-summary link targets an id on the
 * page; (7) no `accesskey` and no `autofocus`.
 *
 * Accessible names are computed as a browser would for these elements:
 * `aria-label`, then a `label` with a matching `for`, then a wrapping
 * `label`, then the text content; text inside `aria-hidden="true"` is
 * skipped and text of separate elements is joined with a space.
 * Synthetic data only.
 */
#[CoversNothing]
final class KeyboardStructureTest extends TestCase
{
    /** The top bar on screens with the project search (list-and-search.md 5). */
    private const TOP_SEARCH = [
        'Skip to main content', 'TiffinDB, all projects', 'Projects', 'Search all tables in Home Inventory',
        'Display', 'Example Member, change your password', 'Sign out',
    ];

    /** The top bar elsewhere. */
    private const TOP = [
        'Skip to main content', 'TiffinDB, all projects', 'Projects',
        'Display', 'Example Member, change your password', 'Sign out',
    ];

    private const FOOTER = ['Source code'];

    private const RATING = ['1, lowest', '2', '3', '4', '5, highest', 'Not rated'];

    private CoreFixture $core;
    private Operations $ops;
    private M2Fixture $m2;
    private EndToEndClient $member;
    private string $loans;
    private string $samples;
    /** @var array<string, string> Samples field name => key. */
    private array $sample = [];
    private int $sampleId;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->core->clock->set('2026-11-03T10:00:00Z');
        $this->ops = $this->core->operations();
        $this->m2 = M2Fixture::build($this->core, $this->ops);

        $admin = $this->core->actor('admin');
        $schema = $this->ops->schema();
        $project = $this->m2->project;
        $this->loans = $schema->createTable($admin, $project, 'Loans', 'loan', true)->key;
        $this->samples = $schema->createTable($admin, $project, 'Samples', 'sample', true)->key;
        $this->sample['Name'] = $schema->design($admin, $project, $this->samples)->fields[0]->field->key;
        $types = [
            'Long text' => ['long_text', []], 'Number' => ['number', ['decimals' => 2]], 'Price' => ['currency', ['code' => 'EUR']],
            'Day' => ['date', []], 'Start time' => ['time', []], 'Delivered' => ['date_time', []], 'Run time' => ['duration', []],
            'Done' => ['checkbox', []], 'Size' => ['choice', ['choices' => ['Small', 'Large']]], 'Stars' => ['rating', ['max' => 3]],
            'Site' => ['url', []], 'Contact' => ['email', []], 'Phone' => ['phone', []], 'File' => ['attachment', []],
            'Sample number' => ['auto_number', []],
        ];
        foreach ($types as $name => [$type, $options]) {
            $this->sample[$name] = $schema->addField($admin, $project, $this->samples, $name, $type, $options, $name === 'Number', $name === 'Run time' ? 'How long it ran.' : '')->key;
        }
        $this->sample['Room'] = $schema->addRelationField($admin, $project, $this->samples, 'Room', $this->m2->rooms, Cardinality::One, Cardinality::Many, 'Samples', false, '')->key;
        $this->sampleId = $this->ops->records()->create($admin, $project, $this->samples, [
            $this->sample['Name'] => 'Sample one',
            $this->sample['Number'] => '12.50',
            $this->sample['Delivered'] => '2025-03-15T09:45',
        ]);

        $config = Fixtures::config(false) + ['data_dir' => $this->core->temp->path('data'), 'timezone' => 'UTC'];
        $services = new CoreServices($config, Fixtures::appRoot(), $this->core->clock, new SeededRandom('tiffindb-keyboard-structure-seed'), $this->core->hasher);
        $this->member = new EndToEndClient(new Kernel($config, Fixtures::appRoot(), null, $services));
        $this->member->get('/sign-in');
        $signedIn = $this->member->submit('/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    public function testListViewWithRecords(): void
    {
        $this->check($this->items(), [
            ...self::TOP_SEARCH, ...$this->sidebar(),
            'Add item', 'Find in Items', 'Sort by', 'Apply', 'Filter',
            ...$this->allRows(),
            ...self::FOOTER,
        ]);
        $x = $this->member->xpath();
        self::assertSame(2, $x->query('//nav[@class="pager"]//span[@aria-disabled="true"][not(@href)][not(@tabindex)]')->length, 'Both pager spans are disabled and not focusable.');
        self::assertSame(1, $x->query('//form[@class="list-controls"][@method="get"]//input[@type="search"][@name="q"]')->length, 'Enter in the find box submits the controls.');
    }

    public function testListViewEmptyNoMatchesAndFiltered(): void
    {
        $this->check($this->m2->tablePath($this->loans), [...self::TOP_SEARCH, ...$this->sidebar(), 'Add loan', ...self::FOOTER]);
        self::assertSame('No records yet', HtmlAssertions::text($this->member->page(), '//main//p[@class="empty-state-title"]'));

        $this->check($this->items() . '?q=zzzz', [
            ...self::TOP_SEARCH, ...$this->sidebar(),
            'Add item', 'Find in Items', 'Sort by', 'Apply', 'Filter', 'Show every record',
            ...self::FOOTER,
        ]);

        $tools = [];
        foreach ($this->itemsByName() as $name) {
            if ($this->m2->itemCategories[$name] === 'Tool') {
                $tools[] = $name;
                $tools[] = $this->roomOf($name);
            }
        }
        self::assertNotSame([], $tools);
        $this->check($this->items() . '?filter=' . $this->m2->itemFields['Category'] . '&op=is&v=Tool', [
            ...self::TOP_SEARCH, ...$this->sidebar(),
            'Add item', 'Find in Items', 'Sort by', 'Apply', 'Filter', 'Remove the filter',
            ...$tools,
            ...self::FOOTER,
        ]);

        // A refused search text: the problem is tied to the find box.
        $this->check($this->items() . '?q=' . str_repeat('a', 201), [
            ...self::TOP_SEARCH, ...$this->sidebar(),
            'Add item', 'Find in Items', 'Sort by', 'Apply', 'Filter',
            ...$this->allRows(),
            ...self::FOOTER,
        ]);
        self::assertSame(1, $this->member->xpath()->query('//input[@id="find"][@aria-invalid="true"][contains(concat(" ", @aria-describedby, " "), " find-error ")]')->length);
    }

    public function testFilterStepsForEachFilterableType(): void
    {
        $crumbs = ['Home Inventory', 'Items'];
        $this->check($this->items() . '/filter', [...self::TOP_SEARCH, ...$crumbs, 'Field', 'Next', 'Cancel', ...self::FOOTER]);

        $steps = [
            'Category' => ['is', 'Choice', 'is empty'],
            'Under warranty' => ['is ticked', 'is not ticked'],
            'Room' => ['links to', 'Record', 'has no link'],
            'Purchased' => ['is in this range', 'From', 'To', 'is empty'],
        ];
        foreach ($steps as $field => $controls) {
            $this->check($this->items() . '/filter?field=' . $this->m2->itemFields[$field], [
                ...self::TOP_SEARCH, ...$crumbs, ...$controls, 'Show matching records', 'Cancel', 'Choose another field', ...self::FOOTER,
            ]);
            self::assertSame(1, $this->member->xpath()->query('//main//form//fieldset[legend[normalize-space(.)="Show records where ' . $field . '"]]')->length);
        }

        // Date and time, on Samples.
        $this->check($this->m2->tablePath($this->samples) . '/filter?field=' . $this->sample['Delivered'], [
            ...self::TOP_SEARCH, 'Home Inventory', 'Samples', 'is in this range', 'From', 'To', 'is empty', 'Show matching records', 'Cancel', 'Choose another field', ...self::FOOTER,
        ]);
    }

    public function testSearchResults(): void
    {
        $this->check($this->m2->projectPath() . '/search?q=machine', [
            ...self::TOP_SEARCH, ...$this->sidebar(),
            'Espresso machine', 'Sewing machine', 'Washing machine',
            // Rooms linked to those items match through their linked titles.
            'Kitchen', 'Study',
            'Espresso machine, manufacturer warranty', 'Sewing machine, manufacturer warranty', 'Washing machine, manufacturer warranty',
            ...self::FOOTER,
        ]);
        $this->check($this->m2->projectPath() . '/search?q=zzzz', [...self::TOP_SEARCH, ...$this->sidebar(), ...self::FOOTER]);
        self::assertSame('Nothing found', HtmlAssertions::text($this->member->page(), '//main//p[@class="empty-state-title"]'));
    }

    public function testRecordFormNewRecordAndTrashedRecord(): void
    {
        $mixer = $this->m2->itemPath('Stand mixer');
        $this->check($mixer, [
            ...self::TOP, 'Home Inventory', 'Items', 'Previous item: Sewing machine', 'Next item: Tent, 4-person',
            'Name', 'Kitchen', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            'Stand mixer, manufacturer warranty',
            'Save', 'Back to list', 'Move to trash',
            ...self::FOOTER,
        ]);

        $this->check($this->items() . '/records/new', [
            ...self::TOP, 'Home Inventory', 'Items',
            'Name', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            'Save', 'Cancel',
            ...self::FOOTER,
        ]);

        $this->check($this->m2->itemPath('Old kettle'), [...self::TOP, 'Home Inventory', 'Items', 'Restore', 'Back to list', ...self::FOOTER]);
        self::assertSame('This item is in the trash', HtmlAssertions::text($this->member->page(), '//h1'));
    }

    public function testRecordFormWithEveryTypeAndWithErrors(): void
    {
        $path = $this->m2->tablePath($this->samples) . '/records/' . $this->sampleId;
        $controls = [
            'Name', 'Long text', 'Number (required)', 'Price (euros)', 'Day', 'Start time', 'Delivered', 'Run time', 'Done', 'Size',
            '1, lowest', '2', '3, highest', 'Not rated', 'Site', 'Contact', 'Phone',
        ];
        $expected = [...self::TOP, 'Home Inventory', 'Samples', ...$controls, 'Save', 'Back to list', 'Move to trash', ...self::FOOTER];
        $this->check($path, $expected);
        $html = $this->member->page();
        self::assertSame(1, $this->member->xpath()->query('//main//p[@id][normalize-space(.)="How long it ran. Hours and minutes, for example 1:30."]')->length, 'The duration help line.');

        // Every type with an input gets bad text at once; Number is cleared (required).
        $k = $this->sample;
        $bad = $this->member->submit($path, [
            'f_' . $k['Number'] => '',
            'f_' . $k['Price'] => 'lots',
            'f_' . $k['Day'] => '2025-02-30',
            'f_' . $k['Start time'] => '25:00',
            'f_' . $k['Delivered'] => 'soon',
            'f_' . $k['Run time'] => 'long',
            'f_' . $k['Site'] => 'not a web address',
            'f_' . $k['Contact'] => 'not an email',
        ]);
        self::assertSame(422, $bad->status, $bad->body);
        $this->assertStructure($bad->body, ['Skip to main content', ...array_slice(self::TOP, 1), 'Home Inventory', 'Samples'], 'Samples 422');
        $x = $this->member->xpath();
        self::assertSame(8, $x->query('//div[@class="error-summary"]//a')->length, 'Eight problems, each linked.');
        self::assertSame(8, $x->query('//main//p[@class="error-text"]')->length);
        self::assertNotSame($html, $bad->body);
    }

    public function testConflictPage(): void
    {
        $mixer = $this->m2->itemPath('Stand mixer');
        $this->member->get($mixer);
        $admin = $this->core->actor('admin');
        $id = (string) $this->m2->itemIds['Stand mixer'];
        $records = $this->ops->records();
        $version = (string) $records->screen($admin, $this->m2->project, $this->m2->items, $id, ListRequest::none())->version;
        $this->core->clock->advance(60);
        $records->update($admin, $this->m2->project, $this->m2->items, $id, [$this->m2->itemFields['Serial number'] => 'EX-000-54321'], $version);

        $conflict = $this->member->submit($mixer, ['f_' . $this->m2->itemFields['Serial number'] => 'EX-000-99999']);
        self::assertSame(409, $conflict->status, $conflict->body);
        $this->assertStructure($conflict->body, [...self::TOP, 'Home Inventory', 'Items', 'Keep the saved version', 'Save my version anyway', ...self::FOOTER], 'conflict');
    }

    public function testDisplaySettings(): void
    {
        $this->check('/settings', [
            ...self::TOP,
            'Light', 'Dark', 'Strong contrast, light', 'Strong contrast, dark',
            'Teal', 'Indigo', 'Plum', 'Graphite',
            'Small', 'Standard', 'Large', 'Larger', 'Largest',
            'Standard', 'Roomy', 'Very roomy',
            'Atkinson Hyperlegible Made for easy reading', 'Lexend Wide and open letters', 'Inter Standard. Plain and compact',
            'Source Serif Book-style letters with serifs', 'This device, sans-serif The plain lettering your device uses',
            'This device, serif The book-style lettering your device uses',
            'Reduce movement and animation', 'Save settings', 'Back to standard',
            ...self::FOOTER,
        ]);
        self::assertSame(0, $this->member->xpath()->query('//section[contains(@class, "settings-preview")]//a | //section[contains(@class, "settings-preview")]//button | //section[contains(@class, "settings-preview")]//input')->length, 'The preview has no focusable element.');
    }

    /** A page with its undo notice: Undo comes right after the top bar's controls and the sidebar. */
    public function testUndoNoticeOnTheList(): void
    {
        $mixer = $this->m2->itemPath('Stand mixer');
        $this->member->get($mixer);
        $moved = $this->member->submit($mixer . '/trash');
        self::assertSame(303, $moved->status);
        $list = $this->member->follow($moved);
        self::assertSame(200, $list->status);
        $rows = [];
        foreach ($this->itemsByName() as $name) {
            if ($name !== 'Stand mixer') {
                $rows[] = $name;
                $rows[] = $this->roomOf($name);
            }
        }
        $this->assertStructure($list->body, [
            ...self::TOP_SEARCH, ...$this->sidebar(-1),
            'Undo: restore Stand mixer', 'Add item', 'Find in Items', 'Sort by', 'Apply', 'Filter',
            ...$rows,
            ...self::FOOTER,
        ], 'list with undo');
    }

    // Helpers.

    /** GETs $uri as the Member (200) and asserts the seven rules with the expected names. */
    private function check(string $uri, array $names): void
    {
        $response = $this->member->get($uri);
        self::assertSame(200, $response->status, "GET {$uri}");
        $this->assertStructure($response->body, $names, $uri);
    }

    /**
     * Rules 1 to 7. $names is the full sequence of accessible names of the
     * focusable elements, or, for a page whose middle is checked
     * separately, the expected start of it.
     *
     * @param list<string> $names
     */
    private function assertStructure(string $html, array $names, string $where): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));

        // 1. tabindex: -1 only, on the error summary and on main#main (the
        // skip link's target since M1, never reached by Tab).
        foreach ($x->query('//*[@tabindex]') as $element) {
            /** @var DOMElement $element */
            self::assertSame('-1', $element->getAttribute('tabindex'), "{$where}: only -1 is allowed.");
            $allowed = $element->getAttribute('class') === 'error-summary'
                || ($element->nodeName === 'main' && $element->getAttribute('id') === 'main');
            self::assertTrue($allowed, "{$where}: only the error summary and main#main have a tabindex.");
        }

        // 2. Names and radio groups.
        foreach ($x->query('//input[not(@type="hidden")] | //select | //textarea | //button') as $control) {
            /** @var DOMElement $control */
            $name = $control->nodeName === 'button' ? self::accessibleName($x, $control) : self::labelName($x, $control);
            self::assertNotSame('', $name, "{$where}: {$control->nodeName} " . $control->getAttribute('name') . ' has an accessible name.');
        }
        foreach ($x->query('//input[@type="radio"]') as $radio) {
            self::assertSame(1, $x->query('ancestor::fieldset[1][legend[normalize-space(.) != ""]]', $radio)->length, "{$where}: radio " . $radio->getAttribute('name') . ' is in a fieldset with a legend.');
        }

        // 3. Native interactive elements only.
        self::assertSame(0, $x->query('//a[not(@href)] | //*[@role="button" or @role="link" or @onclick or @contenteditable]')->length, "{$where}: native elements only.");
        foreach ($x->query('//span[contains(concat(" ", @class, " "), " button ")]') as $span) {
            /** @var DOMElement $span */
            self::assertSame('true', $span->getAttribute('aria-disabled'), "{$where}: a span drawn as a button is a disabled one.");
            self::assertFalse($span->hasAttribute('href') || $span->hasAttribute('tabindex'), "{$where}: a disabled span is not focusable.");
        }

        // 4. Order of the focusable elements.
        $actual = [];
        foreach ($x->query('//a[@href] | //button | //input[not(@type="hidden")] | //select | //textarea') as $element) {
            /** @var DOMElement $element */
            $actual[] = in_array($element->nodeName, ['input', 'select', 'textarea'], true) ? self::labelName($x, $element) : self::accessibleName($x, $element);
        }
        $end = $names === [] || $names[count($names) - 1] !== self::FOOTER[0];
        self::assertSame($names, $end ? array_slice($actual, 0, count($names)) : $actual, "{$where}: focus order.");

        // 5. Forms that change data.
        foreach ($x->query('//form[@method="post"]') as $form) {
            /** @var DOMElement $form */
            $action = $form->getAttribute('action');
            $class = $form->getAttribute('class');
            $submits = $x->query('.//button[not(@type) or @type="submit"]', $form);
            $id = $form->getAttribute('id');
            $outside = $id === '' ? null : $x->query('//button[@form="' . $id . '"]');
            if ($outside !== null && $outside->length > 0) {
                self::assertSame('record-form', $id, "{$where}: only the record form is submitted from outside.");
                self::assertSame(0, $submits->length, "{$where}: the record form's buttons are outside it.");
                $submits = $outside;
                self::assertSame(1, $x->query('.//input[@type="text"]', $form)->length > 0 ? 1 : 0, "{$where}: the record form holds a one-line text input.");
            }
            if ($action === '/sign-out' || str_contains($class, 'trash-form') || str_contains($class, 'notice-form')) {
                self::assertSame(1, $submits->length, "{$where}: one button in {$action}.");
                continue;
            }
            $primary = 0;
            $danger = 0;
            foreach ($submits as $button) {
                /** @var DOMElement $button */
                $classes = ' ' . $button->getAttribute('class') . ' ';
                $primary += str_contains($classes, ' button-primary ') ? 1 : 0;
                $danger += str_contains($classes, ' button-danger ') ? 1 : 0;
            }
            $confirmation = $submits->length === 1 && $danger === 1;
            self::assertTrue($primary === 1 || $confirmation, "{$where}: the form posting to {$action} has exactly one primary submit button.");
        }

        // 6. Errors.
        foreach ($x->query('//p[contains(concat(" ", @class, " "), " error-text ")]') as $error) {
            /** @var DOMElement $error */
            $errorId = $error->getAttribute('id');
            self::assertNotSame('', $errorId, "{$where}: an error message has an id.");
            self::assertGreaterThan(0, $x->query('//*[contains(concat(" ", @aria-describedby, " "), " ' . $errorId . ' ")]')->length, "{$where}: {$errorId} is referenced by aria-describedby.");
        }
        foreach ($x->query('//div[@class="error-summary"]//a/@href') as $href) {
            $target = substr((string) $href->nodeValue, 1);
            self::assertStringStartsWith('#', (string) $href->nodeValue);
            self::assertSame(1, $x->query('//*[@id="' . $target . '"]')->length, "{$where}: the summary link #{$target} has a target.");
        }

        // 7.
        self::assertSame(0, $x->query('//*[@accesskey or @autofocus]')->length, "{$where}: no accesskey or autofocus.");
    }

    /** The name of a link or button: aria-label, else its text without aria-hidden parts. */
    private static function accessibleName(DOMXPath $x, DOMElement $element): string
    {
        $label = trim($element->getAttribute('aria-label'));

        return $label !== '' ? $label : self::text($element);
    }

    /** The name of a form control from aria-label or its label only. */
    private static function labelName(DOMXPath $x, DOMElement $control): string
    {
        $label = trim($control->getAttribute('aria-label'));
        if ($label !== '') {
            return $label;
        }
        $id = $control->getAttribute('id');
        if ($id !== '') {
            $for = $x->query('//label[@for="' . $id . '"]')->item(0);
            if ($for instanceof DOMElement) {
                return self::text($for);
            }
        }
        for ($parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ($parent->nodeName === 'label') {
                return self::text($parent);
            }
        }

        return '';
    }

    /** Text content without aria-hidden parts, with element boundaries as spaces. */
    private static function text(DOMNode $node): string
    {
        $parts = [];
        $walk = static function (DOMNode $n) use (&$walk, &$parts): void {
            foreach ($n->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if ($child->getAttribute('aria-hidden') === 'true' || in_array($child->nodeName, ['select', 'textarea', 'input'], true)) {
                        continue;
                    }
                    $parts[] = ' ';
                    $walk($child);
                    $parts[] = ' ';
                } else {
                    $parts[] = (string) $child->textContent;
                }
            }
        };
        $walk($node);

        return trim((string) preg_replace('/\s+/u', ' ', implode('', $parts)));
    }

    private function items(): string
    {
        return $this->m2->tablePath($this->m2->items);
    }

    /**
     * The sidebar's names: Switch project, each table with its count, Tables and fields.
     *
     * @return list<string>
     */
    private function sidebar(int $itemsDelta = 0): array
    {
        return ['Switch project', 'Items ' . (48 + $itemsDelta), 'Rooms 9', 'Warranties 12', 'Loans 0', 'Samples 1', 'Tables and fields'];
    }

    /** @return list<string> The 48 live item names in the list's default order (Name, A to Z, ASCII case-insensitive). */
    private function itemsByName(): array
    {
        $names = array_keys($this->m2->itemIds);
        usort($names, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $names;
    }

    /** @return list<string> Title and room of every item, in list order. */
    private function allRows(): array
    {
        $rows = [];
        foreach ($this->itemsByName() as $name) {
            $rows[] = $name;
            $rows[] = $this->roomOf($name);
        }

        return $rows;
    }

    private function roomOf(string $name): string
    {
        return $this->m2->itemRooms[$name];
    }
}
