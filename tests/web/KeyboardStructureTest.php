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
use TiffinDB\Tests\Support\IoFixture;
use TiffinDB\Tests\Support\M2Fixture;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';
require_once __DIR__ . '/ScreenStructureTest.php';

/**
 * T-KBD-3 (M4 spec part 2 section 10.1) is in the methods named testM4*:
 * the M4 screens over the M4 data set in a CoreFixture of their own, with
 * the seven rules below and the six M4 items of assertM4Rules.
 *
 * T-KBD-2 (M3 spec part 2 section 9.1) is in the methods after
 * testUndoNoticeOnTheList: the M3 screens as the Admin (a second client
 * signed in through the form), with the same seven rules and the four M3
 * items (mapping controls named after their column, radio groups in
 * fieldsets, the progress status a live region before the Continue
 * button, and the file input's label and help).
 *
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
    private Kernel $kernel;

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
        $this->kernel = new Kernel($config, Fixtures::appRoot(), null, $services);
        $this->member = new EndToEndClient($this->kernel);
        $this->member->get('/sign-in');
        $signedIn = $this->member->submit('/sign-in', ['username' => 'member', 'password' => CoreFixture::PASSWORD]);
        self::assertSame(303, $signedIn->status, $signedIn->body);
    }

    protected function tearDown(): void
    {
        $this->core4?->remove();
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
            ...self::TOP_SEARCH, ...$this->sidebar(0, false),
            'Espresso machine', 'Sewing machine', 'Washing machine',
            // Rooms linked to those items match through their linked titles.
            'Kitchen', 'Study',
            'Espresso machine, manufacturer warranty', 'Sewing machine, manufacturer warranty', 'Washing machine, manufacturer warranty',
            ...self::FOOTER,
        ]);
        $this->check($this->m2->projectPath() . '/search?q=zzzz', [...self::TOP_SEARCH, ...$this->sidebar(0, false), ...self::FOOTER]);
        self::assertSame('Nothing found', HtmlAssertions::text($this->member->page(), '//main//p[@class="empty-state-title"]'));
    }

    public function testRecordFormNewRecordAndTrashedRecord(): void
    {
        $mixer = $this->m2->itemPath('Stand mixer');
        $this->check($mixer, [
            ...self::TOP, 'Home Inventory', 'Items', 'Previous item: Sewing machine', 'Next item: Tent, 4-person',
            'Name', 'Kitchen', 'Choose a different room', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            // M4 record controls (docs/design/m4/record-controls.md section 3).
            'Open receipt-stand-mixer.pdf', 'Replace receipt-stand-mixer.pdf', 'Remove receipt-stand-mixer.pdf', 'Add a file',
            'Open stand-mixer.jpg', 'Replace stand-mixer.jpg', 'Remove stand-mixer.jpg', 'Add an image',
            'Stand mixer, manufacturer warranty', 'Unlink Stand mixer, manufacturer warranty', 'Link another warranty',
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
        // M4 record controls: the empty one-link relation and the attachment field (record-controls.md section 3).
        $expected = [...self::TOP, 'Home Inventory', 'Samples', ...$controls, 'Choose a record', 'Add a file', 'Save', 'Back to list', 'Move to trash', ...self::FOOTER];
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

    // T-KBD-2 (M3 spec part 2 section 9.1): the M3 screens, as the Admin.

    /** The top bar for the Admin (People and Plugins). */
    private const ADMIN_TOP = [
        'Skip to main content', 'TiffinDB, all projects', 'Projects', 'People', 'Plugins',
        'Display', 'Example Admin, change your password', 'Sign out',
    ];

    public function testImportStepOneEmptyAndWithAnUploadProblem(): void
    {
        $admin = $this->admin();
        $start = $this->m2->projectPath() . '/import';
        $formats = ['Decide from the file name', 'CSV .csv, .tsv, .txt', 'Excel workbook .xlsx'];
        $expected = [...self::ADMIN_TOP, 'Home Inventory', 'File', ...$formats, 'Continue', 'Cancel', ...self::FOOTER];
        $this->checkAs($admin, $start, $expected);
        $this->assertFileInput($admin->page());

        $refused = $admin->submit($start);
        self::assertSame(422, $refused->status, $refused->body);
        $this->assertStructure($refused->body, [...self::ADMIN_TOP, 'Home Inventory', 'Choose a file.', 'File', ...$formats, 'Continue', 'Cancel', ...self::FOOTER], 'step 1 with a problem');
        $this->assertFileInput($refused->body);
        self::assertSame(1, $admin->xpath()->query('//input[@type="file"][@aria-invalid="true"]')->length);
    }

    public function testImportReadingProgressAndReadingOptions(): void
    {
        $admin = $this->admin();
        $job = $this->startJob(IoFixture::csv(IoFixture::HEADINGS, (new IoFixture())->itemRows(2100)), 'large.csv');
        $path = $this->jobPath($job);
        $this->checkAs($admin, $path, [...self::ADMIN_TOP, 'Home Inventory', 'Continue', 'Start again', ...self::FOOTER]);
        $this->assertProgress($admin->page());

        $this->checkAs($admin, $path . '/reading', [
            ...self::ADMIN_TOP, 'Home Inventory',
            'UTF-8', 'UTF-16 little-endian', 'UTF-16 big-endian', 'Windows Latin 1',
            'Comma', 'Semicolon', 'Tab', 'Vertical bar',
            'The first row holds the column names',
            'Read the file again', 'Back',
            ...self::FOOTER,
        ]);
    }

    public function testImportReviewForANewTableWithWarnings(): void
    {
        $admin = $this->admin();
        $rows = (new IoFixture())->sampleRows();
        $job = $this->startJob(IoFixture::csv(IoFixture::SAMPLE_HEADINGS, $rows), 'inventory-sample.csv');
        $path = $this->jobPath($job);
        $mapping = [];
        foreach (IoFixture::SAMPLE_HEADINGS as $heading) {
            array_push($mapping, 'Bring in ' . $heading, 'Field name for ' . $heading, 'Type for ' . $heading);
        }
        $this->checkAs($admin, $path, [
            ...self::ADMIN_TOP, 'Home Inventory', 'Change how the file is read',
            'A new table called', 'Name of the new table', 'Add to an existing table', 'Existing table', 'Check again',
            ...$mapping,
            'Import 52 rows', 'Start again',
            ...self::FOOTER,
        ]);
        $this->assertMapping($admin->page(), IoFixture::SAMPLE_HEADINGS, 'Type for ');
        self::assertSame(1, $admin->xpath()->query('//section[contains(@class, "warning-panel")][@aria-labelledby]/h2')->length, 'The warnings are a region with a heading.');
    }

    public function testImportReviewForAnExistingTableAndWithProblems(): void
    {
        $admin = $this->admin();
        $job = $this->startJob("Name,Serial number,Notes\r\nKettle,EX-000-11111,Synthetic\r\nLadder,EX-000-22222,\r\n", 'items.csv');
        $this->ops->imports()->recheck($this->core->actor('admin'), $this->m2->project, $job, ['target' => 'existing', 'table' => $this->m2->items]);
        $path = $this->jobPath($job);
        $headings = ['Name', 'Serial number', 'Notes'];
        $mapping = [];
        foreach ($headings as $heading) {
            array_push($mapping, 'Bring in ' . $heading, 'Field for ' . $heading);
        }
        $expected = [
            ...self::ADMIN_TOP, 'Home Inventory', 'Change how the file is read',
            'A new table called', 'Name of the new table', 'Add to an existing table', 'Existing table', 'Check again',
            ...$mapping,
            'Import 2 rows', 'Start again',
            ...self::FOOTER,
        ];
        $this->checkAs($admin, $path, $expected);
        $this->assertMapping($admin->page(), $headings, 'Field for ');

        // 422: a field chosen twice and nothing else; every problem is linked from the summary.
        $refused = $admin->submit($path, ['action' => 'import', 'field[1]' => $this->m2->itemFields['Name']]);
        self::assertSame(422, $refused->status, $refused->body);
        $this->assertStructure($refused->body, [...self::ADMIN_TOP, 'Home Inventory'], 'review 422');
        self::assertGreaterThan(0, $admin->xpath()->query('//div[@class="error-summary"]//a')->length);
    }

    public function testImportPreparingSavingReportsAndFailure(): void
    {
        $admin = $this->admin();
        $actor = $this->core->actor('admin');
        $imports = $this->ops->imports();
        $p = $this->m2->project;
        $csv = IoFixture::csv(IoFixture::SAMPLE_HEADINGS, (new IoFixture())->sampleRows());
        $job = $this->startJob($csv, 'inventory-sample.csv');
        // "purchased" as a date, as in the mock-up's warning: the three dates that cannot be read are left empty.
        $input = self::reviewInput($imports->review($actor, $p, $job, null, []));
        $input['type'][3] = 'date';
        $imports->saveMapping($actor, $p, $job, $input);
        $path = $this->jobPath($job);

        $this->checkAs($admin, $path, [...self::ADMIN_TOP, 'Home Inventory', 'Continue', 'Start again', ...self::FOOTER]);
        $this->assertProgress($admin->page());
        while (!$imports->prepareChunk($actor, $p, $job)->finished) {
        }
        $this->checkAs($admin, $path, [...self::ADMIN_TOP, 'Home Inventory', 'Save the rows', ...self::FOOTER]);
        $this->assertProgress($admin->page());
        $saved = $admin->submit($path . '/continue', ['step' => 'save']);
        self::assertSame(303, $saved->status);
        $report = [...self::ADMIN_TOP, 'Home Inventory', 'inventory-sample', 'Open the table', 'Import another file', ...self::FOOTER];
        $this->checkAs($admin, $path . '/report', $report);
        self::assertSame(1, $admin->xpath()->query('//table[contains(@class, "report-table")]/caption')->length, 'Values left empty, with a caption.');

        // A report without issues.
        $clean = $this->startJob("Name\r\nKettle\r\nLadder\r\n", 'clean.csv');
        $imports->saveMapping($actor, $p, $clean, self::reviewInput($imports->review($actor, $p, $clean, null, [])));
        while (!$imports->prepareChunk($actor, $p, $clean)->finished) {
        }
        $imports->commit($actor, $p, $clean);
        $this->checkAs($admin, $this->jobPath($clean) . '/report', [...self::ADMIN_TOP, 'Home Inventory', 'clean', 'Open the table', 'Import another file', ...self::FOOTER]);
        self::assertSame(0, $admin->xpath()->query('//table[contains(@class, "report-table")]')->length);

        // The failure page.
        $failing = $this->startJob(IoFixture::csv(IoFixture::HEADINGS, (new IoFixture())->itemRows(2100)), 'large.csv');
        $this->ops->plugins()->disable($actor, 'import', 'csv');
        $imports->readChunk($actor, $p, $failing);
        $this->checkAs($admin, $this->jobPath($failing), [...self::ADMIN_TOP, 'Home Inventory', 'Start again', ...self::FOOTER]);
    }

    public function testExportWithEachFormatChosenByDefault(): void
    {
        $admin = $this->admin();
        $actor = $this->core->actor('admin');
        $path = $this->m2->projectPath() . '/export';
        $formats = ['CSV one table per file', 'Excel workbook one table, or every table as separate sheets', 'JSON the whole project in one file'];
        $options = ['Add a column with each record\'s number', 'Protect against spreadsheet formulas', 'Add a column with each record\'s number'];
        foreach ([['CSV', 0], ['Excel workbook', 1], ['JSON', 2]] as [$name, $first]) {
            $this->checkAs($admin, $path, [
                ...self::ADMIN_TOP, 'Home Inventory', 'Download the project archive',
                ...array_slice($formats, $first),
                'One table:', 'Table to export', 'Every table',
                ...array_slice($options, $first === 0 ? 0 : 2, $first === 2 ? 0 : 3),
                'Download',
                ...self::FOOTER,
            ]);
            $checked = $admin->xpath()->query('//input[@name="format"][@checked]/following-sibling::span')->item(0);
            self::assertStringStartsWith($name, trim((string) $checked?->textContent), "{$name} is chosen by default.");
            foreach (['format', 'scope'] as $group) {
                self::assertSame(1, $admin->xpath()->query('//fieldset[legend][.//input[@name="' . $group . '"]]')->length, "The {$group} radios are a fieldset with a legend.");
            }
            if ($first === 0) {
                $this->ops->plugins()->disable($actor, 'export', 'csv');
            } elseif ($first === 1) {
                $this->ops->plugins()->disable($actor, 'export', 'xlsx');
            }
        }
    }

    public function testArchiveImportFormNameTakenAndResult(): void
    {
        $admin = $this->admin();
        $actor = $this->core->actor('admin');
        $expected = [...self::ADMIN_TOP, 'Projects', 'Archive file', 'Name for the new project (optional)', 'Import', 'Cancel', ...self::FOOTER];
        $this->checkAs($admin, '/projects/import', $expected);
        $this->assertFileInput($admin->page());

        $file = $this->ops->exports()->archive($actor, $this->m2->project);
        $zip = $this->core->temp->path('home.tiffindb.zip');
        copy($file->path, $zip);
        $this->ops->exports()->finish($actor, $this->m2->project, $file->jobKey);
        $taken = $admin->upload('/projects/import', 'file', $zip, 'home.tiffindb.zip', ['name' => 'Home Inventory']);
        self::assertSame(422, $taken->status, $taken->body);
        $this->assertStructure($taken->body, [...self::ADMIN_TOP, 'Projects', 'Another project already has this name.', 'Name for the new project (optional)', 'Import', 'Cancel', ...self::FOOTER], 'name taken');
        $form = (string) $admin->xpath()->query('//main//form[@method="post"]/@action')->item(0)?->nodeValue;
        $made = $admin->submit($form, ['name' => 'Home Inventory copy']);
        self::assertSame(303, $made->status);
        $result = $admin->follow($made);
        self::assertSame(200, $result->status);
        $this->assertStructure($result->body, [...self::ADMIN_TOP], 'archive result');
        self::assertStringContainsString('Home Inventory copy', HtmlAssertions::text($result->body, '//main//*[@role="status"]'));
        $admin->cleanUp();
    }

    public function testPluginsListPluginPageAndEnableConfirmation(): void
    {
        $admin = $this->admin();
        $this->checkAs($admin, '/admin/plugins', [
            ...self::ADMIN_TOP, 'CSV', 'Excel workbook', 'CSV', 'JSON', 'Excel workbook', ...self::FOOTER,
        ]);
        $this->checkAs($admin, '/admin/plugins/export/json', [...self::ADMIN_TOP, 'Plugins', 'Turn off', 'All plugins', ...self::FOOTER]);
        $this->ops->plugins()->disable($this->core->actor('admin'), 'export', 'json');
        $this->checkAs($admin, '/admin/plugins/export/json', [...self::ADMIN_TOP, 'Plugins', 'Turn on', 'All plugins', ...self::FOOTER]);

        // A plugin that did not come with TiffinDB: its page and the confirmation (scanned only, never loaded).
        $config = Fixtures::config(false) + ['data_dir' => $this->core->temp->path('data'), 'timezone' => 'UTC'];
        $services = new PluginsRootServices(new CoreServices($config, Fixtures::appRoot(), $this->core->clock, new SeededRandom('tiffindb-keyboard-structure-m3-seed'), $this->core->hasher), $this->core->pluginsRoot());
        $added = new EndToEndClient(new Kernel($config, Fixtures::appRoot(), null, $services));
        $added->get('/sign-in');
        self::assertSame(303, $added->submit('/sign-in', ['username' => 'admin', 'password' => CoreFixture::PASSWORD])->status);
        $this->checkAs($added, '/admin/plugins/import/example', [...self::ADMIN_TOP, 'Plugins', 'Turn on', 'All plugins', ...self::FOOTER]);
        $this->checkAs($added, '/admin/plugins/import/example/enable', [...self::ADMIN_TOP, 'Plugins', 'Turn it on', 'Cancel', ...self::FOOTER]);
    }

    // T-KBD-3 (M4 spec part 2 section 10.1): the M4 screens over the M4
    // data set (M4Fixture, its own CoreFixture), as the Member, and as the
    // Admin for the empty-trash pages, with rules 1 to 7 and the six M4
    // items (assertM4Rules).

    public function testM4RecordFormControls(): void
    {
        [$fx, $member] = $this->m4();
        $m2 = $fx->m2;
        $this->checkM4($member, $m2->itemPath('Stand mixer'), [
            ...self::TOP, 'Home Inventory', 'Items', 'Previous item: Sewing machine', 'Next item: Tent, 4-person',
            'Name', 'Kitchen', 'Choose a different room', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            'Open receipt-stand-mixer.pdf', 'Replace receipt-stand-mixer.pdf', 'Remove receipt-stand-mixer.pdf', 'Add a file',
            'Open stand-mixer.png', 'Replace stand-mixer.png', 'Remove stand-mixer.png', 'Add an image',
            'Stand mixer, manufacturer warranty', 'Unlink Stand mixer, manufacturer warranty', 'Link another warranty',
            'Save', 'Back to list', 'Move to trash',
            ...self::FOOTER,
        ]);
        self::assertSame(['stand-mixer.png'], $member->texts('//li[contains(@class, "file-row")]/img/@alt'));

        // No room, no files, no warranty.
        $admin = $this->core4->actor('admin');
        $bookshelf = (string) $m2->itemIds['Bookshelf, oak'];
        $this->ops4->records()->unlink($admin, $m2->project, $m2->items, $bookshelf, $m2->itemFields['Room'], (string) $m2->roomIds['Study']);
        $this->checkM4($member, $m2->itemPath('Bookshelf, oak'), [
            ...self::TOP, 'Home Inventory', 'Items', 'Next item: Cordless drill',
            'Name', 'Choose a record', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            'Add a file', 'Add an image', 'Link a record',
            'Save', 'Back to list', 'Move to trash',
            ...self::FOOTER,
        ]);
        self::assertSame(['No room chosen', 'Choose a record'], $member->texts('//div[@class="link-control"]/*'));

        // Other file types: download-only rows, no image.
        $this->checkM4($member, $m2->itemPath('Sewing machine'), [...self::TOP, 'Home Inventory', 'Items'], false);
        $names = $this->focusNames($member->page());
        foreach (['receipt-sewing-machine.pdf', 'receipt-sewing-machine-2.pdf', 'manual.html', 'diagram.svg'] as $file) {
            $at = array_search('Open ' . $file, $names, true);
            self::assertIsInt($at, $file);
            self::assertSame(['Open ' . $file, 'Replace ' . $file, 'Remove ' . $file], array_slice($names, $at, 3));
        }
        self::assertSame(0, $member->xpath()->query('//img')->length);

        // A new record: notes in place of the controls.
        $this->checkM4($member, $m2->tablePath($m2->items) . '/records/new', [
            ...self::TOP, 'Home Inventory', 'Items',
            'Name', 'Category', 'Purchased', 'Value (US dollars)', ...self::RATING, 'Serial number', 'Under warranty', 'Manual', 'Notes',
            'Save', 'Cancel',
            ...self::FOOTER,
        ]);
    }

    public function testM4RequiredRelationAndAttachmentErrors(): void
    {
        [$fx, $member] = $this->m4();
        $m2 = $fx->m2;
        $admin = $this->core4->actor('admin');
        $schema = $this->ops4->schema();
        $schema->updateField($admin, $m2->project, $m2->items, $m2->itemFields['Room'], 'Room', [], true, '', 0);
        $schema->updateField($admin, $m2->project, $m2->items, $m2->itemFields['Receipt'], 'Receipt', [], true, '', 0);
        $id = (string) $m2->itemIds['Bookshelf, oak'];
        $this->ops4->records()->unlink($admin, $m2->project, $m2->items, $id, $m2->itemFields['Room'], (string) $m2->roomIds['Study']);
        $path = $m2->itemPath('Bookshelf, oak');
        $member->get($path);
        $bad = $member->submit($path);
        self::assertSame(422, $bad->status, $bad->body);
        $this->assertStructure($bad->body, ['Skip to main content', ...array_slice(self::TOP, 1), 'Home Inventory', 'Items'], 'Bookshelf 422');
        $this->assertM4Rules($bad->body, 'Bookshelf 422');
        $x = $member->xpath();
        self::assertSame(2, $x->query('//div[@class="error-summary"]//a')->length, 'Room and Receipt, each linked.');
        foreach (['Room' => '//div[@class="link-control"]/a[@class="button button-plain"]', 'Receipt' => '//div[@class="control-actions"]/a[starts-with(normalize-space(.), "Add a file")]'] as $field => $control) {
            $key = $m2->itemFields[$field];
            self::assertSame(1, $x->query('//div[@class="error-summary"]//a[@href="#f-' . $key . '"]')->length, "The summary links to {$field}'s label region.");
            self::assertSame(1, $x->query('//p[@id="f-' . $key . '"]')->length);
            self::assertSame('f-' . $key . ' err-' . $key, (string) $x->query($control . '/@aria-describedby')->item(0)?->nodeValue, "{$field}: the control names its label and its error (P186).");
        }
    }

    public function testM4Picker(): void
    {
        [$fx, $member] = $this->m4(withMoreRooms: true);
        $m2 = $fx->m2;
        $mixer = $m2->itemPath('Stand mixer');
        $room = $mixer . '/links/' . $m2->itemFields['Room'];
        $head = [...self::TOP, 'Home Inventory', 'Items', 'Stand mixer', 'Kitchen', 'Unlink Kitchen', 'Find in Rooms', 'Find'];
        $rooms = [];
        foreach (['Bathroom', 'Bedroom', 'Dining room', 'Garage', 'Hallway', 'Laundry', 'Living room'] as $name) {
            $rooms[] = $name;
            $rooms[] = 'Choose ' . $name;
        }
        $this->checkM4($member, $room, [...$head, ...$rooms], false);
        $this->checkM4($member, $room . '?page=2', $head, false);
        $this->checkM4($member, $room . '?q=gar', [...$head, 'Show all', 'Garage', 'Choose Garage', 'Back to Stand mixer', 'Add a new room and choose it', ...self::FOOTER]);
        $this->checkM4($member, $room . '?q=' . str_repeat('x', 201), $head, false);
        self::assertSame(1, $member->xpath()->query('//input[@name="q"][@aria-invalid="true"][@aria-describedby="err-q"]')->length);

        // Many links, with taken candidates (no button).
        $warranties = $mixer . '/links/' . $m2->itemFields['Warranties'];
        $taken = [];
        foreach (M2Fixture::WARRANTY_ITEMS as $item) {
            if ($item !== 'Stand mixer') {
                $taken[] = $item . ', manufacturer warranty';
            }
        }
        usort($taken, static fn (string $a, string $b): int => strcasecmp($a, $b));
        $this->checkM4($member, $warranties, [
            ...self::TOP, 'Home Inventory', 'Items', 'Stand mixer', 'Stand mixer, manufacturer warranty', 'Unlink Stand mixer, manufacturer warranty',
            'Find in Warranties', 'Find', ...$taken, 'Back to Stand mixer', 'Add a new warranty and link it', ...self::FOOTER,
        ]);
        self::assertSame(count($taken), $member->xpath()->query('//span[@class="picker-taken"]')->length);

        // A trashed link: the note, and no Choose buttons.
        $laundry = (string) array_search('Laundry', $m2->itemRooms, true);
        $this->ops4->records()->trash($this->core4->actor('admin'), $m2->project, $m2->rooms, (string) $m2->roomIds['Laundry']);
        $this->checkM4($member, $m2->itemPath($laundry) . '/links/' . $m2->itemFields['Room'], [...self::TOP, 'Home Inventory', 'Items', $laundry, 'Find in Rooms', 'Find', 'Bathroom', 'Bedroom'], false);
        self::assertSame(0, $member->xpath()->query('//button[starts-with(@aria-label, "Choose ")]')->length);
        self::assertSame(1, $member->xpath()->query('//p[contains(@class, "notice-warn")]')->length);

        // A 422: a warranty taken by another item.
        $member->get($warranties);
        $other = (string) $m2->warrantyIds['Espresso machine, manufacturer warranty'];
        $csrf = (string) $member->xpath()->query('//form[contains(@action, "/remove")]/input[@name="_csrf"]/@value')->item(0)?->nodeValue;
        $refused = $member->post($warranties . '/add', ['_csrf' => $csrf, 'target' => $other, 'back' => 'picker', 'q' => '', 'page' => '1']);
        self::assertSame(422, $refused->status, $refused->body);
        $this->assertStructure($refused->body, ['Skip to main content', ...array_slice(self::TOP, 1), 'Home Inventory', 'Items', 'Stand mixer'], 'Picker 422');
        $this->assertM4Rules($refused->body, 'Picker 422');
    }

    public function testM4UploadFileAndRemovePages(): void
    {
        [$fx, $member] = $this->m4();
        $m2 = $fx->m2;
        $mixer = $m2->itemPath('Stand mixer');
        $receipt = $mixer . '/files/' . $m2->itemFields['Receipt'];
        $photo = $mixer . '/files/' . $m2->itemFields['Photo'];
        $crumbs = [...self::TOP, 'Home Inventory', 'Items', 'Stand mixer'];
        $this->checkM4($member, $receipt . '/new', [...$crumbs, 'File', 'Add the file', 'Cancel', ...self::FOOTER]);
        $this->assertUploadInput($member->page());
        $this->checkM4($member, $photo . '/new?replace=' . $fx->attachmentIds['stand-mixer.png'], [...$crumbs, 'File', 'Replace the file', 'Cancel', ...self::FOOTER]);
        $this->assertUploadInput($member->page());

        // A 422: an empty file.
        $member->get($receipt . '/new');
        $path = $this->core4->upload('');
        $refused = $member->upload($receipt, 'file', $path, 'empty.pdf');
        self::assertSame(422, $refused->status);
        $this->assertStructure($refused->body, ['Skip to main content', ...array_slice(self::TOP, 1), 'Home Inventory', 'Items', 'Stand mixer'], 'Upload 422');
        $this->assertM4Rules($refused->body, 'Upload 422');
        self::assertSame(1, $member->xpath()->query('//input[@id="f-file"][@aria-invalid="true"][contains(concat(" ", @aria-describedby, " "), " err-file ")]')->length);
        $member->cleanUp();

        $file = [...$crumbs, 'Download', 'Replace', 'Remove', 'Back to Stand mixer', ...self::FOOTER];
        $this->checkM4($member, $photo . '/' . $fx->attachmentIds['stand-mixer.png'], $file);
        self::assertSame(['stand-mixer.png'], $member->texts('//img/@alt'));
        $this->checkM4($member, $receipt . '/' . $fx->attachmentIds['receipt-stand-mixer.pdf'], $file);
        $this->checkM4($member, $receipt . '/' . $fx->attachmentIds['receipt-stand-mixer.pdf'] . '/remove', [...$crumbs, 'Remove the file', 'Cancel', ...self::FOOTER]);
    }

    public function testM4CollectionEditorViewAndDelete(): void
    {
        [$fx, $member] = $this->m4();
        $m2 = $fx->m2;
        $items = $m2->tablePath($m2->items);
        $head = [...self::TOP, 'Home Inventory', 'Items', 'Collection name', 'All of these rules', 'Any of these rules'];
        $this->checkM4($member, $items . '/collections/new', [...$head, 'Field for rule 1', 'Remove rule 1', 'Add a rule', 'Sort by', 'Save collection', 'Preview', 'Cancel'], false);
        $this->checkM4($member, $fx->collectionPath('Under warranty') . '/edit', [
            ...$head, 'Field for rule 1', 'Test for rule 1', 'Remove rule 1', 'Field for rule 2', 'Test for rule 2', 'Date for rule 2', 'Remove rule 2',
            'Add a rule', 'Sort by', 'Save collection', 'Preview', 'Cancel', 'Delete this collection',
        ], false);
        $this->checkM4($member, $fx->collectionPath('Retired items') . '/edit', [
            ...$head, 'Field for rule 1', 'Remove rule 1', 'Field for rule 2', 'Test for rule 2', 'Amount for rule 2', 'Remove rule 2',
            'Add a rule', 'Sort by', 'Save collection', 'Preview', 'Cancel', 'Delete this collection',
        ], false);
        self::assertSame(1, $member->xpath()->query('//fieldset[contains(@class, "rule-broken")]')->length, 'The broken rule is shown.');

        // After Add a rule, after Remove, and a 422.
        $edit = $fx->collectionPath('Under warranty') . '/edit';
        $member->get($edit);
        $added = $member->submit($edit, ['action' => 'add']);
        self::assertSame(200, $added->status);
        $this->assertStructure($added->body, [...$head, 'Field for rule 1', 'Test for rule 1', 'Remove rule 1', 'Field for rule 2', 'Test for rule 2', 'Date for rule 2', 'Remove rule 2', 'Field for rule 3', 'Remove rule 3', 'Add a rule'], 'Editor after Add');
        $this->assertM4Rules($added->body, 'Editor after Add');
        $removed = $member->submit($edit, ['action' => 'remove-1']);
        self::assertSame(200, $removed->status);
        $this->assertStructure($removed->body, [...$head, 'Field for rule 1', 'Test for rule 1', 'Date for rule 1', 'Remove rule 1', 'Field for rule 2', 'Remove rule 2', 'Add a rule'], 'Editor after Remove');
        $this->assertM4Rules($removed->body, 'Editor after Remove');
        $bad = $member->submit($edit, ['name' => '', 'rules[1][value]' => 'not a date', 'action' => 'save']);
        self::assertSame(422, $bad->status, $bad->body);
        $this->assertStructure($bad->body, ['Skip to main content', ...array_slice(self::TOP, 1), 'Home Inventory', 'Items'], 'Editor 422');
        $this->assertM4Rules($bad->body, 'Editor 422');
        self::assertGreaterThanOrEqual(2, $member->xpath()->query('//div[@class="error-summary"]//a')->length, 'The name and the rule, each linked.');

        // The view (with the broken-rule notice) and the delete confirmation.
        $sidebar = ['Switch project', 'Items 48', 'Rooms 9', 'Warranties 12'];
        $this->checkM4($member, $fx->collectionPath('Retired items'), [...self::TOP_SEARCH, ...$sidebar], false);
        self::assertSame(1, $member->xpath()->query('//main//div[contains(@class, "notice-warn")][@role="status"]')->length);
        $this->checkM4($member, $fx->collectionPath('Under warranty') . '/delete', [...self::TOP, 'Home Inventory', 'Items', 'Collection: Under warranty', 'Delete the collection', 'Cancel', ...self::FOOTER]);
    }

    public function testM4TrashAndEmptyConfirmation(): void
    {
        [$fx, $member, $admin] = $this->m4(withMoreRooms: true);
        $m2 = $fx->m2;
        $trash = $m2->projectPath() . '/trash';
        $this->checkM4($member, $trash, [...self::TOP, 'Home Inventory', 'Restore all in Items', 'Restore Worn rug', 'Restore Old kettle', 'Restore Cracked mirror', 'Restore all in Rooms'], false);
        $this->checkM4($member, $trash . '?table=' . $m2->rooms . '&page=2', [...self::TOP, 'Home Inventory', 'Show every table', 'Restore all in Rooms'], false);
        self::assertCount(1, $member->texts('//main//button[starts-with(@aria-label, "Restore ") and not(starts-with(@aria-label, "Restore all"))]'), 'One room on the second page.');
        $this->checkM4($member, '/projects/' . $this->garden . '/trash', [...self::TOP, 'Garden Log', 'Back to Garden Log', ...self::FOOTER]);

                $this->checkM4($admin, $m2->projectPath() . '/trash/empty', [...self::ADMIN_TOP, 'Home Inventory', 'Trash', 'Empty the trash', 'Cancel', ...self::FOOTER]);
        self::assertSame(1, $admin->xpath()->query('//main//form//button[contains(@class, "button-danger")][normalize-space(.)="Empty the trash"]')->length);
    }

    // M4 helpers.

    private ?CoreFixture $core4 = null;
    private ?Operations $ops4 = null;
    private string $garden = '';

    /**
     * The M4 data set in its own CoreFixture (closed in tearDownM4), with
     * the Member and the Admin signed in through the form. With
     * $withMoreRooms: 50 more rooms and 51 in the trash (so the Room picker
     * and the Rooms trash have a second page), and a project Garden Log
     * with an empty trash.
     *
     * @return array{0: M4Fixture, 1: EndToEndClient, 2: EndToEndClient}
     */
    private function m4(bool $withMoreRooms = false): array
    {
        $this->core4 = new CoreFixture();
        $this->core4->clock->set('2026-11-03T10:00:00Z');
        $this->ops4 = $this->core4->operations();
        $fx = M4Fixture::build($this->core4, $this->ops4);
        $m2 = $fx->m2;
        $actor = $this->core4->actor('admin');
        if ($withMoreRooms) {
            for ($n = 1; $n <= 51; $n++) {
                $id = $this->core4->addRecord($m2->project, $m2->rooms, [$m2->roomFields['Name'] => sprintf('Store %02d', $n)]);
                $this->ops4->records()->trash($actor, $m2->project, $m2->rooms, (string) $id);
            }
            for ($n = 52; $n <= 101; $n++) {
                $this->core4->addRecord($m2->project, $m2->rooms, [$m2->roomFields['Name'] => sprintf('Store %03d', $n)]);
            }
            $this->garden = $this->ops4->projects()->create($actor, 'Garden Log', 'Plants')->key;
            $this->ops4->projects()->setSharing($actor, $this->garden, [$this->core4->person('member')->key]);
        }
        $config = Fixtures::config(false) + ['data_dir' => $this->core4->temp->path('data'), 'timezone' => 'UTC'];
        $kernel = new Kernel($config, Fixtures::appRoot(), null, new CoreServices($config, Fixtures::appRoot(), $this->core4->clock, new SeededRandom('tiffindb-keyboard-structure-m4-seed'), $this->core4->hasher));
        $clients = [];
        foreach (['member', 'admin'] as $username) {
            $client = new EndToEndClient($kernel);
            $client->get('/sign-in');
            self::assertSame(303, $client->submit('/sign-in', ['username' => $username, 'password' => CoreFixture::PASSWORD])->status);
            $clients[] = $client;
        }

        return [$fx, $clients[0], $clients[1]];
    }

    /** GETs $uri as $client (200), rules 1 to 7 ($exact: the whole focus order, else its start) and the M4 items. */
    private function checkM4(EndToEndClient $client, string $uri, array $names, bool $exact = true): void
    {
        $response = $client->get($uri);
        self::assertSame(200, $response->status, "GET {$uri}");
        if ($exact) {
            self::assertSame(self::FOOTER[0], $names[count($names) - 1], 'An exact order ends with the footer.');
        } else {
            self::assertNotSame(self::FOOTER[0], $names[count($names) - 1], 'A start of the order.');
        }
        $this->assertStructure($response->body, $names, $uri);
        $this->assertM4Rules($response->body, $uri);
    }

    /** @return list<string> the accessible names of the focusable elements, in document order (rule 4's list) */
    private function focusNames(string $html): array
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
        $names = [];
        foreach ($x->query('//a[@href] | //button[not(@tabindex="-1")] | //input[not(@type="hidden")] | //select | //textarea') as $element) {
            /** @var DOMElement $element */
            $names[] = in_array($element->nodeName, ['input', 'select', 'textarea'], true) ? self::labelName($x, $element) : self::accessibleName($x, $element);
        }

        return $names;
    }

    /**
     * The M4 items of section 10.1: (1) Unlink, Link, Choose, Open,
     * Replace, and Remove on the record form and the picker have a name
     * that starts with their visible text and names the record or file;
     * (2) every `form` attribute names a form on the page, and no form is
     * nested; (3) each rule row is a fieldset with the legend "Rule n" and
     * the mock-up's labels; (4) the editor's first submit button is the
     * hidden Preview (tabindex -1), and the visible Preview, Add a rule,
     * and Save collection come after it; (5) each picker row holds one
     * title and at most one button, and the find box has a visible label;
     * (6) every image has an alt text, the file's name.
     */
    private function assertM4Rules(string $html, string $where): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));

        // 1.
        $controls = '//*[contains(@class, "link-control") or contains(@class, "link-list") or contains(@class, "file-list") or contains(@class, "picker-list")]';
        foreach ($x->query($controls . '//a[@href] | ' . $controls . '//button') as $element) {
            /** @var DOMElement $element */
            $visible = self::text($element);
            if (!in_array($visible, ['Unlink', 'Link', 'Choose', 'Open', 'Replace', 'Remove'], true)) {
                continue;
            }
            $name = self::accessibleName($x, $element);
            self::assertStringStartsWith($visible . ' ', $name, "{$where}: {$visible} names what it acts on.");
            self::assertGreaterThan(strlen($visible) + 1, strlen($name));
        }

        // 2.
        foreach ($x->query('//*[@form]') as $element) {
            /** @var DOMElement $element */
            self::assertSame(1, $x->query('//form[@id="' . $element->getAttribute('form') . '"]')->length, "{$where}: form=\"{$element->getAttribute('form')}\" names a form.");
        }
        self::assertSame(0, $x->query('//form//form')->length, "{$where}: no nested form.");

        // 3.
        $n = 0;
        foreach ($x->query('//fieldset[contains(concat(" ", @class, " "), " rule-row ")]') as $row) {
            $n++;
            self::assertSame('Rule ' . $n, trim((string) $x->query('legend', $row)->item(0)?->textContent), "{$where}: rule {$n} legend.");
            $labels = [];
            foreach ($x->query('.//select | .//input[not(@type="hidden")]', $row) as $control) {
                /** @var DOMElement $control */
                $labels[] = $control->getAttribute('aria-label');
            }
            self::assertSame('Field for rule ' . $n, $labels[0] ?? null, "{$where}: rule {$n} field.");
            if (isset($labels[1])) {
                self::assertSame('Test for rule ' . $n, $labels[1], "{$where}: rule {$n} test.");
            }
            if (isset($labels[2])) {
                self::assertStringEndsWith(' for rule ' . $n, $labels[2], "{$where}: rule {$n} value.");
            }
            self::assertSame(1, $x->query('.//button[@aria-label="Remove rule ' . $n . '"][normalize-space(.)="Remove"]', $row)->length, "{$where}: Remove rule {$n}.");
        }

        // 4.
        $editor = $x->query('//form[@id="collection-form"]')->item(0);
        if ($editor instanceof DOMElement) {
            $submits = $x->query('.//button[not(@type) or @type="submit"]', $editor);
            $first = $submits->item(0);
            self::assertInstanceOf(DOMElement::class, $first);
            self::assertSame(['default-action', '-1', 'action', 'preview'], [$first->getAttribute('class'), $first->getAttribute('tabindex'), $first->getAttribute('name'), $first->getAttribute('value')], "{$where}: the hidden Preview first.");
            foreach (['Preview', 'Add a rule', 'Save collection'] as $label) {
                self::assertSame(1, $x->query('.//button[not(@tabindex)][normalize-space(.)="' . $label . '"]', $editor)->length, "{$where}: {$label} is in the tab order.");
            }
        }

        // 5.
        foreach ($x->query('//ul[@class="picker-list"]/li') as $row) {
            self::assertSame('picker-row', $row->getAttribute('class'));
            self::assertSame(1, $x->query('span[@class="picker-title"]', $row)->length, "{$where}: one title per picker row.");
            self::assertLessThanOrEqual(1, $x->query('.//button', $row)->length, "{$where}: at most one button per picker row.");
        }
        $find = $x->query('//form[@class="picker-find"]//input[@name="q"]')->item(0);
        if ($find instanceof DOMElement) {
            self::assertSame(1, $x->query('//label[@for="' . $find->getAttribute('id') . '"][not(contains(@class, "visually-hidden"))]')->length, "{$where}: the find box has a visible label.");
        }

        // 6.
        foreach ($x->query('//img') as $img) {
            /** @var DOMElement $img */
            self::assertNotSame('', trim($img->getAttribute('alt')), "{$where}: an image has an alt text.");
            $row = $x->query('ancestor::li[contains(@class, "file-row")][1]', $img)->item(0);
            if ($row instanceof DOMElement) {
                self::assertStringStartsWith($img->getAttribute('alt') . ' ', self::text($x->query('span[@class="file-name"]', $row)->item(0) ?? $row), "{$where}: the alt text is the file's name.");
            }
        }
    }

    /** The file input of the upload page: a visible label and its help through aria-describedby. */
    private function assertUploadInput(string $html): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
        self::assertSame(1, $x->query('//label[@for="f-file"][not(contains(@class, "visually-hidden"))]')->length);
        self::assertSame(1, $x->query('//input[@id="f-file"][@type="file"][contains(concat(" ", @aria-describedby, " "), " help-file ")]')->length);
        self::assertMatchesRegularExpression('/up to \d[\d.,]* ?(bytes|KB|MB|GB)/', (string) $x->query('//p[@id="help-file"]')->item(0)?->textContent);
    }

    // M3 helpers.

    /** A client signed in as the Admin through the sign-in form. */
    private function admin(): EndToEndClient
    {
        $admin = new EndToEndClient($this->kernel);
        $admin->get('/sign-in');
        self::assertSame(303, $admin->submit('/sign-in', ['username' => 'admin', 'password' => CoreFixture::PASSWORD])->status);

        return $admin;
    }

    /** GETs $uri as $client (200) and asserts the seven rules with the expected names. */
    private function checkAs(EndToEndClient $client, string $uri, array $names): void
    {
        $response = $client->get($uri);
        self::assertSame(200, $response->status, "GET {$uri}");
        $this->assertStructure($response->body, $names, $uri);
    }

    /** An import job of $csv in Home Inventory, started by the Admin through the core (the job page is the Admin's). */
    private function startJob(string $csv, string $name): string
    {
        return $this->ops->imports()->start($this->core->actor('admin'), $this->m2->project, $this->core->upload($csv), $name, null);
    }

    private function jobPath(string $job): string
    {
        return $this->m2->projectPath() . '/import/' . $job;
    }

    /** @return array<string, mixed> the review form as posted with nothing changed */
    private static function reviewInput(\TiffinDB\Core\App\ImportReview $review): array
    {
        $input = ['target' => $review->target, 'table_name' => (string) $review->newTableName, 'table' => (string) $review->tableKey, 'include' => [], 'name' => [], 'type' => [], 'field' => []];
        foreach ($review->columns as $column) {
            if ($column->include) {
                $input['include'][$column->index] = '1';
            }
            $input['name'][$column->index] = $column->fieldName;
            $input['type'][$column->index] = $column->type;
            $input['field'][$column->index] = (string) $column->fieldKey;
        }

        return $input;
    }

    /** Item 4: the file input has a visible label and its help (formats and size limit) through aria-describedby. */
    private function assertFileInput(string $html): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
        $input = $x->query('//input[@type="file"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $input);
        $label = $x->query('//label[@for="' . $input->getAttribute('id') . '"][not(contains(@class, "visually-hidden"))]')->item(0);
        self::assertNotNull($label, 'A visible label.');
        self::assertNotSame('', trim((string) $label->textContent));
        $help = '';
        foreach (preg_split('/\s+/', $input->getAttribute('aria-describedby')) ?: [] as $id) {
            $help .= ' ' . trim((string) $x->query('//*[@id="' . $id . '"]')->item(0)?->textContent);
        }
        self::assertMatchesRegularExpression('/\.(csv|xlsx|zip)\b/i', $help, 'The help names the formats.');
        self::assertMatchesRegularExpression('/up to \d[\d.,]* ?(bytes|KB|MB|GB)/', $help, 'The help gives the size limit.');
    }

    /** Item 3: the status is a live region and the Continue button follows it in reading order. */
    private function assertProgress(string $html): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
        self::assertSame(1, $x->query('//div[@class="progress"]//p[@role="status"]')->length, 'The status is a live region.');
        self::assertSame(1, $x->query('//div[@class="progress"]//p[@role="status"]/following::form[contains(@class, "continue-form")]//button')->length, 'The button follows the status.');
        self::assertSame(0, $x->query('//form[contains(@class, "continue-form")]//button/following::p[@role="status"]')->length);
    }

    /**
     * Item 1: each control of the mapping table names its column, through
     * aria-label built from the heading.
     *
     * @param list<string> $headings
     */
    private function assertMapping(string $html, array $headings, string $last): void
    {
        $x = new DOMXPath(HtmlAssertions::assertWellFormed($html));
        foreach ($headings as $n => $heading) {
            $row = $x->query('//table[contains(@class, "mapping-table")]/tbody/tr')->item($n);
            self::assertInstanceOf(DOMElement::class, $row);
            self::assertSame($heading, trim((string) $x->query('th[@scope="row"]', $row)->item(0)?->textContent));
            $labels = [];
            foreach ($x->query('.//input[not(@type="hidden")] | .//select', $row) as $control) {
                /** @var DOMElement $control */
                $labels[] = $control->getAttribute('aria-label');
            }
            $expected = $last === 'Type for ' ? ['Bring in ' . $heading, 'Field name for ' . $heading, 'Type for ' . $heading] : ['Bring in ' . $heading, 'Field for ' . $heading];
            self::assertSame($expected, $labels, "The controls of {$heading}.");
        }
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
            // M4: the collection editor's hidden default Preview button (section 10.1 item 4).
            $allowed = $element->getAttribute('class') === 'error-summary'
                || ($element->nodeName === 'main' && $element->getAttribute('id') === 'main')
                || ($element->nodeName === 'button' && $element->getAttribute('class') === 'default-action');
            self::assertTrue($allowed, "{$where}: only the error summary, main#main, and the editor's default button have a tabindex.");
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
        // Elements with tabindex -1 are not reached by Tab (M4: the editor's hidden default button).
        foreach ($x->query('//a[@href] | //button[not(@tabindex="-1")] | //input[not(@type="hidden")] | //select | //textarea') as $element) {
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
            // A button inside a form that names another form with `form` belongs to that one (M4 Unlink buttons).
            $submits = $x->query('.//button[(not(@type) or @type="submit") and not(@form)]', $form);
            $id = $form->getAttribute('id');
            $outside = $id === '' ? null : $x->query('//button[@form="' . $id . '"]');
            if ($outside !== null && $outside->length > 0 && str_starts_with($id, 'unlink-') && str_contains($class, 'unlink-form')) {
                // M4: a many-link field's unlink form is submitted only by its Unlink buttons (record-controls.md section 2).
                self::assertSame(0, $submits->length, "{$where}: the unlink form {$id} holds no button.");
                foreach ($outside as $button) {
                    /** @var DOMElement $button */
                    self::assertSame('target', $button->getAttribute('name'), "{$where}: an Unlink button posts its target.");
                    self::assertStringStartsWith('Unlink ', $button->getAttribute('aria-label'), "{$where}: an Unlink button names its record.");
                }
                continue;
            }
            if ($outside !== null && $outside->length > 0) {
                // M3: the import review form's Import button sits after the form, as the record form's Save does (import.md 4.5).
                self::assertContains($id, ['record-form', 'import-review'], "{$where}: only the record form and the import review are submitted from outside.");
                if ($id === 'record-form') {
                    self::assertSame(0, $submits->length, "{$where}: the record form's buttons are outside it.");
                    $submits = $outside;
                } else {
                    $submits = $x->query('.//button[not(@type) or @type="submit"] | //button[@form="' . $id . '"]', $form);
                }
                self::assertSame(1, $x->query('.//input[@type="text"]', $form)->length > 0 ? 1 : 0, "{$where}: the form holds a one-line text input.");
            }
            // M3: the import's "Start again" form (`.../cancel`) and a plugin's "Turn off" form (`.../disable`) are a single
            // secondary button by design (import.md 3, 4, 6, 8; plugins.md 3).
            // M4: the picker's and the trash screen's per-row forms (Link, Choose, Unlink, Restore, Restore all) are one plain button each (picker.md 2, trash.md 3).
            if ($action === '/sign-out' || str_contains($class, 'trash-form') || str_contains($class, 'notice-form') || str_contains($class, 'inline-form') || preg_match('#/(cancel|disable)\z#', $action) === 1) {
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
     * The sidebar's names: Switch project, each table with its count, "New
     * collection" on a table list (M4 spec part 2 section 5, D-46; not on
     * the search page), Tables and fields.
     *
     * @return list<string>
     */
    private function sidebar(int $itemsDelta = 0, bool $tableList = true): array
    {
        // M4 spec part 2 section 5: the trash line is a link after "Tables and fields".
        return [
            'Switch project', 'Items ' . (48 + $itemsDelta), 'Rooms 9', 'Warranties 12', 'Loans 0', 'Samples 1',
            ...($tableList ? ['New collection'] : []),
            'Tables and fields', 'In the trash ' . (3 - $itemsDelta),
        ];
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
